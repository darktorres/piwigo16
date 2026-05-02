# Piwigo 16.x — Modernization Roadmap (PHP)

PHP-only modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-TS.md](ROADMAP-TS.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

---

## #1 — Eliminate procedural `global` declarations across the codebase

**Status:** In progress (`src/` done; `admin/` and `include/` remain) &nbsp;|&nbsp; **Size:** L

### Goal

Zero `global $conf`, `global $user`, `global $page`, `global $lang`, `global $template` declarations anywhere in the application. All code accesses these through the typed service layer:

| Global       | Replacement                                                                  |
| ------------ | ---------------------------------------------------------------------------- |
| `$conf`      | `Piwigo\Core\Config::get(…)` / typed accessors (`Config::galleryTitle()` etc.) |
| `$page`      | `Piwigo\Core\PageState::current()->errors[]` etc.                            |
| `$lang`      | `Piwigo\Core\Lang::current()` / `Lang::get($key)`                            |
| `$user`      | `Piwigo\Users\CurrentUser::get()`                                            |
| `$template`  | `Piwigo\Template\TemplateRegistry::current()` (new — see step 1)             |

The reference-bridge pattern in `Config::attachGlobals()` and `PageState::attachGlobals()` makes the migration incremental: old `$conf['x']` and new `Config::get('x')` read/write the same backing storage, so plugins and untouched files keep working.

### Current state

- `src/`: **done** — `grep -rn "^global \$" src/` returns 0; `NoGlobalInSrcRule` enforces this in CI.
- `admin/`: **138** `global` statements across 72 files (largest concentrations: `include/add_core_tabs.inc.php` 21, `include/functions.php` 17, `include/functions_notification_by_mail.inc.php` 10, `include/functions_upload.inc.php` 9).
- `include/`: **158** `global` statements across 40 files (largest: `functions.inc.php` 17, `functions_user.inc.php` 15, `dblayer/functions_mysqli.inc.php` 12, `ws_functions/pwg.images.php` 12, `ws_functions/pwg.users.php` 12).
- PHPStan level 10 is the proxy metric: 1000+ errors at level 10 today (truncated output), ~75% trace back to `mixed` types from these unannotated `global` declarations. Hot-spot files: `cat_modify.php` (101 errors), `picture_modify.php` (76), `include/functions_notification_by_mail.inc.php` (57), `include/add_core_tabs.inc.php` (54), `include/functions.php` (41).

### Steps

1. **Add a `Template` accessor.** Introduce `Piwigo\Template\TemplateRegistry::current(): Template` and `::set(Template $t): void`, mirroring the contract of `Config::attachGlobals()`/`PageState::current()`. Wire `set()` from the two `$template = new Template(…)` sites in `include/common.inc.php`. Keep `$GLOBALS['template']` referencing the same instance during the migration window so untouched files (and plugins) still work.

2. **Migrate `include/` first** (40 files, smaller surface, more reused). Order bottom-up by dependency: leaf files first (`functions_*.inc.php`), orchestrating files (`common.inc.php`) last. Per file: drop the `global $conf, $user, $page, $lang, $template;` line and replace `$conf['x']` with `Config::get('x')`, `$page['errors'][] = …` with `PageState::current()->errors[] = …`, `$user['id']` with `CurrentUser::get()->id()`, `$template->assign(...)` with `TemplateRegistry::current()->assign(...)`. Commit per file or per logical group.

3. **Migrate `admin/`** (72 files). Work top-down by error-count hot spots: `cat_modify.php`, `picture_modify.php`, `include/functions_notification_by_mail.inc.php`, `include/add_core_tabs.inc.php`, `include/functions.php` first — these five account for ~30% of the level-10 error report.

4. **Extend the PHPStan rule.** Rename `NoGlobalInSrcRule` → `NoGlobalRule` (or add a sibling) covering `admin/` and `include/`. Activate as the last step so new `global` declarations fail CI.

5. **Drop the bootstrap stubs.** Once no caller references the globals, the `/** @var … */` annotations in `tools/phpstan-bootstrap.php` for `$conf`, `$user`, `$page`, `$lang`, `$template` (plus the auxiliary `$logger`, `$service`, etc. once their accessors land) can be removed. The bootstrap stays for `PHPWG_ROOT_PATH` and the plugin function signatures.

### Out of scope (decide separately)

The following globals also appear in `global` statements but are deferred — they need their own accessors before they can join `NoGlobalRule`: `$persistent_cache`, `$logger`, `$mysqli`, `$service`, `$filter`, `$pwg_loaded_plugins`, `$pwg_event_handlers`, `$prefixeTable`. Recommend including `$logger` and `$service` in this work; defer `$mysqli` to the Db layer effort.

### Verification

```bash
# Both must return zero hits in scope:
grep -rn "^[[:space:]]*global \$" admin/ include/ src/
grep -rnE "\bglobal \$(conf|user|page|lang|template)\b" admin/ include/ src/

# PHPStan level 10 must pass clean:
PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1 vendor/bin/phpstan analyse --no-progress

# E2E smoke (admin pages still load, plugins still work):
npx playwright test
```

---

## #2 — Overdue TODO cleanup

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Resolve or formally defer all `TODO`/`FIXME` markers in tracked PHP files. Current count: **34 markers** in `src/` and `include/`.

### Current state (selected markers)

| File | Line | Marker |
|------|------|--------|
| `include/common.inc.php` | 167 | `// TODO remove this data update as soon as 2025 arrives` — **past-due** |
| `include/functions.inc.php` | 1832 | `return $str; // TODO` — stub return, function body missing |
| `include/functions_category.inc.php` | 530 | `// TODO 2.7: add an upgrade script…` — pre-16 remnant |
| `include/config_default.inc.php` | 990 | `//TODO: Put this in admin…` — design note |
| `include/ws_functions/pwg.php` | 846 | `/*TODO - no need to get a huge number of rows…*/` — SQL optimization |
| `include/search_filters.inc.php` | 71 | `// TODO calling get_available_tags()… may cost time` — performance note |
| `src/Piwigo/Admin/updates.php` | 474 | `// TODO why redirect to a plugin page?` — logic question |

### Steps

1. **Triage each marker.** For each TODO: (a) fix it now, (b) open a dated tracking comment `// DEFERRED until X: reason`, or (c) delete the dead code it annotates.

2. **`common.inc.php:167` — act first.** The 2025 past-due item is a data migration shim. Check whether the condition it guards is still reachable in 16.x; if not, delete the block.

3. **`functions.inc.php:1832`** — identify what the function was supposed to do and either implement it or remove the function entirely if no call sites reference it.

4. **`functions_category.inc.php:530`** — the 2.7 upgrade script it references is long gone (pre-16 floor). Delete the comment and the dead branch.

5. **SQL optimization TODOs** — convert to `// PERF:` comments with a concrete ticket reference so they are not confused with unfinished code.

### Verification

```bash
grep -rn "TODO\|FIXME" src/ include/ --include="*.php" | grep -v "vendor\|install/db" | wc -l
# target: 0 actionable markers (DEFERRED/PERF markers are acceptable)
```

---

## #3 — Remove class duplication in `ws_core.inc.php`

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

`include/ws_core.inc.php` currently defines `PwgError`, `PwgNamedArray`, `PwgNamedStruct`, `PwgRequestHandler`, `PwgResponseEncoder`, and `PwgServer` — the same six classes that live under `src/Piwigo/Ws/` and are loaded by Composer autoload. The include file is the authoritative source today; `src/Piwigo/Ws/` holds the PSR-4 copies. The task is to invert this: make `src/` canonical and reduce `include/ws_core.inc.php` to just the `WS_TYPE_*` / `WS_PARAM_*` constants.

### Current state

- `include/ws_core.inc.php`: 681 lines — defines all 6 classes + 10 `define()` constants.
- `src/Piwigo/Ws/PwgServer.php`: 467 lines — PSR-4 copy already exists.
- All class aliases in `src/Piwigo/Compat/aliases.php` let unqualified `PwgError` etc. keep resolving.

### Steps

1. **Verify `src/Piwigo/Ws/` is feature-complete.** Diff each class in `include/ws_core.inc.php` against its `src/` counterpart. Confirm all methods, properties, and typed annotations are present in the `src/` version. If anything is missing, backport it.

2. **Convert `WS_TYPE_*` constants to an enum (bonus — ties into #5).** The 10 `define()` constants (`WS_TYPE_BOOL`, `WS_TYPE_INT`, `WS_TYPE_FLOAT`, `WS_TYPE_POSITIVE`, `WS_TYPE_NEGATIVE`, `WS_TYPE_NOTNULL`, `WS_PARAM_ACCEPT_ARRAY`, `WS_PARAM_FORCE_ARRAY`, `WS_PARAM_OPTIONAL`) are bitmask flags used in `addMethod()` call sites. Introduce `Piwigo\Ws\WsType` and `Piwigo\Ws\WsParam` backed integer enums (or flag constants on the class). Update `ws_functions.inc.php` registration sites.

3. **Strip the class bodies from `include/ws_core.inc.php`.** Replace each class definition with a `class_alias` call pointing at the `Piwigo\Ws\*` counterpart, or simply rely on the existing `src/Piwigo/Compat/aliases.php`. Keep only the `define()` constants (or forward them from the new enum) and the `global` declaration at the top. The file shrinks from 681 to ~30 lines.

4. **Confirm `ws.php` still boots.** `ws.php` includes `ws_core.inc.php` before using any WS types. With the class bodies removed, it must receive the PSR-4 autoloaded versions instead. Run `npx playwright test tests/e2e/` — the `WsApiTest` exercises the live WS layer.

5. **PHPStan.** Level-9 errors in the WS layer should drop significantly once `src/Piwigo/Ws/` is the single source of truth.

### Verification

```bash
grep -c "^class " include/ws_core.inc.php   # must be 0
vendor/bin/phpunit --testsuite Integration  # WsApiTest green
npx playwright test                         # e2e green
```

---

## #4 — Unit test coverage expansion (13% → ≥40%)

**Status:** Not started &nbsp;|&nbsp; **Size:** L

### Goal

Raise PHPUnit unit-test coverage from the current level to ≥40% of `src/` statements. Do not add DB/HTTP-dependent tests to the Unit suite — those belong in Integration or E2E.

### Current state

- **229 test methods** across `tests/Unit/` (Auth, Cache, Core, Image, Menu, Search, Session, Template, Users, Ws).
- Largest untested areas in `src/`: `Admin/` (image backends, plugins, themes, updates), `Calendar/`, `Db/`.

### Steps

1. **Establish a coverage baseline.** Run `vendor/bin/phpunit --testsuite Unit --coverage-html coverage/` and open `coverage/index.html`. Record which namespaces are below 20% — those drive priority.

2. **`src/Piwigo/Core/` — typed services.** `Config`, `PageState`, `Lang`, `CurrentUser`, `Kernel`, `ServiceLocator`. These are the highest-leverage tests: they underpin every other component. Target: 90%+ coverage on each.

3. **`src/Piwigo/Ws/` — encoders and server.** `PwgJsonEncoder`, `PwgRestEncoder`, `PwgXmlWriter`, `PwgServer::addMethod()` / `::verifyParams()`. Pure logic with no DB dependency. Target: 85%+.

4. **`src/Piwigo/Search/` and `src/Piwigo/Calendar/`.** Search Q-classes are already partially covered. Calendar classes require a DB stub — add `AbstractDbStub` to `tests/Unit/stubs/` returning canned result sets.

5. **`src/Piwigo/Template/` — ScriptLoader and manifest logic.** `ScriptLoader::add()` with and without `dist/manifest.json` present. Create a temp directory fixture in `setUp/tearDown`.

6. **`src/Piwigo/Admin/Image/` — GD backend only.** GD is always available in the test container. `image_gd::resize()`, `::rotate()`, `::flip()` against `dev/fixtures/sample.jpg`. Imagick/ext_imagick backends are integration-only.

### Verification

```bash
vendor/bin/phpunit --testsuite Unit --coverage-text | grep "Lines:"
# Target: ≥ 40.00%
```

---

## #5 — PHP 8.1–8.5 features: readonly, enum, match

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Adopt PHP 8.1–8.5 language features where they tighten invariants without changing public API. Targets: `readonly` properties on value objects, `enum` for flag/constant sets, `match` in place of exhaustive `switch` value maps.

### Current state

- `src/` has 68 classes; `include/` free functions are out of scope.
- No `readonly`, `enum`, or `match` usage found in `src/`.
- `include/ws_core.inc.php` has 10 `define()` bitmask constants — enum candidates (linked to #3).

### Steps

1. **Readonly properties.** Audit `src/` for classes whose constructor assigns properties that are never written again. Candidates: `Piwigo\Ws\PwgError` (`$_code`, `$_codeText`), `Piwigo\Cache\PersistentFileCache` (path properties), all search Q-token classes. Apply `readonly` to each confirmed write-once property.

2. **Backed enums.** Replace `define()` bitmask constants with `enum` backed by `int`:
   - `WsType: int` — `BOOL = 0x01`, `INT = 0x02`, `FLOAT = 0x04`, `POSITIVE = 0x10`, `NEGATIVE = 0x20`, `NOTNULL = 0x40` (linked to #3).
   - `GraphicsLibrary: string` — `'auto'`, `'imagick'`, `'ext_imagick'`, `'gd'` (from `Config` typed getter).
   - `DerivativeSize: string` — `'small'`, `'medium'`, `'large'`, `'thumb'`, `'xsmall'`, `'xxsmall'`.

3. **Match expressions.** Replace exhaustive `switch ($x) { case A: return 1; case B: return 2; default: throw }` blocks with `match`. Focus on `src/Piwigo/Template/ScriptLoader.php` and `src/Piwigo/Ws/` protocol dispatchers.

4. **Rector sweep.** After manual candidates are done, run Rector with `->withPhpSets(php81: true, php82: true, php83: true, php84: true, php85: true)` in dry-run mode — accept only the readonly and match rewrites; reject any that change public API.

### Verification

```bash
vendor/bin/rector process --dry-run   # "0 files would be changed" after manual pass
vendor/bin/phpstan analyse            # still green
vendor/bin/phpunit --testsuite Unit   # still green
```

---

## #6 — `functions_user.inc.php` split into typed classes

**Status:** Not started &nbsp;|&nbsp; **Size:** L

### Goal

Break the 2,673-line `include/functions_user.inc.php` into focused, namespaced classes under `src/Piwigo/Users/` and `src/Piwigo/Auth/`. Keep the free-function signatures as thin wrappers so all existing call sites continue to work without a sweep.

### Current state

- `include/functions_user.inc.php`: **2,673 lines**, no class structure.
- Logical clusters already visible in the file: user lookup / hydration, session management, authentication, cookie / remember-me, permissions, notification preferences.

### Steps

1. **Map the clusters.** Grep function names and group by domain:
   - `get_user_by_id`, `get_user_array`, `build_user`, `create_user`, `delete_user` → `Piwigo\Users\UserRepository`
   - `log_user`, `login_user`, `logout_user`, `verify_login` → `Piwigo\Auth\AuthService`
   - `remember_me_token`, `delete_remember_me_cookie`, `set_remember_me_cookie` → `Piwigo\Auth\RememberMeService`
   - `get_user_permissions`, `is_admin`, `is_webmaster`, `can_manage_user` → `Piwigo\Users\PermissionService`
   - `update_user_preferences`, `apply_user_theme` → `Piwigo\Users\PreferencesService`

2. **Move one cluster at a time, leaf-first.** For each cluster:
   a. Create the class under `src/Piwigo/Users/` or `src/Piwigo/Auth/`.
   b. Move the function bodies as `static` or instance methods.
   c. Leave the original free function in `functions_user.inc.php` as a one-liner delegating to the new class.
   d. Add unit tests for the new class before the free-function wrappers are removed.

3. **Tighten types.** Free functions that have `@param mixed $user_id` can be narrowed to `int` in the new class methods. Use `int|false` return types where appropriate.

4. **Remove the wrappers** once all call sites in `include/`, `admin/`, and `src/` have been migrated (or are confirmed to use the new class directly).

5. **PHPStan.** The new classes should be clean at level 9 from day one — write them typed, don't retrofit.

### Verification

```bash
vendor/bin/phpunit --testsuite Unit     # new class tests green
npx playwright test                     # login/logout flows green
grep -c "function " include/functions_user.inc.php   # shrinking per PR
```
