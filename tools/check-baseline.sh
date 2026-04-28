#!/usr/bin/env bash
# Guard against PHPStan baseline growth.
# Fails if the current baseline has MORE entries than the committed baseline.
#
# Usage: bash tools/check-baseline.sh
# Called from the CI lint job after vendor/bin/phpstan analyse passes.
set -euo pipefail

BASELINE="phpstan-baseline.neon"

if [ ! -f "$BASELINE" ]; then
    echo "No baseline file — nothing to check."
    exit 0
fi

committed=$(git show HEAD:"$BASELINE" 2>/dev/null | grep -c "^		-$" || echo 0)
current=$(grep -c "^		-$" "$BASELINE" || echo 0)

if [ "$current" -gt "$committed" ]; then
    echo "PHPStan baseline grew: was $committed entries, now $current."
    echo "Fix the new errors rather than adding them to the baseline."
    exit 1
fi

echo "Baseline OK: $current entries (committed: $committed)."
