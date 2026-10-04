# V1.0 交付说明

本文件说明 V1.0 当前交付到什么程度、包含什么、以及还差什么。**不含推测性结论**：未执行的项目一律写 Pending。

| 项 | 值 |
| --- | --- |
| 锁定 main SHA | `e459cbedd77d9befcbf0f4efade157542c6e7c39` |
| 归档分支 | `docs/V1-acceptance-archive`（基于上述 main，未合 main） |
| 归档日期 | 2026-10-05 |
| 当前发布状态 | ⏳ **Pending——不可发布** |
| 配套清单 | `docs/V1_ACCEPTANCE_CHECKLIST.md` |

## 一、为什么现在不能发布

V1.0 的服务端主链、前端主链与浏览器交互均已验证通过，唯一未完成项是 **MySQL 8.4 Runtime Gate**。

该 Gate 需要一套隔离的一次性 MySQL 8.4 实例，验证 migration / rollback、`duplicate_review_decisions` 表结构（InnoDB、`utf8mb4_unicode_ci`、4 个外键、唯一索引 `NON_UNIQUE=0`）、隔离级别，以及 DuplicateReview 在真实 MySQL 上的行为（含 `lockForUpdate` 行锁与并发唯一约束）。本机无 Docker / Podman / 本地 mysqld，**该 Gate 从未实跑**。

因此：SQLite 下的全绿结果不能替代 MySQL 结论，`V1.0` 不得宣布可发布，也不得据此创建 tag。

## 二、本次交付包含什么

**已合入 main 并通过验证：**

- **认证与会话**：Auth Lite 后端 + UI 真实接入，登录态、CSRF、限流、越权与 open redirect 均有实测记录。
- **业务主链**：Project → Column → Topic → ContentItem → Page → ProductionTask → ChannelTask 的完整 API 链路由 Golden E2E 逐项走通，2 passed / 300 assertions，全量 270 passed / 3207 assertions。
- **渠道素材绑定（W09）**：绑定只引用共享 AssetVersion，渠道不复制图片；current / latest 语义、完整度门禁镜像、stale 场景、错误处理边界均以服务端为权威。
- **查重复核（W10 + D11）**：候选发现、Decision 编号推进、历史保留与重复发布不倒退，均对真实 API 验证。
- **浏览器运行时**：真实 Edge + CDP 走通登录、项目切换、查重、401 回登录、登出重登与两档分辨率，console 零错误。

**已合入 main 的工程基建：**

- MySQL 8.4 Gate 工具链（`scripts/test-mysql.sh`、`docker-compose.mysql-test.yml`、`phpunit.mysql84.xml`、`tests/bootstrap-mysql84-gate.php`、`tests/Gate/Mysql84DriverTest.php`、`.env.mysql-testing.example`、文档），操作说明见 `docs/MYSQL84_TESTING.md`。

**已合入 main 的文档修正：**

- `docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` 的改动范围表述按实际 diff 订正（`tests/Feature/**` 在集成验收阶段确有改动，原表述失真）。

## 三、交付边界

以下内容**不在** V1.0 范围内，文档与代码均未声称支持：

- 调用真实微信 API 发布；测试中的 Publish 仅为工作台渠道状态推进。
- 渠道专属图稿、文件上传与图片预览。
- 渠道素材绑定的删除、编辑与回滚——绑定只是引用。
- 生产环境部署与运维配置。

## 四、已知非阻断项

1. 419 登录页 UI 提示文案为人工复核项（服务端行为已实测）。
2. 主线测试基线为 SQLite 内存库，MySQL 走专用变体，互不污染。
3. MySQL Gate 的账号与密码是公开的一次性本地测试常量，端口固定 3399。
4. `docs/DEV_TASKS.md` 中 W05.1 / W06.1 的历史 MySQL 实机验证与浏览器 Smoke 仍标注待补验。

## 五、解除 Pending 的路径

1. 在装有 Docker 或 Podman 的机器上执行 `scripts/test-mysql.sh all`。
2. 确认 `MYSQL84_GATE_DRIVER_PROVEN`、`STRUCTURE_ASSERT_OK`、`MYSQL84_GATE_TEST_PASS` 均出现且退出码为 0。
3. 确认 `down -v` 后无残留数据卷。
4. 按 `docs/V1_ACCEPTANCE_CHECKLIST.md` 第五节做最小状态更新。
5. 由发布负责人决定是否创建 v1.0 tag。

## 六、状态更新后的维护约定

本文件与配套清单只做**状态性**更新。任何一次修订都不得：

- 把未执行的项目写成已通过；
- 改写已记录的跑分、Gate 结论或非阻断项；
- 借修订之机扩写功能描述或修改代码、测试、Migration。
