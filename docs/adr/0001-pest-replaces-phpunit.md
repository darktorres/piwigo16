# ADR-0001: Pest 4 replaces PHPUnit

## Status

Accepted

## Context

`16.x-rewrite` (the design reference for this replay) used PHPUnit + Paratest for
unit/integration tests and a separate Playwright/TypeScript suite for browser E2E — two
frameworks, two syntaxes, two places for a contributor to learn. Pest 4 unifies unit,
integration, architecture (`pest-plugin-arch`), mutation testing (`pest-plugin-mutate`),
type coverage (`pest-plugin-type-coverage`), and browser E2E (`pest-plugin-browser`, which
wraps Playwright's own Chromium engine over WebSocket) behind one PHP-native test syntax and
one `vendor/bin/pest` entry point.

## Decision

`17.x-rewrite` uses Pest 4 (PHPUnit-compatible under the hood) as the sole PHP test
framework, including for browser E2E via `pest-plugin-browser`. No PHPUnit-only test files,
no separate Playwright/TypeScript E2E suite.

## Consequences

- One test command, one syntax, for unit/integration/arch/mutation/browser tests.
- `pest-plugin-browser`'s known gaps (no multi-tab, no network interception, no dialog
  handling) mean a small number of specs may need `->skip('requires multi-tab support')`
  with an upstream issue filed, rather than falling back to a second framework.
- Vitest remains, separately, for TypeScript unit tests — Pest can't execute TS logic.
