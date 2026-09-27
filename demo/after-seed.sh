#!/bin/sh
# Runs inside the Kimai container after demo/seed.php (shrippen.github.io/demo/kimai/reset.sh):
# starts the Hamburg fake Dawarich on 127.0.0.1:8002 and lets the plugin detect Mara's trips.
DIR="$(dirname "$0")"
setsid nohup php -S 127.0.0.1:8002 "$DIR/fake-dawarich/index.php" > /tmp/fake-dawarich.log 2>&1 &
for _ in 1 2 3 4 5 6 7 8 9 10; do
    curl -s -o /dev/null -H 'Authorization: Bearer demo-key' http://127.0.0.1:8002/api/v1/areas && break
    sleep 1
done
/opt/kimai/bin/console kimai:bundle:mileage:suggest --user=mara --days=14 -n 2>/dev/null | grep -i "mara" || echo "no trips detected"
exit 0
