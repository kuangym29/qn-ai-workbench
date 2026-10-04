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
#   scripts/test-mysql.sh verify  # 执行 migration / 结构断言 / 定向测试
#   scripts/test-mysql.sh suite   # 完整 PHP suite（MySQL）
#   scripts/test-mysql.sh down    # 销毁容器与数据卷
#   scripts/test-mysql.sh all     # up + verify + suite + down（推荐）
#   scripts/test-mysql.sh logs    # 查看容器日志
#
# ── 安全边界（任一条不满足即拒绝执行，绝不"降级继续"）─────────────────
#   1. 生效配置必须是：driver=mysql、host=127.0.0.1、port=3399、
#      database=qn_workbench_test、user=qn_test、密码=本地公开测试常量。
#   2. DB_URL 必须为空。设置它会让 Laravel 用单 URL 覆盖上述全部字段，
#      使逐项校验失效，因此显式拒绝。
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

# 拒绝 DB_URL：它会整体覆盖 host/port/database/username/password，
# 使逐项安全校验形同虚设。
assert_no_db_url() {
  local url
  url="$(php artisan tinker --env=mysql-testing --execute='echo config("database.connections.mysql.url") === null ? "" : (string) config("database.connections.mysql.url");' 2>/dev/null | tr -d '\r' | tail -1)"
  if [ -n "$url" ]; then
    die "检测到 DB_URL='$url'。DB_URL 会覆盖全部连接字段，使安全校验失效。
请在 $ENV_FILE 中移除 DB_URL 后重试。"
  fi
  info "  ✓ DB_URL 未设置（逐项校验有效）"
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

assert_env_file() {
  [ -f "$ENV_FILE" ] || die "缺少 $ENV_FILE。请先执行：cp .env.mysql-testing.example $ENV_FILE"
  if ! grep -qE '^APP_KEY=.+$' "$ENV_FILE"; then
    die "$ENV_FILE 中 APP_KEY 为空。请先执行：php artisan key:generate --env=mysql-testing"
  fi
  if grep -qE '^DB_URL=.+$' "$ENV_FILE"; then
    die "$ENV_FILE 中设置了 DB_URL。DB_URL 会覆盖全部连接字段，使安全校验失效。请移除该行。"
  fi
  info "  ✓ $ENV_FILE 存在、APP_KEY 已设置、无 DB_URL"
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

  log "运行 $label（配置: $PHPUNIT_CONFIG）"
  printf 'MYSQL84_GATE_TEST_START driver=%s host=%s port=%s db=%s user=%s\n' \
    "$DB_DRIVER" "$DB_HOST" "$HOST_PORT" "$DB_NAME" "$DB_USER"

  assert_phpunit_config_is_mysql

  local args=(--configuration "$PHPUNIT_CONFIG")
  [ -n "$filter" ] && args+=(--filter "$filter")

  # bootstrap 会在 PHPUnit 启动时断言实际连接；测试进程内的断言由
  # tests/Concerns/AssertsMysqlGate.php 在每个用例建立连接时再次确认。
  if ! vendor/bin/phpunit "${args[@]}"; then
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

  if grep -qE '<env name="DB_URL" value="[^"]+' "$PHPUNIT_CONFIG"; then
    die "$PHPUNIT_CONFIG 设置了非空 DB_URL，会覆盖逐项校验。"
  fi
  info "  ✓ $PHPUNIT_CONFIG 锁定 mysql / $DB_NAME，无 DB_URL"
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
  verify_migrations
  # 不加 `|| echo`：那会把失败吞掉。全量 suite 未全绿就以非 0 结束，
  # 由 trap 完成清理。
  verify_full_suite

  original_rc=0
  log "全部通过。"
  return $original_rc
}

case "${1:-}" in
  up)     up ;;
  down)   down ;;
  logs)   logs "$@" ;;
  verify) verify_migrations ;;
  suite)  verify_full_suite ;;
  all)    run_all ;;
  *)
    echo "用法: $0 {up|down|logs|verify|suite|all}" >&2
    exit 2
    ;;
esac
