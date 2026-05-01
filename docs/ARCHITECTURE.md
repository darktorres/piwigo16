# Architecture

This document describes how the Piwigo 16.x-rewrite branch is organised after
the modernization plan completed (Phases 0–6). Audience: a contributor who has
just cloned the repo and wants to understand the moving parts.

---

## 1. Bootstrap order

Every HTTP entry point (e.g. `index.php`, `admin.php`, `picture.php`) follows
the same two-phase bootstrap:

**Phase 1 — legacy globals (handled by `include/common.inc.php`)**
1. Loads `include/config_default.inc.php` — populates `$conf` with defaults.
2. Loads `local/config/database.inc.php` — merges DB credentials into `$conf`.
3. Includes `include/dblayer/functions_mysqli.inc.php` — registers all `pwg_query()` etc. free functions.
4. Opens the DB connection, loads conf from DB, sets PHP locale/charset.
5. Loads `include/functions.inc.php` and friends — the bulk of Piwigo's free-function library.
6. Bootstraps the session (`PwgSession`), current user, and page state into globals.

**Phase 2 — typed services (handled by `Piwigo\Core\Kernel::boot()`)**

Called immediately after `include/common.inc.php` in every entry point:
```php
Kernel::boot();
```

`Kernel::boot()` wires the typed service layer to the already-loaded globals
via PHP reference bridges, making both `$conf['key']` and `Config::get('key')`
return the same data. The call is idempotent (guarded by `self::$booted`).

Boot sequence inside `Kernel::boot()`:
1. `Config::attachGlobals()` — references `$GLOBALS['conf']` into `Config::$data`.
2. `PageState::attachGlobals()` — binds page-state properties to `$GLOBALS['page']` keys.
3. `Lang::attachGlobals()` — binds `$GLOBALS['lang']` and `$GLOBALS['lang_info']`.
4. `CurrentUser::attachGlobals()` — binds `$GLOBALS['user']` keys.
5. `ServiceLocator::register()` — registers `Config` and `PageState` instances.

---

## 2. Autoload layout

```
composer.json
├── autoload.psr-4: "Piwigo\\" → src/Piwigo/
├── autoload.psr-4: "Smarty\\" → include/smarty/src/
├── autoload.files: include/smarty/src/functions.php
└── config.classmap-authoritative: true                 ← no filesystem scan at runtime
```

### Why `include/*.inc.php` free-function libraries stay

PHP free functions cannot be autoloaded. Files like `include/functions.inc.php`,
`include/functions_user.inc.php`, `include/dblayer/functions_mysqli.inc.php` are
loaded explicitly in `common.inc.php`. They contain the bulk of Piwigo's business
logic and cannot be moved to classes without rewriting every call site. They are
excluded from Rector and linted but not namespaced.

---

## 3. The DB upgrade contract

### Rules

1. `install/db/*.php` files are the upgrade-chain contract. They are excluded from
   Rector and PHPStan **permanently** — they must not be modernized.
2. The only file in `install/db/` after Phase 6 is `index.php` (security stub).
   All numbered scripts 61–181 were deleted; they covered Piwigo 1.3.0–15.0.0.
3. The upgrade floor is **Piwigo 16.0.0**. `upgrade.php` refuses databases that
   do not have applied_upgrade id 181 (the 15.0.0 boundary) with HTTP 409.

### Authoring a new 16.x upgrade script

When a schema change is needed for a 16.x release:

1. Create `install/db/<N>-database.php` where `<N>` is the next integer after 181.
2. Add 16.x version detection in `upgrade.php` (look for the "add detection here"
   comment after the 409 guard). The restore point is the commit just before
   Phase 6 step 2 in git history, which contains the full upgrade launch machinery.
3. Add the expected `<N>` to `dev/fixtures/piwigo-16.x.sql`'s `piwigo_upgrade`
   table so `UpgradeChainTest` exercises the new script.

### Free functions used by upgrade scripts

The following free functions must not be removed as long as any `install/db/*.php`
references them:
`pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_fetch_array()`,
`pwg_db_real_escape_string()`, `pwg_db_num_rows()`, `conf_update_param()`.

---

## 4. Globals / typed services

### Config

`Piwigo\Core\Config` is a typed facade over `$GLOBALS['conf']`.

```php
// Reading (preferred):
$dir = Config::getString('upload_dir', 'upload/');
$max = Config::getInt('upload_form_max_file_size', 100);

// Fallback (for unknown keys or mixed types):
$val = Config::get('my_plugin_setting');

// Per-request override (not persisted):
Config::override('die_on_sql_error', false);

// DB-persisted write:
conf_update_param('my_plugin_setting', $newValue);
```

`Config::attachGlobals()` establishes a PHP reference so `$conf['key'] = $v`
and `Config::override('key', $v)` are equivalent and stay in sync.

### PageState

`Piwigo\Core\PageState` wraps `$GLOBALS['page']` with typed accessors:

```php
PageState::current()->addError('something went wrong');
PageState::current()->addInfo('done');
$errors = PageState::current()->errors; // list<string>
```

### CurrentUser and Lang

`Piwigo\Users\CurrentUser` and `Piwigo\Core\Lang` follow the same pattern —
typed facades with reference bridges to `$GLOBALS['user']` and `$GLOBALS['lang']`.

### ServiceLocator

`Piwigo\Core\ServiceLocator` is a minimal service container. After boot, you can
resolve typed instances:

```php
$config = ServiceLocator::get(Config::class);
$page   = ServiceLocator::get(PageState::class);
```

Only `Config` and `PageState` are pre-registered. Plugins should not add to the
locator — use constructor injection or free function calls instead.

---

## 5. JS build pipeline

### Vite multi-entry build

The frontend build uses **Vite 5** with 39 entry points in `vite.config.ts`:

```
themes/default/js/*.ts          → core.scripts, switchbox, pngfix, rating, thumbnails.loader, mcs
themes/standard_pages/js/*.ts   → toaster_js, standard_pages_js, standard_profile_js
admin/themes/default/js/*.ts    → common, addAlbum, albums, batchManagerGlobal, … (30 files)
```

`LocalStorageCache`, `album_selector`, and `doubleSlider` are **not** standalone
entries — they are imported by multiple entry points and Vite promotes them to
shared chunks automatically (`assets/chunks/`).

Build output goes to `dist/assets/` with content-hashed filenames.
`dist/manifest.json` maps each entry id (e.g. `core.scripts`) to the hashed filename.

### Custom manifest plugin

`build/piwigo-manifest-plugin.ts` uses Rollup's `generateBundle` hook to capture
the input key (the rollupOptions.input key name) alongside the hashed output filename.
This is critical: Vite's own `.vite/manifest.json` uses file paths as keys, which
would map `core.scripts` incorrectly to `scripts`.

### ScriptLoader manifest-aware mode

`Piwigo\Template\ScriptLoader::add()` checks `dist/manifest.json` before registering
a script. If the entry id is present, it replaces the original path with the hashed
dist URL and clears the require list (Vite encodes import order internally).

**Fallback:** when `dist/manifest.json` is absent (fresh clone, CI without a build
step, or `npm run clean`), ScriptLoader falls through to the legacy file-concatenation
path. This means the gallery and admin work without a build, but JS is served
un-minified and un-hashed.

### Dev workflow

```bash
npm run build        # production build — creates dist/ and dist/manifest.json
npm run dev          # Vite dev server with HMR (requires PIWIGO_VITE_DEV=1 env)
npm run typecheck    # tsc --noEmit for all TS files including tests/e2e/
npm run clean        # rm -rf dist/ _data/combined/ (removes stale build artifacts)
```

TypeScript configuration: `tsconfig.json` at the root covers all authored `.ts`
files. Wave-1 relaxations (`noImplicitAny`, `strictNullChecks` disabled) allow
jQuery-era code to typecheck without a complete rewrite; Wave-2 tightening is a
future pass.

---

## 6. Authoring a new web service method

All web service methods live in `ws.php` (dispatch) and `include/ws_functions.inc.php`
(registration). The framework is `Piwigo\Ws\PwgServer`.

Steps:
1. Register the method in `include/ws_functions.inc.php`:
   ```php
   $service->addMethod(
       'pwg.my.method',
       'ws_my_method',           // callback name
       [                         // parameter definitions
           'photo_id' => ['type' => WS_TYPE_INT|WS_TYPE_POSITIVE],
       ],
       'Description shown in API browser'
   );
   ```
2. Implement the callback (free function or static method):
   ```php
   function ws_my_method(array $params, PwgServer $service): mixed
   {
       $id = (int) $params['photo_id'];
       // ... query the DB, return a result ...
       return ['id' => $id, 'status' => 'ok'];
   }
   ```
   Return values:
   - A scalar or array — encoded as the response payload.
   - `new PwgError(404, 'No such photo')` — encodes as `{"stat":"fail","err":404,...}`.
   - `new PwgNamedArray(...)` or `PwgNamedStruct(...)` — controls XML/JSON output naming.
3. The JSON response shape is fixed:
   ```json
   { "stat": "ok", "result": <return value> }
   ```

---

## 7. Where things are not yet modernized

- **Templates.** Still Smarty 5. ~300 `.tpl` files across `themes/default/` and
  `admin/themes/default/`. No plans to replace Smarty; the surface is too large and
  plugins extend templates by name.
- **Plugin contract.** The plugin loader uses legacy `add_event_handler('foo', 'callback')`
  patterns. `PluginMaintain` and `ThemeMaintain` are now namespaced, but the plugin
  API itself is unchanged.
- **Vendored frontend libraries.** jQuery and all jQuery plugins were removed in
  the Wave 1–6 migration. The only remaining vendored file is
  `themes/default/js/plugins/piecon.js` (favicon progress indicator, converted to
  an ES module). All other replacements are npm packages bundled by Vite.
- **Translations.** `language/*.php` stays as `$lang['key'] = 'value'` arrays.
  Excluded from Rector and PHPStan permanently.
- **Themes other than `default`.** Third-party themes are out of scope. Their
  files are not Rector- or PHPStan-checked. PHP 8.5 deprecation noise in third-party
  themes is the theme author's responsibility.
- **TypeScript strictness.** `tsconfig.json` has `strict: true`, `noImplicitAny: true`,
  and `strictNullChecks: true` all enabled. Some `any` casts remain in legacy-bridge
  code but the compiler enforces types across the authored TS surface.

---

## 8. CI gates

CI runs three jobs per push (GitHub Actions, `.github/workflows/ci.yml`).
All three must pass for merge.

### `lint`

| Step | Command | Fail reason |
|---|---|---|
| PHP format | `vendor/bin/pint --test` | PSR-12 / import-order drift |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | Type errors, banned constructs |
| Baseline guard | `bash tools/check-baseline.sh` | Baseline grew vs. committed version |
| Conf shape drift | `php tools/check-conf-shape.php` | Alias key removed from config_default |
| TypeScript check | `npm run typecheck` | TS errors in `.ts` or `tests/e2e/*.ts` |
| JS build | `npm run build` | Vite build failure |
| Tarball check | (inline script) | Vendor dev-deps leaked into release tarball |
| `strict_types` guard | (inline script) | PHP file missing `declare(strict_types=1)` |

### `unit`

`vendor/bin/phpunit --testsuite Unit` — runs `tests/Unit/`. No DB, no HTTP, no
filesystem mutation outside temp dirs. Zero failures, zero errors, zero risky tests.

Current coverage: `Core/` (Config, PageState, Kernel, Lang, PwgError),
`Template/ScriptLoader`, `Cache/PersistentFileCache`.

### `e2e` + integration

1. `docker compose up -d --wait db web` — MariaDB + PHP 8.5 Apache.
2. `npx playwright test` — full Playwright spec suite (install, smoke, upload,
   create-album, change-setting, Phase 5 console-clean).
3. `vendor/bin/phpunit --testsuite Integration` — `UpgradeChainTest` loads
   `dev/fixtures/piwigo-16.x.sql` and verifies `upgrade.php` updates
   `piwigo_db_version` to the current branch.
