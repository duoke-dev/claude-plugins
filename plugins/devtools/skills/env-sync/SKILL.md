---
name: env-sync
description: 用中心台账补齐本项目的本地 .env,并产出「本地有、中心无」的键名清单拿去登记;若提示词里的环境是「本地」(local),改走双向对账的镜像模式。当用户提到"同步 .env、补 .env、env 同步、对一下环境变量、中心台账、拉取环境变量、我这 .env 缺什么、新项目初始化 env"或粘来一段含 envp_ 拉取票的提示词时使用。
---

# 本地 .env ↔ 中心台账同步

把本项目的 `.env` 与中心台账对一遍。**这份 SKILL 前半篇讲的是生产 / 测试环境**：缺的补上、已有的一律不动、本地独有的产出清单交给人去登记。**「本地」(local) 环境是完全相反的另一套口径——双向对账、值双向可写**,见文末「[本地环境:镜像模式](#本地环境镜像模式)」一节。

**先看提示词里「环境:」后面写的是什么,再决定走哪一套流程**——写的是 `local` 就跳到文末那节,本篇前面所有规矩(只 add 不 update、票打 `GET .../pull`)对它都不适用;写的是 `production` / `staging` / `testing` 才走下面这套。

**中心是台账，不是配置源(生产 / 测试环境)。** 下行只做「只 add 不 update、14 个系统键永不写」的补齐（SSO 三键是可补不可改、要点名的例外，见下文「SSO 三键」一节）；上行只做「产出键名清单」。双向都不改对方已有的正确值。

> **先说结论**：新项目第一次跑，可写入键数**通常是 0**。中心那边还没登记任何变量时，拉回来的全是系统自产键，而它们几乎都在「永不写本地」名单里（唯一的例外是项目已接入统一登录时的 SSO 三键，见下文「SSO 三键」一节，默认也不写、要用户点名）。第一次同步的价值 100% 在上行——把「本地有、中心无」的键名清单列给用户。**不要为了显得有用而去写不该写的键。**

## 三条不许破的规矩(生产 / 测试环境)

「本地」环境反转为镜像,这三条对它均**不适用**,见文末一节。

1. **只 add，不 update，永不 delete。** 本地已存在的键——**包括被 `#` 注释掉的**——一律不动，只在报告里列差异。中心台账的数据质量低于本地 `.env`（普遍存在「登记了 key 但没有值」的行，也躺着早已废弃的死键），不能让质量差的一侧去改质量好的一侧。
2. **14 个系统键永不写本地**（见下）。硬名单，不接受「强制写入」，用户明确要求也不写——要改这些键请他去中心后台改。（SSO 三键不在这份名单里，但有自己的规矩，见「SSO 三键」一节。）
3. **值不进你的上下文。** 拉取直接 `-o` 落临时文件；你只对**键名**做集合运算；写盘用 `jq` 从临时文件取值直接追加。报告里「值不同」只报「不同」，**不报两边的值、不报值的片段、不报指纹**。

## 需要的东西

用户会从中心「环境」详情页点「同步本地 .env」，复制一段提示词粘给你，里面含**中心地址**与一枚 `envp_` 开头的**一次性拉取票**（默认 30 分钟有效，用一次就作废）。

没有票就停下，让用户去中心后台点那个按钮拿。**不要**改用 `DEVTOOLS_TOKEN` 去打 `/api/v1/environment/variables` 代替——那条链路会连 `DEVTOOLS_TOKEN` 自己一起回显，和本任务的口径不同。

先确认 cwd 是项目根（有 `.env` 和 `artisan`）：

```bash
test -f .env && test -f artisan || { echo "不在项目根目录"; exit 1; }
```

## 第 1 步：拉取（值直接落文件，不进 stdout；票只经文件，不进 argv）

```bash
umask 077
H=$(mktemp) && TMP=$(mktemp) && trap 'rm -f "$H" "$TMP"' EXIT
printf '%s\n' 'Authorization: Bearer <票>' > "$H"     # printf 是内建，不产生进程
curl -sS -w '%{http_code}' -o "$TMP" -H @"$H" \
  "$API_BASE/api/v1/environment/pull"
```

用户粘来的提示词里已经是这个形态，照着跑即可。**票不能出现在任何外部命令的参数里**——不要写成
`curl -H "Authorization: Bearer …"`，也不要 `export TICKET=…`：开发机往往多人共用，`ps` 能看见别人进程的完整参数，
票在 curl 存活期间就是明文。写进 0600 的临时头文件、`-H @文件` 读、用完 `trap` 删，是唯一允许的传法。

**不要** `echo "$TMP"` 的内容，**不要** `cat`，**不要**把它塞进变量再打印。

响应码的处理：

| 码 | 含义 | 怎么办 |
| --- | --- | --- |
| 200 | 成功。票**已作废** | 继续 |
| 404 | 票无效 / 过期 / 已用过 / 已作废 / 环境已停用 | 停下，让用户回后台重新点一次按钮拿新票 |
| 429 | 触发限流 | 等一分钟再试，**别循环重试** |
| 其它 | 中心异常 | 停下报告状态码，票**没有**被消耗，可以重试 |

拉取成功后票就没了。**中途失败要重来的话必须让用户重新签票**，不要拿旧票反复打。

## 第 2 步：只对键名做集合运算

```bash
# 中心侧的键名
jq -r '.data | keys[]' "$TMP" | sort > /tmp/center.keys

# 本地活键(排除注释行与空行,取 = 左边)
grep -E '^[A-Za-z_][A-Za-z0-9_]*=' .env | cut -d= -f1 | sort -u > /tmp/local.keys

# 本地被注释掉的键(停用行)
grep -E '^[[:space:]]*#[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=' .env \
  | sed -E 's/^[[:space:]]*#[[:space:]]*//' | cut -d= -f1 | sort -u > /tmp/local.disabled

# SSO 三键里「活行但值为空」的空占位(.env 常是从 .env.example 复制来的,里面预置了 SSO_CLIENT_ID= 这样的空行)按「缺失」处理:
# 以每个键最后一条活行为准(dotenv 语义);grep -q 只看退出码、不打印任何行内容,值不过你的眼
: > /tmp/blank.sso
for K in SSO_BASE_URL SSO_CLIENT_ID SSO_CLIENT_SECRET; do
  if grep -E "^${K}=" .env | tail -n 1 \
    | grep -qE "^[A-Z_]+=[[:space:]]*(\"[[:space:]]*\"|'[[:space:]]*')?[[:space:]]*(#.*)?\$"; then
    echo "$K" >> /tmp/blank.sso
  fi
done
sort -u /tmp/blank.sso -o /tmp/blank.sso
# 空占位不算本地已有:从本地活键里摘掉,后面的分类就把它们当「本地缺」
comm -23 /tmp/local.keys /tmp/blank.sso > /tmp/local.keys.eff && mv /tmp/local.keys.eff /tmp/local.keys
```

`/tmp/blank.sso` 里的键只在 SSO 三键范围内成立（`KEY=`、`KEY=""`、`KEY=''`、`KEY="  "`、`KEY= # 注释` 都算空占位）；**普通键的空值照旧是合法值，不在此列**。

**键名合法性**：中心的 JSON 路径刻意不过滤脏 key，所以这一道得你自己补。不匹配 `^[A-Za-z_][A-Za-z0-9_]*$` 的键**整条丢弃并单列告警**——含换行的脏 key 一旦裸拼进 `.env`，会在文件里凭空多出一行独立的密钥行。

**同 key 多行**：`.env` 里同一个 key 出现多次时，dotenv 的真实语义是**最后一条活行生效**。同时把「同 key 多活行」单列 `duplicate` 警告（用 `#本地/#线上` 注释块切库的文件里这本就是隐患）。

## 第 3 步：分类与报告（默认 dry-run）

先按下表把「中心有、本地没有」的键分类，再出报告。**这一步不写任何文件。**

### 14 个系统键：永不写

```
APP_ENV APP_KEY APP_URL
DEVTOOLS_SCHEDULE_ENABLED DEVTOOLS_API_BASE DEVTOOLS_SCHEDULE_SECRET DEVTOOLS_TOKEN
DEVTOOLS_FLARE_KEY DEVTOOLS_FLARE_ERRORS DEVTOOLS_FLARE_TRACE DEVTOOLS_FLARE_TRACE_SAMPLE_RATE
DEVTOOLS_FLARE_LOG DEVTOOLS_FLARE_LOG_LEVEL
COMPOSER_AUTH
```

它们是中心系统自产的，值对开发机要么无效、要么有害：`APP_KEY` 换掉会让本机已加密数据与全部会话一次作废；`DEVTOOLS_SCHEDULE_SECRET` / `DEVTOOLS_FLARE_KEY` 写进本地等于把生产密钥搬到开发机上；`COMPOSER_AUTH` 等凭据类变量写进本地会扩大泄露面。报告里列为 `skipped(system)`。

这份清单与后台弹窗提示词里内联的那份同源（提示词里的清单由中心实时生成）。两边对不上时**取并集**：两份清单里任一份有的键都不写，并在报告里提一句「skill 的系统键清单过期了」。只收紧、不放宽——哪一份漏了某个键，都不能成为把它写进本地的理由。**SSO 三键同样适用**：只有两份清单都不含它们时才按下一节处理，任一份把它们列为永不写，就一个都不写。

（`DEVTOOLS_TOKEN` 本来就不在响应里——票不是令牌。）

### SSO 三键：可补不可改（本地走中心登录要用）

```
SSO_BASE_URL SSO_CLIENT_ID SSO_CLIENT_SECRET
```

这三个键是项目级的统一登录凭据，**与各部署环境同一份**；本地要登录管理后台只剩中心登录一条路，没有它们就登不进，所以不在「永不写」名单里。规矩比普通键更紧：

- **只在中心确有值时才会出现在拉取结果里**（项目没接入统一登录 / 没托管明文 / 绑定悬垂时，对应的键直接缺席）。缺席的键，本地即便有也**一律不动**，也**不要**把它们放进「本地有、中心没有」的登记清单——它们是系统键，中心表单会拒绝登记。
- **可补不可改**：本地**缺**哪个才可能补，本地已有的（含被 `#` 注释掉的）一律不动、不复活、不覆盖——值不同也只报「不同」，不报值。
- **本地值为空的空占位视为「缺失」**（仅这三个键）：`.env.example` 复制出来的 `SSO_CLIENT_ID=` / `SSO_CLIENT_SECRET=` 这类**活行但值为空（或纯空白）**的行，上面第 2 步已把它们摘出本地活键、记在 `/tmp/blank.sso`，按「本地缺」对待，同样要点名才补；补的方式是**替换那一行的值**，**绝不在文件里追加出第二行同名键**（第 4 步有专门一节）。本地值**非空**的仍然「可补不可改」，一个字不动。
- **默认不写，同 C 类**：报告里列 `add?(sso)`，需用户点名才写，且**不能**被「全部 add」这类批量话顺带写入；点名时提醒他三个键要配套（缺 `SSO_CLIENT_SECRET` 登不进），以及本地的回调地址 `<本地 APP_URL>/sso/callback` 要已在中心登记，否则登录会被拒。
- **拉取结果里只有 1~2 个 SSO 键时**（典型：中心没有托管明文 → 缺 `SSO_CLIENT_SECRET`；绑定悬垂 → 缺 `SSO_CLIENT_ID`）：**别拿残缺的一部分去补**——补进去本地也登不进（三个键缺一不可）。先提醒用户去中心管理端把该项目的 SSO 接入补齐（项目详情页的「SSO 登录设置」；缺 secret 是缺托管明文，补法是「重置 secret」，超管专属，**旧 secret 立即失效**，要他自己评估时机），补齐后重新签票再同步。用户明知如此仍点名，就只补他点名的，并再提醒一次登不进。
- **值全程不过你的眼，对 `SSO_CLIENT_SECRET` 尤其如此**：只做键名集合运算、写盘用 `jq` 从临时文件取值；不要为了「确认写对了」去 `grep` / `cat` / `diff` `.env` 里的 SSO 行（上面判空占位的 `grep -q` 只看退出码、不打印行，是唯一允许的碰法），报告与日志里只出现键名。**任何输出里都不许出现 SSO 值**：包括 `git diff`、对备份文件的 `diff` 这类「帮你看一眼变更」的命令。

### 其余键的判据

| 类 | 判据 | 怎么处理 |
| --- | --- | --- |
| **A 环境无关共享** | 换台开发机也不变（如 `APP_NAME`、第三方 API 的公共地址、功能开关） | 列为 `add` 候选 |
| **B 本机专属** | **换一台开发机，这值要不要变？→ 要变**（如 `DB_HOST`、本机路径、本机端口） | 中心通常留空，天然缺席；出现了也别写，列 `add?` |
| **C 环境相关凭据** | 生产 / 测试各有真值，本地另一套或压根不需要（如生产数据库口令、支付密钥） | 列 `add?`，**默认不写**，需用户逐键点名 |

拿不准 A 还是 C 时按 **C** 处理（默认不写）。

### 报告格式

```
本地 .env 同步报告(dry-run)  项目 xxx / 环境 production

中心有、本地没有:
  add   (A 类,建议补)   APP_NAME  FEATURE_X_ENABLED
  add?  (C 类,需点名)   PAYMENT_SECRET  MAIL_PASSWORD
  add?(sso)(需点名,三键配套;本地空占位按缺失、原位替换)  SSO_BASE_URL  SSO_CLIENT_ID  SSO_CLIENT_SECRET
  skipped(system)       APP_ENV APP_KEY APP_URL DEVTOOLS_API_BASE ...

两边都有但值不同:  DB_DATABASE  MAIL_FROM_ADDRESS
  (本地值保留不动;如需以中心为准请自行手改)

本地有、中心没有(拿去中心登记):  47 个
  DB_HOST DB_PORT DB_USERNAME REDIS_HOST ... (完整清单)

本地已停用(# 注释掉,不复活):  DEVTOOLS_FLARE_KEY
告警:  中心返回 1 个非法键名,已丢弃

写盘前需要你确认。要写哪些?(默认只写 add 那组)
```

**「本地有、中心没有」这份清单是本次同步的主要产出。** 提醒用户：拿它去中心后台的「环境变量Key」登记；B 类（本机专属）登记时**值留空**并在备注里加 `[本机专属]` 前缀；上面那 14 个系统键与 SSO 三键都不该登记，登记前先剔掉。中心表单只拦它自己管辖的那几个系统键，其余靠你先剔。

## 第 4 步：写盘（必须用户确认过）

### 先备份，且备份不落项目目录

```bash
SLUG=$(basename "$PWD")
BACKUP_DIR="$HOME/.dev-admin/env-backups/$SLUG"
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
cp -p .env "$BACKUP_DIR/.env.$(date +%Y%m%d-%H%M%S)"
# 只留最近 5 份
ls -1t "$BACKUP_DIR" | tail -n +6 | while read -r f; do rm -f "$BACKUP_DIR/$f"; done
```

**备份绝不能留在项目目录里**——`.env.bak-*` 被 git 带上远端是真出过的事故。

### 原子写 + 保持原权限位

```bash
MODE=$(stat -f '%Lp' .env)          # macOS;Linux 用 stat -c '%a'
NEW=$(mktemp "$(dirname .env)/.env.tmp.XXXXXX")
cp .env "$NEW"

# 追加区块;值用 jq 从临时文件直接取,不经你的眼
{
  echo ""
  echo "# ── devtools managed ($(date +%Y-%m-%d)) ──"
  for K in $KEYS_TO_ADD; do
    V=$(jq -r --arg k "$K" '.data[$k]' "$TMP")
    printf '%s=%s\n' "$K" "$V"
  done
} >> "$NEW"

chmod "$MODE" "$NEW"
mv "$NEW" .env
```

`chmod` **必须还原原来的权限位，不要一律 0600**：本机 `.env` 多是 `0644`，php-fpm 未必与登录用户同 uid，降权会打断站点。

### 空占位的 SSO 键：原位替换，不追加

点了名要补、且在 `/tmp/blank.sso` 里的 SSO 键（本地已有一行空占位）**不要**走上面的「追加区块」——那会让文件里同时有空占位与新行两行同名键。改为在 `$NEW`（`.env` 的副本）里把那一行**原位替换**；其余点名要补的键（本地完全没有的）才追加。值全程只经 `jq` → 0600 临时文件 → `awk` 读文件，不进命令行参数：

```bash
ONE=$(mktemp)                                   # 0600(umask 077),用完即删
for K in $BLANK_SSO_TO_FILL; do                 # = 点名要补的键 ∩ /tmp/blank.sso
  N=$(grep -nE "^${K}=" .env | tail -n 1 | cut -d: -f1)    # 最后一条活行的行号(只有数字)
  V=$(jq -r --arg k "$K" '.data[$k]' "$TMP")
  # 渲染同下一节「值的渲染」;结果只写进 $ONE
  if printf '%s' "$V" | grep -qE '^[A-Za-z0-9_./:@+-]+$'; then
    printf '%s=%s\n' "$K" "$V" > "$ONE"
  else
    printf '%s=%s\n' "$K" "$(printf '%s' "$V" | jq -Rs '.' | sed 's/\$/\\$/g')" > "$ONE"
  fi
  # 第 N 行换成 $ONE 的内容(awk 按文件原样读,不解释任何转义)
  awk -v n="$N" -v f="$ONE" 'NR==n { while ((getline l < f) > 0) print l; close(f); next } { print }' "$NEW" > "$NEW.x" && mv "$NEW.x" "$NEW"
done
rm -f "$ONE"
```

替换后文件里每个 SSO 键应**恰好一行**，行的位置不变。报告里这类键写 `replaced(sso)`，只报键名。

### 值的渲染

中心返回的是原始值。裸写只在值仅含 `[A-Za-z0-9_./:@+-]` 且非空时才安全；否则用双引号包裹并按序转义 `\` `$` `"`，换行转 `\n`。**判断用 shell/jq 做，别把值读出来自己看**：

```bash
V=$(jq -r --arg k "$K" '.data[$k]' "$TMP")
# 字符类里的 - 必须放最后:macOS 的 grep 对 `\-` 报「invalid character range」
if printf '%s' "$V" | grep -qE '^[A-Za-z0-9_./:@+-]+$'; then
  printf '%s=%s\n' "$K" "$V"
else
  # jq 负责 \ " 与换行;sed 再补转义 $(双引号里 ${...} 会被 dotenv 当引用展开)
  printf '%s=%s\n' "$K" "$(printf '%s' "$V" | jq -Rs '.' | sed 's/\$/\\$/g')"
fi
```

### 并行终端保护

写盘前后比对 `.env` 的 mtime + size，不一致立即中止并恢复备份——用户常开并行终端，这一步能救命。

```bash
BEFORE=$(stat -f '%m %z' .env)
# ... 写盘 ...
```

### 收尾

- 存在 `bootstrap/cache/config.php` 时提示跑 `php artisan config:clear`。
- 报告实际写入了哪些键（**只报键名**）。
- 变更核对让用户自己在终端做（`.env` 通常不在版本控制里，他可以自己 `diff` 备份）；**你不要替他跑 `git diff` / `diff`**——输出里会带出 SSO 三键的值（含 `SSO_CLIENT_SECRET`），而 SSO 值不许出现在你看得到的任何输出里。

## 幂等

同一状态下跑第二次，报告应显示「无变更」，且 `.env` 字节完全不变。做不到就是哪里错了。

## 不要做的事(生产 / 测试环境)

- 不要 `cat .env`、不要把 `.env` 或拉取结果的内容打印出来。
- 不要 `source .env`（会执行里面的内容）。
- 不要为了「补全」去修改已有的键，哪怕中心的值看起来更对——报告给人，让人自己改。**本地环境镜像模式下由 `write` 子命令按人的逐键决策更新 / 复活，见镜像章节，那里这条规矩反过来。**
- 不要复活被 `#` 注释掉的键：那些注释往往是刻意关掉的（本地不向自己上报错误、不接中心定时触发），复活等于把它们静默打开。**本地环境镜像模式下,人明确选「用中心」时脚本会复活，同样见镜像章节。**
- 票只允许以 0600 临时头文件的形态短暂落地（第 1 步那个 `$H`，用完即删）；不要写进 `.env`、任何 git 提交、任何日志，也不要放进任何外部命令的参数或环境变量。**镜像模式同样如此**——那一节的 `$H` 与这里是同一条纪律，没有例外。
- 不要循环重试 429。
- 不要回显 SSO 三键里任何一个的值，对 `SSO_CLIENT_SECRET` 尤其如此（与 `COMPOSER_AUTH` 等凭据同一条纪律）：不 `cat` / `grep` / `diff` `.env` 里的 SSO 行，不把值写进报告、提交、日志或下一条命令的参数。

## 本地要接入错误上报 / 定时任务的话

这 5 个键全在硬名单里，中心不下发，需要用户手工写进 `.env`（你可以把这段贴给他，但**不要替他填令牌**）：

```dotenv
DEVTOOLS_API_BASE=<中心地址>
DEVTOOLS_TOKEN=<生产 / 测试环境「令牌」区签发的人工令牌,勾 read + manage>
DEVTOOLS_SCHEDULE_ENABLED=false     # 本地不接中心触发
# DEVTOOLS_SCHEDULE_SECRET          # 本地刻意不配
# DEVTOOLS_FLARE_KEY                # 本地刻意不配(本地错误不进生产台账、不吃配额)
```

令牌要到**对应的生产 / 测试环境**详情页的「令牌」区签，不要在「本地」环境上签：本地环境不绑 Flare，拿它查错误列表恒返回空，看上去像线上没有错误。令牌属于哪个环境，就只能查、只能处置哪个环境的错误。

## 更详细的口径(生产 / 测试环境)

中心的 API 文档站（后台面 `/docs/local-env-sync`，免登录）有完整版（四分类判据、行为口径速查、排障表）。

## 本地环境:镜像模式

提示词里「环境:local」时走这一节，与上面的一切规矩相反：**本地一旦跑一次同步，中心「本地」环境的手工变量集合就会与本地 `.env` 的活键集合一致**（系统键两边都不碰；SSO 三键是唯一的例外，只会单向下行补进本地、绝不上行，见下文 `downlink`），双向对账、值双向可写。票打的是 `POST .../sync/plan` 与 `POST .../sync/apply`，不是上面的 `GET .../pull`。

**这一节要求装了本插件**——脚本在本 SKILL.md 同目录下的 `scripts/local-env.php`（经 `php artisan devtools:install` 装的就是项目里的 `.claude/skills/env-sync/scripts/local-env.php`；经插件市场装的在插件目录里，下面用 `$SKILL_DIR` 代指本 SKILL.md 所在目录），运行时依赖被同步项目自己 `vendor/` 里的 `vlucas/phpdotenv`（版本跟着项目走，不随插件带一份副本）。命令一律在被同步项目的根目录下执行。没装插件、只靠提示词内联口径是做不了这一节的——脚本要处理多行值、`${VAR}` 引用、`\r` 转义这类 bash 处理不了的细节，让用户先按提示词里的指引装插件。

值全程不过你的眼这条规矩**不变**：全部值都由脚本从临时文件直接读写，你只看键名、分类与人的决策。

### 流程

```bash
SKILL_DIR=<本 SKILL.md 所在目录>
API_BASE=<提示词里「中心:」后面的地址>
SLUG=<提示词里「项目:」后面的 slug>     # 只用来命名脚本的工作目录;照提示词抄,别按本地目录名猜

# 0. 票与 Content-Type 一起写进 0600 头文件,curl 用 -H @文件 读——票不进任何命令的参数(与第 1 步同一条纪律)
umask 077
H=$(mktemp) && PLAN=$(mktemp) && APPLY=$(mktemp) && trap 'rm -f "$H" "$PLAN" "$APPLY"' EXIT
printf '%s\n' 'Authorization: Bearer <票>' 'Content-Type: application/json' > "$H"   # printf 是内建,不产生进程

# 1. 组装 plan 请求体、拉取(票不消耗,可反复对账)
php "$SKILL_DIR/scripts/local-env.php" plan-payload "$SLUG" .env
curl -sS -w '%{http_code}' -o "$PLAN" -H @"$H" \
  --data-binary @"$HOME/.dev-admin/env-sync/$SLUG/plan-request.json" \
  "$API_BASE/api/v1/environment/sync/plan"

# 2. 向人出报告(下面「报告格式」一节),把每一组的分类与推荐默认念给他,拿到决策后写 decisions.json:
#    {"KEY": "local"|"center"|"disable"|"skip", ...}      # skip 只用于 downlink(SSO 三键)
#    differ 只能 local/center;local_only 只能 local/disable;center_only 只能 local/center;
#    downlink(SSO 三键,只下行)只能 center/skip——选 local 会被脚本拒绝。
#    每一个非 same、非 system 的键都要有决策(downlink 里 state=same 的除外),人可以说"差异全部用本地"这类批量话,
#    但沉默不算同意——没表态的键不许写进 decisions.json。(「全部用本地」这类话对 downlink 键不生效:它们只能是 center / skip,要人单独表态。)

# 3. 组装 apply 请求体(缺决策会报错,不会生成半份请求体)
php "$SKILL_DIR/scripts/local-env.php" apply-payload "$SLUG" "$PLAN" "$HOME/.dev-admin/env-sync/$SLUG/decisions.json"
# 输出会列出 set/pull/delete 各自的键名;含 delete 的话,先把这些键名念给人确认再继续下一步
curl -sS -w '%{http_code}' -o "$APPLY" -H @"$H" \
  --data-binary @"$HOME/.dev-admin/env-sync/$SLUG/apply-request.json" \
  "$API_BASE/api/v1/environment/sync/apply"

# 4. 先 dry-run 给人看逐行变更,确认后再真写
php "$SKILL_DIR/scripts/local-env.php" write "$SLUG" "$PLAN" "$HOME/.dev-admin/env-sync/$SLUG/decisions.json" "$APPLY" --dry-run
php "$SKILL_DIR/scripts/local-env.php" write "$SLUG" "$PLAN" "$HOME/.dev-admin/env-sync/$SLUG/decisions.json" "$APPLY"

# 5. 收尾
php "$SKILL_DIR/scripts/local-env.php" cleanup "$SLUG"
```

头文件里 `Content-Type: application/json` 那一行**不能省**：两个写口的请求体都是 JSON，`curl --data-binary @文件` 默认按表单编码发送，不带这一行中心会按表单解析、`keys` / `ops` 落空，直接 422。

两步的响应码：

| 码 | `sync/plan` | `sync/apply` |
| --- | --- | --- |
| 200 | 成功，票**不消耗**，可反复对账 | 成功，票**已作废** |
| 404 | 票无效 / 过期 / 已用过 / 已作废，或这枚票不是本地环境的票 | 同左 |
| 422 | 请求体不合法（多半是漏了 `Content-Type`，或 `.env` 里有键名不合规），票**不消耗** | 同左；`apply` 的 422 还包括决策与服务端状态对不上（比如 `pull` 一个中心已经没有的键） |
| 429 | 触发限流，等一分钟再试，**别循环重试** | 同左 |
| 其它 | 中心异常，停下报告状态码 | 同左，票**没有**被消耗 |

`H` / `PLAN` / `APPLY` 三个临时文件由 `trap` 在退出时删掉；脚本自己的工作目录（请求体、决策文件）在 `~/.dev-admin/env-sync/<slug>/`，`cleanup` 会清空它，流程正常结束或中途放弃都要跑这一步。

### 分类与决策

| 分类 | 判据 | 决策取值 | 没决策会怎样 |
| --- | --- | --- | --- |
| `same` | 两边指纹相同 | 无需决策 | — |
| `differ` | 两边都有,指纹不同 | `local`(用本地覆盖中心) / `center`(用中心覆盖本地) | `apply-payload` 报错,不生成请求体 |
| `local_only` | 本地有,中心这个环境上没有 | `local`(上行) / `disable`(本地停用,不上行) | 同上 |
| `center_only` | 中心这个环境上有,本地没有活键 | `center`(本地写入,停用行则复活) / `local`(中心删这一行) | 同上 |
| `downlink` | SSO 三键，且**中心此刻确有值**（`state`：`center_only` 本地没有活键 / `differ` 值不同 / `same` 值相同） | `center`（本地写入，停用行则复活）/ `skip`（本地不动、中心也不动）；`same` 无需决策。**没有 `local`**：SSO 键只下行，脚本不接受、中心也拒绝任何上行 | `apply-payload` 报错,不生成请求体 |
| `system` | 属「永不写本地」那 14 个，以及中心没有值的 SSO 键（它们仍是系统键） | 无,两边都不碰 | — |

`downlink` 的键**不会**同时出现在 `system` 里，也不会落进 `differ` / `center_only` / `local_only`（所以 SSO 键不会被当成「本地有、中心无」去登记，也不会逼你选一个必然被拒的「用本地」）。`state: differ` / `center_only` 的键同样**要人明确表态**，推荐默认选 `skip`（想补就让人点名选 `center`），**沉默不算同意**；本地有 `# KEY=` 停用行（`disabled_locally: true`）时选 `center` 会复活它，选 `skip` 保持停用。三键要补就一起补。`SSO_CLIENT_SECRET` 的规矩同上一节：值不过你的眼。

**SSO 三键本地值为空的空占位视为缺失**（`.env.example` 复制出来的 `SSO_CLIENT_ID=` 之类，仅这三个键）：`plan-payload` 构造请求体时**不上报**它们的指纹（stdout 会列 `blank-sso(...)` 一行，只有键名），中心于是把它们归为 `center_only`，而不是落成一条「值不同」。点名选 `center` 时，`write` 是**原位替换那一行**，不追加第二行同名键；选 `skip` 文件不动。本地值**非空**的 SSO 键照旧「可补不可改」——值不同时只能 `center` / `skip`，没有 `local`。

**`downlink` 里只出现 1~2 个 SSO 键时**（`downlink` 只列中心此刻确有值的键：没有托管明文就没有 `SSO_CLIENT_SECRET`，绑定悬垂就没有 `SSO_CLIENT_ID`）：**别拿残缺的一部分去补**——补进去本地也登不进。先提醒用户去中心管理端把该项目的 SSO 接入补齐（项目详情页「SSO 登录设置」；补 secret 要「重置 secret」，超管专属、**旧 secret 立即失效**），之后重新签票再同步；他明知如此仍点名，才只补点名的那几个，并再提醒一次登不进。

`local_only` 里 `registered: true` 的键说明项目级已经登记过（只是这个环境没值）；`secret_hint: true` 说明键名命中敏感启发式，上行登记后会自动标脱敏。`center_only` 里 `disabled_locally: true` 说明本地有一行 `# KEY=`，推荐默认选 `local`（让中心把这一行删掉）——但**仍然要人明确表态**，沉默不算选了推荐值。

### 报告格式

```
本地 .env ↔ 中心「本地」环境(项目 my-project)
  一致 12 键
  值不同 2 键(请选 本地/中心):DB_DATABASE  REDIS_PASSWORD
  本地有中心无 3 键(推荐上行,可选停用):FOO  BAR(已登记)  BAZ(将标敏感)
  中心有本地无 2 键(推荐用本地=中心删行,请确认):MAIL_MAILER(本地已停用)  REDIS_HOST(请选)
  SSO 下行 3 键(只能 补入/不动,推荐不动,要补请点名):SSO_BASE_URL(本地无)  SSO_CLIENT_ID(值不同)  SSO_CLIENT_SECRET(本地空占位,按缺失)
  系统键 5 键,两边都不碰:APP_KEY APP_URL DEVTOOLS_TOKEN ...
  引用键 1 键(值含 ${...} 引用,回写会展平,默认不写盘,需点名):MAIL_FROM_NAME
  跳过:foo_bar(键名非全大写)  QUX(无等号,未赋值)
```

不打任何值，不打指纹——`plan-payload` 的 stdout 只有键名、分类计数、`invalid`/`duplicate`/`novalue`/`interpolated`/`blank-sso` 五类清单。

### 写盘规则（脚本自动处理，你不用管细节，出问题时知道原因即可）

- 下面几条里的「`pull` 到值」也包括 `downlink` 里选了 `center` 的 SSO 键，走同一条写盘路径；选 `skip` 的、以及 `state: same` 的一律不动文件。
- `pull` 到值、本地有活行 → 整个区间（多行值也算一个区间）原位替换成一行。
- `pull` 到值、本地只有停用行（`#KEY=` 或 `# KEY=` 都认）→ 复活：去掉 `#`，写入新值。
- `pull` 到值、本地完全没有 → 追加到 `# ── devtools managed ──` 区块。
- `pull` 到值，但本地那一行的值含 `${VAR}` 引用 → **默认跳过写盘**，报告里单列 `skipped-interpolated`，需要人明确点名才会展平覆盖。
- `disable` 决策 → 整个区间每一行都加 `#`。
- 值渲染与中心的规则逐字一致，**额外多转义一个 `\r`**（中心侧这个转义是已知待办，脚本先补上，不然含回车的值每次同步都会显示「值不同」）。
- 纵深：`downlink` 的键若 `apply` 响应里的值是空 / 纯空白，`write` 一律跳过并告警 `skipped-blank-downlink`（只写键名），绝不拿空值覆盖本地可用的凭据；中心在 `apply` 校验通过后、落库前 SSO 接入被解除时，`apply` 直接回 422「中心已无此键,请重新 plan」，票不消耗——回头重新 `plan` 看中心的真实状态。
- 幂等：同一状态跑第二遍，文件字节不变。

### 排障

| 症状 | 多半是 |
| --- | --- |
| `sync/plan`、`sync/apply` 都 404 | 这枚票不是本地环境的票，或提示词里的环境标识不是 `local` |
| `plan-payload` 报「解析失败」 | `.env` 本身有语法问题（未闭合引号、非法转义之类）——脚本不打值，只打错误类别与行号，先按提示修好 `.env` 再重跑 |
| `apply-payload` 报某个键「决策不完整或不合法」 | 上一步的报告里这个键的分类没拿到人的表态，回去问清楚再补 `decisions.json` |
| 某个键一直卡在「值不同」问不出结果 | 人还没表态。脚本**没有**批量展开逻辑——「差异都用本地」这类批量话由你按人的原话逐键写进 `decisions.json`，沉默不算同意；**`downlink` 的键（SSO 三键）不吃这句**（它们没有 `local`，只能 `center` / `skip`），要单独问 |
| 写完某个键还是老样子 | 大概率是 `skipped-interpolated`——原值含 `${VAR}` 引用，需要人明确点名才会展平 |
