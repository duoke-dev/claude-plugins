# Dev Tools 查询 API 参考

Base:`$DEVTOOLS_API_BASE`,认证:`Authorization: Bearer $DEVTOOLS_TOKEN`,均为 JSON 响应。
限流 60 次/分钟;429 时等待后重试。

`DEVTOOLS_TOKEN` 是平台签发给本环境的**系统令牌**,带 `read`、`manage` 等能力
(`manage` 自 **2026-08-19** 起随中心侧改动固定带上,存量令牌都已由平台迁移补齐),
故本文所列的查询与写操作**同一枚 token 全都能做**。

## GET /api/v1/project

项目概况:`data.{name, slug, stage, group, unresolved_errors_count, errors_last_7_days_count}`

## GET /api/v1/errors

Query:`status`(open|resolved)、`level`(debug…emergency)、`seen_between`(如 `2026-07-01,2026-07-13`)、`page`

响应 `data[]`:`id, exception_class, message, file, line_number, status, level, occurrence_count, affected_user_count, first_seen_at, last_seen_at`;`meta`:`current_page, last_page, total`

## GET /api/v1/errors/{errorId}/occurrences

Query:`page`。响应 `data[]`:`id, received_at, stage, application_version, message`(按时间倒序,取第一条即最新);`meta`:`current_page, last_page, total`

## GET /api/v1/occurrences/{occurrenceId}

响应 `data`:
- `id` / `error_id`(反查所属错误)
- `exception_class` / `message` / `received_at` / `stage` / `application_version`(git commit)
- `app_frames[]`:`file`、`line_number`、`class`、`method`、`code_snippet`——**定位看这里**。
  注意:Flare 公共 API 目前不提供完整堆栈,通常只有 1 帧(抛出点,由平台从 error 信息合成,`code_snippet` 为空);本地按 `file:line_number` 对照代码即可
- `vendor_frames[]`:同因通常为空
- `attributes[]`:`{group: request|user|env, key, value}`——OTel 风格上下文(`http.*`/`url.*` 为请求组、`user.*` 为用户组、其余为环境组,含 `context.custom` 自定义上下文)
- `flare_url`:该错误在 Flare 后台的链接。开发者无 Flare 账号;需要完整堆栈时把它交给管理员查看

响应超 50KB 时按 `attributes` → `vendor_frames` → `code_snippet` 顺序裁剪为空;空数组可能是被裁剪,不代表无数据。

## POST /api/v1/errors/{errorId}/resolve

无 body。需 `manage` 能力(`DEVTOOLS_TOKEN` 自带)。

## POST /api/v1/errors/{errorId}/snooze

Body:`{"type": "snooze_until|snooze_forever|snooze_number_of_occurrences|snooze_application_version", "until"?: "ISO8601", "occurrences"?: 100}`

## POST /api/v1/errors/{errorId}/unsnooze

无 body。

## 错误码

- 401:token 无效或已撤销 → 联系管理员
- 403:token 缺少所需能力。`DEVTOOLS_TOKEN` 正常情况下带 `manage`,仍 403 说明中心侧未升到 2026-08-19 之后的版本,
  或 .env 里配的不是系统令牌 → 联系管理员,不要重试
- 404:错误不属于本项目(或资源不存在)
- 429:触发限流,等 60 秒
