# Piwigo development task runner
# Usage: just <recipe>    Run a recipe
#        just --list      Show all recipes

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

# ─── Test ───────────────────────────────────────────────────────────────

# Full local gate — mirrors CI + docs/DEVELOPMENT.md's documented suite
# list. Needs a provisioned piwigo_test DB + Apache vhost + Chromium for
# the DB-backed suites; each self-provisions its own DB state (see
# docs/DEVELOPMENT.md's Tests section), so the dependency order below
# doesn't matter functionally. ECS runs last, non-blocking (`-` prefix) —
# just's prerequisite lists can't mark a step fallible, and this matches
# ci.yml's ecs job + lefthook's pre-commit hook, both non-blocking until
# P5's whole-codebase reformat.
test: analyse require-checker unused test-php test-integration test-contract test-browser test-visual test-js
    -composer lint:php

# Run the JS/TS test suite (Vitest)
test-js:
    bun run test

# Run the PHP test suite (Pest)
test-php:
    composer test

# Integration tests (needs .env.test + a running piwigo_test DB)
test-integration:
    composer test:integration

# WS API contract tests (needs .env.test + the fixture loaded)
test-contract:
    composer test:contract

# Browser E2E flows via pest-plugin-browser (needs .env.test + Apache + Chromium)
test-browser:
    composer test:browser

# Visual regression baselines — run in isolation, see docs/DEVELOPMENT.md
test-visual:
    composer test:visual

# Rebuild tests/Fixtures/piwigo-16.x.sql (destructive to piwigo_test)
test-fixture-regen:
    composer test:fixture-regen

# Code coverage (pcov) — Unit+Arch only
coverage:
    composer test:coverage

# TypeScript type-check (no emit)
typecheck:
    bun run typecheck

# PHPBench (first real subject, KernelBootBench, landed in P11)
bench:
    composer bench

# ─── Lint ───────────────────────────────────────────────────────────────

# Run all linters (PHP + JS + CSS)
lint: lint-php lint-js lint-css

# PHP code style (ECS, check mode)
lint-php:
    composer lint:php

# ESLint
lint-js:
    bun run lint:js

# Stylelint
lint-css:
    bun run lint:css

# ─── Static analysis ────────────────────────────────────────────────────

# PHPStan + Psalm
analyse:
    composer analyse

# ─── Dependency + plan hygiene ──────────────────────────────────────────

# Undeclared-dependency check (composer-require-checker)
require-checker:
    composer require-checker

# Composer + JS unused-dependency/file checks
unused:
    composer unused
    bun run knip

# Validate docs/plan/manifest.yaml
plan-lint:
    composer plan-lint

# ─── Database ───────────────────────────────────────────────────────────

# Reimport tests/Fixtures/piwigo-16.x.sql into piwigo_test
db-fixture:
    bash tools/reimport-fixture.sh

# Drop and recreate piwigo_test empty (follow with `just db-fixture` to
# reload the committed fixture). Reads credentials from .env.test, same
# convention as tools/reimport-fixture.sh and tools/restore-drill.sh.
db-reset:
    #!/usr/bin/env bash
    set -euo pipefail
    set -a
    source .env.test
    set +a
    mysql_args=(-h"${PIWIGO_DB_HOST}" -u"${PIWIGO_DB_USER}")
    if [ -n "${PIWIGO_DB_PASSWORD:-}" ]; then
      mysql_args+=(-p"${PIWIGO_DB_PASSWORD}")
    fi
    mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${PIWIGO_DB_BASE}\`; CREATE DATABASE \`${PIWIGO_DB_BASE}\`;"
