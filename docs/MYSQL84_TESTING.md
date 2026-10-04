# MySQL 8.4 测试环境（Release Gate 用法）

本文件描述如何为 **DuplicateReview（D11）** 补做真实 MySQL 8.4 的 Release Gate 验证。

当前状态：**工具已整改并静态验收通过，runtime 仍不可用**。本机没有 Docker / Podman，也没有可安全使用的独立 MySQL 测试库凭据。按任务约束**不安装任何软件、不猜密码、不使用未知或生产数据库**，因此实测仍未执行。

标记：`MYSQL_TOOLING_REMEDIATED` + `READY_FOR_MYSQL_TOOLING_REREVIEW` + `MYSQL84_RUNTIME_UNAVAILABLE`
Release blocker `r1qL60` **保持开启**，`MYSQL_8_4_RELEASE_GATE_PENDING`。

---

## 1. 为什么 SQLite 不能替代

D11 的决策链依赖三处 MySQL 与 SQLite 行为差异，SQLite 全绿无法证明：

| # | 语义 | SQLite 的问题 |
| --- | --- | --- |
| 1 | `ContentItem::lockForUpdate()` 串行化 `decision_no` 分配 | SQLite 是整库写锁，行锁是否真正生效被掩盖 |
| 2 | `UniqueConstraintViolationException` → 受控 422 | 异常类型与触发时机与 MySQL 不同 |
| 3 | 三个跨表复合 FK + `ON DELETE RESTRICT` | MySQL 对复合 FK 目标索引前缀的要求与 SQLite 不同 |

已在 SQLite 完成的替代验证（作为基线，不是 Gate 通过）：全量 270 passed / 3207 assertions、migrate → rollback → migrate、`duplicate_review_decisions` 的 4 个复合外键与唯一索引落地确认。

## 2. 隔离设计

| 维度 | 取值 | 理由 |
| --- | --- | --- |
| 镜像 | `mysql:8.4` | 与目标环境一致 |
| 库名 | `qn_workbench_test` | 以 `_test` 结尾，视觉上不可能误认为业务库 |
| 账号 | `qn_test` | **不使用 root 作为 Laravel 测试用户** |
| 密码 | `qn_test_pw`（公开常量） | 本地一次性测试值，不是任何真实凭据 |
| 宿主机端口 | `3399`（仅绑 `127.0.0.1`） | 避开本机既有的 3306 / 3307 实例 |
| compose project | `qn-workbench-mysql84-gate` | `up` / `down` / `logs` 只作用于该 project，不影响其它容器 |
| 存储 | 容器 `tmpfs` | 数据不落宿主机磁盘，`down -v` 后无残留 |
| 生命周期 | `down -v` | 一次性，用完即销毁 |

## 3. 文件

| 文件 | 作用 |
| --- | --- |
| `docker-compose.mysql-test.yml` | disposable MySQL 8.4 服务定义，固定 project name |
| `.env.mysql-testing.example` | Laravel 侧测试环境变量样例（**无真实凭据**） |
| `phpunit.mysql84.xml` | **真跑 MySQL 的 PHPUnit 变体**（不复用主线 sqlite 配置） |
| `tests/bootstrap-mysql84-gate.php` | PHPUnit 启动前的双重闸门：配置逐项校验 + 真实 PDO 自证 |
| `tests/Gate/Mysql84DriverTest.php` | 测试进程内的连接自证（仅在变体下注册） |
| `scripts/test-mysql.sh` | 启停 + 校验 + 验证一体的执行脚本 |
| `docs/MYSQL84_TESTING.md` | 本文件 |

`.env.mysql-testing`（实际使用的副本，含本地测试密码）已加入 `.gitignore`，**绝不提交**。

## 4. 使用方法

前置条件：Docker（或 Podman，用 `CONTAINER_RUNTIME=podman` 覆盖）。脚本不会自动安装任何软件，runtime 缺失时以退出码 `127` 明确失败。

```bash
# 准备（一次性）
cp .env.mysql-testing.example .env.mysql-testing
php artisan key:generate --env=mysql-testing

# 一键：启动 → 校验 → migration → 定向测试 → 全量 suite → 销毁
scripts/test-mysql.sh all

# 或分步
scripts/test-mysql.sh up        # 启动并等待 healthy
scripts/test-mysql.sh verify    # 安全校验 + migration + 结构断言 + 定向测试
scripts/test-mysql.sh suite     # 完整 PHP suite（MySQL）
scripts/test-mysql.sh logs      # 查看容器日志
scripts/test-mysql.sh down      # 销毁容器与数据卷
```

脚本会先定位自身目录并切到 repo root，因此从任何工作目录调用都可用。

### 4.1 三道安全闸

**闸一：生效目标校验（`assert_effective_target`）**

`DB_URL` 必须**未设置或为空**——它一旦有值就会整体覆盖 host/port/database/username/password，使逐项校验失效。其余六项走 Laravel 实际生效的 `config()` 逐项比对，**不是 grep `.env` 文件**：文件里写对了不代表生效值对。

`DB_URL` 的判定以 **Laravel 的 `env()` 归一化之后**的形态为准，与 `phpunit.mysql84.xml`、`tests/bootstrap-mysql84-gate.php`、`scripts/test-mysql.sh` 三处完全一致：

| 写法 | 归一化后 | Gate |
| --- | --- | --- |
| 该行不存在 | `null` | 允许 |
| `DB_URL=` | `''` | 允许 |
| `DB_URL=null` / `NULL` / `(null)` | `null` | 允许 |
| `DB_URL=empty` / `(empty)` | `''` | 允许 |
| `DB_URL="  "`（纯空格） | `'  '` | **拒绝** |
| `DB_URL=mysql://root@host/db` | 原值 | **拒绝** |

纯空格算非空：Laravel 不会把 `" "` 归一化成空，它会当作一个无效但非空的 URL 交给底层驱动，错误现场会远离真正的原因。

```
DB_CONNECTION = mysql
DB_HOST       = 127.0.0.1
DB_PORT       = 3399
DB_DATABASE   = qn_workbench_test
DB_USERNAME   = qn_test
DB_PASSWORD   = 约定的本地测试常量（不匹配即中止，绝不拿它去连库）
DB_URL        = 未设置或为空（见上表）
```

在 `migrate:fresh`（破坏性操作）之前会**再验一次**，防止两轮之间配置被改。

**闸二：测试启动前（`tests/bootstrap-mysql84-gate.php`）**

这是修复「`phpunit.xml` 覆盖为 SQLite」的关键。Laravel 的 `<env>` 在测试启动时强制覆盖 `.env`，所以 `php artisan test --env=mysql-testing` 实际连的仍是 `:memory:` 内存库——全绿也证明不了任何 MySQL 行为。

bootstrap 在第一个用例之前做两件事：

1. 逐项断言生效 env 与约定目标一致，且 `DB_URL` 未设置或为空；
2. 真正建立 PDO 连接，向服务器问 `VERSION()` 与 `DATABASE()`，确认是 8.4.x 的 `qn_workbench_test`。

只有全部通过才打印 `MYSQL84_GATE_DRIVER_PROVEN`；任何一项不符立即以退出码 1 结束并打印 `MYSQL84_GATE_DRIVER_NOT_PROVEN`。**测试进程根本不会启动。**

**闸三：测试进程内（`tests/Gate/Mysql84DriverTest.php`）**

从 Laravel 实际建立的连接上再问一次 `VERSION()` / `DATABASE()` / `CURRENT_USER()`，并确认 `content_items` 引擎是 InnoDB（`lockForUpdate` 的前提）。

该类位于 `tests/Gate/`，**只在 `phpunit.mysql84.xml` 中注册**。主线 `phpunit.xml` 不含此目录，因此日常 SQLite 基线的测试数与断言数**零变化**（已实测：`--list-tests` 在主线配置下 Gate 测试数为 0）。

### 4.2 结构断言（`assert_structure`）

全部走 assert，任一不符即以非 0 退出，不允许"打印出来让人自己看"。这几项正是 SQLite 查不到、因而必须到 MySQL 上证明的部分：

| 断言 | 期望 | 为什么必须查 |
| --- | --- | --- |
| `VERSION()` | `8.4.*` | Gate 只认可 8.4 |
| 表引擎 | `InnoDB` | 行锁语义依赖 InnoDB |
| 表 collation | `utf8mb4_unicode_ci` | 与 `config/database.php` 声明一致 |
| `@@transaction_isolation` | `REPEATABLE-READ` | 决定 `lockForUpdate` 的实际行为，须核对而非假定 |
| 外键数量 | `4` | 含三个跨表复合 FK |
| `duplicate_review_pair_decision_no_unique` | 存在 **且 `NON_UNIQUE=0`** | 仅按名字命中一个非唯一索引不算通过 |

### 4.3 Migration 与定向测试

`scripts/test-mysql.sh verify` 依次执行：

1. 安全校验（生效 config + `DB_URL` 未设置或为空）
2. `migrate:fresh` —— 二次校验后全新库跑通全部 migration
3. 结构断言 —— 上表六项
4. `migrate:rollback` —— 全链回滚
5. `migrate` —— 重建
6. `DuplicateReviewApiTest` —— 定向验证 GET / POST / stale / latest_decision / decision_no / 404 / 422

### 4.4 并发与 unique race

D11 已有的 `test_insert_time_unique_race_returns_controlled_422_and_preserves_history` 用 `eloquent.creating` 事件在写入前插入一条同 `decision_no` 的记录，模拟竞态。在 MySQL 上重跑可验证：

- 唯一索引真实拦截重复编号
- 冲突被转成受控 422，而非 500
- 事务整体回滚，不产生半事务
- 已有 Decision 不被覆盖

`lockForUpdate()` 的实际生效可附带观察：同一 ContentItem 上并发两个请求，`decision_no` 应串行为 1、2 而非重复。

## 5. 失败传播

`all` 的完整 suite 未全绿时，`scripts/test-mysql.sh all` **最终返回非 0**——早期版本用 `|| echo` 把失败吞掉，那会让 Gate 报告出现假绿，现已移除。

清理由 `EXIT` trap 兜住，保证失败路径也会销毁容器；trap 内用 `rc=$?` 取原始退出码并原样传出，**不会因为做了清理就吞掉失败**。

退出码约定：

| 码 | 含义 |
| --- | --- |
| `0` | 全部通过 |
| `1` | 校验或测试未通过 |
| `2` | 未知子命令 |
| `127` | 容器 runtime 不可用（未安装 Docker / Podman） |

## 6. 全量套件失败的分类口径

若完整 suite 在 MySQL 下未全绿，**不得修改测试迎合**。按三类归因：

| 类别 | 判定依据 | 处理 |
| --- | --- | --- |
| MySQL 真实产品问题 | 同样语义在 SQLite 通过、MySQL 失败，且原因是约束/事务/字符集行为 | 修产品代码，并补 MySQL 专项测试 |
| 测试基础设施问题 | 失败源于 `RefreshDatabase`、迁移顺序或环境变量，而非业务断言 | 修测试基建，不动业务逻辑 |
| SQLite-specific 假设 | 测试显式依赖 SQLite 特性（如 `AUTOINCREMENT` 语法、无复合 FK 支持） | 改为方言中立写法，并注明原因 |

## 7. 安全红线执行情况

| 红线 | 执行情况 |
| --- | --- |
| 猜现有 MySQL 密码 | **未做**。探测到 3306 / 3307 有 MySQL 8.4 实例，仅读取了协议握手包确认版本，未尝试任何凭据 |
| 使用未知数据库 | **未做** |
| 使用生产数据库 | **未做** |
| 修改用户现有 MySQL 数据 | **未做**。未在任何既有实例上建库建号 |
| 使用 `.env` 中来源不明的数据库 | **未做**。正式 `.env` 指向 3306 的 `qn_ai_workbench`，本次完全未连接 |
| 把真实密码提交 Git | **未做**。仓库内只有公开测试常量；实际 env 已 gitignore |
| 未经允许安装系统级软件 | **未做**。Docker / Podman 缺失时未安装，脚本以 `127` 退出并说明原因 |

## 8. 解除 blocker 的条件

需同时满足：

1. 在装有 Docker 或 Podman 的环境执行 `scripts/test-mysql.sh all`，退出码为 `0`
2. 日志中同时出现 `MYSQL84_GATE_DRIVER_PROVEN` 与 `STRUCTURE_ASSERT_OK`
3. migration / rollback / re-migrate 全部通过，结构断言六项全部符合
4. `DuplicateReviewApiTest` 在 MySQL 下全绿（含 unique race 用例）
5. 完整 PHP suite 在 MySQL 下全绿，或所有失败均已按第 6 节分类并处置
6. 关闭事项 `r1qL60`

在此之前，`MYSQL_8_4_RELEASE_GATE_PASSED` **不得**标记。

## 9. 本机环境探测记录（2026-10-04）

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

## 10. 工具整改的静态验收记录（2026-10-04）

本轮针对复审指出的 5 类阻断做了整改，并在**无 runtime** 的条件下完成了可做的验证。

静态验收 **65/65** 通过（6 组：强安全目标校验 13、证明跑的是 MySQL 17、结构检查 assert 11、失败传播 8、隔离与路径 10、凭据卫生 6）。

`bash -n` 通过；`php -l` 通过；`phpunit.mysql84.xml` XML 良构且解析出 `Unit / Feature / Gate` 三个 testsuite。

拒绝路径实测（每条均以退出码 1 结束，断言逻辑真实执行）：

| 场景 | 结果 |
| --- | --- |
| `DB_CONNECTION=sqlite` | `[GATE-FAIL] DB_CONNECTION 期望 'mysql'` → `EXIT=1` |
| `DB_DATABASE=production_db` | `[GATE-FAIL] DB_DATABASE 期望 'qn_workbench_test'` → `EXIT=1` |
| 非空 `DB_URL` | `[GATE-FAIL] DB_URL=… 整体覆盖逐项校验` → `EXIT=1` |
| 误粘真实密码 | `[GATE-FAIL] DB_PASSWORD 不是约定的常量` → `EXIT=1` |
| 配置全对但无 MySQL 监听 | 7 项配置闸通过后止于 PDO，`GATE_DRIVER_PROVEN` 出现 **0** 次 → `EXIT=1` |
| 变体整体启动 | `vendor/bin/phpunit --configuration phpunit.mysql84.xml` → `EXIT=1`，测试未启动 |

退出码语义实测：`up` / `all` 在无 runtime 时为 `127`；校验失败为 `1`；未知子命令为 `2`。

隔离实测：`phpunit.xml --list-tests` 下 Gate 测试数为 **0**，主线 SQLite 基线不受影响。

### 11. 二次复审整改记录（2026-10-05，DEV-MYSQL84-TOOLING-FIX2）

复审确认的两个代码 blocker，均已修复且未扩大范围。

**Blocker 1：`Mysql84DriverTest` 缺 `use Illuminate\Support\Facades\DB;`**

该类位于 `namespace Tests\Gate`，缺 import 时 `DB::` 会解析到 `Tests\Gate\DB`——不存在的类，测试会以「Class not found」失败，且报错位置与真实原因无关。已补 import，并用 PHP 反射确认：`DB::` 现解析到 `Illuminate\Support\Facades\DB`，`class_exists` 为真且确为 `Facade` 子类。

**Blocker 2：`DB_URL` 空值语义三处不一致**

原实现用统一的 `readEnv()`，未命中时返回哨兵 `"<unset:DB_URL>"`，而判定条件是 `!== ''`——于是**「未设置」被误判为「非空」而假失败**。同时 `phpunit.mysql84.xml` 写的是 `value=""`，与「未设置」在语义上应等同却无人明确。

已拆成两个读取器：`readEnvStrict`（目标字段，未定义返回哨兵以触发期望值比较）与 `readEnvNullable`（`DB_URL`，未定义返回 `null` 即允许）。判定条件由 `$dbUrl !== ''` 改为 `$dbUrl !== null && $dbUrl !== ''`。

`scripts/test-mysql.sh` 侧同步：tinker 输出改为带 `__DB_URL__<null>` / `__DB_URL__<值>` 显式标记，`case` 精确匹配前两者为安全，避免原先把 `null` 折叠成空串的含糊判断。

语义实测（其余配置项均正确）：

| `DB_URL` 形态 | 结果 |
| --- | --- |
| 空字符串 | `[GATE-OK] DB_URL 未设置或为空` |
| 字面 `null` / `NULL` / `(null)` | `[GATE-OK] DB_URL 未设置或为空` |
| 字面 `empty` | `[GATE-OK] DB_URL 未设置或为空` |
| 真实 URL | `[GATE-FAIL] 非空` |
| 纯空格 `"   "` | `[GATE-FAIL] 非空` |

**防护未被削弱**：注入非空 `DB_URL` 到 `.env.mysql-testing` 后，`verify` 仍以 `EXIT=1` 拒绝；恢复空值后放行。env 文件层的 `^DB_URL=.+$` 本就只匹配非空行，未改动。

env 侧实测：`DB_URL=`（空值）放行并通过到 `config()` 层的 `DB_CONNECTION = mysql` 校验；非空则立即中止。

本轮同样未实跑 runtime：所有 `EXIT=1` 均止于 PDO 闸门（无 MySQL 监听），`MYSQL84_GATE_DRIVER_PROVEN` 出现 0 次。退出码语义复验不变：`up` / `all` = `127`，校验失败 = `1`，未知子命令 = `2`。
