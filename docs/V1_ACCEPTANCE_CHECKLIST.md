# V1.0 验收清单

本清单只记录**已发生的事实**。任何未实际执行的项目一律标 `Pending`，不得写成 `Pass`。

| 项 | 值 |
| --- | --- |
| 锁定 main SHA | `e459cbedd77d9befcbf0f4efade157542c6e7c39` |
| 归档分支 | `docs/V1-acceptance-archive`（基于上述 main，未合 main） |
| 归档日期 | 2026-10-05 |
| 结论 | **尚未具备发布条件**——MySQL 8.4 Runtime Gate 仍为 Pending |

## 一、已完成的 Gate

| # | Gate | 状态 | 证据 |
| --- | --- | --- | --- |
| 1 | Auth Backend（DEV-AUTH-LITE） | ✅ Pass | `93bb355` 建后端、`bcf531b` 收口生命周期；真实 API 联调覆盖 login / me / logout / guard / CSRF 419 / 限流 / open redirect / ProjectContext |
| 2 | Auth UI 真实接入（DEV-W11） | ✅ Pass | `16e7661` 前端准备、`da36dbe` 接入真实后端；`D11`/`W10`/`W11` 均为真实 API，无 mock |
| 3 | Golden E2E（V1 主链） | ✅ Pass | `31eb5cc`；`tests/Feature/V1GoldenWorkflowTest.php` 2 passed / 300 assertions；全量 270 passed / 3207 assertions（既有基线 268 / 2907）。详见 `docs/V1_FINAL_E2E_ACCEPTANCE.md` |
| 4 | Browser Runtime（真实浏览器） | ✅ Pass | 系统已装 Microsoft Edge + CDP 驱动，零新依赖；覆盖登录、刷新保持、UserMenu 幂等、ProjectContext 持久化与切换、查重双栏、401（服务端 session 失效后自动回登录且无循环）、logout / relogin、1366 与 1920 响应式；console 零错误、无 401 风暴 |
| 5 | DEV-W09 渠道素材绑定 UI | ✅ Pass | `e46eb4f` 实现（5 个前端文件）、`14d4dc1` 集成验收、`4cd9ec1` 验收记录；Migration 数量 0，`app/**`、`database/**`、`routes/**` 零改动 |
| 6 | DEV-W10 查重复核 UI | ✅ Pass | `f424d20` 面板、`d882a08` 对真实 DEV-D11 API 的验证、`a2cf378` 修复 |
| 7 | DOC-W09-001 事实修正 | ✅ Pass | `e459cbe`；`docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` 的改动范围表述已按实际 diff 订正，**已进入 main**（即当前锁定 SHA） |
| 8 | MySQL 8.4 Gate 工具 | ✅ Pass | `e083e54 → 406c6be → bd0f28d → 387e0ec → d16bc03` 五个提交已进入 main；静态验收 65/65；DB_URL 空值语义在 shell / bootstrap / DriverTest / XML / env 示例 / 文档 六处一致 |

## 二、Pending 项

| # | 项 | 状态 | 说明 |
| --- | --- | --- | --- |
| 9 | **MySQL 8.4 Runtime Gate** | ⏳ **Pending** | 本机无 Docker / Podman / 本地 mysqld，Runtime Gate **从未实跑**。`scripts/test-mysql.sh` 只完成静态与语义层验收。SQLite 跑绿不构成 Gate 通过，工具链已就此设三重拦截 |

Pending 的准确含义：**未执行**，不是「执行了但没通过」，更不是「大概会通过」。

## 三、已知非阻断项

均不影响上述 Gate 结论，也不要求在 Runtime Gate 之前处理。

1. 419 登录页 UI 链路为**人工复核项**：服务端 419 行为已实测生效（正确 token 200 / 错误或跨 session 419），但登录页上的提示文案未做自动断言。
2. 主线 `phpunit.xml` 仍为 SQLite 内存库，这是设计如此；MySQL 走专用变体 `phpunit.mysql84.xml`，两者不会互相污染。
3. MySQL Gate 使用公开的一次性本地测试常量与端口 3399，不涉及任何真实凭据。
4. `docs/DEV_TASKS.md` 中 W05.1 / W06.1 的历史 MySQL 8.4 实机验证与浏览器 Smoke 同样标注为待补验，与本清单口径一致。
5. `resources/js/api/types.ts` 在 W09 之后被 DEV-W10 追加查重字段，属叠加式扩展，未改动 W09 的绑定字段。

## 四、最终可发布条件

以下全部成立时，V1.0 方可宣布可发布：

1. 在装有 Docker 或 Podman 的机器上执行 `scripts/test-mysql.sh all` 全绿——覆盖 `fresh migrate` → 结构断言 → 全链 rollback → re-migrate → 定向测试 → 完整 PHP suite。
2. 留存该次运行证据：`MYSQL84_GATE_DRIVER_PROVEN`、`STRUCTURE_ASSERT_OK`、`MYSQL84_GATE_TEST_PASS` 三处标记与退出码 0。
3. 容器销毁确认：`down -v` 完成后无数据卷残留（库名固定以 `_test` 结尾，账号 `qn_test`）。
4. 按下节「状态更新指引」做**最小状态更新**，不改写任何历史结论。
5. 是否创建 v1.0 tag 由发布负责人决定。**本归档不创建 tag。**

## 五、状态更新指引（Runtime Gate 通过后）

只允许改下列位置，其余内容一律不动：

| 文件 | 允许的改动 |
| --- | --- |
| 本文件第二节 | 第 9 行状态由 `⏳ Pending` 改为 `✅ Pass`，并补记运行日期、机器环境、容器运行时版本、退出码 |
| 本文件第一节表尾 | 追加一行说明 Runtime Gate 的通过证据来源 |
| 本文件第四节 | 第 1–3 条标注完成日期；**第 5 条不因 Gate 通过而自动满足** |
| `docs/V1_RELEASE_DELIVERY.md` | 「当前发布状态」一段由 Pending 改为可发布 |

禁止事项：不得改写已记录的跑分、Gate 结论或非阻断项；不得借状态更新之机扩写功能描述；不得修改代码或测试。
