#!/usr/bin/env bash
set -euo pipefail

PROJECT="aeatech-transaction-manager-doctrine-adapter"
COMPOSE_FILE="docker/docker-compose.yml"

# CPU pinning
PHP_CPUSET="${PHP_CPUSET:-2-3}"
PG_CPUSET="${PG_CPUSET:-4-5}"
MYSQL_CPUSET="${MYSQL_CPUSET:-6-7}"

# What to run: pg | mysql | all
RUN_WHAT="${1:-all}"

# Optional: phpbench extra args, e.g. "--report=default"
PHPBENCH_ARGS="${PHPBENCH_ARGS:---report=aggregate}"

# --- helpers ---------------------------------------------------------------

die() { echo "❌ $*" >&2; exit 1; }

container_name() {
  # Resolve container name by "compose service name" (more robust than hardcoded _1)
  local service="$1"
  docker-compose -p "$PROJECT" -f "$COMPOSE_FILE" ps -q "$service" \
    | xargs -r docker inspect --format '{{.Name}}' \
    | sed 's#^/##' \
    | head -n1
}

wait_healthy() {
  local cn="$1"
  local label="$2"
  echo "⏳ Waiting for $label healthcheck..."
  until docker inspect --format='{{.State.Health.Status}}' "$cn" 2>/dev/null | grep -q healthy; do
    sleep 1
  done
  echo "✅ $label is healthy"
}

pin_cpu() {
  local cn="$1"
  local cpus="$2"
  docker update --cpuset-cpus="$cpus" "$cn" >/dev/null
}

run_phpbench() {
  local php_cn="$1"
  local group_arg="$2" # empty or "--group=..."
  echo "🚀 Running phpbench $group_arg"
  # shellcheck disable=SC2086
  docker exec -it "$php_cn" php vendor/bin/phpbench run $PHPBENCH_ARGS $group_arg
}

# --- main ------------------------------------------------------------------

echo "▶️ Starting containers..."
docker-compose -p "$PROJECT" -f "$COMPOSE_FILE" up -d --build

PHP_CN="$(container_name php-cli-bench)"
PG_CN="$(container_name pgsql-bench)"
MYSQL_CN="$(container_name mysql-bench)"

[[ -n "$PHP_CN" ]] || die "Cannot resolve php-cli-bench container"
[[ -n "$PG_CN" ]] || die "Cannot resolve pgsql-bench container"
[[ -n "$MYSQL_CN" ]] || die "Cannot resolve mysql-bench container"

# Wait DBs
wait_healthy "$PG_CN" "PostgreSQL"
wait_healthy "$MYSQL_CN" "MySQL"

# Pin CPUs
echo "📌 Pinning CPUs"
pin_cpu "$PHP_CN" "$PHP_CPUSET"
pin_cpu "$PG_CN" "$PG_CPUSET"
pin_cpu "$MYSQL_CN" "$MYSQL_CPUSET"

echo "🔍 CPU pinning check:"
docker inspect "$PHP_CN"   --format "PHP  cpuset: {{.HostConfig.CpusetCpus}}"
docker inspect "$PG_CN"    --format "PG   cpuset: {{.HostConfig.CpusetCpus}}"
docker inspect "$MYSQL_CN" --format "MySQL cpuset: {{.HostConfig.CpusetCpus}}"

# Run selected benchmarks
case "$RUN_WHAT" in
  pgsql)
    run_phpbench "$PHP_CN" "--group=pgsql"
    ;;
  mysql)
    run_phpbench "$PHP_CN" "--group=mysql"
    ;;
  all)
    run_phpbench "$PHP_CN" ""
    ;;
  *)
    die "Usage: $0 [pgsql|mysql|all]"
    ;;
esac

echo "✅ Done"
