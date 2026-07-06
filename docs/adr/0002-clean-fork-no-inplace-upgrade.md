# ADR-0002: Clean fork — no in-place upgrade from upstream Piwigo

## Status

Accepted

## Context

`17.x-rewrite`'s schema, config keys, and namespace layout diverge from upstream Piwigo
enough (InnoDB+utf8mb4 uniformly, 277 SCHEMA config entries vs. 189, PSR-4 namespaces,
Doctrine migrations replacing the 23 procedural `install/db/*.php` upgrade scripts) that a
version-by-version in-place upgrade path from an existing upstream install is not a realistic
target — it would mean permanently maintaining two incompatible schema-evolution mechanisms
side by side.

## Decision

There is no in-place upgrade from an existing upstream Piwigo install. Instead, a one-way
`bin/piwigo import:legacy` tool (see [ADR-0025](0025-legacy-import.md), and
docs/PLAN-REPLAY.md's "Legacy import" adoption track, depending on P15 + P23) migrates an
existing install's database and files into a fresh v17 install. Version-to-version upgrades
*within* the v17 fork use Doctrine Migrations (P14).

## Consequences

- Existing Piwigo installs adopt v17 via a one-time data migration, not a rolling upgrade —
  documented as a deliberate break, not an oversight.
- The fork never has to support reading/writing the legacy upstream schema or config format
  as a live, ongoing compatibility surface — only as the one-shot `import:legacy` input
  format.
- All future v17.x → v17.y upgrades go through Doctrine Migrations exclusively; no procedural
  `install/db/*.php`-style scripts are added going forward.
