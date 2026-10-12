# V2 Foundation 执行台账

计划：`docs/superpowers/plans/2026-10-12-qn-ai-workbench-v2-foundation.md`
规格：`docs/superpowers/specs/2026-10-12-qn-ai-workbench-v2-architecture-design.md`
分支：`codex/v2-foundation`

## 执行规则

- 先自审 → TDD RED → GREEN → 回归 → 再自审。
- 大方向 / 使用方式 / 数据主关系变更请示用户；小技术问题直接解决并记录。
- V1 历史业务数据允许清空；V2 最终采用 fresh schema。
- 不为旧 ProductionTask / ChannelTask / Asset 关系保留长期兼容包袱。

## Rulings

- Task 1 Ruling：当前聊天容器无法解析 `github.com`，无法直接 clone 仓库；使用独立 GitHub 分支 `codex/v2-foundation` + GitHub Actions 作为 RED/GREEN 执行环境。代价：测试反馈比本地慢，但不改变业务代码与产品设计。
- Task 1 Ruling：初始 CI 使用 PHP 8.3，但当前 `composer.lock` 锁定的 Symfony 8.1 依赖要求 PHP >= 8.4.1；测试 harness 改为 PHP 8.4。该问题属于测试环境，不修改业务实现。
- Task 1 Ruling：实施计划中的 `./scripts/test-mysql.sh --filter ...` 与仓库现有脚本接口不一致；现有脚本只接受 `up|down|logs|grant|verify|suite|concurrency|all`。MySQL 8.4 验证将调用真实支持的子命令，并在 CI 中补充 V2 定向 MySQL 测试，不修改现有 Release Gate 的既有安全边界。

## Task 1：V2 Core Schema

状态：进行中

已完成：
- 创建 `V2ContentCoreSchemaTest`。
- RED 已验证：`inspirations` / `content_branches` 不存在，Topic 仍可创建多个 ContentItem。
- 新增 V2 schema migrations：Inspirations、Topic V2 字段、ContentItem V2 字段、ContentBranch。
- SQLite 定向测试已 GREEN（GitHub Actions run 38269706547）。

待完成：
- MySQL 8.4 真库验证。
- 全量 PHP 回归。
- Task 1 最终自审。
