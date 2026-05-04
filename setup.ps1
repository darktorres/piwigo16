#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function ok   { param($msg) Write-Host "[ok]  $msg" -ForegroundColor Green }
function warn { param($msg) Write-Host "[!!]  $msg" -ForegroundColor Yellow }
function fail { param($msg) Write-Host "[fail] $msg" -ForegroundColor Red; exit 1 }

Write-Host "Piwigo setup"
Write-Host "============"

# ── PHP ──────────────────────────────────────────────────────────────────────
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    fail "php not found. Install PHP 8.5+ and re-run."
}

$phpMajor = [int](php -r 'echo PHP_MAJOR_VERSION;')
$phpMinor = [int](php -r 'echo PHP_MINOR_VERSION;')
$phpVersion = php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'

if ($phpMajor -lt 8 -or ($phpMajor -eq 8 -and $phpMinor -lt 5)) {
    fail "PHP 8.5+ required, found $phpVersion."
}
ok "PHP $phpVersion"

foreach ($ext in @('mysqli', 'mbstring', 'gd', 'exif')) {
    $loaded = php -m | Where-Object { $_ -ieq $ext }
    if (-not $loaded) {
        fail "PHP extension '$ext' is missing. Enable it in php.ini."
    }
    ok "ext-$ext"
}

# ── Composer ─────────────────────────────────────────────────────────────────
if (Get-Command composer -ErrorAction SilentlyContinue) {
    $composer = 'composer'
} elseif (Test-Path 'composer.phar') {
    $composer = 'php composer.phar'
} else {
    fail "Composer not found. Install it from https://getcomposer.org and re-run."
}
ok "Composer ($composer)"

# ── Node ─────────────────────────────────────────────────────────────────────
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    fail "node not found. Install Node.js from https://nodejs.org and re-run."
}
if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    fail "npm not found. It should ship with Node.js."
}
ok "Node $(node --version) / npm $(npm --version)"

# ── Install dependencies ──────────────────────────────────────────────────────
Write-Host ""
Write-Host "Installing PHP dependencies..."
Invoke-Expression "$composer install"
if ($LASTEXITCODE -ne 0) { fail "composer install failed." }

Write-Host ""
Write-Host "Installing JS dependencies..."
npm install
if ($LASTEXITCODE -ne 0) { fail "npm install failed." }

Write-Host ""
Write-Host "Building frontend assets..."
npm run build
if ($LASTEXITCODE -ne 0) { fail "npm run build failed." }

# ── Done ─────────────────────────────────────────────────────────────────────
Write-Host ""
ok "Setup complete."
Write-Host ""
Write-Host "  Next step: open install.php in your browser to configure the database"
Write-Host "  and complete the Piwigo installation."
Write-Host ""
