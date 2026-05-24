# Repository Structure

A map of every significant directory and file grouping in the 16.x-rewrite branch.

---

## Root

```text
index.php               Single HTTP entry point — handles `?/i/`, `?/install`,
                        `?/upgrade`, `?/upgrade_feed` fast paths inline, then
                        dispatches everything else through the full Kernel boot
rector.php              Rector static-analysis config (not a request handler)
```

The legacy root entry-points `i.php`, `action.php`, `install.php`, `upgrade.php`,
`upgrade_feed.php`, `qsearch.php` all no longer exist on disk — `index.php`
inspects `$_SERVER['QUERY_STRING']` and routes the bypass-eligible prefixes
(`i/`, `install`, `upgrade`, `upgrade_feed`) through minimal bootstraps that
skip the full PSR-15 pipeline.

Non-PHP config / tooling at root: `composer.json`, `package.json`,
`vite.config.ts`, `tsconfig.json`, `phpstan.neon`, `phpunit.xml.dist`,
`pint.json`, `eslint.config.ts`, `.prettierrc.json`, `.stylelintrc.json`,
`playwright.config.ts`, `setup.ps1`, `setup.sh`.

Working notes also at root: `INSTALL.md`, `README.md`, `SECURITY.md`,
`COPYING.txt`, `LICENSE.txt`, `e2e.txt`, `phpstan-l10.txt`, `plugins_list.md`,
`plugins_mgmt.md` (ROADMAP-PHP / STRUCTURE-PLAN moves these into `docs/` later).

---

## `src/Piwigo/` — typed PHP layer

PSR-4 autoloaded under the `Piwigo\` namespace via `composer.json`:

```jsonc
"autoload": {
  "psr-4":  { "Piwigo\\": "src/Piwigo/" },
  "files":  [ "src/Piwigo/Calendar/CalendarConstants.php",
              "src/Piwigo/Search/QConstants.php" ]
}
```

`classmap-authoritative: true` is set; the two `files` entries are the only
remaining non-class top-level constant definitions. `autoload-dev` adds
`Piwigo\Tests\` → `tests/` and `Piwigo\Tools\PhpStan\` → `tools/phpstan/`.

**291 PHP files across 47 top-level namespaces.**

### Controllers (`src/Piwigo/Controller/`)

PSR-15 `ControllerInterface` implementations. Each `__invoke(Request, args): Response`.
22 top-level + 10 admin = 32 controller files (one is the interface itself).

#### Gallery / public pages (21 controllers)

| Class                       | Notes                                                                                                                                                                                                    |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GalleryController`         | Main gallery; `/`, `/category/{rest}`, `/favorites`, `/recent`, `/best-rated`, `/most-visited`, `/recent-albums`, `/random`, `/search/{id}`, `/search/{id}/{rest}` — dispatches via `SectionInitializer` |
| `PictureController`         | `/picture/{rest}` — single-image page                                                                                                                                                                    |
| `SearchController`          | `/search` — builds query, redirects to results                                                                                                                                                           |
| `QSearchController`         | `/qsearch` — quick-search redirect (former `qsearch.php`)                                                                                                                                                |
| `TagsController`            | `/tags`, `/tags/{rest}` — tag cloud + filtered gallery                                                                                                                                                   |
| `CommentsController`        | `/comments` — paginated comment list                                                                                                                                                                     |
| `FeedController`            | `/feed` — RSS 2.0 feed                                                                                                                                                                                   |
| `NotificationController`    | `/notification` — RSS subscription page                                                                                                                                                                  |
| `IdentificationController`  | `/identification` — login                                                                                                                                                                                |
| `RegisterController`        | `/register` — user registration                                                                                                                                                                          |
| `PasswordController`        | `/password` — password reset                                                                                                                                                                             |
| `ProfileController`         | `/profile` — user preferences                                                                                                                                                                            |
| `AboutController`           | `/about` — gallery about page                                                                                                                                                                            |
| `NbmController`             | `/nbm` — notification-by-mail subscribe/unsubscribe                                                                                                                                                      |
| `PopuphelpController`       | `/popuphelp` — gallery-side help popup                                                                                                                                                                   |
| `ActionController`          | `/action` — binary file-server (downloads, format variants); former `action.php`                                                                                                                         |
| `WsController`              | `/ws{rest}` — web services API + OpenAPI spec/UI                                                                                                                                                         |
| `ImageDerivativeController` | `/i/{rest}` — derivative serving via the `?/i/` fast path                                                                                                                                                |
| `InstallController`         | `/install` — installer; `?/install` fast path                                                                                                                                                            |
| `UpgradeController`         | `/upgrade` — upgrader; `?/upgrade` fast path                                                                                                                                                             |
| `UpgradeFeedController`     | `/upgrade_feed` — feed-based DB upgrade runner                                                                                                                                                           |

#### Admin (`src/Piwigo/Controller/Admin/`, 10 controllers)

| Class                     | Pages handled                                                                                                                             |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `AdminController`         | Dispatcher; renders admin shell; `/admin{rest}`                                                                                           |
| `AlbumController`         | album, albums, album_notification, cat_list, cat_modify, cat_options, cat_perm, element_set_ranks                                         |
| `BatchManagerController`  | batch_manager, batch_manager_global, batch_manager_unit, queue                                                                            |
| `ConfigurationController` | configuration                                                                                                                             |
| `ExtensionsController`    | plugins/installed/new, plugin, themes/installed/new/standard_pages, theme, languages/installed/new, updates/pwg/ext, extend_for_templates |
| `GroupsController`        | group_list, group_perm                                                                                                                    |
| `MaintenanceController`   | maintenance/actions/env/sys, history, stats, site_manager, site_reader_local, site_update                                                 |
| `MiscController`          | comments, menubar, notification_by_mail, permalinks, popuphelp, rating, rating_user, tags, profile, help, intro                           |
| `PhotoController`         | photo, picture_modify/coi/formats, photos_add, photos_add_direct/ftp/applications                                                         |
| `UsersController`         | user_list, user_perm, user_activity                                                                                                       |

### Domain namespaces (`src/Piwigo/<Domain>/`)

| Namespace         | Contents                                                                                                                                                                                                                                            |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Activity`        | `ActivityRepository`                                                                                                                                                                                                                                |
| `Admin`           | `AdminService`, `InstallService`, `UpgradeService`, `MaintenanceService`, `Plugins`, `Themes`, `Languages`, `Updates`, `PluginMaintain`, `ThemeMaintain`, `Tabsheet`, `CoreTabsRegistrar` plus per-domain admin sub-namespaces (see below)          |
| `Auth`            | `AuthKeyRepository`, `CookieService`, `PasswordService`, `PwgBase32`, `PwgTOTP`                                                                                                                                                                     |
| `Bootstrap`       | `CommonBootstrap`, `Container` (PHP-DI builder), `ExceptionHandler`                                                                                                                                                                                 |
| `Cache`           | `CacheFactory`, `PersistentCache`, `PersistentCacheRegistry`, `PersistentFileCache`, `RequestCache`, `Simple`                                                                                                                                       |
| `Calendar`        | `CalendarBase`, `CalendarMonthly`, `CalendarWeekly`, `CalendarService`, `CalendarConstants` (autoload-files)                                                                                                                                        |
| `Category`        | `CategoryRepository`, `CategoryService`, `CategoryCatsRenderer`, `CategoryDefaultRenderer`                                                                                                                                                          |
| `Comment`         | `CommentRepository`, `CommentService`                                                                                                                                                                                                               |
| `Config`          | `Config` (typed accessor facade), `ConfigLoader`, `ConfigService`, `ConfigStorage`, `TestMode`, `UnknownConfigKeyException`                                                                                                                         |
| `Controller`      | See above                                                                                                                                                                                                                                           |
| `Core`            | `Kernel`, `PageState`, `Lang`, `LanguageStack`, `Util`, `BoolUtil`, `StringUtil`, `Filesystem`, `Logger`, `LoggerRegistry`, `InstallSentinel`, `AppInfo`, `DateService`, `ActivitySystem`, `AccessLevel`, `ErrorCollector`, `ValidationPattern`     |
| `Db`              | `DbConnection`, `Dml`, `DbInfo`, `SchemaHelper`, `SqlExpr`, `QueryHelper`, `Tables`, `AbstractRepository`                                                                                                                                           |
| `Exception`       | `AuthException`, `ConfigException`, `DbException`, `HttpException`, `NotFoundException`, `PiwigoException`, `ValidationException`                                                                                                                   |
| `Feed`            | `FeedHelper`, `FeedRepository`, `PiwigoFeedCreator`                                                                                                                                                                                                 |
| `Filter`          | `FilterService`                                                                                                                                                                                                                                     |
| `Group`           | `GroupRepository`                                                                                                                                                                                                                                   |
| `History`         | `HistoryRepository`                                                                                                                                                                                                                                 |
| `Html`            | `HtmlService` (HTML generation helpers)                                                                                                                                                                                                             |
| `Http`            | `RequestFactory`, `ResponseFactory`, `ResponseEmitter`, `PathExtractor`, `MiddlewarePipeline`                                                                                                                                                       |
| `Http/Middleware` | `AuthMiddleware`, `ControllerInvokerMiddleware`, `CsrfMiddleware`, `ExceptionHandlerMiddleware`, `FilterMiddleware`, `RoutingMiddleware`, `SessionMiddleware`                                                                                       |
| `Image`           | `ImageRepository`, `SrcImage`, `DerivativeImage`, `DerivativeService`, `DerivativePipeline`, `DerivativeEncoding`, `DerivativeParams`, `DerivativeSize`, `ImageDerivativeContext`, `ImageRect`, `ImageStdParams`, `SizingParams`, `WatermarkParams` |
| `Job`             | `MessengerFactory` plus message + `Handler/` classes (`BatchUploadJob`, `GenerateDerivativeJob`, `RegenerateAllDerivativesJob`, `ReindexImagesJob`, `SendNotificationEmailJob` and matching handlers)                                               |
| `Lang`            | `LangService`, `Translator`                                                                                                                                                                                                                         |
| `Language`        | `LanguageRepository`                                                                                                                                                                                                                                |
| `Mail`            | `MailService`                                                                                                                                                                                                                                       |
| `Menu`            | `BlockManager`, `DisplayBlock`, `RegisteredBlock`, `MenubarRenderer`                                                                                                                                                                                |
| `Metadata`        | `MetadataService`                                                                                                                                                                                                                                   |
| `Notification`    | `NotificationRepository`, `NotificationService`, `MailNotificationContext`                                                                                                                                                                          |
| `Page`            | `PageHeaderRenderer`, `PageTailRenderer`, `NoPhotoYetRenderer`, plus `Page/Context/` (`AdminPageContext`, `AlbumPageContext`, `PicturePageContext`, `SearchPageContext`, `TagsPageContext`)                                                         |
| `Permalink`       | `PermalinkRepository`, `PermalinkService`                                                                                                                                                                                                           |
| `Permission`      | `PermissionRepository` (the `PermissionService` lives in `Piwigo\Users\`)                                                                                                                                                                           |
| `Picture`         | `PictureService`, `PictureCommentRenderer`, `PictureContentRenderer`, `PictureMetadataRenderer`, `PictureRateRenderer`                                                                                                                              |
| `Plugin`          | `PluginRepository`, `PluginService`                                                                                                                                                                                                                 |
| `Plugins`         | Bundled plugin support: `EventDispatcher`, `LoadedPluginRegistry`, plus per-plugin `Config` classes under `LocalFilesEditor/`, `NbcThemeChanger/`, `PiwigoOpenstreetmap/`, `PiwigoVideojs/`                                                         |
| `Rate`            | `RateRepository`, `RateService`                                                                                                                                                                                                                     |
| `Routing`         | `Router`, `RouteResult`                                                                                                                                                                                                                             |
| `Search`          | `SearchService`, `SearchRepository`, `SearchFilterRenderer`, `Q*` query AST classes (`QConstants`, `QExpression`, `QSearchScope`, `QSingleToken`, `QMultiToken`, `QDateRangeScope`, `QNumericRangeScope`, `QResults`), plus `Inflector/`            |
| `Section`         | `SectionInitializer` (parses `/category/12-foo/start-24` etc.)                                                                                                                                                                                      |
| `Session`         | `PwgSession`, `SessionRepository`, `SessionService`                                                                                                                                                                                                 |
| `Site`            | `LocalSiteReader`, `SiteRepository`                                                                                                                                                                                                                 |
| `Storage`         | `StorageRegistry` (Flysystem disk registry)                                                                                                                                                                                                         |
| `Tag`             | `TagRepository`, `TagService`, `SelectedTagsRenderer`                                                                                                                                                                                               |
| `Template`        | `Template`, `TemplateRegistry`, `LatteEngine`                                                                                                                                                                                                        |
| `Theme`           | `ThemeRepository` (the theme manager class is `Piwigo\Admin\Themes`)                                                                                                                                                                                |
| `Url`             | `UrlGenerator`, `UrlService`                                                                                                                                                                                                                        |
| `Users`           | `User`, `CurrentUser`, `UserService`, `UserRepository`, `UserBootstrap`, `AuthService`, `PermissionService`, `PreferencesService`, `ProfileService`                                                                                                 |
| `Ws`              | `PwgServer`, `PwgServerRegistry`, `PwgRequestHandler`, `WsHelper`, `WsMethodRegistrar`, `MethodDefinition`, `ParamDefinition`, `WsParam`, `WsType`, `PwgError`, `PwgNamedArray`, `PwgNamedStruct`                                                   |
| `Ws/Encoder`      | `PwgResponseEncoder` base                                                                                                                                                                                                                           |
| `Ws/Method`       | WS method endpoint classes (`CategoriesEndpoints`, `CommentsEndpoints`, `ExtensionsEndpoints`, `GeneralEndpoints`, `GroupsEndpoints`, `ImagesEndpoints`, `PermissionsEndpoints`, `TagsEndpoints`, `UsersEndpoints`)                                 |
| `Ws/OpenApi`      | `SpecBuilder` (OpenAPI JSON generation), `OpenApiDocument`, `ApiMethod` attribute                                                                                                                                                                   |
| `Ws/Protocol`     | `PwgJsonEncoder`, `PwgRestEncoder`, `PwgRestRequestHandler`, `PwgSerialPhpEncoder`, `PwgXmlWriter`                                                                                                                                                  |

#### Admin sub-namespaces (`src/Piwigo/Admin/<Domain>/`)

| Sub-namespace  | Contents                                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------------------------ |
| `Album`        | `AlbumsTabRenderer`                                                                                                |
| `BatchManager` | `FilterResolver`                                                                                                   |
| `Category`     | `CategoryAdminService`                                                                                             |
| `Config`       | `SizesProcessor`, `WatermarkProcessor`                                                                             |
| `History`      | `HistoryAdminService`                                                                                              |
| `Image`        | `PwgImage`, `ImageAdminService`, `GraphicsLibrary`, `ImageInterface`, `ImageGd`, `ImageImagick`, `ImageExtImagick` |
| `Integrity`    | `C13yInternal`, `CheckIntegrity`                                                                                   |
| `Metadata`     | `MetadataAdminService`                                                                                             |
| `Notification` | `NotificationAdminService`                                                                                         |
| `Tag`          | `TagAdminService`                                                                                                  |
| `Upload`       | `UploadService`, `DirectPreparer`                                                                                  |
| `Users`        | `UserAdminService`, `UserTabRenderer`                                                                              |

`src/types/` — TypeScript declarations for PHP-emitted globals (`globals.d.ts`,
`css.d.ts`).

---

## `config/` — runtime wiring

| File            | Purpose                                                          |
| --------------- | ---------------------------------------------------------------- |
| `routes.php`    | `RouteCollection` with 32 named routes                           |
| `container.php` | DI container bindings (middleware, repositories, services)       |
| `storage.php`   | Flysystem disk definitions (uploads, derivatives, watermarks, …) |
| `messenger.php` | Symfony Messenger transport / routing map                        |

The legacy `include/` and `admin/` directories are gone. Bootstrap responsibility
is split between `index.php` (route detection + minimal bootstraps) and
`Piwigo\Bootstrap\CommonBootstrap::run()` (full boot path: exception handler,
config defaults, env loading, install/upgrade redirects, DB connect, `Kernel::boot()`,
`loadConfFromDb()`, migrations, logger, session, user, plugins, theme).

---

## `themes/` and `themes/admin/` — presentation layer

### `themes/_base/` — gallery theme

```text
css/                    SCSS-compiled stylesheets
icon/                   Theme icons
images/                 Theme images
js/*.ts                 TypeScript entries: core.scripts, core.switchbox, search,
                        popuphelp, picture_nav_keys, mcs, pngfix, rating, thumbnails.loader
template/               Smarty .tpl files for all gallery pages
template/mail/          Email templates
template/help/          Popup help HTML fragments
s26/                    Variant skin assets (color outline icons)
vendor/                 Vendored frontend libraries
watermarks/             Default watermark assets
theme.css print.css iconset.css fix-ie5-ie6.css fix-ie7.css fix-khtml.css
local_head.tpl themeconf.inc.php
```

### `themes/standard_pages/` — identification/register/password/profile

```text
css/  fonts/  images/   Skin assets
js/*.ts                  TypeScript entries: toaster_js, standard_pages_js, standard_profile_js
template/                Login, register, password, profile templates
skins/                   11 color skins (cadmium, cobalt, default, fuchsia,
                         green, lime, purple, red, sienna, silver, teal)
theme.css themeconf.inc.php
```

### `themes/admin/_base/` — admin theme

```text
css/                    Admin SCSS (components, pages)
js/*.ts                 ~30 TypeScript admin entries (albums, batchManagerGlobal,
                        tags, user_list, …)
template/               Admin Smarty templates (one per admin page)
fontello/               Admin icon font
fonts/                  Admin font assets
```

### `themes/admin/light/` and `themes/admin/dark/`

Alternate admin skins (CSS + icon overrides only).

---

## `dist/` — Vite build output

Content-hashed JS/CSS bundles. `dist/manifest.json` maps entry IDs to hashed
filenames; read by `ViteManifest::entry()` to resolve `{=viteEntry('id')}` calls
in Latte templates. **Not committed to git** (`.gitignore`d — regenerated by
`npm run build`).

---

## `install/` — installer and DB upgrade scripts

```text
config.sql                       Default config rows
piwigo_structure-mysql.sql       Initial schema
db/                              Incremental DB upgrade scripts (.php).
                                 Numbered from 182 onwards (16.x era);
                                 files 1–181 deleted — upgrade floor is 16.0.0.
                                 Currently only contains `index.php` (directory protection).
index.php                        Directory-protection redirect
```

v17 is greenfield — the schema is defined entirely by
`install/piwigo_structure-mysql.sql`. There is no core data-migration
framework. Per-plugin schema evolution still ships through
`Piwigo\Plugin\Migration\PluginMigrationRunner`, which keys applied
versions on (plugin_id, version) in `<prefix>plugin_migrations`.

---

## `tests/` — test suite

```text
tests/Unit/             PHPUnit unit tests (no DB required)
  Auth/ Cache/ Config/ Controller/ Core/ Http/ Image/ Job/ Log/ Menu/
  Plugins/ Routing/ Search/ Session/ Storage/ Tag/ Template/ Url/ Users/ Ws/
tests/Integration/      PHPUnit integration tests (need .env.local + DB)
  InstallChainTest.php  IntegrationTestCase.php  Job/  UpgradeChainTest.php  WsApiTest.php
tests/E2e/              Playwright end-to-end tests
  config/               Playwright fixtures and test configuration
  helpers/              Page-object helpers (admin-login, debug-helpers,
                        page-monitor, strict-assertions, test-data, upload-photo, url)
bootstrap.php           PHPUnit bootstrap (env, autoload, DI bootstrap)
```

---

## `tools/` — development scripts

| Path                                  | Purpose                                                                                                                                                                                                                            |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tools/phpstan/`                      | PHPStan bootstrap and custom rules (`ConfigKeyExistsRule`, `NoDynamicNewRule`, `NoErrorSuppressionRule`, `NoGlobalInSrcRule`, `StrictTypesRequiredRule`, dynamic-return-type extension `EventDispatcherDispatchDynamicReturnType`) |
| `tools/i18n/`                         | Translation extraction and validation scripts                                                                                                                                                                                      |
| `tools/language/`                     | Language file management helpers                                                                                                                                                                                                   |
| `tools/ws/`                           | WS method documentation generators                                                                                                                                                                                                 |
| `tools/build-config-accessors.php`    | Generates typed `Config::*()` accessors from `Config::SCHEMA`                                                                                                                                                                      |
| `tools/build-config-reference.php`    | Generates `docs/CONFIG-REFERENCE.md` from `Config::SCHEMA`                                                                                                                                                                         |
| `tools/triggers_list.php`             | Documents all `trigger_notify`/`trigger_change` hooks                                                                                                                                                                              |
| `tools/phpstan-bootstrap.php`         | PHPStan bootstrap (declares runtime-defined constants like `PHPWG_ROOT_PATH`, `PREFIX_TABLE`, `PEM_URL`, plugin/theme callback function stubs)                                                                                     |
| `tools/phpstan-types.php`             | PHPStan type stubs                                                                                                                                                                                                                 |
| `tools/analyze-mixed.py`              | One-shot: analyses remaining `mixed` types (output captured in `docs/MIXED-TYPES.md`)                                                                                                                                              |
| `tools/migrate-free-functions.py`     | One-shot migration helper used during the `include/` retirement                                                                                                                                                                    |
| `tools/migrate-preboot-functions.py`  | One-shot migration helper                                                                                                                                                                                                          |
| `tools/fix-remaining-free-calls.py`   | One-shot                                                                                                                                                                                                                           |
| `tools/fix-remaining-free-calls-2.py` | One-shot                                                                                                                                                                                                                           |
| `tools/ws.htm`                        | Static WS browser shell                                                                                                                                                                                                            |

The Python migration scripts are scheduled for deletion in STRUCTURE-PLAN.md
Step 6 — already-executed; git history is canonical.

---

## `bin/` — CLI commands

```text
bin/piwigo              Symfony Console application (e.g. `bin/piwigo messenger:consume async`)
```

---

## `build/` — Vite build helpers

```text
build/piwigo-manifest-plugin.ts     Custom Vite plugin generating Piwigo-format manifest.json
```

---

## `dev/` — development fixtures

```text
dev/fixtures/piwigo-15.x.sql        15.x DB snapshot (UpgradeChainTest 409-refusal path)
dev/fixtures/piwigo-17.0.sql        16.x DB snapshot for integration + E2E tests
dev/vite-entries.json               Vite entry catalog
```

---

## `language/` — translations

~110 language directories, each containing PO files:

```text
language/<locale>/
  common.po              User-facing strings (frontend + shared admin)
  admin.po               Admin-only strings
  install.po             Installer strings
  upgrade.po             Upgrader strings
  help_quick_search.po   Help popup
  about.html             About page content (locale-specific)
  help/*.html            Popup help content per admin page
  index.php              Directory-protection redirect
```

The legacy `*.lang.php` array files were converted to PO and removed
(see `docs/I18N.md`). Excluded from Rector and PHPStan permanently.

---

## `plugins/` — bundled plugins

Five plugins shipped with the repo:

| Plugin                 | Description                                    |
| ---------------------- | ---------------------------------------------- |
| `LocalFilesEditor`     | In-admin CSS/template file editor              |
| `nbc_ThemeChanger`     | Per-user theme switcher                        |
| `piwigo-openstreetmap` | OpenStreetMap integration for geotagged photos |
| `piwigo-videojs`       | Video.js player for video files                |
| `user_tags`            | User-submitted tag annotations                 |

Mirrors of these exist as typed config stubs in `src/Piwigo/Plugins/`
(scheduled rename to `src/Piwigo/PluginConfig/` in STRUCTURE-PLAN.md Step 8).

---

## `template-extension/` — legacy template overlay

Distributed/yoga overlay mechanism. Mostly empty in this fork; STRUCTURE-PLAN.md
Step 9 folds this into `resources/templates/overrides/`.

---

## Runtime directories (not in git)

| Directory            | Purpose                                                                                                                                                         |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `_data/i/`           | Derivative image cache (thumbnails, resized variants)                                                                                                           |
| `_data/cache/`       | Generic persistent cache                                                                                                                                        |
| `_data/combined/`    | Legacy JS/CSS combiner cache (used without Vite build)                                                                                                          |
| `_data/logs/`        | Application logs                                                                                                                                                |
| `_data/templates_c/` | Smarty compiled templates                                                                                                                                       |
| `galleries/`         | Original uploaded photos (default path; configurable)                                                                                                           |
| `upload/`            | Upload staging area                                                                                                                                             |
| `local/`             | Site-local state — `.installed` / `.installed.test` install sentinels, watermarks, theme overrides. (DB credentials live in `.env` at the repo root, not here.) |

These move under `var/` in STRUCTURE-PLAN.md Steps 1–2.
