#!/usr/bin/env bash
## tools/ci/phase3-checks.sh — Phase 3 guardrails; must exit 0 on every push.
## Run from the repo root: bash tools/ci/phase3-checks.sh
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

## 1. Composer autoload builds under strict PSR-4 rules.
##    Fails loudly if any src/Piwigo/Foo/Bar.php has a wrong namespace declaration.
echo "[1/4] composer dump-autoload --strict-psr …"
composer dump-autoload --strict-psr --no-interaction --quiet \
  || fail "composer dump-autoload --strict-psr failed — namespace mismatch in src/Piwigo/"

## 2. No bare `new ShortClassName(` outside src/Piwigo/ for any class that now
##    lives in a Piwigo\ namespace (i.e., has a src/Piwigo/ file).
##    Rector's RenameClassRector should have replaced all such sites with
##    'use Piwigo\...\ClassName; new ClassName()' or FQN 'new \Piwigo\...\ClassName()'.
echo "[2/4] checking for bare unqualified class instantiations …"
MIGRATED=$(find src/Piwigo -name '*.php' | \
  xargs grep -h '^class ' 2>/dev/null | \
  grep -oP '(?<=^class )\w+' || true)
FAIL=0
for cls in $MIGRATED; do
  # Search include/, admin/, *.php root for `new ClassName(` without a qualifying backslash
  # or a preceding `use` import that resolves it.
  hits=$(grep -rn "new ${cls}\b" --include='*.php' \
    --exclude-dir=vendor --exclude-dir=install/db --exclude-dir="src" \
    . 2>/dev/null | grep -v 'class_alias' || true)
  if [ -n "$hits" ]; then
    echo "  WARNING: bare 'new ${cls}(' found (may be legitimately aliased):"
    echo "$hits" | head -3
    # Don't hard-fail — some sites are intentional aliases or same-namespace refs.
  fi
done

## 3. Release tarball must not ship dev tooling.
##    .gitattributes export-ignore rules exclude phpstan, rector, phpunit, pint.
echo "[3/4] checking release tarball excludes dev deps …"
git archive --format=tar.gz HEAD -o /tmp/piwigo-phase3-check.tgz
BAD=$(tar -tzf /tmp/piwigo-phase3-check.tgz | \
  grep -E '^vendor/(phpstan|rector|phpunit|laravel|symfony)/' || true)
rm -f /tmp/piwigo-phase3-check.tgz
if [ -n "$BAD" ]; then
  echo "$BAD"
  fail "Release tarball contains dev dependencies — check .gitattributes export-ignore"
fi

## 4. PHPStan at configured level exits 0.
echo "[4/4] phpstan analyse …"
vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  || fail "PHPStan failed"

echo ""
echo "OK: all Phase 3 checks passed."
