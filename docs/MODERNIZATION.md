# Piwigo 16.x — Modernization & Architecture

Audience: contributors and plugin authors working against the `16.x-rewrite` branch.

**Stack:** PHP 8.5 with `declare(strict_types=1)`, full TypeScript via Vite 8, PSR-4 namespacing under `src/Piwigo/`, typed services with reference-bridge globals, PHPStan level 10 clean, PHPUnit + Playwright test suites.

---

## How the codebase boots

`index.php` is the only HTTP entry point. The legacy `i.php`, `action.php`, `install.php`, `upgrade.php`, and `qsearch.php` files no longer exist on disk — their behaviour is dispatched off the request query-string at the top of `index.php`.

`index.php` inspects `$_SERVER['QUERY_STRING']` and routes four prefixes through minimal bootstraps that bypass the full PSR-15 pipeline (no DB-loaded config, no plugins, no session):

| Prefix         | Controller                  | Why bypass                                   |
| -------------- | --------------------------- | -------------------------------------------- |
| `i/`           | `ImageDerivativeController` | Hot path — derivative serving must stay fast |
| `install`      | `InstallController`         | No DB exists yet                             |
| `upgrade`      | `UpgradeController`         | DB schema may be mid-migration               |
| `upgrade_feed` | `UpgradeFeedController`     | DB schema may be mid-migration               |

Every other request falls through to the full boot:

```php
require_once PHPWG_ROOT_PATH . 'vendor/autoload.php';
CommonBootstrap::run();
Kernel::boot();

new ResponseEmitter()->emit(
    Kernel::handle(RequestFactory::fromGlobals())
);
```

**`Piwigo\Bootstrap\CommonBootstrap::run()`** replaces the old `include/common.inc.php`. It registers the exception handler, sanitises superglobals, seeds `$GLOBALS['page']`/`['user']`/`['lang']`/`['filter']` arrays, runs `ConfigLoader::applyDefaults()` + `loadEnv()` + `applyEnvOverrides()`, redirects to `index.php?/install` if the install sentinel is missing, opens the DB connection, calls `Kernel::boot()`, loads `$conf` from the DB via `ConfigService::loadConfFromDb()`, runs Doctrine migrations when `Config::autoMigrate()` is true, wires the `Logger` into `LoggerRegistry`, redirects to `index.php?/upgrade` if `piwigo_db_version` lags `PHPWG_VERSION`, then bootstraps the session, user, plugins and theme.

**`Piwigo\Core\Kernel::boot()`** wires the typed service layer on top of the globals via PHP reference bridges. The call is idempotent. Boot order:

1. `PageState::attachGlobals()` — binds `$GLOBALS['page']` keys by reference
2. `Lang::attachGlobals()` — binds `$GLOBALS['lang']`
3. `CurrentUser::attachGlobals()` — binds `$GLOBALS['user']` keys
4. `Container::build()` — wires the PHP-DI container; `Kernel::service(X::class)` resolves services post-boot
5. `LoggerRegistry::set(new NullLogger())` if not yet initialised (the install/upgrade fast paths skip `CommonBootstrap`)
6. `StorageRegistry` is eagerly resolved from the container so procedural upload code can call `StorageRegistry::disk()` without going through the container

Migrations are intentionally NOT run from `Kernel::boot()` — they run from `CommonBootstrap::run()` after `loadConfFromDb()`, because some migrations depend on `$conf` being populated.

After boot, `$conf['key']` and the typed `Config` accessors read the same backing storage.

**`Kernel::handle()`** runs the PSR-15 middleware pipeline:

`ExceptionHandler → Session → Auth → Filter → CSRF → Routing → ControllerInvoker`

`ControllerInvokerMiddleware` resolves the matched controller from the DI container and invokes it. Unmatched routes get a 404 response directly from this middleware.

---

## Autoload layout

```text
composer.json
├── autoload.psr-4   "Piwigo\\"  → src/Piwigo/
├── autoload.files               → src/Piwigo/Calendar/CalendarConstants.php,
│                                  src/Piwigo/Search/QConstants.php
└── config.classmap-authoritative: true
```

The historical `include/` directory has been removed. All first-party PHP lives under `src/Piwigo/`. The two `autoload.files` entries are the only remaining non-class top-level constant definitions; everything else is a class and reachable via PSR-4.

`autoload-dev` adds `Piwigo\Tests\` → `tests/` and `Piwigo\Tools\PhpStan\` → `tools/phpstan/`.

---

## Typed services

### Config

`Piwigo\Config\Config` owns the runtime configuration as a private static array; the legacy `$GLOBALS['conf']` reference bridge (`attachGlobals`) has been retired. All reads and writes go through the typed facade — generated accessors from `Config::SCHEMA` by `tools/build-config-accessors.php`:

```php
Config::uploadDir()                  // string
Config::uploadFormMaxFileSize()      // int
Config::isFormatsEnabled()           // bool
Config::raw('blk_' . $id)            // mixed — escape hatch for parametric keys only
Config::override('key', $value)      // per-request, not persisted
Kernel::service(ConfigService::class)
    ->confUpdateParam('key', $value) // DB-persisted
```

Static keys MUST go through a typed accessor. The private `getString`/`getInt`/`getBool`
helpers throw `UnknownConfigKeyException` if called with a key not in `SCHEMA`. The
`tools/phpstan/ConfigKeyExistsRule.php` PHPStan rule fails the build on accessor-name
typos.

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
`$GLOBALS['lang']`. `Lang::t($key, ...$args)` is the static translation entry point;
`LangService::l10n()` is the request-scoped service used by templates.

### Kernel::service()

`ServiceLocator` was deleted in §1.3. Post-boot service resolution goes through
`Kernel::service(X::class)`, which delegates to the PHP-DI container. New code always
uses constructor injection instead; `Kernel::service()` is reserved for static-context
call-sites (all-static classes, cycle-breaking edges) where a constructor parameter is
not practical.

```php
// constructor injection — preferred for DI-managed classes
public function __construct(
    private readonly ConfigService $config,
    private readonly UrlGenerator $urls,
) {}

// Kernel::service() — only for static contexts or cycle-breaking
Kernel::service(ConfigService::class)->confUpdateParam('key', $value);
```

---

## DB upgrade contract

`install/db/` contains only `index.php` (a directory-protection redirect). The legacy
numbered upgrade scripts (61–181, covering Piwigo 1.3.0–15.0.0) were deleted in
Phase 6 and are not coming back. Three rules, enforced by tooling:

1. **Upgrade floor is Piwigo 16.0.0.** `UpgradeController` (mounted at `index.php?/upgrade`)
   refuses databases without `applied_upgrade` id 181 (the 15.0.0 marker) with HTTP 409.
2. **No core data migrations.** v17 is greenfield: `install/piwigo_structure-mysql.sql`
   defines the canonical schema directly. The `src/Piwigo/Migrations/` directory was
   retired along with `MigrationRunner` after the storage refactor (B1-B10) folded its
   per-batch DDL into the install schema. Per-plugin migrations still ship via
   `Piwigo\Plugin\Migration\PluginMigrationRunner`, which has its own ledger
   (`<prefix>plugin_migrations`).
3. **`UpgradeChainTest` gates every push.** It loads `dev/fixtures/piwigo-17.0.sql`,
   drives `index.php?/upgrade`, and asserts `piwigo_db_version` matches the current
   branch. A second test loads `dev/fixtures/piwigo-15.x.sql` on top to confirm the
   409 refusal path still works.

---

## JS / TypeScript build pipeline

### Entries and chunks

Vite 8 with ~69 entry points in `vite.config.ts` (9 frontend, 3 standard pages, ~57 admin):

```text
themes/_base/js/*.ts            → core.scripts, core.switchbox, search, popuphelp,
                                   picture_nav_keys, mcs, pngfix, rating, thumbnails.loader
themes/standard_pages/js/*.ts   → toaster_js, standard_pages_js, standard_profile_js
themes/admin/_base/js/*.ts      → common, admin, addAlbum, albums, batchManagerGlobal, …
```

Shared modules (`LocalStorageCache`, `album_selector`, `doubleSlider`, etc.) are
imported by multiple entries and auto-promoted to shared chunks under
`dist/assets/chunks/`. They have no standalone Vite entries.

Build output goes to `dist/assets/` with content-hashed filenames. `dist/manifest.json`
is written by `build/piwigo-manifest-plugin.ts` — a custom format that maps each entry
id to `{file, imports, css}`. It is not the same file as Vite's own
`.vite/manifest.json`.

### ScriptLoader integration

`Piwigo\Template\ScriptLoader::add()` consults `dist/manifest.json` when a
`{combine_script id="..."}` Smarty tag is processed. If the id is in the manifest,
the loader emits the hashed URL and clears the require list (Vite handles import
order). If the manifest is absent (fresh clone, no build), it falls back to the legacy
file-concatenation path — the gallery still works without a build step.

### Dev workflow

```bash
npm run build        # production build — creates dist/ and dist/manifest.json
npm run dev          # Vite dev server with HMR
npm run typecheck    # tsc --noEmit across .ts files (incl. tests/E2e)
npm run clean        # remove dist/ and _data/combined/
```

### Adding a new JS module

1. Create `.ts` in `themes/admin/_base/js/` or `themes/_base/js/`.
2. Add it as a Vite entry in `vite.config.ts` with the id that Smarty templates will reference.
3. Reference it from a template: `{combine_script id="my_module" path="...my_module.ts"}`.
4. Run `npm run typecheck && npm run build`.

---

## Authoring a new web service method

Methods are registered in `src/Piwigo/Ws/WsMethodRegistrar.php` against the
`PwgServer` instance. Each method is described by a `MethodDefinition` value
object, with parameters declared as `ParamDefinition` instances:

```php
$server->register(new MethodDefinition(
    name:         'pwg.my.method',
    callback:     $this->myEndpoints->myMethod(...),
    description:  'Description shown in the API browser',
    params:       [
        ParamDefinition::required(name: 'photo_id', type: WsType::Int->value | WsType::Positive->value),
    ],
    tags:         ['my'],
    requiresAuth: true,
));
```

Implement the callback as a method on a service in `src/Piwigo/Ws/Method/`:

```php
final class MyEndpoints
{
    /** @param array<string, mixed> $params */
    public function myMethod(array $params): mixed
    {
        $id = (int) $params['photo_id'];
        return ['id' => $id, 'status' => 'ok'];
    }
}
```

Return a scalar/array for success, `new PwgError(404, 'msg')` for failure, or
`PwgNamedArray`/`PwgNamedStruct` to control XML/JSON output naming. The JSON envelope
is always `{"stat":"ok","result":...}` or `{"stat":"fail","err":N,"message":"..."}`.

---

## Local checks

This is a personal fork. CI runs in `.github/workflows/ci.yml` (Pint, PHPStan, audit jobs)
on push/PR; the same checks below should pass locally before landing significant changes:

| Check                | Command                                                           |
| -------------------- | ----------------------------------------------------------------- |
| PHP format           | `vendor/bin/pint --test`                                          |
| Static analysis      | `vendor/bin/phpstan analyse --no-progress`                        |
| Config accessor sync | `php tools/build-config-accessors.php --check`                    |
| TypeScript check     | `npm run typecheck`                                               |
| JS build             | `npm run build`                                                   |
| Unit tests           | `vendor/bin/phpunit --testsuite Unit`                             |
| Integration tests    | `vendor/bin/phpunit --testsuite Integration` (needs `.env.local`) |
| E2E tests            | `npm run test:e2e` (needs `.env.local` + local Apache up)         |

---

## Roadmap

Remaining modernization work is tracked in three per-track files, each with current state, concrete steps, and verification commands per item:

- `ROADMAP-PHP.md` — PHP / backend
- `ROADMAP-TS.md` — TypeScript / frontend glue
- `ROADMAP-CSS.md` — CSS / themes

---

## What is not yet modernized

- **Templates** — still Smarty 5; ~135 `.tpl` files under `themes/` (plus 31 in bundled plugins). Roadmap item #23 plans the migration to Latte.
- **Plugin contract** — plugin API uses `add_event_handler()` patterns. `PluginMaintain`
  and `ThemeMaintain` are namespaced but the contract is unchanged.
- **Translations** — gettext PO files under `language/<locale>/`. The legacy `$lang['key'] = 'value'` PHP arrays were converted and removed (see `docs/I18N.md`); fallback PHP includes are still honored at runtime for plugins/themes that haven't migrated.
- **Third-party themes** — not Rector- or PHPStan-checked; PHP 8.5 compatibility is
  the theme author's responsibility.

---

## Plugin author guide

This section covers what changed in 16.x that can affect plugin behaviour without
causing an immediate fatal error.

### Configuration

The `$GLOBALS['conf']` reference bridge has been **retired**. Plugins that read or write `$conf` directly will see stale data (or no data) and must migrate to the typed facade. Use FQN to avoid alias dependency:

| Was                       | Now                                                                                       |
| ------------------------- | ----------------------------------------------------------------------------------------- |
| `$conf['upload_dir']`     | `\Piwigo\Config\Config::uploadDir()`                                                      |
| `$conf['max_file_size']`  | `\Piwigo\Config\Config::uploadFormMaxFileSize()`                                          |
| `$conf['enable_formats']` | `\Piwigo\Config\Config::isFormatsEnabled()`                                               |
| `$conf['key'] = $v`       | `\Piwigo\Config\Config::override('key', $v)` (per-request)                                |
| `conf_update_param(...)`  | `\Piwigo\Core\Kernel::service(\Piwigo\Config\ConfigService::class)->confUpdateParam(...)` |

The free function `conf_update_param()` no longer exists. Plugins that still call it
will fatal at runtime — switch to the `ConfigService` form.

### Database layer

Only `mysqli` is supported. The legacy free functions (`pwg_query()`,
`pwg_db_fetch_assoc()`, `pwg_db_real_escape_string()`, `pwg_db_num_rows()`,
`query2array()`, `mass_inserts()`, `mass_updates()`) have all been removed; use
constructor-injected `Doctrine\DBAL\Connection` and Doctrine DBAL APIs instead,
or the helpers in `Piwigo\Db\Dml`.

### Class names

All first-party Piwigo classes live in the `Piwigo\` namespace. There is no class-alias
shim — bare unqualified names like `PluginMaintain` or `PwgError` will not resolve.
Plugins must reference the namespaced names directly:

| Old              | Now                           |
| ---------------- | ----------------------------- |
| `PluginMaintain` | `Piwigo\Admin\PluginMaintain` |
| `ThemeMaintain`  | `Piwigo\Admin\ThemeMaintain`  |
| `Template`       | `Piwigo\Template\Template`    |
| `PwgError`       | `Piwigo\Ws\PwgError`          |
| `PwgSession`     | `Piwigo\Session\PwgSession`   |

### PHP version

PHP 8.5 is required. Key breakage points:

- `curl_close()` is deprecated — use `unset($ch)`.
- `mysql_*` functions are gone — use the `DbConnection` / Doctrine DBAL APIs.
- Undeclared dynamic properties emit `E_DEPRECATED` — add explicit declarations or
  `#[AllowDynamicProperties]`.

### File I/O — use StorageRegistry, not raw filesystem functions

Since 16.x, Piwigo routes user-facing file I/O through
`\Piwigo\Storage\StorageRegistry`. Named disks map logical names to
Flysystem `FilesystemOperator` instances configured in `config/storage.php`.

**Plugin best practice:**

```php
// Old (works, but breaks when the site moves storage to S3/SFTP):
file_put_contents(PHPWG_ROOT_PATH . 'plugins/MyPlugin/data.json', $json);
$data = file_get_contents(PHPWG_ROOT_PATH . 'plugins/MyPlugin/data.json');

// New (portable across any Flysystem backend):
$disk = \Piwigo\Storage\StorageRegistry::disk('plugins');
$disk->write('MyPlugin/data.json', $json);
$data = $disk->read('MyPlugin/data.json');
```

Available disks and their roots (configured in `config/storage.php`):

| Disk          | Default root                     | Use for                        |
| ------------- | -------------------------------- | ------------------------------ |
| `uploads`     | `Config::uploadDir()`            | Original user photos           |
| `derivatives` | `_data/i/`                       | Thumbnails / resized variants  |
| `watermarks`  | `local/watermarks/`              | Watermark PNG files            |
| `themes`      | `themes/`                        | Theme assets                   |
| `plugins`     | `plugins/`                       | Plugin data files              |
| `exports`     | `_data/exports/`                 | Generated export archives      |
| `local`       | `local/`                         | Site-local overrides & uploads |
| `temp`        | `sys_get_temp_dir() . '/piwigo'` | Scratch / chunk assembly       |

The disk behind each name can be swapped to S3, SFTP, or any other
Flysystem adapter by editing `config/storage.php` — plugin code does not
change. See the comments in that file for concrete S3 and SFTP examples.

### Testing your plugin

Activate on a fresh 16.x install with `error_reporting(E_ALL)` and browse the gallery,
a photo page, and the admin dashboard. Check the PHP error log for any `Deprecated` or
`Warning` lines before shipping.
