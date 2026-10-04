#!/usr/bin/env bash
# MySQL 8.4 Release Gate runner — QN AI 内容工作台
#
# 用途：对 docker-compose.mysql-test.yml 启动的一次性 MySQL 8.4 实例执行
#       DuplicateReview（D11）的 Release Gate 验证。
#
# 这个脚本**只做三件事**：启停一个 disposable 容器、在其上跑验证、销毁容器。
# 它不会连接任何其它 MySQL 实例，也不会读取或修改既有 .env。
#
# 前置条件：Docker（或 Podman，使用 BASH 中的 CONTAINER_RUNTIME 覆盖）。
# 用法：
#   scripts/test-mysql.sh up      # 启动并等待 healthy
#   scripts/test-mysql.sh verify  # 执行 migration / 定向测试 / 全量测试
#   scripts/test-mysql.sh down    # 销毁容器与数据卷
#   scripts/test-mysql.sh all     # up + verify + down（推荐）
#
# 安全保证：
#   - 库名固定以 _test 结尾，账号 qn_test，绝不使用 root 作为 Laravel 用户
#   - 密码是公开的本地测试常量，不是任何真实凭据
#   - 宿主机端口固定 3399，避开本机既有的 3306 / 3307 实例
#   - 数据全部落在容器 tmpfs，`down -v` 后无任何磁盘残留

set -euo pipefail

CONTAINER_RUNTIME="${CONTAINER_RUNTIME:-docker}"
COMPOSE_FILE="docker-compose.mysql-test.yml"
ENV_FILE=".env.mysql-testing"
HOST_PORT="3399"

# 与 compose / env 样例保持一致的本地测试常量。
DB_NAME="qn_workbench_test"
DB_USER="qn_test"
DB_PASSWORD="qn_test_pw"

compose() {
  if [ "$CONTAINER_RUNTIME" = "podman" ]; then
    podman compose -f "$COMPOSE_FILE" "$@"
  else
    $CONTAINER_RUNTIME compose -f "$COMPOSE_FILE" "$@"
  fi
}

require_runtime() {
  if ! command -v "$CONTAINER_RUNTIME" >/dev/null 2>&1; then
    echo "错误：未找到 $CONTAINER_RUNTIME。请安装 Docker 或设置 CONTAINER_RUNTIME=podman。" >&2
    echo "本脚本不会自动安装任何软件。" >&2
    exit 127
  fi
}

wait_healthy() {
  echo "等待 MySQL 8.4 容器进入 healthy 状态..."
  for _ in $(seq 1 60); do
    status=$(compose ps --format json 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 || true)
    case "$status" in
      *healthy*) echo "容器已 healthy。"; return 0 ;;
    esac
    sleep 2
  done
  echo "错误：容器未在预期时间内 healthy。" >&2
  compose logs --tail 50 || true
  exit 1
}

assert_env_file() {
  if [ ! -f "$ENV_FILE" ]; then
    echo "缺少 $ENV_FILE。请先执行：cp .env.mysql-testing.example $ENV_FILE" >&2
    exit 1
  fi
  if grep -qE '^DB_PASSWORD=.+$' "$ENV_FILE"; then
    # 确认这是我们约定的本地测试常量，而不是误粘的真实凭据。
    if ! grep -q "DB_PASSWORD=$DB_PASSWORD" "$ENV_FILE"; then
      echo "错误：$ENV_FILE 中的 DB_PASSWORD 不是约定的本地测试常量。" >&2
      echo "为避免误用真实凭据，验证已中止。" >&2
      exit 1
    fi
  fi
}

up() {
  require_runtime
  compose up -d
  wait_healthy
}

down() {
  require_runtime
  compose down -v --remove-orphans
  echo "容器与数据卷已销毁。"
}

verify_migrations() {
  assert_env_file
  echo "== 1/4  fresh migrate =="
  php artisan migrate:fresh --force --env=mysql-testing

  echo "== 1b   duplicate_review_decisions 结构核对 =="
  # 4 个复合外键 + 唯一索引 + InnoDB 引擎，三者都是 SQLite 无法证明的部分。
  php artisan tinker --env=mysql-testing --execute="
    \$rows = DB::select(\"SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'\");
    echo 'foreign_keys=' . \$rows[0]->c . PHP_EOL;
    \$idx = DB::select(\"SELECT COUNT(*) AS c FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'
      AND INDEX_NAME = 'duplicate_review_pair_decision_no_unique'\");
    echo 'unique_index_columns=' . \$idx[0]->c . PHP_EOL;
    \$eng = DB::select(\"SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'\");
    echo 'engine=' . \$eng[0]->ENGINE . ' collation=' . \$eng[0]->TABLE_COLLATION . PHP_EOL;
  "

  echo "== 2/4  rollback（全链回滚）=="
  php artisan migrate:rollback --force --env=mysql-testing

  echo "== 3/4  re-migrate =="
  php artisan migrate --force --env=mysql-testing

  echo "== 4/4  DuplicateReview 定向测试 =="
  php artisan test --env=mysql-testing --filter=DuplicateReviewApiTest
}

verify_full_suite() {
  assert_env_file
  echo "== 完整 PHP suite（MySQL）=="
  php artisan test --env=mysql-testing
}

case "${1:-}" in
  up) up ;;
  down) down ;;
  verify) verify_migrations ;;
  suite) verify_full_suite ;;
  all)
    up
    verify_migrations
    # 全量套件失败不应阻断容器清理，因此这里单独兜底。
    verify_full_suite || echo "全量套件未全绿，请按分类逐项分析（产品问题 / 测试基建 / SQLite-specific 假设）。"
    down
    ;;
  *)
    echo "用法: $0 {up|down|verify|suite|all}" >&2
    exit 2
    ;;
esac
