<?php

declare(strict_types=1);

use Dotenv\Parser\Entry;
use Dotenv\Parser\EntryParser;
use Dotenv\Parser\Lines;
use Dotenv\Parser\Value;

/**
 * 本地环境镜像同步脚本(二期,设计稿 §5)。
 *
 * 运行位置:被同步项目的根目录(cwd)。依赖该项目 vendor/ 里的 vlucas/phpdotenv——不随本插件带一份副本,
 * 版本就跟着项目走,不会出现「插件的 phpdotenv 与项目实际运行的 phpdotenv 语义不一致」这种事。
 *
 * 四条铁律(与本文件顶部注释一一对应,改动前先重读设计稿 §5.1):
 *  1. 值只在文件之间流动:stdout / stderr 永远只有键名、分类、计数与固定错误文案。任何 Throwable 只打
 *     固定文案+非零码退出,绝不输出异常消息本身——phpdotenv 的解析错误会把出错条目的原始值拼进消息。
 *  2. 解析是「文件语义」不是「运行时语义」:只用 Dotenv\Parser\Lines / EntryParser(不用 Dotenv::createImmutable()
 *     或 Laravel 的 Env::getRepository(),那条路径会读 OS 环境变量、被同名 OS 变量遮蔽)。
 *  3. 整文件必须先能逐条解析通过,任一条目失败即整体拒绝,不产出任何文件。
 *  4. 写盘前必备份;.env 本身原子替换(同目录临时文件 + 保持原权限位 + mv);写盘前后比对 mtime+size。
 *  5. 中间文件(plan/apply 的请求与响应、决策文件)一律落在项目目录之外的工作目录,0700/0600,
 *     不接受自由输出路径;流程结束用 cleanup 子命令清空。
 */

// 注:CLI 引导块故意放在文件最末尾,不是这里——PHP 顶层 const/变量语句是按执行顺序生效的
// (不像函数声明会整体提升),引导块若放在最前面,轮到它调用 local_env_main() 时,
// 后面才声明的那些 const 还没执行到,会直接炸「Undefined constant」。

// ============================================================
// 常量
// ============================================================

/** 上行键名的正式格式,与中心的上行键名正则逐字同源。 */
const LOCAL_ENV_UPLOAD_KEY_PATTERN = '/\A[A-Z_][A-Z0-9_]*\z/';

// slug 只用来命名 ~/.dev-admin 下的工作目录与备份目录,必须是单个安全的路径段:
// 字母 / 数字开头,只含字母、数字、. _ -,长度 1~100——排除掉 `.`、`..`、含 `/` 的任何形态,
// 否则 cleanup 的 glob+unlink 会顺着 `..` 摸到别的目录。照提示词里「项目:」后面的 slug 抄即可。
const LOCAL_ENV_SLUG_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/';

/** 停用行(# KEY=)扫描正则:井号后允许空白、允许 export 前缀,只认未加引号的键名(常见形态)。 */
const LOCAL_ENV_DISABLED_LINE_PATTERN = '/^\s*#\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/';

/**
 * SSO 三键(中心只对它们开放**下行**,与中心「本地可下行写入的系统键」清单同源;
 * 中心侧有守卫用例钉住两边一致)。本地活行里这三个键**值为空**(从 .env.example 复制出来的空占位)视为「缺失」,
 * 见 local_env_blank_sso_keys()。
 */
const LOCAL_ENV_SSO_KEYS = ['SSO_BASE_URL', 'SSO_CLIENT_ID', 'SSO_CLIENT_SECRET'];

// ============================================================
// 解析:文件 → 结构化清单(fail-closed,永不泄值)
// ============================================================

/**
 * 解析失败的固定错误类别——只在这五种里选一种,绝不拼接 phpdotenv 的原始错误消息(会带出值)。
 *
 * @return string 固定中文类别
 */
function local_env_categorize_parse_error(string $phpdotenvMessage): string
{
    return match (true) {
        str_starts_with($phpdotenvMessage, 'Encountered unexpected whitespace') => '未加引号的空格',
        str_starts_with($phpdotenvMessage, 'Encountered a missing closing quote') => '未闭合引号',
        str_starts_with($phpdotenvMessage, 'Encountered an unexpected escape sequence') => '非法转义',
        str_starts_with($phpdotenvMessage, 'Encountered an invalid name') => '非法键名',
        str_starts_with($phpdotenvMessage, 'Encountered an unexpected equals') => '空键名',
        default => '未知解析错误',
    };
}

/** 复刻 Dotenv\Parser\Lines::isCommentOrWhitespace()(private,不可直接调用),判据完全一致。 */
function local_env_is_comment_or_blank(string $line): bool
{
    $trimmed = trim($line, " \n\r\t\0\x0B");

    return $trimmed === '' || $trimmed[0] === '#';
}

/**
 * 把 Lines::process() 输出的「条目」按原始物理行顺序精确匹配回行号区间(0-indexed,闭区间)。
 *
 * 不复刻 phpdotenv 的私有多行状态机——每个条目本就是若干连续物理行用 "\n" 拼接而成的产物,
 * 按输出顺序从游标往后逐条精确匹配源行序列即可得到区间,O(n),且天然对不上时立刻报错(fail-closed)。
 *
 * @param  string[]  $lines
 * @param  string[]  $rawEntries
 * @return list<array{0: int, 1: int}> 与 $rawEntries 一一对应的 [起始行, 结束行]
 *
 * @throws RuntimeException 行序列对不上(说明脚本的理解与 phpdotenv 实际行为已经分岔)
 */
function local_env_map_entries_to_lines(array $lines, array $rawEntries): array
{
    $ranges = [];
    $cursor = 0;
    $lineCount = count($lines);

    foreach ($rawEntries as $raw) {
        while ($cursor < $lineCount && local_env_is_comment_or_blank($lines[$cursor])) {
            $cursor++;
        }

        $parts = explode("\n", $raw);
        $partCount = count($parts);

        if ($cursor + $partCount > $lineCount || implode("\n", array_slice($lines, $cursor, $partCount)) !== $raw) {
            throw new RuntimeException('line-mapping-mismatch');
        }

        $ranges[] = [$cursor, $cursor + $partCount - 1];
        $cursor += $partCount;
    }

    // 吞行校验:游标之后如果还剩非注释/空行,说明被一个从未闭合的多行值吞掉了(未闭合引号)。
    while ($cursor < $lineCount && local_env_is_comment_or_blank($lines[$cursor])) {
        $cursor++;
    }

    if ($cursor < $lineCount) {
        throw new RuntimeException('unclosed-quote-swallowed-lines:'.($cursor + 1));
    }

    return $ranges;
}

/**
 * 解析整份 .env,返回结构化清单;任一条目解析失败即整体 fail-closed。
 *
 * @return array{
 *     ok: bool,
 *     error?: array{line: ?int, category: string},
 *     lines?: list<string>,
 *     active?: array<string, array{value: string, interpolated: bool, start: int, end: int}>,
 *     disabled?: array<string, array{start: int, end: int}>,
 *     novalue?: list<string>,
 *     invalid?: list<string>,
 *     duplicate?: list<string>,
 * }
 */
function local_env_parse(string $content): array
{
    $lines = preg_split('/\r\n|\n|\r/', $content);

    if ($lines === false) {
        return ['ok' => false, 'error' => ['line' => null, 'category' => '文件编码异常,无法按行切分']];
    }

    $rawEntries = Lines::process($lines);

    try {
        $ranges = local_env_map_entries_to_lines($lines, $rawEntries);
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'unclosed-quote-swallowed-lines:')) {
            $line = (int) substr($e->getMessage(), strlen('unclosed-quote-swallowed-lines:'));

            return ['ok' => false, 'error' => ['line' => $line, 'category' => '未闭合引号,自第 '.$line.' 行起后续行被吞']];
        }

        return ['ok' => false, 'error' => ['line' => null, 'category' => '解析结果与原始行对不上']];
    }

    $active = [];
    $novalueSeen = [];
    $invalid = [];
    $duplicate = [];
    $activeCount = [];

    foreach ($rawEntries as $index => $raw) {
        $result = EntryParser::parse($raw);
        $entryOption = $result->success();

        if ($entryOption->isEmpty()) {
            $message = $result->error()->getOrElse('未知解析错误');
            [$start] = $ranges[$index];

            return ['ok' => false, 'error' => ['line' => $start + 1, 'category' => local_env_categorize_parse_error($message)]];
        }

        /** @var Entry $entry */
        $entry = $entryOption->get();
        $name = $entry->getName();
        $upper = strtoupper($name);
        [$start, $end] = $ranges[$index];

        // 必须原文就已经是全大写(不能拿 strtoupper 后的结果去比对自己——那对任何合法标识符恒真)。
        // phpdotenv 自身的键名字符集比这宽(允许小写、允许点号),「非全大写」在这里单指原文含小写字母;
        // 点号等字符仍会被下面的正则拦下(设计稿 §3.4 只认 [A-Z_][A-Z0-9_]*)。
        if (preg_match(LOCAL_ENV_UPLOAD_KEY_PATTERN, $name) !== 1 || strlen($name) > 255) {
            $invalid[] = $name;
            unset($novalueSeen[$upper]);

            continue;
        }

        $valueOption = $entry->getValue();

        if ($valueOption->isEmpty()) {
            // 裸键(无等号):既不是活键也不是停用行,phpdotenv 解析为 null 值
            $novalueSeen[$upper] = true;
            unset($active[$upper]);

            continue;
        }

        /** @var Value $value */
        $value = $valueOption->get();

        if (isset($active[$upper])) {
            $activeCount[$upper] = ($activeCount[$upper] ?? 1) + 1;
        } else {
            $activeCount[$upper] = 1;
        }

        $active[$upper] = [
            'value' => $value->getChars(),
            'interpolated' => $value->getVars() !== [],
            'start' => $start,
            'end' => $end,
        ];
        unset($novalueSeen[$upper]);
    }

    foreach ($activeCount as $key => $count) {
        if ($count > 1) {
            $duplicate[] = $key;
        }
    }

    $disabled = [];
    foreach ($lines as $i => $line) {
        if (preg_match(LOCAL_ENV_DISABLED_LINE_PATTERN, $line, $m) !== 1) {
            continue;
        }

        // 同上:原文必须已经是全大写,不比对 strtoupper 后的结果。
        if (preg_match(LOCAL_ENV_UPLOAD_KEY_PATTERN, $m[1]) === 1 && strlen($m[1]) <= 255) {
            $disabled[$m[1]] = ['start' => $i, 'end' => $i];
        }
    }

    return [
        'ok' => true,
        'lines' => $lines,
        'active' => $active,
        'disabled' => $disabled,
        'novalue' => array_keys($novalueSeen),
        'invalid' => array_values(array_unique($invalid)),
        'duplicate' => array_values(array_unique($duplicate)),
    ];
}

// ============================================================
// 值渲染(逐字复刻中心的值渲染规则,额外多转义一个 \r,见设计稿 §5.5)
// ============================================================

function local_env_render_value(string $value): string
{
    if ($value !== '' && preg_match('#\A[A-Za-z0-9_.\-/:@+]+\z#', $value) === 1) {
        return $value;
    }

    $escaped = str_replace(
        ['\\', '$', '"', "\n", "\r"],
        ['\\\\', '\\$', '\\"', '\\n', '\\r'],
        $value
    );

    return '"'.$escaped.'"';
}

// ============================================================
// 工作目录(项目目录之外,0700/0600)
// ============================================================

function local_env_home_dir(): string
{
    $home = getenv('HOME');

    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    $info = function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : false;

    return is_array($info) && isset($info['dir']) ? rtrim((string) $info['dir'], '/') : sys_get_temp_dir();
}

function local_env_valid_slug(string $slug): bool
{
    return preg_match(LOCAL_ENV_SLUG_PATTERN, $slug) === 1;
}

function local_env_work_dir(string $slug): string
{
    if (! local_env_valid_slug($slug)) {
        throw new InvalidArgumentException('slug 不合法');
    }

    return local_env_home_dir().'/.dev-admin/env-sync/'.$slug;
}

function local_env_ensure_work_dir(string $slug): string
{
    $dir = local_env_work_dir($slug);

    if (! is_dir($dir)) {
        $previous = umask(0);
        mkdir($dir, 0700, true);
        umask($previous);
    } else {
        @chmod($dir, 0700);
    }

    return $dir;
}

function local_env_write_json(string $path, mixed $data): void
{
    $previous = umask(0077);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    chmod($path, 0600);
    umask($previous);
}

function local_env_read_json(string $path): mixed
{
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("读不到文件:{$path}");
    }

    $data = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("不是合法 JSON:{$path}");
    }

    return $data;
}

// ============================================================
// plan-payload:组装 plan 请求体
// ============================================================

/**
 * 本地活行里「值为空(或纯空白)」的 SSO 三键。
 *
 * .env 常是从 .env.example 复制来的,里面预置了 `SSO_CLIENT_ID=` / `SSO_CLIENT_SECRET=` 这样的**空占位**——
 * 它们对登录没有任何作用,等同没有。所以这三个键(仅这三个,普通键的空值照旧是合法值)的空占位视为「缺失」:
 * 构造 plan 请求时不上报它的指纹,中心于是把它归为 downlink 的 center_only(缺失,点名选 center 即可补入),
 * 而不是算出 sha256('') 落成一条「值不同」的 differ。写盘时仍是**原位替换那一行**(parsed['active'] 里照样有它的区间),
 * 不会追加出第二行同名键。非空的本地值不在此列:照旧「可补不可改」。
 *
 * 判空口径是 phpdotenv 解析后的值 trim 为空:`KEY=`、`KEY=""`、`KEY=''`、`KEY=   `、`KEY="   "` 都算。
 * 放在脚本侧而不是让中心从指纹里猜:指纹是无盐 sha256,中心只认得出恰好空串,认不出纯空白;
 * 脚本手里有明文,判得准,且不引入任何协议变化(老版脚本 / 老版服务端互相照常工作)。
 *
 * @return list<string> 键名(只有键名,不含值)
 */
function local_env_blank_sso_keys(array $parsed): array
{
    $blank = [];

    foreach (LOCAL_ENV_SSO_KEYS as $key) {
        if (isset($parsed['active'][$key]) && trim($parsed['active'][$key]['value']) === '') {
            $blank[] = $key;
        }
    }

    return $blank;
}

/**
 * @return array{keys: array<string, string>, disabled: list<string>}
 */
function local_env_build_plan_request(array $parsed): array
{
    $keys = [];
    $blankSso = array_flip(local_env_blank_sso_keys($parsed));

    foreach ($parsed['active'] as $key => $entry) {
        if (isset($blankSso[$key])) {
            continue; // SSO 空占位按缺失处理,不上报(见 local_env_blank_sso_keys)
        }

        $keys[$key] = hash('sha256', $entry['value']);
    }

    return ['keys' => $keys, 'disabled' => array_values(array_keys($parsed['disabled']))];
}

// ============================================================
// apply-payload:按人的决策组装 apply 请求体
// ============================================================

/**
 * 决策与分类的合法组合(设计稿 §5.2):
 *  differ      ∈ {local, center}
 *  local_only  ∈ {local, disable}
 *  center_only ∈ {center, local}
 *  downlink    ∈ {center, skip}   (2026-10-15 起:SSO 三键,只下行——没有 local,即**永远不会**为它生成 set / delete 上行 op;
 *                                  state=same 的无需决策、不生成 op;skip = 本地不动、中心也不动)
 *
 * @return array{ops: list<array{key: string, op: string, value?: string}>, errors: list<string>}
 */
function local_env_build_apply_ops(array $planResponse, array $decisions, array $parsed): array
{
    $ops = [];
    $errors = [];
    $data = $planResponse['data'] ?? $planResponse;

    $differ = $data['differ'] ?? [];
    $localOnly = array_keys($data['local_only'] ?? []);
    $centerOnly = array_keys($data['center_only'] ?? []);
    $downlink = $data['downlink'] ?? [];

    foreach ($differ as $key) {
        $decision = $decisions[$key] ?? null;

        if (! in_array($decision, ['local', 'center'], true)) {
            $errors[] = "{$key}: differ 类必须选 local 或 center";

            continue;
        }

        if ($decision === 'local') {
            if (! isset($parsed['active'][$key])) {
                $errors[] = "{$key}: 本地找不到这个活键,无法上行";

                continue;
            }
            $ops[] = ['key' => $key, 'op' => 'set', 'value' => $parsed['active'][$key]['value']];
        } else {
            $ops[] = ['key' => $key, 'op' => 'pull'];
        }
    }

    foreach ($localOnly as $key) {
        $decision = $decisions[$key] ?? null;

        if (! in_array($decision, ['local', 'disable'], true)) {
            $errors[] = "{$key}: local_only 类必须选 local(上行)或 disable(本地停用)";

            continue;
        }

        if ($decision === 'local') {
            if (! isset($parsed['active'][$key])) {
                $errors[] = "{$key}: 本地找不到这个活键,无法上行";

                continue;
            }
            $ops[] = ['key' => $key, 'op' => 'set', 'value' => $parsed['active'][$key]['value']];
        }
        // decision === 'disable':不上行,本地写盘时由 write 子命令注释掉该行,这里不生成 op。
    }

    foreach ($centerOnly as $key) {
        $decision = $decisions[$key] ?? null;

        if (! in_array($decision, ['local', 'center'], true)) {
            $errors[] = "{$key}: center_only 类必须选 local(中心删行)或 center(本地写入)";

            continue;
        }

        $ops[] = $decision === 'center'
            ? ['key' => $key, 'op' => 'pull']
            : ['key' => $key, 'op' => 'delete'];
    }

    // downlink(SSO 三键):只下行。center → pull;skip → 不生成 op;其余取值一律报错——
    // 尤其不接受 local / disable:那会是对系统键的上行 set / delete,中心 apply 本就一律 422,脚本这一侧先把门关上。
    // 报错文案区分「没表态」与「表了不合法的态」:前者去问人,后者是 decisions.json 写错了。
    foreach (local_env_downlink_pending($downlink) as $key) {
        $decision = $decisions[$key] ?? null;

        if ($decision === null) {
            $errors[] = "{$key}: downlink 类还没有决策,请向人确认选 center(本地写入)还是 skip(本地不动)";

            continue;
        }

        if (! in_array($decision, ['center', 'skip'], true)) {
            $errors[] = "{$key}: downlink 类只能选 center(本地写入)或 skip(本地不动),不接受 local / disable(SSO 键只下行、没有上行)";

            continue;
        }

        if ($decision === 'center') {
            $ops[] = ['key' => $key, 'op' => 'pull'];
        }
    }

    return ['ops' => $ops, 'errors' => $errors];
}

/**
 * plan 响应里 downlink 分类中**需要人表态**的键名(state 为 center_only / differ;same 一律忽略)。
 *
 * downlink 为空时服务端序列化成 `[]`,非空时是对象——foreach 两种都认。老版服务端没有这个字段时 `?? []` 进来即空。
 *
 * @param  array<string, array{state?: string}>|list<never>  $downlink
 * @return list<string>
 */
function local_env_downlink_pending(array $downlink): array
{
    $pending = [];

    foreach ($downlink as $key => $info) {
        if (in_array($info['state'] ?? null, ['center_only', 'differ'], true)) {
            $pending[] = (string) $key;
        }
    }

    return $pending;
}

// ============================================================
// write:按 apply 响应与决策写盘
// ============================================================

/**
 * skipped 的 reason 有两种:`skipped-interpolated`(本地原值含 `${VAR}` 引用,默认不展平)与
 * `skipped-blank-downlink`(downlink 键的 apply 响应值为空 / 纯空白——纵深防线,绝不拿空值覆盖本地可用的凭据)。
 *
 * @return array{changes: list<array{key: string, action: string}>, skipped: list<array{key: string, reason: string}>, content: string}
 */
function local_env_apply_writes(array $parsed, array $planResponse, array $decisions, array $applyResponse): array
{
    $lines = $parsed['lines'];
    $data = $planResponse['data'] ?? $planResponse;
    $pulled = ($applyResponse['data'] ?? $applyResponse)['pulled'] ?? [];

    $differ = $data['differ'] ?? [];
    $localOnly = array_keys($data['local_only'] ?? []);
    $centerOnly = array_keys($data['center_only'] ?? []);
    $downlinkPending = local_env_downlink_pending($data['downlink'] ?? []);

    $changes = [];
    $skipped = [];

    // 先处理「pull 到值」的键(differ→center、center_only→center、downlink→center),按行号从大到小替换,
    // 避免前面的替换改变了后面区间的行号。downlink(SSO 三键)与其它 center 决策走同一条写盘路径:
    // 活行原位替换(含 interpolated 的跳过规则)、停用行复活、完全没有则追加到 managed 区块。
    $pullTargets = [];
    foreach ([...$differ, ...$centerOnly, ...$downlinkPending] as $key) {
        if (($decisions[$key] ?? null) === 'center' && array_key_exists($key, $pulled)) {
            $pullTargets[] = $key;
        }
    }

    $replacements = []; // [[start, end, newLines], ...],稍后按 start 降序统一应用
    $appendLines = [];  // 本地完全没有的键,追加到 managed 区块

    foreach ($pullTargets as $key) {
        $value = (string) $pulled[$key];

        // 纵深:downlink 键(SSO 三键)中心此刻拿不出值(校验到执行之间接入被解除之类)时,服务端会直接拒绝;
        // 万一响应里仍带着空 / 纯空白的值,绝不能把本地可用的凭据覆盖成空占位——跳过并告警(只写键名,不写值)。
        if (in_array($key, $downlinkPending, true) && trim($value) === '') {
            $skipped[] = ['key' => $key, 'reason' => 'skipped-blank-downlink'];

            continue;
        }

        if (isset($parsed['active'][$key])) {
            $entry = $parsed['active'][$key];

            if ($entry['interpolated']) {
                $skipped[] = ['key' => $key, 'reason' => 'skipped-interpolated'];

                continue;
            }

            $replacements[] = [$entry['start'], $entry['end'], [$key.'='.local_env_render_value($value)]];
            $changes[] = ['key' => $key, 'action' => 'updated'];

            continue;
        }

        if (isset($parsed['disabled'][$key])) {
            $entry = $parsed['disabled'][$key];
            $rawLine = $lines[$entry['start']];

            if (str_contains($rawLine, '${')) {
                $skipped[] = ['key' => $key, 'reason' => 'skipped-interpolated'];

                continue;
            }

            $replacements[] = [$entry['start'], $entry['end'], [$key.'='.local_env_render_value($value)]];
            $changes[] = ['key' => $key, 'action' => 'revived'];

            continue;
        }

        $appendLines[] = $key.'='.local_env_render_value($value);
        $changes[] = ['key' => $key, 'action' => 'appended'];
    }

    // 「disable」决策(只出现在 local_only):注释掉当前活行的整个区间
    foreach ($localOnly as $key) {
        if (($decisions[$key] ?? null) !== 'disable') {
            continue;
        }

        if (! isset($parsed['active'][$key])) {
            continue; // 没有活行可注释,no-op
        }

        $entry = $parsed['active'][$key];
        $commented = array_map(
            fn (string $line): string => str_starts_with(ltrim($line), '#') ? $line : '# '.$line,
            array_slice($lines, $entry['start'], $entry['end'] - $entry['start'] + 1)
        );
        $replacements[] = [$entry['start'], $entry['end'], $commented];
        $changes[] = ['key' => $key, 'action' => 'disabled'];
    }

    // 按 start 降序应用替换,不影响尚未处理的更早区间的行号
    usort($replacements, fn (array $a, array $b): int => $b[0] <=> $a[0]);

    foreach ($replacements as [$start, $end, $newLines]) {
        array_splice($lines, $start, $end - $start + 1, $newLines);
    }

    if ($appendLines !== []) {
        if ($lines !== [] && trim(end($lines)) !== '') {
            $lines[] = '';
        }
        $lines[] = '# ── devtools managed ──';
        array_push($lines, ...$appendLines);
    }

    // $lines 来自 preg_split(),原文件若以换行收尾,分割结果末尾天然带一个空字符串元素——
    // implode 就已经精确复刻原文件的收尾换行,不必再手动补一个 "\n"(补了在「未追加新内容」的
    // 多数情况下会变成双换行,写一次文件末尾就多一行空行)。只有「确实追加了内容」时才需要显式收尾。
    $content = implode("\n", $lines);
    if ($content !== '' && ! str_ends_with($content, "\n")) {
        $content .= "\n";
    }

    return [
        'changes' => $changes,
        'skipped' => $skipped,
        'content' => $content,
    ];
}

// ============================================================
// 写盘:备份 + 原子替换 + 权限位保持 + mtime/size 校验
// ============================================================

function local_env_backup(string $envPath, string $slug): string
{
    if (! local_env_valid_slug($slug)) {
        throw new InvalidArgumentException('slug 不合法');
    }

    $dir = local_env_home_dir().'/.dev-admin/env-backups/'.$slug;

    if (! is_dir($dir)) {
        $previous = umask(0);
        mkdir($dir, 0700, true);
        umask($previous);
    }

    $backupPath = $dir.'/.env.'.date('Ymd-His');
    copy($envPath, $backupPath);
    chmod($backupPath, 0600);

    $backups = glob($dir.'/.env.*') ?: [];
    sort($backups);
    while (count($backups) > 5) {
        @unlink(array_shift($backups));
    }

    return $backupPath;
}

/**
 * 并行终端保护:文件当前的 mtime/size 是否与「读取那一刻」记录的状态不一致。
 *
 * 单独抽出来是为了可测——本机常开并行终端,写盘前这道校验能拦住「另一个终端也在改同一份 .env」
 * 的场景,拆成纯函数才测得到「状态不一致就该拒绝」这件事,不必真的在两次系统调用之间制造竞态。
 *
 * @param  array<string, mixed>|false  $originalStat  stat() 在读取那一刻的返回值
 */
function local_env_file_changed_since(string $path, array|false $originalStat): bool
{
    $currentStat = stat($path);

    if ($currentStat === false || $originalStat === false) {
        return true;
    }

    return $currentStat['mtime'] !== $originalStat['mtime'] || $currentStat['size'] !== $originalStat['size'];
}

function local_env_write_env_file(string $envPath, string $newContent): void
{
    $mode = fileperms($envPath) & 0777;
    $tmpPath = $envPath.'.tmp-'.bin2hex(random_bytes(4));

    file_put_contents($tmpPath, $newContent);
    chmod($tmpPath, $mode);
    rename($tmpPath, $envPath);
}

// ============================================================
// CLI 子命令
// ============================================================

function local_env_fail(string $message): int
{
    fwrite(STDERR, $message."\n");

    return 1;
}

function local_env_cmd_plan_payload(array $args): int
{
    if (count($args) < 2) {
        return local_env_fail('用法:plan-payload <slug> <.env路径>');
    }

    [$slug, $envPath] = $args;

    if (! is_file($envPath)) {
        return local_env_fail("找不到文件:{$envPath}");
    }

    $content = file_get_contents($envPath);

    if ($content === false) {
        return local_env_fail("读不到文件:{$envPath}");
    }

    $parsed = local_env_parse($content);

    if (! $parsed['ok']) {
        $line = $parsed['error']['line'] ?? null;
        $where = $line !== null ? "第 {$line} 行" : '';

        return local_env_fail("解析失败:{$where} {$parsed['error']['category']}。请人工修好 .env 再同步。");
    }

    $dir = local_env_ensure_work_dir($slug);
    $request = local_env_build_plan_request($parsed);
    local_env_write_json($dir.'/plan-request.json', $request);

    echo '请求体已写入:'.$dir."/plan-request.json\n";
    echo '活键 '.count($parsed['active']).' 个,停用行 '.count($parsed['disabled'])." 个\n";

    if ($parsed['invalid'] !== []) {
        echo 'invalid(键名非全大写或超长,已跳过):'.implode(' ', $parsed['invalid'])."\n";
    }
    if ($parsed['duplicate'] !== []) {
        echo 'duplicate(同键多活行,已取最后一条):'.implode(' ', $parsed['duplicate'])."\n";
    }
    if ($parsed['novalue'] !== []) {
        echo 'novalue(无等号裸键,已跳过):'.implode(' ', $parsed['novalue'])."\n";
    }

    $blankSso = local_env_blank_sso_keys($parsed);
    if ($blankSso !== []) {
        echo 'blank-sso(SSO 键本地值为空,按缺失处理、未上报,补入时原位替换该行):'.implode(' ', $blankSso)."\n";
    }

    $interpolated = [];
    foreach ($parsed['active'] as $key => $entry) {
        if ($entry['interpolated']) {
            $interpolated[] = $key;
        }
    }
    if ($interpolated !== []) {
        echo 'interpolated(值含 ${...} 引用,回写会展平,默认不写盘):'.implode(' ', $interpolated)."\n";
    }

    return 0;
}

function local_env_cmd_apply_payload(array $args): int
{
    if (count($args) < 3) {
        return local_env_fail('用法:apply-payload <slug> <planResponse路径> <decisions路径>');
    }

    [$slug, $planResponsePath, $decisionsPath] = $args;

    $envPath = getcwd().'/.env';

    if (! is_file($envPath)) {
        return local_env_fail('当前目录找不到 .env,请确认 cwd 是项目根');
    }

    try {
        $planResponse = local_env_read_json($planResponsePath);
        $decisions = local_env_read_json($decisionsPath);
    } catch (RuntimeException $e) {
        return local_env_fail($e->getMessage());
    }

    $content = file_get_contents($envPath);
    $parsed = local_env_parse((string) $content);

    if (! $parsed['ok']) {
        return local_env_fail('解析失败,先跑 plan-payload 排查');
    }

    $built = local_env_build_apply_ops($planResponse, $decisions, $parsed);

    if ($built['errors'] !== []) {
        echo "决策不完整或不合法,以下键未生成 op:\n";
        foreach ($built['errors'] as $error) {
            echo "  {$error}\n";
        }

        return 1;
    }

    $dir = local_env_ensure_work_dir($slug);
    local_env_write_json($dir.'/apply-request.json', ['ops' => $built['ops']]);

    echo '请求体已写入:'.$dir."/apply-request.json\n";

    $byOp = ['set' => [], 'pull' => [], 'delete' => []];
    foreach ($built['ops'] as $op) {
        $byOp[$op['op']][] = $op['key'];
    }

    foreach (['set', 'pull', 'delete'] as $opType) {
        if ($byOp[$opType] !== []) {
            echo "{$opType}: ".implode(' ', $byOp[$opType])."\n";
        }
    }

    if ($byOp['delete'] !== []) {
        echo "以上 delete 键会删掉中心『本地』环境对应的行,请先确认无误再继续 curl。\n";
    }

    return 0;
}

function local_env_cmd_write(array $args): int
{
    $dryRun = in_array('--dry-run', $args, true);
    $args = array_values(array_filter($args, fn (string $a): bool => $a !== '--dry-run'));

    if (count($args) < 4) {
        return local_env_fail('用法:write <slug> <planResponse路径> <decisions路径> <applyResponse路径> [--dry-run]');
    }

    [$slug, $planResponsePath, $decisionsPath, $applyResponsePath] = $args;

    $envPath = getcwd().'/.env';

    if (! is_file($envPath)) {
        return local_env_fail('当前目录找不到 .env,请确认 cwd 是项目根');
    }

    try {
        $planResponse = local_env_read_json($planResponsePath);
        $decisions = local_env_read_json($decisionsPath);
        $applyResponse = local_env_read_json($applyResponsePath);
    } catch (RuntimeException $e) {
        return local_env_fail($e->getMessage());
    }

    $beforeContent = file_get_contents($envPath);
    $beforeStat = stat($envPath);
    $parsed = local_env_parse((string) $beforeContent);

    if (! $parsed['ok']) {
        return local_env_fail('解析失败,先跑 plan-payload 排查');
    }

    $result = local_env_apply_writes($parsed, $planResponse, $decisions, $applyResponse);

    foreach ($result['changes'] as $change) {
        echo "{$change['key']}: {$change['action']}\n";
    }
    foreach ($result['skipped'] as $skip) {
        $why = $skip['reason'] === 'skipped-blank-downlink'
            ? '未写盘,中心此刻没有给出这个键的值,本地原值保持不动,请回中心核对 SSO 接入后重新同步'
            : '未写盘,原值 ${...} 引用会被展平,需人工处理';
        echo "{$skip['key']}: {$skip['reason']}({$why})\n";
    }

    if ($result['changes'] === []) {
        echo "无变更,文件不动。\n";

        return 0;
    }

    if ($dryRun) {
        echo "--dry-run:以上是将要发生的变更,未实际写盘。\n";

        return 0;
    }

    // 写盘前后比对 mtime + size(并行终端保护):写之前的状态与刚读取时不一致就中止。
    if (local_env_file_changed_since($envPath, $beforeStat)) {
        return local_env_fail('.env 在读取之后被其它进程改过,为安全起见中止写盘,请重新跑一遍流程。');
    }

    $backupPath = local_env_backup($envPath, $slug);
    local_env_write_env_file($envPath, $result['content']);

    echo "已备份到:{$backupPath}\n";
    echo "已写盘。\n";

    if (is_file(getcwd().'/bootstrap/cache/config.php')) {
        echo "检测到 bootstrap/cache/config.php 存在,建议跑一次 php artisan config:clear。\n";
    }

    return 0;
}

function local_env_cmd_cleanup(array $args): int
{
    if (count($args) < 1) {
        return local_env_fail('用法:cleanup <slug>');
    }

    $dir = local_env_work_dir($args[0]);

    if (is_dir($dir)) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    echo "工作目录已清空:{$dir}\n";

    return 0;
}

function local_env_main(array $argv): int
{
    $command = $argv[1] ?? null;
    $args = array_slice($argv, 2);

    // 四个子命令的第一个参数都是 slug;在这里先拒掉不合法的,给人一句能看懂的话,
    // 而不是落进下面 catch-all 的「内部错误」(work_dir / backup 里那两道 throw 只是纵深防线)。
    if (in_array($command, ['plan-payload', 'apply-payload', 'write', 'cleanup'], true)
        && isset($args[0]) && ! local_env_valid_slug($args[0])) {
        return local_env_fail('slug 不合法:只能含字母、数字、. _ -,须以字母或数字开头,长度 1~100(照提示词里「项目:」后面的抄)。');
    }

    try {
        return match ($command) {
            'plan-payload' => local_env_cmd_plan_payload($args),
            'apply-payload' => local_env_cmd_apply_payload($args),
            'write' => local_env_cmd_write($args),
            'cleanup' => local_env_cmd_cleanup($args),
            default => local_env_fail('用法:local-env.php <plan-payload|apply-payload|write|cleanup> ...'),
        };
    } catch (Throwable) {
        // 铁律第一条:任何未预期的异常也不许把消息打出去(可能带着值)。
        return local_env_fail('脚本执行失败(内部错误),未写入任何文件。');
    }
}

// —— 引导:只在真的作为 CLI 脚本被调用时才拉起项目的 autoload —— //
// 必须放在全部 const/function 声明之后:PHP 按顺序执行顶层语句,提前调用 local_env_main()
// 会撞上还没执行到的 const 声明。require() 一个测试文件时 $argv[0] 是测试运行器自己的路径,
// 不等于 __FILE__,这段整体跳过,只留下面的函数/常量定义可供直接调用。
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $autoload = getcwd().'/vendor/autoload.php';

    if (! is_file($autoload)) {
        fwrite(STDERR, '找不到 vendor/autoload.php。请确认当前目录('.getcwd().")是被同步项目的根目录,且已 composer install。\n");

        exit(2);
    }

    require $autoload;

    exit(local_env_main($argv));
}
