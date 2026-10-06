# V1.0 验收清单（Acceptance Archive V2）

本清单只记录**已发生的事实**。任何未实际执行的项目一律标 `Pending`，不得写成 `Pass`。

| 项 | 值 |
| --- | --- |
| 验收归档基线 main SHA | `8e5d08b36fc3e4f3fa30b5f52ad06186b4b67a9c` |
| 基线 tree | `11da9ba33702ba3d6707af68d1a97acbc470c7f6` |
| 归档分支 | `docs/V1-acceptance-archive-v2`（基于上述 main，未合 main） |
| 归档日期 | 2026-10-06 |
| 结论 | **V1 技术验收 Gate 已全部通过；当前进入 Release Closeout，尚未创建 `v1.0` tag / GitHub Release。** |

> **关于基线 SHA 的口径**：上表记录的是**本归档创建时的正式 main 基线**，不是最终 release SHA。
> 最终 Release SHA 需在本清单与 Release / Deployment 文档全部合入 main、且 Final Release Review 通过之后另行锁定。

---

## 一、当前验收结论

V1 的三段状态必须分开看，不能互相替代：

| 阶段 | 状态 | 说明 |
| --- | --- | --- |
| **1. 技术验收** | ✅ **已完成** | SQLite、Golden Workflow、Browser Runtime、Auth、W09 / W10、MySQL 8.4 真实 Runtime、真并发证明、六栏目新物理路径读取验证，全部通过 |
| **2. Release Closeout** | 🔄 **进行中** | 本 Acceptance Archive V2 合入 main、Release / Deployment Docs 收口、最终 main SHA 锁定、Final Release Review |
| **3. 正式发布** | ⏳ **尚未发生** | tag = 0，GitHub Release = 0 |

**技术 Gate 已全部通过**；当前未完成的是**发布流程**，不是功能或数据库 Gate。

---

## 二、技术 Gate 总表

| # | Gate | 状态 | 证据摘要 |
| --- | --- | --- | --- |
| 1 | Auth Backend（DEV-AUTH-LITE） | ✅ Pass | 真实 API 联调覆盖 login / me / logout / guard / CSRF 419 / 限流 / open redirect / ProjectContext |
| 2 | Auth UI 真实接入（DEV-W11） | ✅ Pass | 前端接入真实后端，无 mock；Auth UI Passed |
| 3 | Golden E2E（V1 主链） | ✅ Pass | `tests/Feature/V1GoldenWorkflowTest.php` **2 passed / 300 assertions**；详见 `docs/V1_FINAL_E2E_ACCEPTANCE.md` |
| 4 | Browser Runtime（真实浏览器） | ✅ Pass | 真实 Edge + CDP 走通登录、刷新保持、UserMenu、ProjectContext、查重双栏、session 失效回登录、logout / relogin、1366 与 1920；console 零错误、无 401 风暴。marker：`FRONTEND_RUNTIME_REVIEW_PASSED` |
| 5 | 普通 SQLite 全量 | ✅ Pass | `php artisan test` **270 passed / 3209 assertions**，0 failure / error / skipped / risky / incomplete |
| 6 | DEV-W09 渠道素材绑定 UI | ✅ Pass | Migration 数量 0 |
| 7 | DEV-W10 查重复核 UI | ✅ Pass | 对真实 DEV-D11 API 验证 |
| 8 | **MySQL 8.4 Runtime Gate** | ✅ **Pass** | 真实 MySQL 8.4.11 Runtime 运行通过，详见第三节。marker：`MYSQL_8_4_RELEASE_GATE_PASSED` |
| 9 | **真并发 Runtime Proof** | ✅ **Pass** | 两个独立 worker / connection 的真实行锁等待已被数据库层证明，详见第四节。marker：`MYSQL84_CONCURRENCY_RUNTIME_PROOF_PASSED` |
| 10 | **六栏目当前物理路径迁移** | ✅ Pass | 26 / 26 Manifest 验证通过；286 / 286 来源文件真实打开读取成功，详见第六节 |
| 11 | **README Closeout** | ✅ Pass | 已复审 README 已进入 main `8e5d08b…`，详见第七节 |

> **统计口径互不合并**：Full SQLite `270 / 3209`、Golden Workflow `2 / 300`、MySQL Full `283 / 3281`、Golden Corpus `4 items / 37 pages / count 62` 是四个不同验收对象，不得相加或混计。

---

## 三、MySQL 8.4 Runtime Evidence

首次真实 Runtime 曾暴露 `MissingAppKeyException`。该问题已通过 APP_KEY Gate 修复，并在最终成功 Runtime 中证明：APP_KEY probe 通过、bootstrap 通过、worker 通过、日志与 artifact 中**无明文泄露**。**APP_KEY 不再是 blocker。**

### 3.1 运行标识

| 项 | 值 |
| --- | --- |
| Run | `37343124218` |
| Job | `111874925038`（job 名 `gate`） |
| Run attempt | 1 |
| Conclusion | `success` |
| Trigger commit | `4307691c711b25fc3b8b6608e34ae43887ccd84f` |
| Trigger 分支 | `ci/mysql84-runtime-gate`（临时分支） |
| MySQL 版本 | `8.4.11` |
| PHP 版本 | `8.4.26` |
| gate exit code | `0` |
| observer exit code | `0` |
| cleanup | verified（`down -v` 后无残留数据卷） |

### 3.2 测试结果

| 范围 | 结果 |
| --- | --- |
| DuplicateReview 定向 | **13 tests / 122 assertions** |
| Full MySQL | **283 tests / 3281 assertions** |
| errors | 0 |
| failures | 0 |
| skipped | 0 |
| risky | 0 |
| incomplete | 0 |

**最终结论：`MYSQL_8_4_RELEASE_GATE_PASSED`。**

### 3.3 Runtime markers

- `MYSQL84_GATE_DRIVER_PROVEN`
- `STRUCTURE_ASSERT_OK`
- `MYSQL84_GATE_TEST_PASS`

### 3.4 结构验收事实

- 存储引擎 `InnoDB`
- 排序规则 `utf8mb4_unicode_ci`
- 隔离级别 `REPEATABLE-READ`
- 4 个外键
- `duplicate_review_decisions` 的 pair + `decision_no` 五字段 UNIQUE 正确
- `fresh migrate` → structure assertions → rollback → re-migrate 全链通过

### 3.5 Artifact 证据

| 项 | 值 |
| --- | --- |
| Artifact ID | `11359456691` |
| 名称 | `mysql84-gate-4307691c711b25fc3b8b6608e34ae43887ccd84f-1` |
| Digest | `sha256:2886a0b9547a3f8be7f54498a0fabcee5812bd2b596439996f5c3cd2ec6d8268` |
| 内容 | 10 个日志文件：Gate、environment、MySQL server、exit codes、cleanup |

**已确认不含**：`.env`、APP_KEY 明文、DB dump、敏感凭据。

### 3.6 临时 CI workflow 的处置

真实 Runtime 使用了临时 workflow `.github/workflows/mysql84-runtime-gate.yml`。该 workflow **没有进入 main**（已复核：main 中不存在，CI 分支上存在）。

这是**正确结果**：Runtime 证据依赖它，但正式 main 不保留临时验收脚手架。

详细 Gate 设计与安全边界见 `docs/MYSQL84_TESTING.md`。

---

## 四、真并发 Runtime Proof

并发结论**不是**「测试通过」，而是已在真实 MySQL Runtime 中取得数据库层证据：

- 两个独立 worker / connection
- A 持有 row lock
- B 进入**真实 lock wait**
- `performance_schema.data_lock_waits` 精确证明 B requesting → A blocking
- NOWAIT 返回 MySQL `3572`
- release 后 `decision_no = [1, 2]`
- 两个不同 Decision ID
- latest = #2
- worker 返回 ID 与数据库一致
- cleanup 完成

**结论：`MYSQL84_CONCURRENCY_RUNTIME_PROOF_PASSED`。**

> 具体动态 connection ID 属运行期细节，不在归档中固定记录。

---

## 五、已知非阻断边界

均不影响上述 Gate 结论，也不要求在 Release Closeout 之前处理。

1. **无项目级 RBAC**：V1 没有项目级成员或角色授权，所有已登录用户都可访问所有 Project。`Project` 是**数据作用域边界**，不是**权限授权边界**；`current_project_id` 只决定作用域。属已知边界，不是当前 Release blocker。
2. **不调用真实微信 API**：渠道 Task 的 publish 仅为工作台内部状态推进。
3. **Asset 为 filesystem-local / metadata-only**：`assets` / `asset_versions` 仅保存槽位与 `file_id` 引用，不含对象存储集成；`FILESYSTEM_DISK` 默认 `local`。
4. **SourceReference 不负责通用上传 / 同步**：常规 SourceReference 保存来源身份与相对引用；历史 importer 另有当前物理路径解析与可读取性验证（见第六节）。
5. **查重阈值仍需人工判断**：3-gram Jaccard 只作候选方法。
6. 419 登录页 UI 提示文案为人工复核项（服务端行为已实测）。
7. 主线 `phpunit.xml` 为 SQLite 内存库，MySQL 走专用变体 `phpunit.mysql84.xml`，两者互不污染。
8. MySQL Gate 使用公开的一次性本地测试常量与端口 `3399`，不涉及任何真实凭据。

**PHP 运行时要求**：`composer.lock` 生产依赖的实际平台要求为 **PHP >= 8.4.1**（`composer.json` 的宽松声明不代表 lock 下的真实要求）。部署前后请执行：

```sh
composer check-platform-reqs --lock --no-dev
composer check-platform-reqs --no-dev
```

---

## 六、六栏目当前物理路径迁移 ✅

已进入 main：`60085650e72eb6cdd46a082667a1f95c63fc1c09`

| 事实 | 值 |
| --- | --- |
| 当前物理根 | `2.5D家庭IP形象/01_栏目内容项目/` |
| 历史 `source_path` | **身份不变**（不因文件移动而改写） |
| 当前物理读取 | 由 `YujianHistoryManifest::currentSourcePath()` 解析 |
| Manifest 验证 | **26 / 26** 通过 |
| 来源文件真实读取 | **286 / 286** 成功 |
| 旧栏目根目录 | **6 个全部不存在** |
| symlink / junction / 兼容 fallback | **0** |
| Database / Migration 变更 | **0** |

> 历史来源身份与当前物理位置分离的口径见 `docs/DEV-D07_SOURCE_PROVENANCE_AUDIT.md`。

---

## 七、README Closeout ✅

已复审 README 已进入 main：`8e5d08b36fc3e4f3fa30b5f52ad06186b4b67a9c`

README 当前口径：技术验收完成、MySQL 8.4 Runtime Gate = Passed、Release Closeout Pending、PHP >= 8.4.1、SQLite 270 / 3209、**未 tag / 未 Release**。

---

## 八、main 集成历史

当前 main 已依次包含：

| 内容 | 提交 |
| --- | --- |
| MySQL 8.4 permanent Runtime-proven baseline | `459cca0e…` |
| 六栏目集中路径解析 | `60085650…` |
| Reviewed README Closeout | `8e5d08b…` |

---

## 九、正式发布前剩余条件

以下为**发布流程剩余条件**，不是功能或数据库 Gate Pending：

1. 当前 main 上 final smoke / consistency review 通过
2. 最终 main SHA 锁定
3. Final Release Review = Pass
4. 发布负责人创建 `v1.0` tag
5. 创建 GitHub Release，且 tag 与 Release 指向同一个最终 SHA

> Acceptance Archive V2 已进入 main `3c799c7a6cab2e5813d40b27a4ad240ef169814d`；Release / Deployment Docs 为当前 Closeout 文档阶段。
> tag 与 GitHub Release 是**最后动作**。本归档不创建 tag，也不创建 Release。

---

## 十、最终发布条件

正式发布至少要求第九节 1–5 全部成立。其中第 2–5 项（最终 SHA 锁定、Final Review、`v1.0`、GitHub Release）**尚未满足**，因此当前不得宣布已发布。

---

## 十一、维护约定

本清单与 `docs/V1_RELEASE_DELIVERY.md` 只做**状态性**更新。任何一次修订都不得：

- 把未执行的项目写成已通过；
- 改写已记录的跑分、Gate 结论或非阻断项；
- 借修订之机扩写功能描述或修改代码、测试、Migration；
- 在 Final Release Review 之前写入最终 release SHA。
