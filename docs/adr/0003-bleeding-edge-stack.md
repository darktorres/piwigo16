# ADR-0003: Hard-required bleeding-edge stack, no capability gating

## Status

Accepted

## Context

`origin/16.x` (this replay's starting point) targets old PHP with no lower-version floor
enforced anywhere in the codebase, and no CI to catch drift. Supporting a wide version range
means every feature that could use a modern language/server capability instead has to guard
for its absence, and the guard code itself never gets deleted once the floor eventually
rises.

## Decision

`17.x-rewrite` hard-requires PHP 8.5, MySQL 9.7 (MariaDB 12.x / PostgreSQL 18 in the provider
matrix), and Node 24. There is no lower-version floor and no capability-gating: a feature
that needs a specific server version requires it outright, rather than degrading. MySQL 9.x
is an *Innovation* (non-LTS) release line — an accepted risk, mitigated by pinning the exact
server version in Docker/compose and hedging provider lock-in via the MariaDB/PostgreSQL
matrix (see the plan's risk register). Versions verified 2026-05-31; re-verify at each
phase's execution time, since this is a fast-moving target.

## Consequences

- No version-compatibility shims or `function_exists()`-style feature detection for language
  features — code targets 8.5 directly.
- The Docker/compose runtime pins exact server versions so "MySQL 9.x is non-LTS" risk
  doesn't turn into silent behavior churn on an unpinned `latest` tag.
- Contributors and CI must run the pinned versions; there is no supported fallback path for
  older PHP/MySQL/Node.
