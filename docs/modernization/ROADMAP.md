# Piwigo 16.x — Modernization Roadmap

Detailed breakdown of the remaining modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries.

Recommended sequence: 2 → 10 → 3 → 4 → 5 → 7 → 8 → 6 → 11.

Item numbers are stable identifiers — completed items are removed, but the remaining numbers do not shift. See [MODERNIZATION.md](MODERNIZATION.md) for the history of completed items (e.g., #1 PHPStan level 9, #9 jQuery removal).

---

## #2 — Eliminate procedural `global` declarations across the codebase

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

5. **PHPStan.** Level-9 errors in the WS layer (from #1) should drop significantly once `src/Piwigo/Ws/` is the single source of truth.

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

---

## #7 — TypeScript `any` reduction

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Reduce `any` escapes in authored TypeScript from the current **468** to **≤250**, focusing on `(window as any)` calls and untyped function parameters. Do not touch vendored `node_modules/` or generated `dist/`.

### Current state

- 468 total `any` patterns across `admin/themes/default/js/` and `themes/default/js/`.
- Largest concentrations: `common.ts` (window globals for plugin interop), `batchManagerGlobal.ts` (legacy data shapes), `user_list.ts` (plugin tab API).

### Approach

**Tier 1 — window globals for plugin interop (~130 instances).** Functions like `applyFontCheckbox`, `array_delete`, `sprintf` are assigned to `window` so Smarty-rendered inline scripts can call them. These should stay on `window` for the plugin contract but can be typed via a declaration file:

```typescript
// src/types/admin-globals.d.ts
interface Window {
    applyFontCheckbox(el: HTMLInputElement): void;
    array_delete<T>(arr: T[], value: T): T[];
    sprintf(format: string, ...args: unknown[]): string;
    TemporaryState: typeof TemporaryState;
    // …
}
```

With the interface in place, replace `(window as any).applyFontCheckbox` with `window.applyFontCheckbox`.

**Tier 2 — untyped plugin callbacks (~80 instances).** Plugin function maps in `batchManagerUnit.ts` and `batchManagerGlobal.ts` use `(window as any)[pluginId + '_save']`. Type as:

```typescript
type PluginSaveCallback = (pictureId: number) => Promise<void> | void;
const pluginSave = (window as Record<string, unknown>)[pluginId + '_save'] as PluginSaveCallback | undefined;
```

**Tier 3 — data shape unknowns (~100 instances).** `fetch()` responses typed as `any`. Replace with explicit interfaces for each WS method response shape. Start with the most-used: `pwg.images.search`, `pwg.categories.getList`, `pwg.tags.getList`.

**Keep:** `(window as any).pluginValues`, `(window as any)[pluginId + '_batchManagerSave']` in plugin interop hot-paths — these are acceptable `any` uses where the call target is truly dynamic.

### Steps

1. Create `themes/default/js/types/admin-globals.d.ts` and `themes/default/js/types/ws-responses.d.ts`.
2. Fill in Tier 1 declarations — `npm run typecheck` confirms each file as it is typed.
3. Tier 2: replace cast per file, largest files first (`common.ts`, `batchManagerGlobal.ts`, `user_list.ts`).
4. Tier 3: add WS response interfaces, replace `any` in `fetch().then((data: any) =>` chains.

### Verification

```bash
grep -rn ": any\b\|as any\b\|(window as any)" admin/themes/default/js/ themes/default/js/ --include="*.ts" | wc -l
# target: ≤ 250
npm run typecheck   # still zero errors
```

---

## #8 — CSS design tokens + Stylelint

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

- Stylelint passing for all 31 authored CSS files with zero errors and no `!important` outside JS-toggled visibility rules.
- CSS custom properties for all repeated colors, spacing values, and breakpoints.
- Monster files split into per-concern files: `admin/themes/default/theme.css` (8,375 lines) and `themes/default/theme.css` (1,122 lines) become thin `@import` lists.
- Admin child themes (`clear`, `roma`) reduced to `:root {}` variable override blocks.
- `themes/modus/` skins refactored from hundreds of element overrides to single `:root {}` blocks.

### Current state

- `admin/themes/default/theme.css`: **8,375 lines**, monolithic (60+ `/* name.css */` section markers baked in).
- `admin/themes/roma/theme.css`: **2,716 lines** — duplicates parent section headers and carries far more than color overrides.
- `admin/themes/clear/theme.css`: **1,392 lines** — same problem.
- `themes/default/theme.css`: **1,122 lines**, unsplit.
- `themes/default/fix-khtml.css`: **16 lines**, orphan — zero references anywhere in the repo.
- `themes/default/css/clear-search.css` + `dark-search.css`: **295 + 298 lines** — color-only variants duplicating `search.css` structure.
- **572 `!important` declarations** across 31 project CSS files. Grouped:
  - ~230 justified (child-theme load-order, plugin overrides, JS-toggled visibility) — keep permanently.
  - 22 tom-select overrides — fix with higher specificity.
  - ~176 in `themes/modus/skins/*.css` — disappear when token system lands.
  - ~95 internal specificity battles across ~20 files — fix file-by-file.
- Stylelint configured but `ignoreFiles` excludes whole theme directories instead of just vendor libraries.
- Zero CSS custom properties anywhere in project CSS.
- No canonical breakpoints — `576px`, `640px`, `800px`, `1100px` used inconsistently.

### File inventory (31 authored files in scope)

**Frontend themes:**
- `themes/modus/css/hf_base.css` (917 lines), `plugin_compatibility.css`, `tags.css`
- `themes/modus/skins/*.css` — 11 skin files (avocado, blueberry, cafe_latte, glacier, neon_orange, neon_pink, newspaper, quartz, splash, strawberry_jam, swimming_pool)
- `themes/default/theme.css`, `css/search.css`, `css/clear-search.css`, `css/dark-search.css`
- `themes/elegant/theme.css`, `themes/smartpocket/theme.css`, `themes/bootstrap_darkroom/theme.css`

**Admin themes:**
- `admin/themes/default/theme.css`, `css/components/general.css`
- `admin/themes/clear/theme.css`, `css/components/general.css`
- `admin/themes/roma/theme.css`, `css/components/general.css`

**Plugins:**
- `plugins/GDThumb/css/gdthumb.css`, `admin.css`
- `plugins/AdminTools/template/admin_style.css`, `public_style.css`
- `plugins/language_switch/language_switch.css`, `style.css`

**Out of scope (vendor):** `themes/bootstrap_darkroom/css/**`, fontello files, open-sans files, `themes/default/js/plugins/selectize.*.css`, `themes/elegant/admin/jquery.ui.button.css`.

### Inline `<style>` block inventory

**Static blocks — safe to extract (no `{$var}` / `{if}` / `{cssResolution}` inside):**
`album_notification.tpl`, `albums.tpl`, `batch_manager_unit.tpl`, `cat_list.tpl`, `cat_modify.tpl`, `cat_search.tpl`, `configuration_display.tpl`, `configuration_sizes.tpl`, `generate_thumbnails.tpl`, `generate_video_thumbnails.tpl`, `help.tpl`, `history.tpl`, `install.tpl` (+ `upgrade.tpl` — nearly identical), `intro.tpl`, `maintenance_actions.tpl`, `maintenance_env.tpl`, `menubar.tpl`, `permalinks.tpl`, `photos_add_applications.tpl`, `photos_add_direct.tpl`, `picture_modify.tpl`, `rating_user.tpl`, `site_update.tpl`, `updates_pwg.tpl`, `user_activity.tpl`, `user_list.tpl`, `batch_manager_global.tpl` (second block only), `themes/modus/admin/modus_admin.tpl` (75 lines static), `themes/smartpocket/admin/admin.tpl`, `themes/smartpocket/template/search.tpl`, plugin templates in AdminTools/GDThumb/LocalFilesEditor/TakeATour.

**Dynamic blocks — must stay inline:** `batch_manager_global.tpl` (first block — thumb sizes), `thumbnails.tpl`, `month_calendar.tpl`, `mainpage_categories.tpl`, `comment_list.tpl` (all themes), `themes/default/template/mail/*/header.tpl` (email, must be inline).

### `!important` tier breakdown

**Tier 1 — Keep permanently (~230 instances).** Add `/* reason */` comment where missing.

| Reason | Files | Count |
|--------|-------|-------|
| Child-theme load-order (child CSS loads before parent; overrides need `!important` until CSS variable migration is complete) | `admin/themes/roma/theme.css`, `admin/themes/clear/theme.css` | ~97 |
| Third-party plugin CSS override (mcs-search, masonry inject their own CSS) | `plugin_compatibility.css`, `search.css`, `dark-search.css`, `clear-search.css` | ~33 |
| Modus skin overrides of mcs-search (already commented) | All 9 skin files | ~83 |
| Masonry/JS inline position overrides | `hf_layout.css`, `hf_responsive.css`, `thumbnails.css` | ~7 |
| JS-toggled visibility (`display: none/flex/block`) | `user_activity.css`, `upload.css`, `user_list.css`, `icons.css`, `smartpocket/theme.css`, `search-in-set.css` | ~7 |
| Tag cloud JS inline-style override | `themes/modus/css/tags.css` | ~5 |

**Tier 2 — Fix with higher specificity: tom-select overrides (22 instances).**
`batch_manager_unit.css` (11) and `picture_modify.css` (11) contain an identical block styling `.ts-control .item` at specificity (0,2,0). Tom-select ships `.ts-control > .item` at (0,1,1). Our (0,2,0) already wins — `!important` is redundant. Fix: verify against `node_modules/tom-select/dist/css/tom-select.css`, then drop `!important`. Extract the shared block into `admin/themes/default/css/components/tomselect-item.css`.

**Tier 3 — Fix internal specificity battles (~95 instances, ~20 files).**
These exist because the original monolithic `theme.css` relied on cascade order within one file; when split, overrides lost their position advantage.

| File | Count | Notes |
|------|-------|-------|
| `css/pages/album-manager.css` | 16 | Mixed: tom-select items (Tier 2 pass) + layout overrides |
| `css/pages/dashboard.css` | 11 | Pure specificity: cursor, display, margin, padding, text-decoration |
| `css/pages/user-manager.css` | 8 | `background: var(--admin-*)` fighting higher-specificity parent rules |
| `css/pages/plugins.css` | 7 | `display: grid/inline/flex`, `margin-right/left: 0` |
| `css/pages/user_list.css` | 6 | Excluding 2 JS-toggled display:none (keep) |
| `css/pages/albums.css` | 6 | Tom-select `.item` (Tier 2 fix) + margin |
| `css/pages/cat-list.css` | 5 | `var(--admin-*)` + transform — fighting higher specificity |
| `css/components/icons.css` | 3 | Excluding 1 JS-toggled |
| `css/pages/watermark.css` | 3 | |
| `css/pages/history.css` | 3 | |
| Remaining single-instance files | ~10 | tabsheets, content, batch-manager, picture-edit, themes/elegant, colors.css, picture.css |

Approach per instance: (1) note the property + selector, (2) grep for conflicting rule, (3) fix by raising specificity, lowering source rule specificity, or reordering within the file.

### Target directory layout

**`admin/themes/default/css/`** (post-split):
```
base/
  reset-defaults.css       ← "General defaults", forms, "Tables & forms"
  typography.css
components/
  general.css              ← (existing file, unchanged)
  pagination.css
  waiting.css
  tipTip.css
  menubar.css
  tabsheets.css
  dropdown.css
  search-bar.css
  datepicker.css
  webkit-hacks.css
  tomselect-item.css       ← shared Tier 2 fix (extracted from batch_manager_unit + picture_modify)
pages/
  dashboard.css
  history.css
  batch-manager.css
  tag-manager.css
  picture-edit.css         ← "Picture Edit" + "Format tab"
  album-manager.css        ← "Album Manager" + "album search" + "Move Album"
  user-manager.css         ← "UserList Pop in" + "Edit user popin" + "Activity Tab"
  comments.css             ← "Pending Comments"
  watermark.css
  upload.css               ← "Add photos, direct mode" + "Upload Form"
  plugins.css
  install-upgrade.css
  intro.css
  rating.css
  rating-user.css
  cat-modify.css
  cat-list.css
  cat-search.css
  cat-perm.css
  user-activity.css
  user-list.css
  albums.css
  batch_manager_unit.css   ← (existing file, keep name)
  batch_manager_global.css
  picture_modify.css       ← (existing file, keep name)
features/
  selection-mode.css
  merge-options.css
  group-editor.css
  jqtree-overrides.css
  icons.css
theme.css                  ← thin entry: @import the above in order
```

**`themes/default/css/`** (post-split):
```
menubar.css
content.css
picture.css
layout.css
colors.css
forms.css
calendar.css
thumbnails.css
comments.css
popup.css
search.css                 ← variable-driven (replaces search.css + clear-search.css + dark-search.css)
iconset.css                ← (unchanged)
print.css
theme.css                  ← thin entry: @import the above
```

### Steps

Execute in this order (risk-free first):

**Step 1 — Extend Stylelint coverage.**
Update `.stylelintrc.json` `ignoreFiles` to exclude only vendor libraries:
```json
"ignoreFiles": [
  "node_modules/**", "dist/**", "tests/**",
  "themes/bootstrap_darkroom/css/**",
  "themes/modus/css/open-sans/**",
  "themes/modus/css/fontello/**",
  "themes/default/fontello/**",
  "themes/default/js/plugins/**",
  "themes/elegant/admin/**"
]
```
Add new rules: `"declaration-no-important": [true, {"severity": "warning"}]`, `"custom-property-pattern": "^[a-z][a-z0-9-]*$"`, `"color-no-invalid-hex": true`, `"shorthand-property-no-redundant-values": true`. Record new baseline (expected: ~3,557 errors, 572 warnings across 31 files).

**Step 2 — Mechanical auto-fix.**
```bash
bunx stylelint --fix $(git ls-files '*.css' | grep -v node_modules | grep -v dist)
```
Eliminates all auto-fixable formatting errors (empty lines, whitespace, shorthand redundancy, pseudo-element `::` notation) without touching logic. Rerun lint, record new baseline.

**Step 3 — Delete the orphan.**
```bash
git rm themes/default/fix-khtml.css
```

**Step 4 — Split `themes/default/theme.css`** along its `/** Menubar / Content / Picture / Default Layout / Default colors / Tables & forms */` section markers into the per-concern files listed in the target layout above. `themes/default/theme.css` becomes an `@import` list. `themes/default/template/header.tpl` is unchanged — it still loads `theme.css`.

**Step 5 — Collapse search CSS variants.**
Replace `search.css` + `clear-search.css` + `dark-search.css` with a single `search.css` using `--search-*` CSS variables:
```css
/* search.css — variable-driven */
.filter .filter-icon        { color: var(--search-icon); }
.filter-manager-popin       { background-color: var(--search-popin-bg); }
```
Each skin supplies its `--search-*` variable set (either in its `theme.css` `:root` block or a dedicated skin file). Drop the `{$themeconf.colorscheme}-search.css` load in `themes/default/template/inc/search_filters.inc.tpl:4`. Net savings: ~500 lines.

**Step 6 — Non-color design tokens in `themes/modus/css/hf_base.css`.**
Add at top:
```css
:root {
  --space-xs: 5px;   --space-sm: 10px;  --space-md: 15px;
  --space-lg: 20px;  --space-xl: 30px;
  --font-size-sm: 13px; --font-size-base: 15px; --font-size-lg: 20px;
  --line-height-base: 1.5;
  --radius-sm: 5px;  --radius-md: 10px;
  --z-dropdown: 100; --z-overlay: 500;  --z-modal: 1000;
  --bp-sm: 576px;    --bp-md: 800px;    --bp-lg: 1100px;
}
```
Replace all hardcoded values throughout the file. Canonical breakpoints: `sm=576px md=800px lg=1100px` — adopt project-wide, add `/* Breakpoints: sm=576px md=800px lg=1100px */` header in every file that uses media queries.

**Step 7 — Color tokens from PHP skin system in `themes/modus/css/base.css.tpl`.**
Emit ALL `$skin` colors as CSS variables once at top:
```css
:root {
  --color-bg:           {$skin.BODY.backgroundColor};
  --color-text:         {$skin.BODY.color};
  --color-link:         {$skin.A.color};
  --color-link-hover:   {$skin['A:hover'].color};
  --color-menubar-bg:   {$skin.menubar.backgroundColor|default:''};
  --color-menubar-text: {$skin.menubar.color|default:''};
  --color-dropdown-bg:  {$skin.dropdowns.backgroundColor|default:''};
  /* … all $skin keys */
}
```
Replace all inline `{$skin.*}` injections in the rest of `base.css.tpl` with `var(--color-*)`.

**Step 8 — Refactor modus skin files (eliminates 176 `!important`).**
With the token system in place each skin's only job is to override variable values. Transform each from hundreds of element-level overrides to a single `:root {}` block:
```css
/* avocado.css — before: 957 lines, 27× !important */
/* avocado.css — after: ~40 lines, 0× !important */
:root {
  --color-accent:      #74bf04;
  --color-accent-dark: #65a603;
}
```
The `!important` in skin files exists solely because they fight specificity with `hf_base.css`. Once both use the same variable names, there is nothing to fight.

**Step 9 — Split `themes/modus/css/hf_base.css`** (917 lines) into four files loaded via `{combine_css}` in `themes/modus/template/header.tpl`:

| New file | Content |
|----------|---------|
| `hf_layout.css` | Structural rules, containers |
| `hf_components.css` | Nav, menus, thumbnails, forms |
| `hf_typography.css` | Font sizes, headings, links |
| `hf_responsive.css` | All `@media` blocks |

**Step 10 — Introduce CSS design tokens in admin parent.**
Create `admin/themes/default/css/base.css.tpl` (Smarty-templated, following the modus pattern):
```smarty
:root {
  --admin-bg:      {$admin_skin.page.backgroundColor};
  --admin-fg:      {$admin_skin.page.color};
  --admin-accent:  {$admin_skin.accent};
  --admin-border:  {$admin_skin.border};
}
```
Each `themeconf.php` for `clear` and `roma` defines its `$admin_skin` array. `admin/themes/default/template/header.tpl` loads `base.css.tpl` with `template=true` before the split CSS.

**Step 11 — Split `admin/themes/default/theme.css`** along its 60+ `/* name.css */` section markers into the target layout in the directory tree above. `admin/themes/default/theme.css` becomes an `@import` list.

**Step 12 — Slim admin child themes.**
With `var(--admin-*)` in place, `admin/themes/clear/theme.css` (1,392 lines) and `admin/themes/roma/theme.css` (2,716 lines) reduce to `:root {}` variable override blocks. Structural rules currently duplicated in both (borders, padding, grid, `@keyframes`) move up into the parent's split CSS.

**Step 13 — Plugin CSS: remove `!important` (quick wins).**
- `plugins/GDThumb/css/gdthumb.css:8` `background: inherit !important` — specificity of `ul.thumbnails .gdthumb` is sufficient, remove.
- `plugins/GDThumb/css/admin.css:41,42,46` — raise selector to `.GDThumb_config input[type="text"]`.
- Same treatment for `plugins/AdminTools/` and `plugins/language_switch/` instances.

**Step 14 — Relocate TakeATour skin files.**
Move the bodies of `plugins/TakeATour/css/clear.css` and `plugins/TakeATour/css/roma.css` into the corresponding admin skin CSS (or `admin/themes/{clear,roma}/css/takeatour.css`). Simplify `plugins/TakeATour/tpl/js_css.tpl:3-4`.

**Step 15 — `!important` final elimination pass.**
Work through Tier 2 (tom-select: `batch_manager_unit.css`, `picture_modify.css`, `albums.css`) then Tier 3 file-by-file from largest to smallest. After each file: `bunx stylelint --fix <file>` + browser smoke-test of that admin page. Keep all Tier 1 instances; add `/* reason */` comment to any that are missing one.

**Step 16 — Extract static inline `<style>` blocks.**
For every static template in the inventory above: cut CSS into `css/pages/<name>.css`, replace `<style>` block with `{combine_css path="admin/themes/default/css/pages/<name>.css"}`. Merge `install.tpl` and `upgrade.tpl` into one shared CSS file (they are near-identical today).

### Verification

After each step:
1. `bunx stylelint "**/*.css" --ignore-path .stylelintignore` — error/warning count decreasing.
2. Visual smoke-test on the admin pages touched: dashboard, each sidebar section, toggle clear/roma via the head button, gallery index/category/picture, search popin (light + dark), 3+ modus skins.
3. Network tab: confirm same or fewer CSS requests (the `{combine_css}` combiner bundles source files; more source files must not multiply requests).
4. Diff `_data/combined/t*.css` before and after — concatenated output should be byte-for-byte similar modulo ordering.

```bash
bunx stylelint "**/*.css" --ignore-path .stylelintignore   # zero errors, ≤100 warnings (Tier 1 keeps)
grep -rn "fix-khtml" .                                      # empty
grep -rn "clear-search\|dark-search" themes/               # empty
wc -l admin/themes/default/theme.css                        # ≤ 30 (just @imports)
wc -l themes/default/theme.css                             # ≤ 15 (just @imports)
grep -rn "!important" themes/modus/css/skins/              # empty (after Step 8)
```

---

## #10 — Overdue TODO cleanup

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

## #11 — Eliminate remaining `window.*` data-bridge globals in `{footer_script}` blocks

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Remove all `window.foo = value` data-bridge assignments in Smarty `{footer_script}` blocks. Each surviving assignment is a gap in the TypeScript module graph — the data is invisible to `tsc` and PHPStan. Replace each with either a `<script type="application/json">` page-data block (for structured data) or a `data-*` attribute (for single values).

### Current state

**20 remaining assignments** in `admin/themes/default/template/` (0 in `themes/default/template/` — frontend is already clean).

Key clusters:

| Template | Globals | Pattern |
|----------|---------|---------|
| `batch_manager_global.tpl` | `window.lang`, `window.all_elements`, `window.str_*`, `nb_thumbs_page`, `nb_thumbs_set` | page-data JSON block |
| `picture_modify.tpl` | `window.related_categories_ids`, `window.str_are_you_sure`, `window.url_delete`, `window.str_*` | mix of page-data + data-attrs |
| `admin.tpl` | `window.str_root`, `window.pwg_token` | page-data JSON block |
| `user_list.tpl` | `window.str_*` (user confirmation strings) | page-data JSON block |

### Steps

For each cluster:

1. **Add a `<script type="application/json" id="pwg-<page>-data">` block** to the PHP controller's `page_data_json` array (pattern established in `batch_manager_unit.php`).

2. **Read from `getPageData<T>('pwg-<page>-data')`** in the corresponding TS file.

3. **Remove the `window.*` assignments** from the `{footer_script}` block. If the block becomes empty, remove the entire `{footer_script}` / `{/footer_script}` pair.

4. For single-element targets (e.g., `window.url_delete` used as an `href`), prefer `data-url-delete="…"` on the triggering element and read it from `dataset` in the TS handler.

### Verification

```bash
grep -rn "^window\." admin/themes/default/template/ --include="*.tpl" \
  | grep -v "window\.location\|window\.open\|window\.confirm"
# must return empty
npm run typecheck    # still zero errors
npm run build        # clean
```
