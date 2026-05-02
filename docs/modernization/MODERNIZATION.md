# Piwigo 16.x — Modernization & Architecture

Audience: contributors and plugin authors working against the `16.x-rewrite` branch.

**Current state:** Phases 0–6 and Phase 8 complete. PHP 8.5 strict, full TypeScript, PSR-4
namespacing, typed services, 218 unit tests, 15 E2E specs. Active work is Phase 7
(PHPStan baseline elimination).

---

## How the codebase boots

Every entry point (`index.php`, `admin.php`, `picture.php`, `ws.php`, …) follows the same prologue:

```php
define('PHPWG_ROOT_PATH', './');
require __DIR__ . '/vendor/autoload.php';
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();
```

**`include/common.inc.php`** is the legacy bridge. It loads `config_default.inc.php`,
`local/config/database.inc.php`, the mysqli dblayer free functions, `functions.inc.php`
and friends, then bootstraps the session and current user into globals.

**`Kernel::boot()`** (`src/Piwigo/Core/Kernel.php`) wires the typed service layer on top
of those globals via PHP reference bridges. The call is idempotent. Boot order:

1. `Config::attachGlobals()` — `$GLOBALS['conf']` becomes a reference into `Config::$data`
2. `PageState::attachGlobals()` — binds `$GLOBALS['page']` keys by reference
3. `Lang::attachGlobals()` — binds `$GLOBALS['lang']` and `$GLOBALS['lang_info']`
4. `CurrentUser::attachGlobals()` — binds `$GLOBALS['user']` keys
5. `ServiceLocator::register()` — registers `Config` and `PageState` instances

After boot, both `$conf['key']` and `Config::get('key')` return the same value. Legacy
`include/` free-function libraries keep working unchanged.

---

## Autoload layout

```text
composer.json
├── autoload.psr-4  "Piwigo\\"  → src/Piwigo/
├── autoload.psr-4  "Smarty\\"  → include/smarty/src/
├── autoload.files              → include/smarty/src/functions.php
└── config.classmap-authoritative: true
```

PHP free functions cannot be autoloaded. `include/functions.inc.php`,
`include/functions_user.inc.php`, and `include/dblayer/functions_mysqli.inc.php` are
loaded explicitly in `common.inc.php` and excluded from Rector and PHPStan. Only
classes migrate to `src/`; free functions stay.

---

## Typed services

### Config

`Piwigo\Core\Config` is the typed reader over `$conf`. Legacy access still works:

```php
global $conf;
$dir = $conf['upload_dir'];       // still fine
```

New code uses typed getters:

```php
Config::getString('upload_dir', './upload')
Config::getInt('upload_form_max_file_size', 100)
Config::getBool('enable_formats')
Config::get('any_key')            // returns mixed — fallback for dynamic keys
Config::override('key', $value)   // per-request, not persisted
conf_update_param('key', $value)  // DB-persisted (free function, unchanged)
```

### PageState

`Piwigo\Core\PageState` wraps `$page`. The `$GLOBALS['page']` arrays are bound by
reference, so `$page['errors'][] = 'msg'` and `PageState::current()->addError('msg')`
are equivalent.

```php
PageState::current()->addError('something went wrong');
PageState::current()->addInfo('done');
$errors = PageState::current()->errors;   // list<string>
```

### CurrentUser and Lang

Same pattern — typed facades with reference bridges to `$GLOBALS['user']` and
`$GLOBALS['lang']`. `l10n()` delegates to `Lang::current()->t()`.

### ServiceLocator

Minimal static map. After boot:

```php
$config = ServiceLocator::get(Config::class);
$page   = ServiceLocator::get(PageState::class);
```

Only `Config` and `PageState` are pre-registered. Plugins should not add to it.

---

## DB upgrade contract

`install/db/*.php` files are the permanent regression contract for existing-database
compatibility. Five rules, enforced by tooling:

1. **Excluded from Rector and PHPStan forever.** Any automated rewrite that touched
   these files could break the upgrade chain.
2. **No numbered scripts remain** — files 61–181 (Piwigo 1.3.0–15.0.0) were deleted
   in Phase 6. `install/db/` now contains only `index.php`.
3. **Upgrade floor is Piwigo 16.0.0.** `upgrade.php` refuses databases without
   `applied_upgrade` id 181 (the 15.0.0 marker) with HTTP 409.
4. **Free functions never disappear.** `pwg_query()`, `pwg_db_fetch_assoc()`,
   `pwg_db_real_escape_string()`, `pwg_db_num_rows()`, `conf_update_param()` retain
   their current signatures forever.
5. **`UpgradeChainTest` gates every push.** Loads `dev/fixtures/piwigo-16.x.sql`,
   drives `upgrade.php`, asserts `piwigo_db_version` matches `PHPWG_VERSION`.

To add a new 16.x upgrade script: create `install/db/<N>-database.php` (next integer
after 181), add 16.x version detection in `upgrade.php`, and insert the expected `<N>`
into `dev/fixtures/piwigo-16.x.sql`'s `piwigo_upgrade` table.

---

## JS / TypeScript build pipeline

### Entries and chunks

Vite 5 with 39 entry points in `vite.config.ts`:

```text
themes/default/js/*.ts          → core.scripts, mcs, switchbox, pngfix, rating, thumbnails.loader
themes/standard_pages/js/*.ts   → toaster_js, standard_pages_js, standard_profile_js
admin/themes/default/js/*.ts    → common, addAlbum, albums, batchManagerGlobal, … (30 files)
```

`LocalStorageCache`, `album_selector`, and `doubleSlider` are imported by multiple entries
and auto-promoted to shared chunks under `dist/assets/chunks/`. They have no standalone
Vite entries.

Build output goes to `dist/assets/` with content-hashed filenames. `dist/manifest.json`
maps each entry id to its hashed filename (Piwigo-specific format produced by
`build/piwigo-manifest-plugin.ts`; differs from Vite's own `.vite/manifest.json`).

### ScriptLoader integration

`Piwigo\Template\ScriptLoader::add()` consults `dist/manifest.json` when a
`{combine_script id="..."}` Smarty tag is processed. If the id is in the manifest,
the loader emits the hashed URL and clears the require list (Vite handles import order).
If the manifest is absent (fresh clone, no build), it falls back to the legacy
file-concatenation path — the gallery works without a build step.

### Dev workflow

```bash
npm run build        # production build — creates dist/ and dist/manifest.json
npm run dev          # Vite HMR dev server (set PIWIGO_VITE_DEV=1 in the web container)
npm run typecheck    # tsc --noEmit across all .ts files
npm run clean        # remove dist/ and _data/combined/
```

### Adding a new JS module

1. Create `.ts` in `admin/themes/default/js/` or `themes/default/js/`.
2. Add it as a Vite entry in `vite.config.ts` with the id that Smarty templates will reference.
3. Reference it from a template: `{combine_script id="my_module" path="...my_module.ts"}`.
4. Run `npm run typecheck && npm run build`.

---

## Authoring a new web service method

Register in `include/ws_functions.inc.php`:

```php
$service->addMethod(
    'pwg.my.method',
    'ws_my_method',
    ['photo_id' => ['type' => WS_TYPE_INT|WS_TYPE_POSITIVE]],
    'Description shown in the API browser'
);
```

Implement as a free function:

```php
function ws_my_method(array $params, PwgServer $service): mixed
{
    $id = (int) $params['photo_id'];
    // query DB, return result
    return ['id' => $id, 'status' => 'ok'];
}
```

Return a scalar/array for success, `new PwgError(404, 'msg')` for failure, or
`PwgNamedArray`/`PwgNamedStruct` to control XML/JSON output naming. The JSON envelope
is always `{"stat":"ok","result":...}` or `{"stat":"fail","err":N,"message":"..."}`.

---

## Local checks

This is a personal fork without enforced CI. Run these locally before
landing significant changes:

| Check | Command |
|---|---|
| PHP format | `vendor/bin/pint --test` |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` |
| Conf shape drift | `php tools/check-conf-shape.php` |
| TypeScript check | `npm run typecheck` |
| JS build | `npm run build` |
| Unit tests | `vendor/bin/phpunit --testsuite Unit` |
| Integration tests | `vendor/bin/phpunit --testsuite Integration` (needs `.env.local`) |
| E2E tests | `npx playwright test` (needs `.env.local` + local Apache up) |

---

## Roadmap

See **[ROADMAP.md](ROADMAP.md)** for the full breakdown of remaining work, with current state, concrete steps, and verification commands for each item.

Summary table:

| # | Description | Size | Status |
|---|---|---|---|
| 1 | PHPStan level 9 / baseline elimination (625 errors remaining) | L | **WIP** |
| 2 | Fix remaining `global` declarations inside `src/` | S | Verify/close |
| 3 | Remove class duplication in `ws_core.inc.php` | M | Not started |
| 4 | Unit test coverage expansion (229 tests → ≥40% coverage) | L | Not started |
| 5 | PHP 8.1–8.5 features: readonly, enum, match | M | Not started |
| 6 | `functions_user.inc.php` split into typed classes | L | Not started |
| 7 | TypeScript `any` reduction (468 → ≤250) | M | Not started |
| 8 | CSS design tokens + Stylelint | M | Not started |
| 9 | jQuery upgrade / incremental replacement | XL | Planning only |
| 10 | Overdue TODO cleanup (34 markers) | S | Not started |
| 11 | Eliminate remaining `window.*` data-bridge globals (~20 assignments) | M | ✅ Done — see ROADMAP-TS.md #3 and PLAN-inline-assets-extraction.md |

Recommended sequence: 1 → 2 → 10 → 3 → 4 → 5 → 7 → 8 → 6 → 9 → 11.

---

## What is not yet modernized

- **Templates** — still Smarty 5; ~300 `.tpl` files. No plans to replace.
- **Plugin contract** — plugin API uses `add_event_handler()` patterns. `PluginMaintain`
  and `ThemeMaintain` are namespaced but the contract is unchanged.
- **Free-function libraries** — `include/functions*.inc.php` have native types and
  `declare(strict_types=1)` but remain procedural and non-namespaced.
- **Translations** — `language/*.php` stays as `$lang['key'] = 'value'` arrays,
  excluded from Rector and PHPStan permanently.
- **Third-party themes** — not Rector- or PHPStan-checked; PHP 8.5 compatibility is
  the theme author's responsibility.

---

## Plugin author guide

This section covers what changed in 16.x that can affect plugin behaviour without
causing an immediate fatal error.

### Configuration

`$conf` is still a plain PHP array and `$conf['key']` still works. No action required.

> **Note:** An earlier 16.x preview (Phase 4 Wave C) wrapped `$conf` in a
> deprecation-emitting proxy. That proxy was removed before final release. If you saw
> `E_USER_DEPRECATED` notices during pre-release testing, they are gone.

Voluntary migration to typed getters (use the FQN to avoid alias dependency):

| Was | Now |
|---|---|
| `$conf['upload_dir']` | `\Piwigo\Core\Config::getString('upload_dir')` |
| `$conf['max_file_size']` | `\Piwigo\Core\Config::getInt('max_file_size')` |
| `$conf['enable_formats']` | `\Piwigo\Core\Config::getBool('enable_formats')` |
| `$conf['key'] = $v` | `\Piwigo\Core\Config::override('key', $v)` (per-request) |
| `conf_update_param(...)` | unchanged — still the right way to persist |

### Database layer

Only `mysqli` is supported. If your plugin checked `$conf['dblayer']`, that key still
exists and will always return `'mysqli'`. No code change needed.

### Class names

All first-party Piwigo classes moved to the `Piwigo\` namespace. Short unqualified names
still resolve via `src/Piwigo/Compat/aliases.php` (loaded by Composer automatically),
so existing `extends PluginMaintain` or `new PwgError(...)` calls continue to work.

To opt into IDE tooling, use the namespaced names:

| Old | New |
|---|---|
| `PluginMaintain` | `Piwigo\Admin\PluginMaintain` |
| `ThemeMaintain` | `Piwigo\Admin\ThemeMaintain` |
| `Template` | `Piwigo\Template\Template` |
| `PwgError` | `Piwigo\Ws\PwgError` |
| `PwgSession` | `Piwigo\Session\PwgSession` |

### PHP version

PHP 8.5 is required. Key breakage points:

- `curl_close()` is deprecated — use `unset($ch)`.
- `mysql_*` functions are gone — use `pwg_query()` / `pwg_db_*`.
- Undeclared dynamic properties emit `E_DEPRECATED` — add explicit declarations or
  `#[AllowDynamicProperties]`.

### Testing your plugin

Activate on a fresh 16.x install with `error_reporting(E_ALL)` and browse the gallery,
a photo page, and the admin dashboard. Check the PHP error log for any `Deprecated` or
`Warning` lines before shipping.

---

## Pending work

### Phase 6 — unconfirmed cleanup steps

- **Delete `GlobalsBridge.php`** — Wave C shipped but the proxy can be removed once
  deprecation logs confirm plugin-side access is quiet. Delete
  `src/Piwigo/Core/GlobalsBridge.php` and the `installAsConfProxy()` call in
  `Kernel::boot()`.
- **Static dblayer include** — `common.inc.php` uses a dynamic string-interpolation
  include; `Config::dbLayer()` always returns `'mysqli'`. Replace with a static
  `include` so PHPStan can see the 70 `pwg_*` free functions.
- **Delete `tests/e2e/global-setup.js`** — stale CJS leftover alongside the canonical
  `global-setup.ts`.
- **E2E TypeScript typecheck** — `tests/e2e/` is excluded from `tsconfig.json`;
  add `tests/e2e/tsconfig.json` and `tsc --noEmit -p tests/e2e/tsconfig.json` to the
  `typecheck` npm script.

### Phase 3/4 — sub-plan long tail

- **Phase 2 (~85–90%)** — untyped params on older free functions in
  `include/functions.inc.php`, `functions_url.inc.php`, etc. Rector bleeds this off
  gradually.
- **Phase 3 (~50–60%)** — 20 legacy `*.class.php` shims in `include/` (12) and
  `admin/include/` (8) should be confirmed as empty stub aliases and removed.
- **Phase 4 (~35–50%)** — raw `$conf[...]` hot spots still to migrate:
  `admin/include/functions.php` (149), `include/functions.inc.php` (139),
  `include/functions_user.inc.php` (121), `include/ws_functions/pwg.images.php` (95).
  `CurrentUser::` adoption is light — `$user` global still dominant outside `src/`.
