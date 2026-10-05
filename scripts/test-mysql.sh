#!/usr/bin/env bash
# MySQL 8.4 Release Gate runner — QN AI 内容工作台
#
# 用途：对 docker-compose.mysql-test.yml 启动的一次性 MySQL 8.4 实例执行
#       DuplicateReview（D11）的 Release Gate 验证。
#
# 这个脚本**只做四件事**：启停一个 disposable 容器、在其上跑验证、销毁容器。
# 它不会连接任何其它 MySQL 实例，也不会读取或修改既有 .env。
#
# 前置条件：Docker（或 Podman，使用环境变量 CONTAINER_RUNTIME 覆盖）。
# 用法：
#   scripts/test-mysql.sh up      # 启动并等待 healthy
#   scripts/test-mysql.sh verify      # 执行 migration / 结构断言 / 定向测试
#   scripts/test-mysql.sh suite       # 完整 PHP suite（MySQL）
#   scripts/test-mysql.sh down        # 销毁容器与数据卷
#   scripts/test-mysql.sh all         # up + grant + verify + suite + down（推荐）
#   scripts/test-mysql.sh grant       # 给 qn_test 补 performance_schema 只读权限
#   scripts/test-mysql.sh concurrency # 只跑并发 Gate（行锁串行化验证）
#   scripts/test-mysql.sh logs        # 查看容器日志
#
# ── 安全边界（任一条不满足即拒绝执行，绝不"降级继续"）─────────────────
#   1. 生效配置必须是：driver=mysql、host=127.0.0.1、port=3399、
#      database=qn_workbench_test、user=qn_test、密码=本地公开测试常量。
#   2. DB_URL 必须未设置或**语义为空**。任何非空值都会让 Laravel 用单 URL 覆盖
#      上述全部字段，使逐项校验失效，因此显式拒绝。语义判定走 Laravel env() 的
#      归一化规则（本脚本 / bootstrap / DriverTest / XML / env 示例 / 文档 六处一致）：
#        允许 —— 未设置、DB_URL=、null、NULL、(null)、empty、(empty)
#        拒绝 —— 纯空格、任意真实 URL 或任何其它非空值
#   3. 校验走 Laravel 实际生效的 config()，不是 grep .env 文件；
#      且在 migrate:fresh 之前再验一次，防止中途被改。
#   4. 测试必须真跑在 MySQL 上：phpunit.xml 把 DB_CONNECTION 硬编码为 sqlite，
#      故本脚本使用专用变体 phpunit.mysql84.xml，并在 PHPUnit bootstrap
#      与测试进程中双重断言实际 driver / database。SQLite 跑绿不算 Gate 成功。
#
# 隔离：compose 固定 project name qn-workbench-mysql84-gate，up/down/logs
#       全部作用于该 project，不会影响本机任何其它容器。
# 凭据：库名固定以 _test 结尾，账号 qn_test，绝不使用 root 作为 Laravel 用户；
#       密码是公开的本地测试常量，不是任何真实凭据。
# 数据：全部落在容器 tmpfs，`down -v` 后无任何磁盘残留。

set -euo pipefail

# ── 路径定位：无论从哪个目录调用，都切到 repo root ────────────────────
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

CONTAINER_RUNTIME="${CONTAINER_RUNTIME:-docker}"
COMPOSE_FILE="docker-compose.mysql-test.yml"
COMPOSE_PROJECT="qn-workbench-mysql84-gate"
ENV_FILE=".env.mysql-testing"
PHPUNIT_CONFIG="phpunit.mysql84.xml"
HOST_PORT="3399"

# 与 compose / env 样例 / phpunit.mysql84.xml 保持一致的本地测试常量。
# 这些是公开的 throwaway 值，不是任何真实凭据。
DB_DRIVER="mysql"
DB_HOST="127.0.0.1"
DB_NAME="qn_workbench_test"
DB_USER="qn_test"
DB_PASSWORD="qn_test_pw"

# 与 docker-compose.mysql-test.yml 的 MYSQL_ROOT_PASSWORD 一致，只用于容器内授权。
# 它不是任何真实凭据；使用它只做一件事：给上面这个 throwaway 账号补观测权限。
MYSQL_ROOT_PASSWORD="qn_test_root_pw"

# 结构断言的期望值。
EXPECT_MYSQL_VERSION_PREFIX="8.4."
EXPECT_ENGINE="InnoDB"
EXPECT_COLLATION="utf8mb4_unicode_ci"
EXPECT_ISOLATION="REPEATABLE-READ"
EXPECT_FOREIGN_KEYS=4
EXPECT_UNIQUE_INDEX="duplicate_review_pair_decision_no_unique"

# ── 输出helpers ─────────────────────────────────────────────────────
log()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
info() { printf '  %s\n' "$*"; }
die()  { printf '\n错误：%s\n' "$*" >&2; exit 1; }

compose() {
  if [ "$CONTAINER_RUNTIME" = "podman" ]; then
    podman compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" "$@"
  else
    $CONTAINER_RUNTIME compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" "$@"
  fi
}

require_runtime() {
  if ! command -v "$CONTAINER_RUNTIME" >/dev/null 2>&1; then
    # 127 = 命令不存在，与"校验未通过"（1）区分开，便于 CI 判读。
    printf '\n错误：未找到 %s。请在装有 Docker 或 Podman 的机器上运行。\n' "$CONTAINER_RUNTIME" >&2
    printf '本脚本不会自动安装任何软件，也不会假装 runtime 已就绪。\n' >&2
    exit 127
  fi
}

# ── 1. 强安全目标校验 ────────────────────────────────────────────────

# 逐项比较，任何不等即中止。
expect_eq() {
  local label="$1" actual="$2" expected="$3"
  if [ "$actual" != "$expected" ]; then
    die "$label 不匹配：期望 '$expected'，实际 '$actual'。为避免误连其它数据库，验证已中止。"
  fi
  info "  ✓ $label = $actual"
}

# DB_URL 语义（与 phpunit.mysql84.xml 的 <env> 和 bootstrap 闸门完全一致）：
#   允许 —— 未设置（config 读到 null）、显式空字符串。两者都不会覆盖任何连接字段。
#   拒绝 —— 任何非空字符串。DB_URL 一旦有值，Laravel 用它整体覆盖
#           host/port/database/username/password，逐项安全校验即形同虚设。
#
# tinker 侧输出 __DB_URL__<NUL 或空> 这样的显式标记，而不是把 null 折叠成空串，
# 这样本函数能区分「未设置」与「非空」，不会像单纯的 -n 判断那样含糊。
assert_no_db_url() {
  local url
  url="$(php artisan tinker --env=mysql-testing --execute='
    $u = config("database.connections.mysql.url");
    echo "__DB_URL__" . ($u === null ? "<null>" : "<" . $u . ">");
  ' 2>/dev/null | tr -d '\r' | grep -o '__DB_URL__.*' | tail -1)"

  if [ -z "$url" ]; then
    die "无法读取生效的 DB_URL（tinker 无输出）。为避免在未证明的目标上运行，验证已中止。"
  fi

  # <null> 与 <> 都表示安全；其余一律拒绝。
  case "$url" in
    '__DB_URL__<null>'|'__DB_URL__<>')
      info "  ✓ DB_URL 未设置或为空（逐项校验有效）"
      ;;
    *)
      die "检测到 DB_URL=${url#__DB_URL__}。DB_URL 会覆盖全部连接字段，使安全校验失效。
请在 $ENV_FILE 中移除 DB_URL、或将其留空（DB_URL=）后重试。"
      ;;
  esac
}

# 通过 Laravel 实际生效的 config() 校验目标，**不是** grep .env 文件。
# 因为 config 层才决定真正连哪个库；文件里写对了不代表生效值对。
assert_effective_target() {
  log "安全目标校验（经 Laravel config() 生效值）"
  assert_no_db_url

  local out
  out="$(php artisan tinker --env=mysql-testing --execute='
    $c = config("database.default");
    $m = config("database.connections.mysql");
    echo implode("|", [
      $c,
      (string) ($m["host"] ?? ""),
      (string) ($m["port"] ?? ""),
      (string) ($m["database"] ?? ""),
      (string) ($m["username"] ?? ""),
      (string) ($m["password"] ?? ""),
    ]);
  ' 2>/dev/null | tr -d '\r' | tail -1)"

  if [ -z "$out" ]; then
    die "无法读取 Laravel 生效配置（tinker 无输出）。请确认 .env.mysql-testing 存在且 APP_KEY 已设置。"
  fi

  local IFS='|'
  # shellcheck disable=SC2086
  set -- $out
  local actual_driver="$1" actual_host="$2" actual_port="$3" \
        actual_db="$4" actual_user="$5" actual_pass="$6"

  expect_eq "DB_CONNECTION"  "$actual_driver" "$DB_DRIVER"
  expect_eq "DB_HOST"        "$actual_host"   "$DB_HOST"
  expect_eq "DB_PORT"        "$actual_port"   "$HOST_PORT"
  expect_eq "DB_DATABASE"    "$actual_db"     "$DB_NAME"
  expect_eq "DB_USERNAME"    "$actual_user"   "$DB_USER"
  # 密码比对：命中约定常量才继续，误粘真实凭据直接中止而非拿它去连库。
  if [ "$actual_pass" != "$DB_PASSWORD" ]; then
    die "DB_PASSWORD 不是约定的本地测试常量。为避免误用真实凭据，验证已中止。"
  fi
  info "  ✓ DB_PASSWORD = <约定的本地测试常量>"
  log "安全目标校验通过：确认为隔离的 MySQL 8.4 测试库。"
}

# 在破坏性操作（migrate:fresh）之前再验一次。
# 上一轮校验与本轮之间配置可能被改，故 migrate 前重新确认。
assert_target_before_migrate() {
  log "migrate:fresh 前二次校验（防止配置在运行中被改）"
  assert_effective_target
}

# ── DB_URL 归一化（对齐 Illuminate\Support\Env::get 的语义）─────────────
# Laravel 的 env() 读值后会做两层归一：
#   1. 剥掉成对的外层引号（"x" / 'x' → x）；
#   2. 小写后命中 null / (null) → null，empty / (empty) → ''。
# 于是下面这些写法在语义上等价于「未设置或空值」，都必须放行：
#     未设置、DB_URL=、DB_URL=null / NULL / (null)、DB_URL=empty / (empty)
# 这两类归一化后仍是实打实的非空字符串，必须拒绝：
#     DB_URL="   "（纯空格）、DB_URL=mysql://…（真实 URL 或任何其它值）
#
# 归一化结果写入 DB_URL_NORM 供报错展示真实值。返回 0 = 安全，1 = 不安全。
normalize_db_url() {
  local raw="$1" v
  DB_URL_NORM=""

  # 只剥一层成对引号，且不动内部空白：纯空格必须原样送进下面的 case 才能被拒。
  if [[ "$raw" =~ ^\"(.*)\"$ ]]; then
    v="${BASH_REMATCH[1]}"
  elif [[ "$raw" =~ ^\'(.*)\'$ ]]; then
    v="${BASH_REMATCH[1]}"
  else
    v="$raw"
  fi

  case "$(printf '%s' "$v" | tr '[:upper:]' '[:lower:]')" in
    ''|empty|'(empty)')
      DB_URL_NORM='<空字符串>'
      return 0
      ;;
    null|'(null)')
      DB_URL_NORM='<null>'
      return 0
      ;;
  esac

  DB_URL_NORM="$v"
  return 1
}

assert_env_file() {
  [ -f "$ENV_FILE" ] || die "缺少 $ENV_FILE。请先执行：cp .env.mysql-testing.example $ENV_FILE"
  if ! grep -qE '^APP_KEY=.+$' "$ENV_FILE"; then
    die "$ENV_FILE 中 APP_KEY 为空。请先执行：php artisan key:generate --env=mysql-testing"
  fi

  # DB_URL 预检：逐行走上面的归一化，而不是看 '=' 后面有没有字符。
  # 旧实现 `^DB_URL=.+$` 会把 DB_URL=null / NULL / (null) / empty / (empty)
  # 这些**语义为空**的写法误判成非空并拒绝，与 Gate 统一规则冲突。
  # 防护未删：纯空格与任何真实 URL 依旧在此中止。
  #
  # 出现多行 DB_URL 时采用 fail-closed：任一行不安全即拒绝，不去猜哪行生效。
  local raw value unsafe=0 unsafe_value="" seen=0
  while IFS= read -r raw; do
    seen=1
    value="${raw#DB_URL=}"
    # 只容忍 CRLF 行尾；除此之外不动空白，纯空格必须原样送去归一化。
    value="${value%$'\r'}"
    if ! normalize_db_url "$value"; then
      unsafe=1
      unsafe_value="$DB_URL_NORM"
      break
    fi
  done < <(grep -E '^DB_URL=' "$ENV_FILE" || true)

  if [ "$unsafe" -eq 1 ]; then
    die "$ENV_FILE 中的 DB_URL='${unsafe_value}' 不安全。DB_URL 一旦非空，Laravel 会用它整体覆盖
host/port/database/username/password，逐项安全校验即形同虚设。
允许：删除该行、DB_URL=、DB_URL=null / NULL / (null)、DB_URL=empty / (empty)。
拒绝：纯空格与任何真实 URL。"
  fi

  if [ "$seen" -eq 1 ]; then
    info "  ✓ $ENV_FILE 存在、APP_KEY 已设置、DB_URL 未设置或语义为空"
  else
    info "  ✓ $ENV_FILE 存在、APP_KEY 已设置、DB_URL 未设置"
  fi
}

# ── 测试用 APP_KEY ──────────────────────────────────────────────────
# 问题：phpunit.mysql84.xml 把 APP_ENV 固定为 testing，Laravel 因此**不会**去读
# .env.mysql-testing —— 那个文件里由 `key:generate --env=mysql-testing` 生成的
# APP_KEY 到不了 PHPUnit 进程，于是所有 HTTP Feature 测试启动 Laravel 时抛
# MissingAppKeyException（表现为"结构 Gate 全绿、13 个功能测试集体报错"）。
#
# 修法：只用 Laravel 自己去读生效值（不自己实现 dotenv 解析器），然后**只对这一次
# PHPUnit 进程**以环境变量注入。不写 .env / .env.testing、不进 GitHub secret、
# 不进 artifact、不进日志。
#
# 取不到或为空一律 fail-closed：宁可明确中止，也不让它以 13 个红测试的形式失败。
TEST_APP_KEY=""

resolve_test_app_key() {
  local key

  # 命令替换会吞掉 stdout，所以 tinker 的输出不会流到终端。
  # 失败时也不打印 tinker 的原始输出（那可能带路径等信息），只报"读不到"。
  key="$(php artisan tinker --env=mysql-testing --execute='
    $k = config("app.key");
    echo is_string($k) ? $k : "";
  ' 2>/dev/null | tr -d '\r' | tail -1)"

  # Laravel 的 env() 归一化可能给出字面 "null"；那同样视为"没有 key"。
  key="${key#"${key%%[![:space:]]*}"}"   # 去前导空白
  key="${key%"${key##*[![:space:]]}"}"   # 去尾部空白

  if [ -z "$key" ] || [ "$key" = "null" ] || [ "$key" = "(null)" ]; then
    TEST_APP_KEY=""
    return 1
  fi

  TEST_APP_KEY="$key"
  return 0
}

assert_test_app_key() {
  log "解析测试用 APP_KEY（来自 .env.mysql-testing；不落盘、不打印）"

  if [ ! -f "$ENV_FILE" ]; then
    die "缺少 $ENV_FILE，无法解析 APP_KEY。请先执行：
  cp .env.mysql-testing.example $ENV_FILE
  php artisan key:generate --env=mysql-testing"
  fi

  if ! resolve_test_app_key; then
    die "$ENV_FILE 中没有可用的 APP_KEY。
请执行：php artisan key:generate --env=mysql-testing
Gate 不会把 key 写进 .env / .env.testing / 日志 / artifact，也不会打印它的值。"
  fi

  info "  ✓ APP_KEY 已加载并将传给 PHPUnit（值不显示）"
}

# ── 容器生命周期 ─────────────────────────────────────────────────────

wait_healthy() {
  log "等待 MySQL 8.4 容器进入 healthy 状态..."
  local status=""
  for _ in $(seq 1 60); do
    status="$(compose ps --format json 2>/dev/null \
      | grep -o '"Health":"[^"]*"' \
      | head -1 | sed 's/.*:"//; s/"$//' || true)"
    # 精确匹配：'unhealthy' 也含 'healthy' 子串，通配会误判成功。
    if [ "$status" = "healthy" ]; then
      info "  ✓ 容器状态 = healthy"
      return 0
    fi
    if [ "$status" = "unhealthy" ] || [ "$status" = "exited" ] || [ "$status" = "dead" ]; then
      compose logs --tail 50 || true
      die "容器状态 = $status，停止等待。"
    fi
    sleep 2
  done
  compose logs --tail 50 || true
  die "容器未在预期时间内 healthy（最后状态：'${status:-unknown}'）。"
}

up() {
  require_runtime
  log "启动 disposable MySQL 8.4（compose project: $COMPOSE_PROJECT）"
  compose up -d
  wait_healthy
}

down() {
  require_runtime
  log "销毁容器与数据卷（compose project: $COMPOSE_PROJECT）"
  compose down -v --remove-orphans
  info "  ✓ 容器与数据卷已销毁"
}

# ── 并发 Gate 的观测权限 ────────────────────────────────────────────
# tests/Gate/Mysql84ConcurrencyTest.php 必须用 performance_schema.data_lock_waits
# 证明 "worker B 正在等待 worker A"；没有这个读权限就没有任何资格谈"证明了并发"。
# 官方镜像创建的 qn_test 默认只有 qn_workbench_test.* 的权限，这里补一条只读。
#
# 幂等：重复执行只会重新授权一次。GRANT 失败不静默、也不降级——
# 并发 Gate 会在运行时明确 FAIL 并指出是权限问题。
ensure_grants() {
  require_runtime
  log "并发 Gate 前置：授权 performance_schema 观测能力"

  local grant_sql="GRANT SELECT ON performance_schema.* TO '${DB_USER}'@'%';"
  if ! compose exec -T mysql-test mysql -uroot "-p${MYSQL_ROOT_PASSWORD}" -e "${grant_sql}" >/dev/null 2>&1; then
    die "无法给 ${DB_USER} 授予 performance_schema 读权限。
可能原因：容器未 healthy，或 root 凭据与 docker-compose.mysql-test.yml 不一致。
并发 Gate 依赖 performance_schema.data_lock_waits 证明锁等待；缺少它不能宣称通过。"
  fi
  info "  ✓ ${grant_sql}"

  # 立即以 throwaway 账号自检：授权有没有真的生效，不能只看 GRANT 没报错。
  local check
  check="$(compose exec -T mysql-test mysql -u"${DB_USER}" "-p${DB_PASSWORD}" \
    -N -B -e 'SELECT COUNT(*) FROM performance_schema.data_lock_waits;' 2>&1 | tr -d '\r' || true)"

  if [ -z "$check" ] || printf '%s' "$check" | grep -qiE 'error|access denied'; then
    die "${DB_USER} 仍无法读取 performance_schema.data_lock_waits（返回：${check:-<空>}）。
并发 Gate 需要该权限；没有它测试会明确 FAIL，而不是退化成顺序执行。"
  fi

  info "  ✓ 以 ${DB_USER} 身份读取 performance_schema.data_lock_waits 成功"
}

verify_concurrency_gate() {
  assert_env_file
  assert_effective_target
  ensure_grants
  run_phpunit "Mysql84ConcurrencyTest"
}

logs() {
  require_runtime
  compose logs --tail "${2:-100}"
}

# ── 2 & 3. 结构断言 + 定向测试 ──────────────────────────────────────
# 结构检查全部走 assert：任一不符即以非 0 退出，不允许"打印出来让人自己看"。
# 这几项正是 SQLite 查不到、因而必须到 MySQL 上证明的部分。
assert_structure() {
  log "结构断言（information_schema，SQLite 无法证明的部分）"
  php artisan tinker --env=mysql-testing --execute="
    \$fail = [];
    \$ok = [];

    \$version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
    if (str_starts_with(\$version, '$EXPECT_MYSQL_VERSION_PREFIX')) {
        \$ok[] = 'mysql_version=' . \$version;
    } else {
        \$fail[] = 'mysql_version 期望 $EXPECT_MYSQL_VERSION_PREFIX*，实际 ' . \$version;
    }

    \$t = DB::selectOne(\"SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'\");
    if (! \$t) {
        \$fail[] = '表 duplicate_review_decisions 不存在';
    } else {
        if (\$t->ENGINE === '$EXPECT_ENGINE') {
            \$ok[] = 'engine=' . \$t->ENGINE;
        } else {
            \$fail[] = 'engine 期望 $EXPECT_ENGINE，实际 ' . \$t->ENGINE;
        }
        if (\$t->TABLE_COLLATION === '$EXPECT_COLLATION') {
            \$ok[] = 'collation=' . \$t->TABLE_COLLATION;
        } else {
            \$fail[] = 'collation 期望 $EXPECT_COLLATION，实际 ' . \$t->TABLE_COLLATION;
        }
    }

    // 隔离级别决定 lockForUpdate 的实际行为，必须核对而非假定。
    \$iso = (string) DB::selectOne('SELECT @@transaction_isolation AS i')->i;
    if (strtoupper(\$iso) === '$EXPECT_ISOLATION') {
        \$ok[] = 'transaction_isolation=' . \$iso;
    } else {
        \$fail[] = 'transaction_isolation 期望 $EXPECT_ISOLATION，实际 ' . \$iso;
    }

    \$fk = (int) DB::selectOne(\"SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'\")->c;
    if (\$fk === $EXPECT_FOREIGN_KEYS) {
        \$ok[] = 'foreign_keys=' . \$fk;
    } else {
        \$fail[] = 'foreign_keys 期望 $EXPECT_FOREIGN_KEYS，实际 ' . \$fk;
    }

    // 必须证明该索引是 UNIQUE：NON_UNIQUE=0 才是唯一索引，
    // 仅按名字命中一个非唯一索引不算通过。
    \$uq = DB::selectOne(\"SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'
        AND INDEX_NAME = '$EXPECT_UNIQUE_INDEX'\");
    if (! \$uq) {
        \$fail[] = '唯一索引 $EXPECT_UNIQUE_INDEX 不存在';
    } elseif ((int) \$uq->NON_UNIQUE !== 0) {
        \$fail[] = '索引 $EXPECT_UNIQUE_INDEX 存在但 NON_UNIQUE=' . \$uq->NON_UNIQUE . '（不是唯一索引）';
    } else {
        \$cols = DB::select(\"SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'
            AND INDEX_NAME = '$EXPECT_UNIQUE_INDEX' ORDER BY SEQ_IN_INDEX\");
        \$ok[] = 'unique_index=$EXPECT_UNIQUE_INDEX(' . count(\$cols) . ' cols: '
            . collect(\$cols)->pluck('COLUMN_NAME')->implode(',') . ')';
    }

    foreach (\$ok as \$line) { echo '  [PASS] ' . \$line . PHP_EOL; }
    if (\$fail) {
        foreach (\$fail as \$line) { echo '  [FAIL] ' . \$line . PHP_EOL; }
        echo PHP_EOL . 'STRUCTURE_ASSERT_FAILED' . PHP_EOL;
        exit(1);
    }
    echo 'STRUCTURE_ASSERT_OK' . PHP_EOL;
  " || die "结构断言未通过（见上方 [FAIL] 行）。"
}

verify_migrations() {
  assert_env_file
  assert_effective_target

  log "1/4  fresh migrate（破坏性操作前已二次校验目标）"
  assert_target_before_migrate
  php artisan migrate:fresh --force --env=mysql-testing

  log "2/4  结构断言"
  assert_structure

  log "3/4  rollback（全链回滚）"
  php artisan migrate:rollback --force --env=mysql-testing

  log "4/4  re-migrate"
  php artisan migrate --force --env=mysql-testing

  log "5/5  DuplicateReview 定向测试（真实 MySQL）"
  run_phpunit "DuplicateReviewApiTest"
}

# ── 运行测试：证明跑的是 MySQL ───────────────────────────────────────
# phpunit.xml 把 DB_CONNECTION 硬编码为 sqlite 且 DB_DATABASE=:memory:，
# 直接 `php artisan test` 即使加了 --env 也会被 <env> 覆盖成 SQLite，
# 于是"SQLite 跑绿"会被误当成 Gate 通过。这里改用专用变体，并在
# bootstrap + 测试进程内双重断言实际 driver/database。
run_phpunit() {
  local filter="${1:-}"
  local label="完整 PHP suite"
  [ -n "$filter" ] && label="定向测试 $filter"

  # APP_KEY 必须最先解析：缺它的话后面每个 HTTP 用例都会 MissingAppKey。
  assert_test_app_key

  log "运行 $label（配置: $PHPUNIT_CONFIG）"
  printf 'MYSQL84_GATE_TEST_START driver=%s host=%s port=%s db=%s user=%s\n' \
    "$DB_DRIVER" "$DB_HOST" "$HOST_PORT" "$DB_NAME" "$DB_USER"

  assert_phpunit_config_is_mysql

  local args=(--configuration "$PHPUNIT_CONFIG")
  [ -n "$filter" ] && args+=(--filter "$filter")

  # bootstrap 会在 PHPUnit 启动时断言实际连接与 APP_KEY 是否存在；测试进程内由
  # tests/Gate/Mysql84DriverTest.php 从已建立的连接上再确认一次
  # （driver / host / port / database / user / DB_URL 空值语义 / 8.4 版本 / InnoDB）。
  #
  # APP_KEY 只注入这一次调用，不落盘。子进程（并发 worker）会经 getenv() 继承它，
  # 并在 ConcurrencyWorkerProcess::environment() 里用 config('app.key') 再确认一次。
  #
  # 刻意不用 `export APP_KEY` —— 那会把 key 扩散到本脚本后续所有子进程；
  # 行内前缀只作用于这一条命令。
  if ! APP_KEY="$TEST_APP_KEY" vendor/bin/phpunit "${args[@]}"; then
    die "$label 未通过（MySQL 8.4 测试库）。"
  fi
  printf 'MYSQL84_GATE_TEST_PASS %s\n' "$label"
}

# 静态确认专用 phpunit 变体没有把 DB 覆盖回 SQLite。
# 这是第一道闸；真正的运行时证明在 bootstrap 与测试进程内。
assert_phpunit_config_is_mysql() {
  [ -f "$PHPUNIT_CONFIG" ] || die "缺少 $PHPUNIT_CONFIG（用于真跑 MySQL 的 PHPUnit 变体）。"

  local conn db
  conn="$(grep -oE '<env name="DB_CONNECTION" value="[^"]*"' "$PHPUNIT_CONFIG" | head -1 | sed 's/.*value="//; s/"$//')"
  db="$(grep -oE '<env name="DB_DATABASE" value="[^"]*"' "$PHPUNIT_CONFIG" | head -1 | sed 's/.*value="//; s/"$//')"

  [ "$conn" = "mysql" ] || die "$PHPUNIT_CONFIG 的 DB_CONNECTION='$conn'，必须为 mysql。
若为 sqlite，测试跑的是内存库，Gate 结果无效。"
  [ "$db" = "$DB_NAME" ] || die "$PHPUNIT_CONFIG 的 DB_DATABASE='$db'，必须为 $DB_NAME。"

  # 同样允许空值：value="" 是显式空，合法。只拒绝非空。
  if grep -qE '<env name="DB_URL" value="[^"]+' "$PHPUNIT_CONFIG"; then
    die "$PHPUNIT_CONFIG 设置了非空 DB_URL，会覆盖逐项校验。请改为空值。"
  fi
  info "  ✓ $PHPUNIT_CONFIG 锁定 mysql / $DB_NAME，DB_URL 未设置或为空"
}

verify_full_suite() {
  assert_env_file
  assert_effective_target
  run_phpunit ""
}

# ── all：失败必须失败 ────────────────────────────────────────────────
# 关键点：完整 suite 失败要让 `all` 最终返回非 0，但清理仍必须执行。
# 用 EXIT trap 兜住清理，同时把原始退出码原样传出——trap 不能吞掉它。
run_all() {
  local original_rc=0
  # trap 在退出时执行清理；\$_? 是触发退出时的原始退出码。
  trap 'rc=$?; down >/dev/null 2>&1 || true; exit $rc' EXIT

  up
  ensure_grants
  verify_migrations
  # 不加 `|| echo`：那会把失败吞掉。全量 suite 未全绿就以非 0 结束，
  # 由 trap 完成清理。
  verify_full_suite

  original_rc=0
  log "全部通过。"
  return $original_rc
}

case "${1:-}" in
  up)          up ;;
  down)        down ;;
  logs)        logs "$@" ;;
  grant)       ensure_grants ;;
  verify)      verify_migrations ;;
  suite)       verify_full_suite ;;
  concurrency) verify_concurrency_gate ;;
  all)         run_all ;;
  *)
    echo "用法: $0 {up|down|logs|grant|verify|suite|concurrency|all}" >&2
    exit 2
    ;;
esac
