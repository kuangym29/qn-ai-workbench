# V1.0 交付说明（Release Delivery）

本文件说明 V1.0 当前交付到什么程度、包含什么、以及还差什么。**不含推测性结论**：未执行的项目一律写 Pending。

| 项 | 值 |
| --- | --- |
| 交付归档基线 main SHA | `8e5d08b36fc3e4f3fa30b5f52ad06186b4b67a9c` |
| 基线 tree | `11da9ba33702ba3d6707af68d1a97acbc470c7f6` |
| 归档分支 | `docs/V1-acceptance-archive-v2`（基于上述 main，未合 main） |
| 归档日期 | 2026-10-06 |
| **当前发布状态** | **技术验收已通过；Release Closeout 进行中；尚未正式发布。** |
| 配套清单 | [`docs/V1_ACCEPTANCE_CHECKLIST.md`](docs/V1_ACCEPTANCE_CHECKLIST.md) |

> 不使用「可发布 / 不可发布」的二元判断：当前既无 final release SHA，也无 `v1.0` tag 与 GitHub Release，三者缺一即不构成正式发布。

---

## 一、当前为何尚未正式发布

**原因是 Release Closeout 尚未完成，而不是技术 Gate 未通过。**

技术侧已经没有待决 Gate：

- MySQL 8.4 Runtime Gate 已在真实 MySQL 8.4.11 环境通过（Run `37343124218` / Job `111874925038`，Full MySQL `283 tests / 3281 assertions`，gate 与 observer 退出码均为 `0`，cleanup verified）
- 真并发 Runtime Proof 已通过（`performance_schema.data_lock_waits` 证明真实行锁等待）
- README 已收口并进入 main
- 本 Acceptance Archive 正在收口

仍待完成的是发布流程：Release / Deployment Docs 收口、最终 main SHA 锁定、Final Release Review、`v1.0` tag 与 GitHub Release。

完整证据见 [`docs/V1_ACCEPTANCE_CHECKLIST.md`](docs/V1_ACCEPTANCE_CHECKLIST.md) 与 [`docs/MYSQL84_TESTING.md`](docs/MYSQL84_TESTING.md)。

---

## 二、已交付范围

**已合入 main 并通过验证：**

| 能力 | 说明 |
| --- | --- |
| Auth Lite | Laravel `web` guard + 服务端 Session + 同源 CSRF；登录限流、Session 轮换与失效、guest 401 JSON / 页面跳登录 |
| Formal Copy / Revision | `ContentPage` 稳定页身份、`ContentPageVersion` append-only、`ContentCopyRevision` 整篇确认 |
| Duplicate Review | 分层候选 + 人工决策，`lockForUpdate()` 串行编号，历史保留不倒退 |
| Production Task | 任务绑定当前正式 Revision，换版与整链重置语义明确，四个状态维度互不推导 |
| Shared Asset / AssetVersion | 按 Task + Page + Role 的稳定槽位与 append-only 版本 |
| Channel Asset Binding | 渠道绑定共享资产版本，角色绑定方向受约束，绑定不完整时拦截发布与视频审核 |
| Channel Task | 公众号 / 视频号三维状态独立推进，支持排期与发布确认 |
| Source Reference | 按 Project 或 ContentItem 管理来源引用与相对路径 |
| Golden Workflow | `tests/Feature/V1GoldenWorkflowTest.php` 2 passed / 300 assertions |
| MySQL 8.4 permanent Runtime-proven baseline | 真实 MySQL 8.4.11 Runtime 通过的永久基线与 Gate 工具链 |
| 六栏目集中路径解析 | 历史 `source_path` 身份不变，当前物理读取经 `currentSourcePath()` 解析 |
| Reviewed README | 已复审 README 已进入 main `8e5d08b…` |

**已合入 main 且已在真实 MySQL 8.4 Runtime 中通过验证的 Gate 工具链：**

`scripts/test-mysql.sh`（正式入口 `scripts/test-mysql.sh all`）、`docker-compose.mysql-test.yml`、`phpunit.mysql84.xml`、`tests/bootstrap-mysql84-gate.php`、`.env.mysql-testing.example`，操作与安全边界见 [`docs/MYSQL84_TESTING.md`](docs/MYSQL84_TESTING.md)。

**普通测试基线**：`php artisan test` 为 SQLite 内存库，当前 **270 passed / 3209 assertions**。它是开发回归基线，**不构成真实 MySQL 证明**；数据库结论以第三节的 Runtime 证据为准。

---

## 三、MySQL Gate 与历史来源读取

- 真实 Runtime：Run `37343124218` / Job `111874925038` / Trigger `4307691c…`；MySQL `8.4.11`、PHP `8.4.26`；DuplicateReview 定向 `13 / 122`、Full MySQL `283 / 3281`；errors / failures / skipped / risky / incomplete 全为 0；gate 与 observer 退出码 `0`；cleanup verified。结论 marker：`MYSQL_8_4_RELEASE_GATE_PASSED`。
- Artifact `11359456691`，digest `sha256:2886a0b9547a3f8be7f54498a0fabcee5812bd2b596439996f5c3cd2ec6d8268`；已确认不含 `.env`、APP_KEY 明文、DB dump 与敏感凭据。
- 真实 Runtime 使用的临时 workflow `.github/workflows/mysql84-runtime-gate.yml` **未进入 main**，这是正确结果。
- 历史来源读取：26 / 26 Manifest 通过，286 / 286 来源文件真实打开读取成功；旧六栏目根目录已不存在且无 symlink / junction / 兼容入口；Database / Migration 变更 0。

---

## 四、交付边界

以下内容**不在** V1.0 范围内，文档与代码均未声称支持：

- 调用真实微信 API 发布；渠道 publish 仅为工作台内部状态推进。
- **文件上传与图片预览**：`assets` / `asset_versions` 只保存槽位与 `file_id` 引用，无上传入口、无对象存储集成（已按当前 main 实现复核）。
- 渠道素材绑定的删除、编辑与回滚——绑定只是引用。
- 云端发布自动化。
- 生产环境部署与运维配置（见第六节）。

**SourceReference 的分层口径**：常规 SourceReference 负责保存来源身份与相对引用，不上传、不同步文件；特定历史导入流程会按 Manifest 解析当前物理位置并验证源文件可读取。

---

## 五、已知非阻断项

1. **无项目级 RBAC**：所有已登录用户可访问所有 Project。`Project` 是**数据作用域边界**，不是**权限授权边界**。
2. **Asset filesystem-local / metadata-only**：`FILESYSTEM_DISK` 默认 `local`，不含对象存储与版本化文件服务。
3. 查重阈值仍需人工判断，3-gram Jaccard 只作候选方法。
4. 419 登录页 UI 提示文案为人工复核项（服务端行为已实测）。
5. 主线测试为 SQLite 内存库，MySQL 走 `phpunit.mysql84.xml`，互不污染。
6. MySQL Gate 的账号与密码是公开的一次性本地测试常量，端口固定 `3399`。

---

## 六、部署边界与提示

更完整的 Deployment Checklist 将在后续 Closeout 包中提供，**当前尚未进入 main，因此本文件不链接它**。README 已包含基础部署提示。

部署前置（简要）：

- 全站 HTTPS；
- 首次部署生成 `APP_KEY` 并**长期保留**，生产升级不得重新生成；
- 生产数据库不使用 root 账号或空密码；
- 先备份再执行 `php artisan migrate`；**生产环境禁止 `migrate:fresh`**；
- 前端执行正式构建 `npm run build`；
- **PHP >= 8.4.1**（以 `composer.lock` 实际生产依赖要求为准），部署前后执行 `composer check-platform-reqs --lock --no-dev` 与 `composer check-platform-reqs --no-dev`。

---

## 七、Release Closeout 待完成项

1. Acceptance Archive V2 审核并进入 main
2. Release / Deployment Docs 审核并进入 main
3. 当前 main 上 final smoke / consistency review 通过
4. 最终 main SHA 锁定
5. Final Release Review = Pass
6. 发布负责人决定创建 `v1.0` tag
7. 创建 GitHub Release，tag 与 Release 指向同一最终 SHA

> 当前 tag = 0、GitHub Release = 0。**本文件不创建 tag，也不创建 Release。**

---

## 八、维护约定

本文件与 [`docs/V1_ACCEPTANCE_CHECKLIST.md`](docs/V1_ACCEPTANCE_CHECKLIST.md) 只做**状态性**更新。任何一次修订都不得：

- 把未执行的项目写成已通过；
- 改写已记录的跑分、Gate 结论或非阻断项；
- 借修订之机扩写功能描述或修改代码、测试、Migration；
- 在 Final Release Review 之前写入最终 release SHA。
