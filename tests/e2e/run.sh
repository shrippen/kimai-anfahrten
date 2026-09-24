#!/usr/bin/env bash
# Runs the end-to-end tests against a Kimai checkout with a configured database.
#
#   tests/e2e/run.sh /path/to/kimai
#
# The Kimai database is dropped and re-created! Requirements: PHP, a MariaDB/MySQL
# database in Kimai's .env.local, Node.js with the "playwright" package and Chromium.
set -euo pipefail

KIMAI="$(cd "$1" && pwd)"
PLUGIN="$(cd "$(dirname "$0")/../.." && pwd)"
E2E="$PLUGIN/tests/e2e"
CONSOLE="php $KIMAI/bin/console"
export SHOTS="${SHOTS:-$E2E/screenshots}"
mkdir -p "$SHOTS"

echo "== Installing Kimai and the plugin"
ln -sfn "$PLUGIN" "$KIMAI/var/plugins/MileageBundle"
$CONSOLE doctrine:database:drop --force --if-exists -q
$CONSOLE doctrine:database:create -q
$CONSOLE kimai:install -n -q
$CONSOLE kimai:bundle:mileage:install -n -q
[ -d "$KIMAI/var/plugins/HolidayBundle" ] && $CONSOLE kimai:bundle:holiday:install -n -q || true
$CONSOLE assets:install public -q
$CONSOLE kimai:user:create admin admin@example.com ROLE_SUPER_ADMIN 'Admin12345!' -q
$CONSOLE kimai:user:create hans hans@example.com ROLE_USER 'Hans12345!' -q
$CONSOLE kimai:user:create tina tina@example.com ROLE_TEAMLEAD 'Tina12345!' -q
php "$E2E/seed.php" "$KIMAI"
$CONSOLE kimai:bundle:mileage:suggest --user=admin --days=1 -q || true
$CONSOLE cache:clear -q

echo "== Checking migrations against the entity mapping"
DIFF="$($CONSOLE doctrine:schema:update --dump-sql 2>/dev/null | grep -i mileage || true)"
if [ -n "$DIFF" ]; then echo "Schema differs from migrations:"; echo "$DIFF"; exit 1; fi

echo "== Starting Kimai (8001) and fake Dawarich (8002)"
php -S 127.0.0.1:8001 -t "$KIMAI/public" > "$SHOTS/kimai.log" 2>&1 &
KIMAI_PID=$!
php -S 127.0.0.1:8002 "$E2E/fake-dawarich/index.php" > "$SHOTS/dawarich.log" 2>&1 &
DAWARICH_PID=$!
trap 'kill $KIMAI_PID $DAWARICH_PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null http://127.0.0.1:8001/de/login && break; sleep 1; done

echo "== Running browser tests"
node "$E2E/e2e.js"
