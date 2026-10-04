# V1 最终端到端验收

## 范围与方法

`tests/Feature/V1GoldenWorkflowTest.php` 新增两条真实 HTTP/API 验收测试。测试经路由、中间件、控制器、服务和 SQLite 数据库执行；仅用 Factory 创建登录用户。Project 及之后的业务记录与状态均通过正式 API 创建或推进。没有修改生产代码。

## Golden Workflow

访客访问业务 API 返回 401；登录后当前 Project 为空。测试通过 API 创建并选择 Project，随后创建 Column、Topic、ContentItem 和四种 Page Type 的 Working Copy。另一篇同 Project 内容先形成正式 Revision，作为查重历史语料。当前篇目取得真实 Candidate，依次追加 `confirmed_duplicate` 与 `ignored` Decision，核对最新决定为编号 2、编号 1 历史仍在，再确认完整正式文案快照。

ProductionTask 固定文案 Revision，经图稿状态推进后，为四页分别登记 `copy_master` 与 `clean_master` AssetVersion。公众号和视频号分别绑定正确角色的共享资产；反向角色绑定被拒绝。未完成绑定前，公众号发布及视频号审核均被拒绝；绑定完整后，视频审核和两个渠道发布成功。重复发布保持既有 `published_at`，不得倒退到未发布。

确认新的正式文案后，旧查重决定、旧 Production Revision、资产版本和绑定历史仍保留。另一条测试验证重启 Production 到 Revision 2 后，旧绑定作为 `latest_binding` 保留，但不再算作 `current_binding`，完整度重置。跨 Project 的 AssetVersion 绑定与内容读取返回 404。最后退出登录，业务 API 与 `/api/auth/me` 返回 401；重新登录后当前 Project 仍为空。

## 验收结果

- Golden Workflow：2 passed，300 assertions。
- 完整 PHP：270 passed，3207 assertions。既有参考基线为 268 tests，2907 assertions。
- Pint、TypeScript typecheck、Vite build：通过。
- SQLite：独立数据库 `migrate:fresh`、最后一批 migration rollback、再次 migrate 均通过。
- MySQL 8.4 Runtime Gate：仍待独立验收，不能据此宣称 V1 可发布。

本测试证明服务端 HTTP 主链与数据库语义；浏览器 UI 交互和真实微信平台发布不在本次自动化覆盖内。测试中的 Publish 是工作台渠道状态推进，不代表调用真实微信发布接口。
