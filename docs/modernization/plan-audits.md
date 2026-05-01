# Modernization plan status audits — pending work

Last updated: 2026-05-01. Branch: `16.x-rewrite`.

Completed plans (iverson globals, adleman tests, matsumoto phases 0/1/5) are not listed.

---

## matsumoto umbrella — Phase 2 (~85-90%)

Long tail of untyped params on older free functions. Rector's `TYPE_DECLARATION` set is wired and bleeds this off gradually. Hot files: `include/functions.inc.php`, `include/functions_url.inc.php`.

---

## matsumoto umbrella — Phase 3 (~50-60%)

- Confirm the 20 legacy `*.class.php` shims in `include/` (12) and `admin/include/` (8) are empty stub aliases, not duplicate implementations — then remove them.
- Finish migrating any remaining plugin/theme classes still under `include/`.

---

## matsumoto umbrella — Phase 4 (~35-50%)

Largest remaining phase. Scaffolds and Wave A/B are in place; Wave C not started.

**Raw `$conf[...]` hot spots still to migrate:**

| File | Occurrences |
|---|---|
| `admin/include/functions.php` | 149 |
| `include/functions.inc.php` | 139 |
| `include/functions_user.inc.php` | 121 |
| `include/ws_functions/pwg.images.php` | 95 |

**`CurrentUser::` adoption** is light — `$user` global still dominant outside `src/`.

**Wave C** (ArrayObject deprecation proxy / `GlobalsBridge`) — not started.
