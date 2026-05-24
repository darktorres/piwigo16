# Piwigo development task runner
# Usage: just <recipe>    Run a recipe
#        just --list      Show all recipes

set dotenv-load

# ─── Meta ───────────────────────────────────────────────────────────────

# List available recipes
default:
    @just --list

# ─── Build ──────────────────────────────────────────────────────────────

# Build JS/CSS assets via Vite
build:
    bun run build

# Start Vite dev server with HMR
dev:
    bun run dev

# Delete built assets
clean:
    bun run clean

# ─── Lint ───────────────────────────────────────────────────────────────

# Run all linters (PHP + JS + CSS + Markdown + Latte)
lint: lint-php lint-js lint-css lint-md lint-latte

# PHP code style (Pint)
lint-php:
    vendor/bin/pint --test

# ESLint
lint-js:
    bun run lint:js

# Stylelint
lint-css:
    bun run lint:css

# Markdownlint
lint-md:
    bun run lint:md

# Latte template syntax
lint-latte:
    composer lint:latte

# No inline scripts (CSP)
lint-csp:
    composer lint:no-inline-scripts

# Fix all auto-fixable lint issues
fix: fix-php fix-js fix-css fix-md

# Fix PHP style
fix-php:
    vendor/bin/pint

# Fix JS lint
fix-js:
    bun run lint:js:fix

# Fix CSS lint
fix-css:
    bun run lint:css:fix

# Fix Markdown lint
fix-md:
    bun run lint:md:fix

# Check formatting (Prettier)
format:
    bun run format

# Fix formatting
format-fix:
    bun run format:fix

# ─── Analyse ────────────────────────────────────────────────────────────

# Run all static analysis (PHPStan + Psalm + TypeScript)
analyse: phpstan psalm typecheck

# PHPStan (level 10)
phpstan:
    vendor/bin/phpstan analyse

# Psalm
psalm:
    vendor/bin/psalm

# TypeScript type checking
typecheck:
    bun run typecheck

# Rector dry-run
rector:
    vendor/bin/rector --dry-run

# ─── Test ───────────────────────────────────────────────────────────────

# Run unit tests
test:
    vendor/bin/phpunit --testsuite Unit

# Run integration tests
test-integration:
    vendor/bin/phpunit --testsuite Integration

# Run all test suites in parallel
test-parallel:
    tools/test-parallel.sh

# Run Playwright E2E tests
test-e2e:
    bun run test:e2e

# Run Playwright E2E with UI
test-e2e-ui:
    bun run test:e2e:ui

# ─── Templates ──────────────────────────────────────────────────────────

# Precompile all Latte templates
precompile:
    composer precompile:templates

# Clear compiled Latte cache
clear-cache:
    rm -rf _data/templates_c/latte/

# ─── CI ─────────────────────────────────────────────────────────────────

# Run the full CI gate locally
ci: lint analyse test build precompile
    @echo "All gates green."

# PSR-4 autoload validation
psr4:
    composer dump-autoload --strict-psr

# Composer + bun dependency audit
audit:
    composer audit --abandoned=fail
    @echo "---"
    @echo "bun has no built-in audit; use 'npx audit-ci' or socket.dev if needed"

# ─── OpenAPI ────────────────────────────────────────────────────────────

# Dump OpenAPI spec to openapi.json
openapi-dump:
    bun run openapi:dump

# Lint OpenAPI spec
openapi-lint: openapi-dump
    bunx redocly lint openapi.json
