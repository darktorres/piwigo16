# F5 — Pending / Incomplete

Status of the [F5 plan](../../.claude/plans/what-is-the-proper-magical-taco.md)
audited against the live codebase (not git history). PHPStan level-10,
Psalm errors-only and PHPUnit are all green; the gaps below are
scoped work, not regressions.

## F5-b — factory classes (partial)

`src/Piwigo/Core/resolve.php` exists and is used in
`config/container.php:54`. The companion `Container/*Factory.php`
directory was never created — `config/container.php` still has 50+
inline `factory(static fn ...)` closures. Plan called for "one per
existing newLazyProxy site" extracted to typed factory classes.

**Work**: extract the inline closures into
`src/Piwigo/Core/Container/<Name>Factory.php` (constructor-injected
deps, single `build(): T` method). Replace the closure with
`factory([Factory::class, 'build'])`.

## F5-c — SessionService → SessionStore rename

Functionally complete: `Session.php` exists, `$_SESSION[` only lives
in `Session.php` + `FlashBag.php` (the plan's gate).
`SessionService.php` (87 lines, PHP `SessionHandlerInterface` impl)
was never renamed to `SessionStore.php`. Used by 6 in-tree sites.

**Work**: rename the file/class, sweep call sites
(`UserAdminService`, `AuthService`, `MaintenanceController`,
`UserService`, `SessionBootstrap`, the file itself).

## F5-h — Result DTOs sparse

83 Params DTOs vs. **7** Result DTOs. Plan called for output DTOs per
endpoint; most handlers still return raw arrays. Lower-value work
than Params (no validation surface, no `mixed` boundary), so
possibly deferrable — but the `SpecBuilder` OpenAPI reflection path
the plan envisioned cannot work without Result DTOs.

**Work, per endpoint**: extract the return shape into a `final
readonly class XxxResult` with `toArray(): array`; update the
handler return type to `XxxResult|PwgError`. `PwgServer` already
unwraps `WsResult` instances.

Eleven handlers have 0 `$params[]` accesses and were deliberately
skipped on the Params side (no input to type) — those still need
Result DTOs if we want full coverage:

- `Pwg/Extensions/{CheckUpdates,PluginsGetList}`
- `Pwg/{GetCacheSize,GetInfos,GetVersion}`
- `Pwg/History/Search`
- `Pwg/Images/{CheckUpload,EmptyLounge}`
- `Pwg/Session/Logout`
- `Pwg/Tags/GetAdminList`

## F5-i — `SearchRules` deep adoption

Foundation shipped: `SearchRules` + 17 filter VOs +
`MalformedSearchRulesException`. Adoption is shallow: `SearchService`
and `SearchFilterRenderer` consume some typed VOs but the
deep-rewrite the plan calls for is still pending.

`SearchFilterRenderer.php` is the **#1 psalm-info hotspot** (67
issues — top of the entire codebase). `SearchService.php` is #7 (47).
The plan body itself flagged this as "Remaining: …200+ mixed
accesses, high-risk, needs end-to-end smoke for every filter
combination."

**Work**: rewrite both files to consume `SearchRules` end-to-end.
Hand-test every filter combination (allwords, tags, dates, ratios,
ratings, dimensions, expert, added_by, filetypes) before/after.

## F5-k — acceptance gates

| Gate                                                              | Target                                                                        | Current                  |
| ----------------------------------------------------------------- | ----------------------------------------------------------------------------- | ------------------------ |
| `psalm --show-info` total                                         | `<50`                                                                         | **1877**                 |
| `grep -rn 'is_array(.* ?? null)' src/`                            | 0                                                                             | **154**                  |
| `$_SESSION[` outside Session.php/FlashBag.php                     | 0                                                                             | 0 ✓                      |
| Raw SQL outside repos/migrations                                  | 8 (install/upgrade umbrella, deferred per [[project_install_rework_planned]]) | 8 ✓                      |
| Every `@psalm-suppress` / `@phpstan-ignore` has rationale comment | 100%                                                                          | 28 sites (need re-audit) |
| Psalm errors-only                                                 | 0                                                                             | **0** ✓                  |
| PHPStan level-10                                                  | 0                                                                             | **0** ✓                  |
| PHPUnit                                                           | green                                                                         | **1239/1239** ✓          |

### Psalm-info hotspots (top 10 files)

```
67  src/Piwigo/Search/SearchFilterRenderer.php       (F5-i deep)
57  src/Piwigo/Category/CategoryRepository.php       (residue)
56  src/Piwigo/Section/SectionInitializer.php        (residue)
55  src/Piwigo/Telemetry/TelemetryService.php        (F5-j hardening)
52  src/Piwigo/Controller/Admin/MiscController.php   (residue)
50  src/Piwigo/Controller/Admin/MaintenanceController.php (residue)
47  src/Piwigo/Search/SearchService.php              (F5-i deep)
35  src/Piwigo/Controller/Admin/BatchManagerController.php (residue)
33  src/Piwigo/Users/UserRepository.php              (residue)
33  src/Piwigo/Image/ImageRepository.php             (residue)
```

The `<50` target is far away. F5-i deep adoption is load-bearing —
it directly burns down the #1 and #7 entries (114 issues
combined). Beyond that, the long tail in controllers and admin
classes will need a broad residue sweep that no single F5 step
currently owns.

## Suggested order

1. **F5-i deep adoption** — biggest single hotspot, blocks the
   acceptance gate.
2. **F5-h Result DTOs** for the 11 zero-params handlers (cheap, gets
   to 100% coverage on the Action namespace).
3. **F5-b factory classes** — improves container.php discoverability
   and unblocks any future per-factory typing.
4. **F5-c SessionStore rename** — cosmetic; do it as a low-risk pass
   between higher-value work.
5. **Residue sweep** — long-tail psalm-info burndown across
   controllers/admin/repositories. No single phase covers this
   today; needs its own letter.
