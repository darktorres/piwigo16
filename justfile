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

# Run the JS/TS test suite (Vitest)
test:
    bun run test

# Run the PHP test suite (Pest)
test-php:
    composer test

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

# Composer + JS unused-dependency/file checks
unused:
    composer unused
    bun run knip

# Validate docs/plan/manifest.yaml
plan-lint:
    composer plan-lint
