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

状态：已完成（见 2026-10-13 接管复核和最终 CI 结果）

已完成：
- 创建 `V2ContentCoreSchemaTest`。
- RED 已验证：`inspirations` / `content_branches` 不存在，Topic 仍可创建多个 ContentItem。
- 新增 V2 schema migrations：Inspirations、Topic V2 字段、ContentItem V2 字段、ContentBranch。
- SQLite 定向测试已 GREEN（GitHub Actions run 38269706547）。

待完成：
- MySQL 8.4 真库验证。
- 全量 PHP 回归。
- Task 1 最终自审。

## 2026-10-13 Work 接管与复核

- 接管远程 `codex/v2-foundation`，HEAD `68515b2b1edbb6764d0d3419e1604d6a2a904093`。原本机 `01_Main/qn-ai-workbench` 保持 main 未改；本次使用独立完整检出，不包含 QN-Work-Panel。
- 已读设计基线和 7-task foundation 计划。使用 Work 内只读代理分别审查 schema/约束与 CI；没有直接调用 WorkBuddy/豆包的执行接口，未假设其可用。
- 远程 CI run `38269905936` 为 failure。PHP job 缺少 APP_KEY；MySQL job 首先因脚本 mode 100644 导致 Permission denied（exit 126）。修复启动方式后还需要生成 MySQL 测试 APP_KEY。
- Task 1 Ruling：Inspiration 复合外键改为 RESTRICT；保留强制 Project 隔离和长期来源链，不新增删除接口。若将来需要删除被引用灵感，必须先显式处理关联。代价：有来源引用时不能直接物理删除灵感。
- RED：SQLite 删除已引用灵感触发 NOT NULL topics.project_id；独立 MySQL 8.4.11 同构 FK 实测 ERROR 1830，证明 SET NULL 对两个列一起生效。
- RED：新 `V2MigrationRollbackTest` 实测 SQLite 无法按字符串名称删除 FK；改用框架常规 FK 名与列数组 dropForeign 后 GREEN。
- Task 1 Ruling：四个旧测试夹具改为不同 Topic 对应不同 ContentItem，保留来源共享、祖先隔离、默认状态和 V1 全链路测试目的；不放宽 V2 topic 唯一约束。代价：旧测试不再使用同选题多内容的无效夹具。
- 补测：跨 Project 的 Inspiration/ContentItem 引用拒绝、停止分支仍占唯一键、公众号两种形式共用母内容、直接选题默认值、4 个 V2 migration 回滚/重迁及旧行保留。
- Task 1 Ruling：MySQL CI 改为每次 foundation push/PR 和手动触发都执行；用 bash 启动脚本、生成两套临时应用密钥。代价：每次 CI 增加 MySQL gate 运行时间。
- SQLite 全量：`php vendor/phpunit/phpunit/phpunit --colors=never` → 279 tests / 3238 assertions PASS。
- MySQL 定向：`php vendor/phpunit/phpunit/phpunit -c phpunit.mysql84.xml --filter V2ContentCoreSchemaTest` → 8 tests / 17 assertions PASS，bootstrap 证明 mysql/127.0.0.1:3399/qn_workbench_test/8.4.11。
- Task 1 Ruling：本机没有 Docker，使用独立新建 data directory 的 MySQL 8.4.11 原生进程和既有 phpunit.mysql84.xml/连接自证；不使用现有业务库和 runtime/mysql-data。代价：本地原生验证不等同于 Docker 生命周期，远程 CI 仍需复验。
- 本机 PHP 8.5.10，远程 CI 保持 PHP 8.4。
- 窗口写权限切换后，将含改动和依赖的检出复制至当前可写 `work/qn-ai-workbench`；原检出保留，后续仅在新检出继续。
- MySQL 全量（包括 Gate/并发）运行中，尚不宣称 Task 1 完成。
- Task 2 RED：`V2DomainModelTest` 3 项因缺失 Inspiration/ContentBranch/TopicStatus 失败，符合计划；未开始领域实现。

## Task 2：领域模型

- RED 已观察：Inspiration / ContentBranch / TopicStatus 缺失。
- GREEN：新增来源链模型关系、状态/形式枚举、分支停止恢复；保留仍有 V1 调用的关系和 expectedAssetRole。
- Task 2 Ruling：增加 Xiaohongshu 枚举会使旧 V1 channel 创建校验自动放行，补测试实测返回 201（RED）；旧请求显式仅允许两个微信渠道后返回 422（GREEN）。代价：旧接口不提供小红书能力，符合预留原则。
- 定向 50 tests / 534 assertions PASS；SQLite 全量 283 tests / 3279 assertions PASS。
- 本批次仅模型/工厂/枚举和旧接口预留渠道门禁，不新增前台录入负担。

## Tasks 1–6 集成结果（2026-10-13）

- Task 1 最终自审完成：MySQL 8.4.11 真库全量 292 tests / 3310 assertions PASS，含迁移回滚、既有 Gate/并发检查。此快照早于新 V2 API 集成；新增 API MySQL 另行复验。
- Tasks 3–6：Work 后端与前端代理按独立文件范围执行，主 Work 集成；没有调用 WorkBuddy/豆包，没有混入 QN-Work-Panel。
- 后端 RED：缺少 V2 路由；GREEN 初版 9 tests / 163 assertions。独立复审发现标题 max255 超过数据库200，以及已开始创作的 Topic 换栏目触发 FK500；新增失败测试后修正为 max200/换栏目422，11 tests / 177 assertions PASS。
- Task 3 Ruling：独立 V2 controller/resource/API client 保留真实契约，旧嵌套 API 暂存到 Plan6。代价：后续清理需合并或删除兼容代码。
- Task 3 Ruling：开始创作锁 Topic 并检查已有母体，分支添加锁 ContentItem；完整校验渠道组合后事务写入，重复开始409返回已有ID。代价：同一母体的创建/添加请求串行。
- Task 3 Ruling：已开始创作后拒绝更换栏目，避免破坏既有 Topic/Column 复合外键；同栏目更新允许。代价：改栏目需要后续明确迁移流程。
- 前端：灵感、选题池/星标、开始创作、真实来源、内容库/共享编辑、分支停止恢复、进行中矩阵与审核占位页完成；小红书禁用。只维护一份前端合法组合元数据。
- 主 Work SQLite 全量：294 tests / 3471 assertions PASS。前端 npm run typecheck 与 npm run build 均 PASS。
- 自审：无新增项目管理字段、无额外状态、无 SourceReference 假来源、V2 不依赖 ProductionTask/ChannelTask；视频及其他阶段未提前展开。
- 通过 GitHub 连接同步 schema/CI 和模型提交，远程提交0f9f12c、ec637a9；对应本地原提交70845e9、2d66f97的 tree 完全一致。保留 backup/v2-foundation-local-audit，再将本地分支对齐相同远程对象，未改工作文件。
- 原生 Git 远程连接失败，使用 GitHub 连接发布并以 tree SHA/commit SHA校验；仅更新 codex/v2-foundation，main 未动。
- 新 API MySQL、最新远程 CI及浏览器手工验收尚待确认，不能声称这些已通过。

## Task 7 文档与验收

- 已更新 README、架构与数据库基线的 V2 优先说明，保留 V1 历史资料；明确旧数据可丢弃、不承诺生产迁移/回填、Plan6清理旧任务模型。
- 新增 V2_FOUNDATION_ACCEPTANCE.md，列出完整手工闭环、路由、约束与后续视频范围；手工验收项目明确标待执行。
- 新增 V2 API MySQL 8.4.11 真库复验：11 tests / 177 assertions PASS（含标题200边界和已开始选题换栏目422）。日志 mysql-v2-api-regression.log；与之前全量292项互补，新API已实际在MySQL运行。


## 最终 CI 与收尾（2026-10-13）

- 开发提交 77c9eb53872f37ada515e1c52d0b238201949ff8 的远程 run 38291719825 SUCCESS；PHP job114953150647、MySQL job114953150126均成功。
- 远程 SQLite全量294 tests / 3471 assertions PASS；MySQL8.4全量307 tests / 3543 assertions PASS，定向DuplicateReview13 tests / 122 assertions PASS，完整release gate成功。
- 主Work再次运行npm run typecheck、npm run build均PASS（862 modules）。工作区干净，分支codex/v2-foundation；main未改。
- 独立本地MySQL测试实例已正常shutdown；未触及其他MySQL服务。
- Tasks1–7代码/测试/文档范围完成。浏览器手工验收尚未执行，按V2_FOUNDATION_ACCEPTANCE.md继续；图片/AI导入/视频/实际审核仍属后续计划。
