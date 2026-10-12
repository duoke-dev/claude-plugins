# duoke-dev 的 Claude Code 插件市场

本仓库是 Claude Code 插件市场 `duoke-dev` 的**只读发布镜像**:内容由维护方的内部流程自动生成,每次同步是一个快照提交。

- 不接受 Pull Request 与 Issue。直接改本仓库的内容不会被采纳,下一次同步会把它覆盖。
- 读取不需要任何凭据,匿名即可添加市场、安装插件。

## 接入

在项目的 `.claude/settings.json` 里合并写入下面两键并提交:

```json
{
  "extraKnownMarketplaces": {
    "duoke-dev": {
      "source": { "source": "git", "url": "https://github.com/duoke-dev/claude-plugins.git" },
      "autoUpdate": true
    }
  },
  "enabledPlugins": { "devtools@duoke-dev": true }
}
```

成员打开项目时接受信任对话框(未信任的文件夹会忽略上面的声明),再按提示安装。没弹安装提示时,也可以在项目目录手动运行:

```bash
claude plugin marketplace add https://github.com/duoke-dev/claude-plugins.git --scope project
claude plugin install devtools@duoke-dev --scope project
```

注意:`marketplace add` 会把 `.claude/settings.json` 里的 `duoke-dev` 条目改写成只剩 `source`,运行后要把 `"autoUpdate": true` 补回该条目(`install` 不会补)。第三方市场默认不自动更新,`autoUpdate: true` 不能省;想立刻拿到新版本,运行 `claude plugin marketplace update duoke-dev`。

## 分发的 skill(插件 `devtools`)

| skill | 用途 | 需要 |
|---|---|---|
| `prod-errors` | 在 Claude Code 里查询线上错误、看 stacktrace 定位、修复后标记解决 | 配套的错误追踪服务端;项目 `.env` 里的 `DEVTOOLS_API_BASE` 与 `DEVTOOLS_TOKEN` |
| `env-sync` | 把本地 `.env` 与服务端台账对一遍:只补不改、系统键永不写入、值不进 AI 上下文;「本地」环境走双向镜像 | 配套服务端签发的一次性拉取票(随服务端生成的提示词给出);镜像模式还要求项目 `vendor/` 里有 `vlucas/phpdotenv` |

没有配套服务端时,这些 skill 装上也用不起来。

## 权利声明

保留所有权利。本仓库不附带开源许可证(没有 LICENSE 文件):公开只为让开发机能匿名拉取插件,未经许可不得用于其它用途。
