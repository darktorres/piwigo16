#!/usr/bin/env bash
# Runs PHPStan (this repo's own install, config: ../phpstan-extensions.neon)
# against every already-ported plugin/theme's src/ and tests/ in the
# sibling piwigo16-plugins/piwigo16-themes repos. Globbed fresh on every
# run, so a newly ported extension is picked up with no config change.
#
# tests/ is included alongside src/ because a port's own Pest/PHPUnit
# tests live in that same sibling repo now (tools/link-ported-extension-
# tests.php's own docblock explains why), not this repo's tree -- so
# this is the only PHPStan pass that ever sees them; the main repo's own
# `composer analyse` (paths: [.]) cannot reach outside this tree at all.
set -euo pipefail
cd "$(dirname "$0")/.."

shopt -s nullglob
paths=(../piwigo16-plugins/*_17.0.0/src ../piwigo16-themes/*_17.0.0/src ../piwigo16-plugins/*_17.0.0/tests ../piwigo16-themes/*_17.0.0/tests)
shopt -u nullglob

if [ ${#paths[@]} -eq 0 ]; then
    echo "No ported plugin/theme src/ directories found next to this repo (../piwigo16-plugins, ../piwigo16-themes) -- nothing to analyse." >&2
    exit 0
fi

exec vendor/bin/phpstan analyse -c phpstan-extensions.neon "${paths[@]}"
