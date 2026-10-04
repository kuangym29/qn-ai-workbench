# MySQL 8.4 测试环境（Release Gate 用法）

本文件描述如何为 **DuplicateReview（D11）** 补做真实 MySQL 8.4 的 Release Gate 验证。

当前状态：**配置已就绪，runtime 不可用**。本机没有 Docker / Podman，也没有可安全使用的独立 MySQL 测试库凭据。按任务约束**不安装任何软件、不猜密码、不使用未知或生产数据库**，因此实测未能执行。

标记：`MYSQL_8_4_TEST_ENV_READY` + `MYSQL84_RUNTIME_UNAVAILABLE`
Release blocker `r1qL60` **保持开启**。

---

## 1. 为什么 SQLite 不能替代

D11 的决策链依赖三处 MySQL 与 SQLite 行为差异，SQLite 全绿无法证明：

| # | 语义 | SQLite 的问题 |
| --- | --- | --- |
| 1 | `ContentItem::lockForUpdate()` 串行化 `decision_no` 分配 | SQLite 是整库写锁，行锁是否真正生效被掩盖 |
| 2 | `UniqueConstraintViolationException` → 受控 422 | 异常类型与触发时机与 MySQL 不同 |
| 3 | 三个跨表复合 FK + `ON DELETE RESTRICT` | MySQL 对复合 FK 目标索引前缀的要求与 SQLite 不同 |

已在 SQLite 完成的替代验证（作为基线，不是 Gate 通过）：全量 245 passed / 2482 assertions、migrate → rollback → migrate、`duplicate_review_decisions` 的 4 个复合外键与唯一索引落地确认。

## 2. 隔离设计

| 维度 | 取值 | 理由 |
| --- | --- | --- |
| 镜像 | `mysql:8.4` | 与目标环境一致 |
| 库名 | `qn_workbench_test` | 以 `_test` 结尾，视觉上不可能误认为业务库 |
| 账号 | `qn_test` | **不使用 root 作为 Laravel 测试用户** |
| 密码 | `qn_test_pw`（公开常量） | 本地一次性测试值，不是任何真实凭据 |
| 宿主机端口 | `3399` | 避开本机既有的 3306 / 3307 实例 |
| 存储 | 容器 `tmpfs` | 数据不落宿主机磁盘，`down -v` 后无残留 |
| 生命周期 | `--rm` / `down -v` | 一次性，用完即销毁 |

## 3. 文件

| 文件 | 作用 |
| --- | --- |
| `docker-compose.mysql-test.yml` | disposable MySQL 8.4 服务定义 |
| `.env.mysql-testing.example` | Laravel 侧测试环境变量样例（**无真实凭据**） |
| `scripts/test-mysql.sh` | 启停 + 验证一体的执行脚本 |
| `docs/MYSQL84_TESTING.md` | 本文件 |

`.env.mysql-testing`（实际使用的副本，含本地测试密码）已加入 `.gitignore`，**绝不提交**。

## 4. 使用方法

前置条件：Docker（或 Podman，用 `CONTAINER_RUNTIME=podman` 覆盖）。脚本不会自动安装任何软件。

```bash
# 一键：启动 → 验证 → 销毁
scripts/test-mysql.sh all

# 或分步
scripts/test-mysql.sh up        # 启动并等待 healthy
cp .env.mysql-testing.example .env.mysql-testing
scripts/test-mysql.sh verify    # migration + 定向测试
scripts/test-mysql.sh suite     # 完整 PHP suite
scripts/test-mysql.sh down      # 销毁容器与数据卷
```

### 4.1 Migration 验证内容

`scripts/test-mysql.sh verify` 依次执行：

1. `migrate:fresh` —— 全新库跑通全部 24 个 migration
2. 结构核对 —— 直接查 `information_schema`，确认：
   - `duplicate_review_decisions` 的外键数量（应为 4）
   - `duplicate_review_pair_decision_no_unique` 索引存在
   - 引擎为 `InnoDB`、collation 为 `utf8mb4_unicode_ci`
3. `migrate:rollback` —— 全链回滚
4. `migrate` —— 重建
5. `DuplicateReviewApiTest` —— 定向验证 GET / POST / stale / latest_decision / decision_no / 404 / 422

第 2 步是本任务的核心：这三项在 SQLite 上根本查不到 `information_schema`，无法证明。

### 4.2 并发与 unique race

D11 已有的 `test_insert_time_unique_race_returns_controlled_422_and_preserves_history` 用 `eloquent.creating` 事件在写入前插入一条同 `decision_no` 的记录，模拟竞态。在 MySQL 上重跑可验证：

- 唯一索引真实拦截重复编号
- 冲突被转成受控 422，而非 500
- 事务整体回滚，不产生半事务
- 已有 Decision 不被覆盖

`lockForUpdate()` 的实际生效可附带观察：同一 ContentItem 上并发两个请求，`decision_no` 应串行为 1、2 而非重复。

## 5. 全量套件失败的分类口径

若完整 `php artisan test --env=mysql-testing` 未全绿，**不得修改测试迎合**。按三类归因：

| 类别 | 判定依据 | 处理 |
| --- | --- | --- |
| MySQL 真实产品问题 | 同样语义在 SQLite 通过、MySQL 失败，且原因是约束/事务/字符集行为 | 修产品代码，并补 MySQL 专项测试 |
| 测试基础设施问题 | 失败源于 `RefreshDatabase`、迁移顺序或环境变量，而非业务断言 | 修测试基建，不动业务逻辑 |
| SQLite-specific 假设 | 测试显式依赖 SQLite 特性（如 `AUTOINCREMENT` 语法、无复合 FK 支持） | 改为方言中立写法，并注明原因 |

## 6. 安全红线执行情况

| 红线 | 执行情况 |
| --- | --- |
| 猜现有 MySQL 密码 | **未做**。探测到 3306 / 3307 有 MySQL 8.4 实例，仅读取了协议握手包确认版本，未尝试任何凭据 |
| 使用未知数据库 | **未做** |
| 使用生产数据库 | **未做** |
| 修改用户现有 MySQL 数据 | **未做**。未在任何既有实例上建库建号 |
| 使用 `.env` 中来源不明的数据库 | **未做**。正式 `.env` 指向 3306 的 `qn_ai_workbench`，本次完全未连接 |
| 把真实密码提交 Git | **未做**。仓库内只有公开测试常量；实际 env 已 gitignore |
| 未经允许安装系统级软件 | **未做**。Docker / Podman 缺失时未安装，直接走配置交付路径 |

## 7. 解除 blocker 的条件

需同时满足：

1. 在装有 Docker 或 Podman 的环境执行 `scripts/test-mysql.sh all`
2. migration / rollback / re-migrate 全部通过，`information_schema` 核对项全部符合
3. `DuplicateReviewApiTest` 在 MySQL 下全绿（含 unique race 用例）
4. 完整 PHP suite 在 MySQL 下全绿，或所有失败均已按第 5 节分类并处置
5. 关闭事项 `r1qL60`

在此之前，`MYSQL_8_4_RELEASE_GATE_PASSED` **不得**标记。

## 8. 本机环境探测记录（2026-10-04）

| 项 | 结果 |
| --- | --- |
| Docker | 不在 PATH，`C:\Program Files\Docker` 不存在 |
| Podman | 不在 PATH，`C:\Program Files\RedHat\Podman` 不存在 |
| WSL | 存在但被本机安全策略阻断，无法作为容器运行时 |
| 本地 `mysqld.exe` | 在 `Program Files` / `Program Files (x86)` / `ProgramData` 下均未找到 |
| 3306 端口 | 有 MySQL **8.4.11** 在监听，但无本项目测试库凭据 |
| 3307 端口 | 有 MySQL **8.4.0** 在监听，来源不明，不可用 |
| 仓库内凭据文件 | 无 `.env.testing` / `.env.mysql`；`.env.example` 的 root 空密码连接被拒 |
| 文档记录 | `docs/DATABASE_BASELINE.md:39` 已记载同一阻塞在 DEV-010A 阶段即存在 |

两个既有实例均**不属于**本项目可安全使用的测试环境，因此未尝试连接。
