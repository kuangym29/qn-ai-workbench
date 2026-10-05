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
| `tests/Gate/Mysql84ConcurrencyTest.php` | 真实并发 Gate：两个独立进程争夺同一 ContentItem 的行锁（见 §4.5） |
| `tests/Gate/Support/ConcurrencyRuntimeDirectory.php` | 两 worker 之间的编排信号（原子写入 + 轮询等待） |
| `tests/Gate/Support/ConcurrencyWorkerProcess.php` | 独立 worker 进程的启动、输出采集与超时回收 |
| `tests/Gate/Support/LockWaitObserver.php` | 从 `performance_schema` 观测「谁在等谁」的证据采集 |
| `tests/Gate/Support/GateInfrastructureUnavailable.php` | 观测能力缺失时抛出的异常（必须 FAIL，不得降级） |
| `tests/Gate/Support/WorkerPauseGate.php` | A 的暂停闸门：只有显式放行才继续，超时抛异常（不碰 IO，可单测） |
| `tests/Gate/Support/PauseWatchdogTimeout.php` | watchdog 超时异常；抛出即意味着事务必须回滚 |
| `tests/Gate/LockWaitProbeErrorClassificationTest.php` | NOWAIT 错误分类回归检查：只有 driver code 3572 算锁冲突 |
| `tests/Gate/WorkerPauseWatchdogTest.php` | watchdog 语义回归检查：超时必须抛异常而非放行 |
| `tests/Gate/Support/concurrency_worker.php` | worker 入口脚本：独立 bootstrap + 真实调用 `appendDecision()` |
| `scripts/test-mysql.sh` | 启停 + 校验 + 验证一体的执行脚本 |
| `docs/MYSQL84_TESTING.md` | 本文件 |

`.env.mysql-testing`（实际使用的副本，含本地测试密码）已加入 `.gitignore`，**绝不提交**。

## 4. 使用方法

前置条件：Docker（或 Podman，用 `CONTAINER_RUNTIME=podman` 覆盖）。脚本不会自动安装任何软件，runtime 缺失时以退出码 `127` 明确失败。

```bash
# 准备（一次性）
cp .env.mysql-testing.example .env.mysql-testing
php artisan key:generate --env=mysql-testing

# 一键：启动 → 授权 → 校验 → migration → 定向测试 → 全量 suite → 销毁
scripts/test-mysql.sh all

# 或分步
scripts/test-mysql.sh up          # 启动并等待 healthy
scripts/test-mysql.sh grant       # 给 qn_test 补 performance_schema 只读权限（§4.5 并发 Gate 必需）
scripts/test-mysql.sh verify      # 安全校验 + migration + 结构断言 + 定向测试
scripts/test-mysql.sh concurrency # 只跑并发 Gate
scripts/test-mysql.sh suite       # 完整 PHP suite（MySQL）
scripts/test-mysql.sh logs        # 查看容器日志
scripts/test-mysql.sh down        # 销毁容器与数据卷
```

脚本会先定位自身目录并切到 repo root，因此从任何工作目录调用都可用。

### 4.1 三道安全闸

**闸一：生效目标校验（`assert_effective_target`）**

`DB_URL` 必须**未设置或为空**——它一旦有值就会整体覆盖 host/port/database/username/password，使逐项校验失效。其余六项走 Laravel 实际生效的 `config()` 逐项比对，**不是 grep `.env` 文件**：文件里写对了不代表生效值对。

`DB_URL` 的判定以 **Laravel 的 `env()` 归一化之后**的形态为准，与 `scripts/test-mysql.sh`、`tests/bootstrap-mysql84-gate.php`、`tests/Gate/Mysql84DriverTest.php`、`phpunit.mysql84.xml` / `.env.mysql-testing.example` 四处完全一致（判定条件统一为「只允许 `null` 或 `''`」）：

| 写法 | 归一化后 | Gate |
| --- | --- | --- |
| 该行不存在 | `null` | 允许 |
| `DB_URL=` | `''` | 允许 |
| `DB_URL=null` / `NULL` / `(null)` | `null` | 允许 |
| `DB_URL=empty` / `(empty)` | `''` | 允许 |
| `DB_URL="  "`（纯空格） | `'  '` | **拒绝** |
| `DB_URL=mysql://root@host/db` | 原值 | **拒绝** |

纯空格算非空：Laravel 不会把 `" "` 归一化成空，它会当作一个无效但非空的 URL 交给底层驱动，错误现场会远离真正的原因。

`.env.mysql-testing` 的文件层预检（`assert_env_file`）用的是**同一张表**：逐行取出 `DB_URL=` 的值，按同样的归一化判定，而不是看 `=` 后面有没有字符。出现多行 `DB_URL` 时按 fail-closed 处理——任一行不安全即拒绝，不去猜哪一行生效。

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

同一处还会复核生效配置里的 `DB_URL`：只允许 `null` 或 `''`，任何其它非空值（含纯空格）即失败。这条断言与闸一使用的是**同一套**空值语义，不会出现「shell 放行、测试拒绝」或反之的错位。

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

`lockForUpdate()` 的实际生效不再只是"附带观察"：§4.5 用一个独立的 Gate 测试专门验证它。

### 4.5 并发 Gate：真实行锁串行化（`tests/Gate/Mysql84ConcurrencyTest.php`）

#### 为什么需要单独一条测试

上面的 race 测试是**注入式**的：它在 `eloquent.creating` 里手动插一条同号记录来制造冲突，因此只能证明「唯一索引拦得住」，证明不了「正常路径下两个会话不会同时走到 INSERT」。后者才是 Release Gate 关心的——它依赖 InnoDB 行锁真的把第二个会话挂起。

SQLite 层面那套「先 sleep 再让第二个调用执行」在这里毫无意义：同一进程里开两次调用只有一个会话，根本不会形成锁等待。所以这条测试必须用**两个独立 PHP 进程**。

#### 并发协调机制

| 环节 | 做法 |
| --- | --- |
| 进程隔离 | `ConcurrencyWorkerProcess` 用 `PHP_BINARY` 拉起子进程；每个 worker 各自 `bootstrap/app.php` → Console Kernel → 自己的 PDO 连接，MySQL 侧是独立的 `CONNECTION_ID()` |
| 编排信号 | `ConcurrencyRuntimeDirectory` 在系统临时目录建随机运行目录，用**原子写入（临时文件 + rename）**的信号文件协调；信号只管编排（谁启动、何时释放），不参与任何"并发是否成立"的判定 |
| 暂停窗口 | worker A 注册 `eloquent.creating: App\Models\DuplicateReviewDecision` 监听，在回调里写 `paused.json` 并阻塞等待 `release-A`；此刻 A 已拿到 `content_items` 行锁、编号算完，但 INSERT 未发生、事务未提交 |
| B 的启动时机 | orchestrator 只在读到 `paused.json` 之后才启动 worker B；worker B 自己也会再确认一次，看不到信号就拒绝执行（避免退化成顺序执行） |
| 释放 | orchestrator 观测到锁等待后写 `release-A`；A 提交 #1，B 随即获锁并读到 #1，算出 #2 |
| 兜底 | A 的暂停是一道**只能由父进程显式放行**的闸门（`WorkerPauseGate`）：watchdog 到期不会当成释放，而是抛 `PauseWatchdogTimeout`，异常冒泡出 `DB::transaction` 闭包 → 事务回滚、worker 非 0 退出、不 INSERT 不 COMMIT；worker 最终一律被 `kill()` 回收，不会留下占锁的活事务 |

#### 如何证明真实 lock wait

三条证据按强弱排列。**任何一条观测不到即 FAIL，绝不降级或跳过。**

1. **决定性证据**：`performance_schema.data_lock_waits` 出现 `requesting = worker B 的 PROCESSLIST_ID`、`blocking = worker A 的 PROCESSLIST_ID` 的记录。这条记录是 InnoDB 真的把 B 挂起时才写入的，且这里是**精确匹配双向**，不是"存在任何等待就行"。
   连接身份来自各 worker 自己上报的 `SELECT CONNECTION_ID()`，经 `performance_schema.threads` 与 `THREAD_ID` 关联。
2. **行锁确实存在**：A 暂停期间，orchestrator 用第三条连接执行 `SELECT id FROM content_items WHERE id = ? FOR UPDATE NOWAIT`，撞出 MySQL **driver code 3572** 才算通过；同时检查 `data_locks` 里 A 已 `GRANTED` 的锁。若 NOWAIT 竟然抢到了，说明排他锁不存在，直接失败。
   判定**只认 driver code 3572**（取自 PDO `errorInfo[1]`），不看 message 文本：`QueryException` 的 message 会带上原始 SQL，而 SQL 本身含 `NOWAIT`，用文本匹配会把 1142 权限错误、1064 语法错误、2006 连接错误一并误判成「锁成立」。SQLSTATE 同样不可用——3572 与 2006 都是 `HY000`。这条规则由 `LockWaitProbeErrorClassificationTest` 钉住，不需要真实数据库即可回归。
3. **时间旁证**：B 的 `call_started_at` 早于 A 的 `resumed_at`，而 B 的 `lock_returned_at` 晚于 A 的 `resumed_at`——说明 B 确实被堵了一段时间。**单靠时间推断无效**，它只在 1、2 成立时作为旁证记录。

#### 需要的额外 MySQL 权限

需要 `qn_test` 对 `performance_schema.*` 的 `SELECT` 权限。官方镜像默认不给（只给业务库权限）。

- 自动：`scripts/test-mysql.sh grant` 会用 root 授权，并**立即以 `qn_test` 身份回读一次自检**；`all` 流程已内置这一步。
- 手工：`docker compose -p qn-workbench-mysql84-gate -f docker-compose.mysql-test.yml exec -T mysql-test mysql -uroot -pqn_test_root_pw -e "GRANT SELECT ON performance_schema.* TO 'qn_test'@'%';"`
- 权限不足时的行为：`LockWaitObserver::assertObservable()` 抛 `GateInfrastructureUnavailable`，测试以**失败**结束并在报告里打印上面的授权命令。**不会**跳过、不会退化成顺序执行、不会给出"通过"。

#### 最终断言

- 两个 worker 都正常退出（exit code 0），没有 500 / 未捕获异常；
- worker B 没有走到 `UniqueConstraintViolationException → 422` 那条受控分支（走到就说明编号没被串行化）；
- 同一 pair 恰好 2 条 Decision，`decision_no` 严格为 `[1, 2]`，两条 ID 不同；
- 两次 `decision` / `note` 均保留，`#1` 未被覆盖，latest 为 `#2`；
- A、B 是两个不同的 MySQL 会话（`CONNECTION_ID` 不同）；
- 三条证据全部成立。

#### 与 SQLite suite 的隔离

本测试只在 `phpunit.mysql84.xml` 的 `Gate` testsuite 下注册。主线 `phpunit.xml` 不含 `tests/Gate` 目录，普通 SQLite suite 的测试数与断言数**零变化**，也不会因为缺 `performance_schema` 而失败。

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

### 12. 三次复审整改记录（2026-10-05，DEV-MYSQL84-TOOLING-FIX3）

只有一个改动点：`tests/Gate/Mysql84DriverTest.php` 中的 `DB_URL` 断言。

**问题：DriverTest 与 Gate 规则不一致**

整改后 bootstrap / shell / XML / env 示例四处都已是「`null` 或 `''` 均允许」，唯独 DriverTest 仍是：

```php
$this->assertNull($mysql['url'] ?? null, 'DB_URL 非空会整体覆盖逐项校验，必须为空。');
```

`assertNull` 只接受 `null`。而 `phpunit.mysql84.xml` 明确写的是 `<env name="DB_URL" value=""/>`，传入后 `config('database.connections.mysql.url')` 的形态是**空字符串**——也就是说 Gate 一旦真跑起来，这条断言会在正确配置下失败，是一个假阴性。

**修复：改为显式二值断言**

```php
$dbUrl = $mysql['url'] ?? null;
$this->assertTrue(
    $dbUrl === null || $dbUrl === '',
    'DB_URL 只允许未设置（null）或空字符串。任何其它非空值（含纯空格）都会整体覆盖'
    .' host/port/database/username/password，使上面的逐项校验失效。'
    .' 实际值：'.var_export($dbUrl, true),
);
```

用 `===` 全等比较而非弱比较，因此纯空格 `"   "` 仍然被拒（Laravel 不把它归一化为空）。防护一条未删：非空 `DB_URL` 依旧失败。

实测（真实走 Laravel 的 `Env::get()` 归一化，再套用测试中同一判定表达式）：

| `DB_URL` 形态 | `Env::get()` 归一化后 | DriverTest 判定 |
| --- | --- | --- |
| 未设置 | `null` | 允许 |
| `DB_URL=` | `''` | 允许 |
| `DB_URL=null` / `NULL` / `(null)` | `null` | 允许 |
| `DB_URL=empty` / `(empty)` | `''` | 允许 |
| `DB_URL="   "`（纯空格） | `'   '` | **拒绝** |
| `DB_URL=mysql://root@host/db` | 原值 | **拒绝** |

对照：旧的 `assertNull` 在归一化结果为**空字符串**的三行（`DB_URL=` / `empty` / `(empty)`）会误判失败——正是本次修复消除的假阴性。

本轮无需 MySQL runtime，也未实跑 runtime；结论仅限静态与语义层面，`MYSQL_8_4_RELEASE_GATE_PENDING` 不变。

### 13. 四次复审整改记录（2026-10-05，DEV-MYSQL84-TOOLING-FIX4）

第一个 blocker：`scripts/test-mysql.sh` 的 `assert_env_file()` 预检。

**问题：文件层预检与统一规则冲突**

预检原本是 `grep -qE '^DB_URL=.+$'`，只看 `=` 后面有没有字符。于是：

| 写法 | 归一化语义 | 旧预检 | 应得结论 |
| --- | --- | --- | --- |
| `DB_URL=null` / `NULL` / `(null)` | `null` | **拒绝** | 允许 |
| `DB_URL=empty` / `(empty)` | `''` | **拒绝** | 允许 |

这五种**语义为空**的写法会被提前判成非空并中止——与 shell 其它分支、bootstrap、DriverTest 已锁定的规则相反。

**修复：预检改走归一化，不删除预检**

新增 `normalize_db_url()`，逐行按 Laravel 的 `env()` 语义归一（剥一层成对引号 → 小写后命中 `null` / `(null)` 得 null，`empty` / `(empty)` 得 `''`），然后判定「只允许 null 或 `''`」。多行 `DB_URL` 采用 fail-closed：任一行不安全即拒。

**实测矩阵**（走真实脚本里的 `assert_env_file()`，见下）：

| `.env.mysql-testing` 中的写法 | 结果 |
| --- | --- |
| 未设置（无 `DB_URL` 行） | `PASS`（`EXIT=0`） |
| `DB_URL=` | `PASS` |
| `DB_URL=null` | `PASS` |
| `DB_URL=NULL` | `PASS` |
| `DB_URL=(null)` | `PASS` |
| `DB_URL=empty` | `PASS` |
| `DB_URL=(empty)` | `PASS` |
| `DB_URL="   "`（纯空格） | **FAIL**（`EXIT=1`） |
| `DB_URL=mysql://root@host/db` | **FAIL**（`EXIT=1`） |

对照：旧的 `^DB_URL=.+$` 在 `null` / `NULL` / `(null)` / `empty` / `(empty)` 五行会误拒——正是本次消除的假阳性。防护未削弱：纯空格与真实 URL 仍被拒。

**顺带修正**：`run_phpunit()` 的注释指向了并不存在的 `tests/Concerns/AssertsMysqlGate.php`，实际自证类是 `tests/Gate/Mysql84DriverTest.php`，已改正，避免后人按错误路径去找。

本轮同样未实跑 runtime（无 Docker/Podman），结论仅限静态与语义层面。

### 14. 并发 Gate 实现记录（2026-10-05，DEV-MYSQL84-CONCURRENCY-GATE-TEST）

分支 `workbuddy/DEV-MYSQL84-concurrency-gate`，只补"真实并发"这一项缺失的 Gate 证据。

**新增（6 个文件，全部在 Gate 范围内）**

| 文件 | 作用 |
| --- | --- |
| `tests/Gate/Mysql84ConcurrencyTest.php` | 唯一的测试用例：两个 worker 争同一 ContentItem 的行锁 |
| `tests/Gate/Support/ConcurrencyRuntimeDirectory.php` | 编排信号（原子写入） |
| `tests/Gate/Support/ConcurrencyWorkerProcess.php` | worker 进程的启动 / 输出采集 / 超时回收 |
| `tests/Gate/Support/LockWaitObserver.php` | `performance_schema` 证据采集 |
| `tests/Gate/Support/GateInfrastructureUnavailable.php` | 观测缺失异常（必须 FAIL） |
| `tests/Gate/Support/concurrency_worker.php` | worker 入口脚本 |

**最小改动（1 个文件）**：`scripts/test-mysql.sh` 新增幂等的 `ensure_grants()` 与 `grant` / `concurrency` 两个子命令，`all` 流程在 `verify` 之前插入授权步骤。root 凭据与 `docker-compose.mysql-test.yml` 的 `MYSQL_ROOT_PASSWORD` 一致，且授权后立即以 `qn_test` 身份回读自检。

**口径**：本实现**没有**宣称 Runtime Gate 已通过。本机无 Docker / Podman，本轮**未实跑**任何 runtime 测试，只完成了静态检查（PHP syntax ×6、`bash -n`、XML parse、主线 Gate=0、mysql84 配置的测试发现）。`MYSQL_8_4_RELEASE_GATE_PENDING` 保持。

**已知的运行时前置条件**（首次实跑前请确认）：

1. `qn_test` 需要 `SELECT ON performance_schema.*`（`scripts/test-mysql.sh grant` 已负责）；
2. `APP_KEY` 由 `scripts/test-mysql.sh` 在调用 PHPUnit 时**按进程注入**（见 §16），无需手工导出，也不要写进 `.env` / `.env.testing` / CI secret；
2. `--innodb-lock-wait-timeout=10` 约束的**只有一个**窗口：「B 开始在 `lockForUpdate` 上等待」→「A 收到放行并提交」。因此父进程的观测窗口设为 5s（`WAIT_LOCK_OBSERVE_SECONDS`），留足一倍以上余量；命中后立即放行。A 的 watchdog（`A_PAUSE_TIMEOUT_SECONDS` = 30s）是防父进程异常导致永久挂住的兜底，**可以**大于 10s，且它到期只回滚、绝不正常提交。
3. worker 用 `proc_open` 拉起，需要本机 `PHP_BINARY` 可用、且 `.env.mysql-testing` 已存在（orchestrator 会把实际生效的 DB 配置与 `APP_KEY` 显式传给子进程，并在 worker 侧再做一次目标自证）。

### 15. 并发 Gate 二次整改记录（2026-10-05，DEV-MYSQL84-CONCURRENCY-GATE-FIX2）

修两个会直接导致**假绿**的缺陷，并给两者都补上不依赖 MySQL 的回归检查。

**Blocker 1：NOWAIT 错误分类过宽**

旧判定是 `str_contains($message, '3572') || str_contains(strtolower($message), 'nowait')`。但 `QueryException` 的 message 会带上原始 SQL，而这条 SQL 本身就含 `NOWAIT`——于是 1142（权限不足）、1064（语法错误）、2006（连接断开）全会被判成「锁成立」。这类误判最危险的地方在于：它会把一次观测故障包装成「排他锁已证明」。

现在只认 **MySQL driver code 3572**，取自 PDO 的 `errorInfo[1]`：

- SQLSTATE 不可用——3572 与 2006 同为 `HY000`，1142/1064 同为 `42000`；
- `QueryException` 构造时调用 `parent::__construct('', 0, $previous)`，自身的 `errorInfo` 通常为空，真正带 driver code 的是 `previous` 那层 `PDOException`，所以要沿异常链取；
- 取不到 driver code 时判为「不是锁冲突」，由调用方原样抛出——绝不「取不到就当锁成立」。

**Blocker 2：watchdog 到期被当成正常释放**

旧循环到期即跳出，随后 A 照常 INSERT + COMMIT。父进程一旦没在窗口内放行，整场编排会悄悄退化成「A 先提交、B 顺序执行」的假并发，甚至可能报出一份漂亮的「编号已串行化」结论。

现在 A 的暂停是一道只能显式放行的闸门（`WorkerPauseGate::await()`）：超时抛 `PauseWatchdogTimeout`，异常冒泡出 `DB::transaction` 闭包 → 事务回滚 → worker 非 0 退出 → 不 INSERT、不 COMMIT。payload 字段也由 `hold_seconds` 更名为 `pause_timeout_seconds`，语义不再有歧义。

**新增的两个回归检查都不需要数据库**

| 检查 | 覆盖 | 实测 |
| --- | --- | --- |
| `LockWaitProbeErrorClassificationTest` | 3572 → true；1142 / 1064 / 2006 / `errorInfo` 缺失 → 重新抛出；并构造「SQLSTATE 相同、driver code 不同」的对照，证明判定不依赖 SQLSTATE | 5 tests / 19 assertions |
| `WorkerPauseWatchdogTest` | 已放行立即返回；迟到放行仍被承认；超时必抛异常；`timeout=0` 也不放行；边界时刻到达的信号不被误伤 | 5 tests / 10 assertions |

两者合计 **10 tests / 29 assertions 全绿**，bootstrap 只需 `vendor/autoload.php`，全程未连接任何数据库。它们只注册在 `phpunit.mysql84.xml` 的 Gate testsuite 下，主线 SQLite suite 的测试数与断言数不变（实测仍为 270 / 0 命中）。

**时间预算重新设计**

受 `--innodb-lock-wait-timeout=10` 约束的**只有一个**窗口：「B 开始在 `lockForUpdate` 上等待」→「A 收到放行并提交」。父进程观测窗口 `WAIT_LOCK_OBSERVE_SECONDS = 5s`，命中即放行，留一倍以上余量。A 的 watchdog `A_PAUSE_TIMEOUT_SECONDS = 30s` 是防挂死兜底，**允许**大于 10s，且到期只回滚。此前「所有父窗口都必须 < 10s」是错误假设——真正需要小于 10s 的只有 B 的等待时长。

**本轮同样未实跑 Runtime Gate**（无 Docker / Podman），静态检查见提交说明；`MYSQL_8_4_RELEASE_GATE_PENDING` 保持。

### 16. APP_KEY 传递修复记录（2026-10-05，DEV-MYSQL84-GATE-APPKEY-FIX3）

**现象**：GitHub Actions Run `37292699175` —— MySQL 与结构 Gate 全绿，但
`DuplicateReviewApiTest` 13 errors / 0 assertions，统一是
`Illuminate\Encryption\MissingAppKeyException`。

**根因**：`phpunit.mysql84.xml` 把 `APP_ENV` 固定为 `testing`，Laravel 因此**不会**去读
`.env.mysql-testing`；那个文件里由 `php artisan key:generate --env=mysql-testing`
生成的 `APP_KEY` 到不了 PHPUnit 进程，HTTP Feature tests 启动 Laravel 时 key 为空。

**修法**（在官方 Gate runner 内，`scripts/test-mysql.sh`）：

1. `resolve_test_app_key()` 通过 **Laravel 自己**（`php artisan tinker --env=mysql-testing`
   读 `config('app.key')`）取生效值，不自己实现 dotenv 解析器；
2. 只接受非空：取不到、或字面 `null` / 空白 → 返回失败，**fail-closed**；
3. `run_phpunit()` 用行内前缀 `APP_KEY="$TEST_APP_KEY" vendor/bin/phpunit …` 注入，
   **只作用于这一条命令**（刻意不用 `export`，避免 key 扩散到后续所有子进程）；
4. 不写 `.env` / `.env.testing` / GitHub secret / artifact / 日志。

`tests/bootstrap-mysql84-gate.php` 增加 **闸 1.5**：APP_KEY 未设置 / 空 / 字面 `null` /
纯空格 → Gate 直接 FAIL；非空只打印 `APP_KEY 已提供（值不显示）`。这样一旦传递链再断，
会在**第一个用例之前**给出明确错误，而不是 13 个 HTTP 测试集体 MissingAppKey。

**传递链**（已逐跳验证）：

```text
.env.mysql-testing
  → resolve_test_app_key()（tinker 读 config('app.key')）
  → APP_KEY="…" 行内注入 phpunit 进程
  → Laravel config('app.key')            ← 注入则长度 51 一致；不注入则长度 0
  → ConcurrencyWorkerProcess::environment() → 子进程 APP_KEY 一致
```

并发 worker 无需改动：它本就通过 `getenv()` 继承进程环境，并以 `config('app.key')` 复核。

**不泄露的验证方式**：所有检查都只输出「长度」与「是否一致」的布尔值，脚本里没有
`set -x`、没有 `export APP_KEY`、没有把 key 放进任何 echo / die 文案；日志只出现
`✓ APP_KEY 已加载并将传给 PHPUnit（值不显示）` 与 `APP_KEY 已提供（值不显示）`。

**本轮未实跑 Runtime Gate**（本机无 Docker / Podman），静态与链路验证见提交说明；
`MYSQL_8_4_RELEASE_GATE_PENDING` 保持。

### 17. APP_KEY probe 严格化记录（2026-10-05，DEV-MYSQL84-GATE-APPKEY-FIX4）

FIX3 的 `resolve_test_app_key()` 用的是 `tinker … | tr -d '\r' | tail -1`，存在四类
false-accept：管道后的 `$?` 是 `tail` 的退出码（php 失败也可能被当成成功）；warning /
prompt / 错误文字只要落在最后一行就会被当成 key；多条输出被 `tail -1` 静默丢弃；bootstrap
只能验证"非空"，无法知道字符串是否真来自 `config('app.key')`。

**probe 协议**：`tinker --execute` 只输出一条记录，key 整体做一次 base64 编码。

```text
__QN_APP_KEY_B64__<base64(APP_KEY)>__END__
```

marker 名通过行内环境变量（`QN_PROBE_PREFIX` / `QN_PROBE_SUFFIX`）传给探针，保证 bash
与 PHP 两侧单一来源；传的是 marker 名，不是 key。标准 Laravel 的 `base64:` 前缀被整体
编码一次，解码后逐字节还原，不剥除也不二次解释。

**解析层 `parse_app_key_probe_output()` 的十项判定**（任一不满足即 fail-closed）：
完整 stdout 捕获 → 独立取 php 真实退出码（非 0 立即拒，即使 stdout 里有合法 marker）→
去首尾空白后必须整条匹配单条 marker → marker 出现次数恰好为 1 → 编码体不得再含
marker 片段 → 严格 base64 字符集 → 长度是 4 的倍数 → `base64 -d` 成功 → 解码结果
去空白后非空。**不再使用 `tail -1`**，任何多余输出都会让整条匹配失败。

**退出码处理**：`probe_stdout="$(…)"` 与 `probe_rc=$?` 显式分两步写。刻意不写成
`local x="$(…)"` —— 那样 `$?` 取到的是 `local` 自己的退出码（永远 0），探针失败会被吞掉。
函数由 `if ! resolve_test_app_key` 调用，因此不依赖 errexit，全部显式 `return 1`。

**secret 安全**：失败原因只取固定短语（`APP_KEY probe 执行失败` / `输出格式无效` /
`编码无法解码` / `解码结果为空`），不回显 stdout、marker、编码体或 key；无 `set -x`、
无 `export APP_KEY`、不写 `.env.testing` / artifact / workflow secret。

**回归矩阵**（stub 替换 `php`，不连 MySQL，13 项全部符合预期）：
正常单一 marker 通过；**rc≠0 但含合法 marker 拒绝**（旧实现会误接受）；warning-only 拒绝；
两条 marker 拒绝；marker + 额外输出拒绝；缺 `__END__` 拒绝；长度非 4 倍数 / 非法字符 /
非法 padding 拒绝；解码为空拒绝；解码为纯空格拒绝；`base64:…` key 还原后与原值逐字节
一致（前缀保留、长度 51）。

**本轮未实跑 Runtime Gate**（本机无 Docker / Podman）；`MYSQL_8_4_RELEASE_GATE_PENDING` 保持。
