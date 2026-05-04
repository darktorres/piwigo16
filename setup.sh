#!/usr/bin/env bash
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[ok]${NC}  $*"; }
warn() { echo -e "${YELLOW}[!!]${NC}  $*"; }
fail() { echo -e "${RED}[fail]${NC} $*" >&2; exit 1; }

echo "Piwigo setup"
echo "============"

# ── PHP ──────────────────────────────────────────────────────────────────────
command -v php >/dev/null 2>&1 || fail "php not found. Install PHP 8.5+ and re-run."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [[ "$PHP_MAJOR" -lt 8 || ( "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 5 ) ]]; then
    fail "PHP 8.5+ required, found $PHP_VERSION."
fi
ok "PHP $PHP_VERSION"

for ext in mysqli mbstring gd exif; do
    php -m | grep -qi "^${ext}$" || fail "PHP extension '$ext' is missing. Enable it in php.ini."
    ok "ext-$ext"
done

# ── Composer ─────────────────────────────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
    COMPOSER=composer
elif [[ -f composer.phar ]]; then
    COMPOSER="php composer.phar"
else
    fail "Composer not found. Install it from https://getcomposer.org and re-run."
fi
ok "Composer ($COMPOSER)"

# ── Node ─────────────────────────────────────────────────────────────────────
command -v node >/dev/null 2>&1 || fail "node not found. Install Node.js from https://nodejs.org and re-run."
command -v npm  >/dev/null 2>&1 || fail "npm not found. It should ship with Node.js."
ok "Node $(node --version) / npm $(npm --version)"

# ── Install dependencies ──────────────────────────────────────────────────────
echo
echo "Installing PHP dependencies..."
$COMPOSER install || fail "composer install failed."

echo
echo "Installing JS dependencies..."
npm install || fail "npm install failed."

echo
echo "Building frontend assets..."
npm run build || fail "npm run build failed."

# ── Done ─────────────────────────────────────────────────────────────────────
echo
ok "Setup complete."
echo
echo "  Next step: open install.php in your browser to configure the database"
echo "  and complete the Piwigo installation."
echo
