#!/usr/bin/env bash
# Starts Kimai with this plugin and the Studio Weber demo data (the shrippen demo world).
# The Kimai setup is shared by all shrippen Kimai plugins and lives in the sibling
# checkout shrippen.github.io (demo/kimai/reset.sh).
#   demo/start.sh [de|en] [default|knust]
set -euo pipefail
BUNDLE=MileageBundle
SHARED="$(cd "$(dirname "$0")/../.." && pwd)/shrippen.github.io/demo/kimai/reset.sh"
if [ ! -x "$SHARED" ]; then
    echo "Needs shrippen.github.io checked out next to this plugin: $SHARED" >&2
    exit 1
fi
exec "$SHARED" --lang "${1:-de}" --theme "${2:-default}" --only "$BUNDLE"
