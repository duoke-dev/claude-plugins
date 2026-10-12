---
name: prod-errors
description: 查看和处理本项目的线上(生产环境)错误。当用户提到"线上错误、线上报错、生产报错、线上出问题、production error、查看报错",或需要调查生产环境异常、修复后标记解决时使用。
---

# 线上错误查询与处理

通过公司 Dev Tools 平台查询本项目的生产错误(含 stacktrace 与请求上下文),修复后可标记解决。开发者无需访问 Flare 后台。

## 凭证

从项目 `.env` 读取(用 grep 提取,不要 source 整个文件):

```bash
API_BASE=$(grep '^DEVTOOLS_API_BASE=' .env | cut -d= -f2- | tr -d '"')
TOKEN=$(grep '^DEVTOOLS_TOKEN=' .env | cut -d= -f2- | tr -d '"')
```

任一为空时停止,提示用户:找管理员在管理端「项目详情页」复制 .env 配置片段。

> `DEVTOOLS_TOKEN` 是平台签发给本环境的**系统令牌**,读(查错误)与写(标记解决 / 暂停通知)都用它,**不需要再额外配一枚令牌**。

## 请求约定

所有请求带 `Authorization: Bearer $TOKEN` 和 `Accept: application/json`。
分页参数 `page`,每页最多 30 条。完整参数与响应字段见 references/api.md。

```bash
flare_api() { curl -sS -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" "$@"; }
```

## 常用操作

1. 最近未解决错误(默认按最后出现时间倒序):
   `flare_api "$API_BASE/api/v1/errors?status=open"`
2. 某错误的发生记录:
   `flare_api "$API_BASE/api/v1/errors/{errorId}/occurrences"`
3. 发生详情(stacktrace + 请求上下文,分析根因主要看这个):
   `flare_api "$API_BASE/api/v1/occurrences/{occurrenceId}"`
4. 标记已解决(仅在修复验证完成且用户确认后):
   `flare_api -X POST "$API_BASE/api/v1/errors/{errorId}/resolve"`
5. 暂停通知:
   `flare_api -X POST -H "Content-Type: application/json" -d '{"type":"snooze_forever"}' "$API_BASE/api/v1/errors/{errorId}/snooze"`

## 工作流

> **按错误码直查**:用户直接给出错误码(即通知卡片里的「错误码」= Flare 错误 id)时,跳过第 1 步列表,直接 `flare_api "$API_BASE/api/v1/errors/{errorId}/occurrences"` 取最新 occurrence 再查详情。该接口按当前项目 token 校验错误归属:返回 **404** 即本项目查不到该码——**不要当作技能故障或反复重试**,提示用户"这个错误码在本项目查不到,多半是**其他项目**的错误(错误码不跨项目共享),请到对应项目里让 Claude Code 查线上报错(/devtools:prod-errors)"。

1. **列错误**:查 open 状态错误,用简洁表格呈现(异常类 / 消息 / 位置 / 次数 / 最后出现时间)。
2. **看详情**:用户选定(或默认最新)错误 → 先列 occurrences 取最新一条 id → 查详情。
3. **定位代码**:详情里 `app_frames[].file:line_number` 指向抛出点,直接对照本地代码(Flare 公共 API 暂不提供完整堆栈,通常仅此 1 帧);结合 `attributes`(request/user/env 上下文)与 `message` 分析根因。注意线上代码版本可能落后于本地,以 `application_version`(commit hash)为准核对。需要完整堆栈时,把详情里的 `flare_url` 交给管理员在 Flare 后台查看。
4. **修复**:按项目常规流程修复并验证。
5. **闭环**:修复经用户确认后,调 resolve 标记解决。同一错误再次发生时平台会自动重新打开并推送通知。

## 约束

- 只能查询/处理当前项目的错误,作用域限本环境所绑定的项目。
- resolve / snooze 是写操作,**必须先获得用户明确同意**。
- 平台不可用(连接失败/5xx)时降级:告知用户请管理员直接在管理后台查看。

## 排障

| 现象 | 含义 | 怎么办 |
| --- | --- | --- |
| `401` | token 无效 / 已撤销,或其所属环境、项目已删除 | 提示用户找管理员在管理端「项目详情页」重新复制 .env 配置片段 |
| `403` | token 不具备该接口所需能力 | `DEVTOOLS_TOKEN` **本应能做 resolve / snooze**——自 **2026-08-19** 起平台给系统令牌固定带上了 `manage`(存量令牌已由平台迁移一并补齐,无需轮换)。此时若仍 403,说明**中心侧还没升到该日期之后的版本**,或 .env 里配的不是系统令牌(而是某枚只读的人工令牌):提示用户找管理员核对,**不要反复重试** |
| `404` | 该错误不属于本项目(错误码不跨项目共享),或资源不存在 | 见上文「按错误码直查」的处置 |
| `429` | 触发限流(常规 60 次/分) | 等待后重试 |
