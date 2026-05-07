# Repository Structure

A map of every significant directory and file grouping in the 16.x-rewrite branch.

---

## Root

```
index.php               Kernel entry point — the only HTTP entry point for all gallery/admin requests
action.php              Permanent shim: binary file-server (downloads, format variants)
i.php                   Permanent shim: image derivative server (minimal bootstrap, no full stack)
qsearch.php             Redirect shim: ?q= → UrlGenerator::searchPage()
index.php?/install  Install wizard (detected early in index.php, no DB needed)
index.php?/upgrade  Upgrade wizard (detected early in index.php)
upgrade_feed.php        Feed-based DB upgrade runner; gated by Config::checkUpgradeFeed()
migrations.php          Doctrine Migrations CLI config (not a request handler)
rector.php              Rector static analysis config (not a request handler)
```

Non-PHP config / tooling at root: `composer.json`, `package.json`, `vite.config.ts`,
`tsconfig.json`, `phpstan.neon`, `phpunit.xml.dist`, `pint.json`, `eslint.config.ts`,
`.prettierrc.json`, `.stylelintrc.json`, `playwright.config.ts`.

---

## `src/Piwigo/` — typed PHP layer

PSR-4 autoloaded under the `Piwigo\` namespace. All new code goes here. 182 PHP files
across 47 namespaces.

### Controllers (`src/Piwigo/Controller/`)

PSR-15 `ControllerInterface` implementations. Each `__invoke(Request, args): Response`.

**Gallery / public pages (19 controllers)**

| Class | Route | Notes |
|---|---|---|
| `GalleryController` | `/`, `/category/{rest}`, `/favorites`, `/recent`, `/best-rated`, `/most-visited`, `/recent-albums`, `/random`, `/search/{id}`, `/search/{id}/{rest}` | Main gallery; dispatches via SectionInitializer |
| `PictureController` | `/picture/{rest}` | Single-image page |
| `SearchController` | `/search` | Builds query, redirects to results |
| `TagsController` | `/tags`, `/tags/{rest}` | Tag cloud + filtered gallery |
| `CommentsController` | `/comments` | Paginated comment list |
| `FeedController` | `/feed` | RSS 2.0 feed |
| `NotificationController` | `/notification` | RSS subscription page |
| `IdentificationController` | `/identification` | Login |
| `RegisterController` | `/register` | User registration |
| `PasswordController` | `/password` | Password reset |
| `ProfileController` | `/profile` | User preferences |
| `AboutController` | `/about` | Gallery about page |
| `NbmController` | `/nbm` | Notification-by-mail subscribe/unsubscribe |
| `PopuphelpController` | `/popuphelp` | Gallery-side help popup |
| `WsController` | `/ws{rest}` | Web services API + OpenAPI spec/UI |
| `ImageDerivativeController` | `/i/{rest}` | Served via `i.php` shim |
| `InstallController` | `/install` | Installer |
| `UpgradeController` | `/upgrade` | Upgrader |

**Admin (`src/Piwigo/Controller/Admin/`, 10 controllers)**

| Class | Pages handled |
|---|---|
| `AdminController` | Dispatcher; renders admin shell; `/admin{rest}` |
| `AlbumController` | album, albums, album_notification, cat_list, cat_modify, cat_options, cat_perm, element_set_ranks |
| `BatchManagerController` | batch_manager, batch_manager_global, batch_manager_unit, queue |
| `ConfigurationController` | configuration |
| `ExtensionsController` | plugins/installed/new, plugin, themes/installed/new/standard_pages, theme, languages/installed/new, updates/pwg/ext, extend_for_templates |
| `GroupsController` | group_list, group_perm |
| `MaintenanceController` | maintenance/actions/env/sys, history, stats, site_manager, site_reader_local, site_update |
| `MiscController` | comments, menubar, notification_by_mail, permalinks, popuphelp, rating, rating_user, tags, profile, help, intro |
| `PhotoController` | photo, picture_modify/coi/formats, photos_add, photos_add_direct/ftp/applications |
| `UsersController` | user_list, user_perm, user_activity |

### Domain namespaces (`src/Piwigo/<Domain>/`)

| Namespace | Contents |
|---|---|
| `Activity` | User activity tracking |
| `Admin/Album` | Album admin service |
| `Admin/BatchManager` | Batch manager service |
| `Admin/Category` | Category admin service |
| `Admin/Config` | Config admin helpers |
| `Admin/History` | History admin service |
| `Admin/Image` | `PwgImage` (image processing wrapper) |
| `Admin/Integrity` | Integrity check service |
| `Admin/Metadata` | Metadata sync service |
| `Admin/Notification` | `NotificationAdminService` |
| `Admin/Tag` | Tag admin service |
| `Admin/Upload` | Upload service |
| `Admin/Users` | User admin service |
| `Auth` | `AuthService`, `TokenService`, CSRF helpers |
| `Bootstrap` | `Container` (DI container builder), `Compat` aliases |
| `Cache` | `PersistentCache`, `RequestCache`, `PersistentCacheRepository` |
| `Calendar` | Calendar views (monthly/weekly/daily) |
| `Category` | `CategoryRepository`, `CategoryService` |
| `Comment` | `CommentRepository`, `CommentService` |
| `Config` | `Config` (typed accessor facade), `ConfigLoader`, `ConfigSchema` |
| `Core` | `Kernel`, `ServiceLocator`, `PageState`, `Lang`, `Util`, `BoolUtil`, `LoggerRegistry` |
| `Db` | DBAL helpers, `SchemaManager` |
| `Exception` | `AuthException`, `ConfigException`, `ValidationException`, etc. |
| `Feed` | `FeedRepository` |
| `Filter` | `FilterResolver` |
| `Group` | `GroupRepository` |
| `History` | `HistoryRepository` |
| `Html` | `HtmlService` (HTML generation helpers) |
| `Http` | `RequestFactory`, `ResponseFactory`, `ResponseEmitter`, `PathExtractor`, `MiddlewarePipeline` |
| `Http/Middleware` | `AuthMiddleware`, `CsrfMiddleware`, `ExceptionHandlerMiddleware`, `FilterMiddleware`, `RoutingMiddleware`, `SessionMiddleware`, `ControllerInvokerMiddleware` |
| `Image` | `ImageRepository`, `SrcImage`, `DerivativeImage`, `ImageStdParams`, `DerivativeService`, `ImageFormat*` |
| `Job` | `JobDispatcher`, message/handler classes for async jobs |
| `Lang` | `LangLoader`, `LanguageStack` |
| `Language` | `Languages` (plugin-style language pack manager) |
| `Mail` | `MailService` |
| `Menu` | `BlockManager`, `MenuBlock`, `MenuRegistry` |
| `Metadata` | `MetadataReader` |
| `Migrations` | `MigrationRunner` (Doctrine Migrations wrapper) |
| `Notification` | `NotificationRepository`, `MailNotificationContext` |
| `Page` | `PageContext`, section-context DTOs |
| `Permalink` | `PermalinkRepository` |
| `Permission` | `PermissionRepository` |
| `Picture` | `PictureService` |
| `Plugin` | `PluginMaintain`, `PluginService` |
| `Plugins` | Bundled plugins: `LocalFilesEditor`, `NbcThemeChanger`, `PiwigoOpenstreetmap`, `PiwigoVideojs` |
| `Rate` | `RateRepository`, `RateService` |
| `Routing` | `Router`, `RouteResult` |
| `Search` | `SearchService`, `SearchQuery`, query builder, result types |
| `Section` | `SectionInitializer` (parses `/category/12-foo/start-24` etc.) |
| `Session` | `PwgSession`, `SessionMiddleware` |
| `Site` | `SiteService` |
| `Storage` | `StorageRegistry` (Flysystem disk registry) |
| `Tag` | `TagRepository`, `TagService` |
| `Template` | `Template` (Smarty wrapper), `TemplateRegistry`, `ScriptLoader`, `CssLoader` |
| `Theme` | `Themes` (theme manager) |
| `Url` | `UrlGenerator`, `UrlService` |
| `Users` | `CurrentUser`, `UserService`, `UserRepository`, `UserBootstrap`, `UserFields` |
| `Ws` | `PwgServer`, `WsHelper`, `PwgServerRegistry` |
| `Ws/Encoder` | JSON, PHP, XML-RPC encoders |
| `Ws/Method` | WS method endpoint classes (GeneralEndpoints, ImageEndpoints, etc.) |
| `Ws/OpenApi` | `SpecBuilder` (OpenAPI JSON generation) |
| `Ws/Protocol` | WS protocol handlers |

`src/types/` — TypeScript type declarations for PHP-emitted data structures.

---

## `config/` — runtime wiring

| File | Purpose |
|---|---|
| `routes.php` | `RouteCollection` with 27 named routes |
| `container.php` | DI container bindings (middleware, repositories, services) |
| `storage.php` | Flysystem disk definitions (uploads, derivatives, watermarks, …) |
| `messenger.php` | Symfony Messenger transport/routing map |

---

## `include/` — legacy free-function libraries

Procedural PHP included by `common.inc.php`. **Not namespaced, not Rector-processed.**

| File / Group | Contents |
|---|---|
| `common.inc.php` | Bootstrap: config, DB, session, user, template init |
| `constants.php` | Global constants (table names, paths, version) |
| `functions.inc.php` | Core utilities |
| `functions_user.inc.php` | User-related helpers |
| `functions_url.inc.php` | URL builder functions (wrappers around `UrlService`) |
| `functions_search.inc.php` | Search query helpers |
| `functions_calendar.inc.php` | Calendar rendering |
| `functions_filter.inc.php` | Filter state helpers |
| `functions_plugins.inc.php` | Plugin event system (`add_event_handler`, `trigger_notify`, etc.) |
| `functions_cookie.inc.php` | Cookie helpers |
| `menubar.inc.php` | `initialize_menu()` — builds the gallery sidebar |
| `page_header.php` | Smarty page-header renderer (sets `<head>`, loads CSS/JS) |
| `page_tail.php` | Smarty page-tail renderer (closes HTML, fires hooks) |
| `search_filters.inc.php` | Gallery search filter sidebar |
| `no_photo_yet.inc.php` | "No photos yet" page logic |
| `picture_*.inc.php` | Picture-page helpers (comment, metadata, rate) |
| `category_*.inc.php` | Category rendering helpers |
| `derivative*.inc.php` | Image derivative parameter handling |
| `ws_*.inc.php` | Web service infrastructure (init, core, default methods) |
| `ws_functions/` | Per-domain WS method registrations (pwg.images, pwg.categories, …) |
| `ws_protocols/` | Legacy WS encoders (rest, json, xmlrpc) — kept for backward compat |
| `feed_functions.php`, `password_functions.php`, etc. | Extracted from former entry-point files |
| `profile_functions.php` | `save_profile_from_post`, `load_profile_in_template` |
| `dblayer/functions_mysqli.inc.php` | mysqli free functions (`pwg_query`, `pwg_db_fetch_assoc`, …) |
| `inflectors/` | Singular/plural inflectors (en, fr) |

---

## `admin/` — admin support files

| Path | Contents |
|---|---|
| `admin/site_reader_local.php` | Local site-reader sync logic; `require`d by `BatchManagerController` and `MaintenanceController` |
| `admin/include/functions.php` | Admin-specific free functions (`check_input_parameter`, `mass_inserts`, …) |
| `admin/include/functions_*.php` | History, metadata, NBM, permalinks, plugins, upgrade, upload helpers |
| `admin/include/*.inc.php` | Shared tab setups, batch manager filters, watermark/sizes config processors |

---

## `themes/admin/` and `themes/` — presentation layer

### `themes/_base/` — gallery theme

```
css/                    SCSS-compiled stylesheets
js/*.ts                 TypeScript entries: core.scripts, mcs, switchbox, pngfix, rating, thumbnails.loader
template/               Smarty .tpl files for all gallery pages
template/mail/          Email templates
template/help/          Popup help HTML fragments
```

### `themes/standard_pages/` — identification/register/password/profile

```
js/*.ts                 TypeScript entries: toaster_js, standard_pages_js, standard_profile_js
template/               Login, register, password, profile templates
```

### `themes/admin/_base/` — admin theme

```
css/                    Admin SCSS
js/*.ts                 ~30 TypeScript admin entries (albums, batchManagerGlobal, tags, user_list, …)
template/               Admin Smarty templates (one per admin page)
fontello/               Admin icon font
```

### `themes/admin/light/` and `themes/admin/dark/`

Alternate admin skins (CSS + icon overrides only).

---

## `dist/` — Vite build output

Content-hashed JS/CSS bundles. `dist/manifest.json` maps entry IDs to hashed filenames.
`dist/.vite/manifest.json` is Vite's own manifest (not used by Piwigo's ScriptLoader).
**Not committed to git** (`.gitignore`d — regenerated by `npm run build`).

---

## `install/` — installer and DB upgrade scripts

| Path | Contents |
|---|---|
| `install/db/` | Incremental DB upgrade scripts (`<N>-database.php`). Numbered from 182 onwards (16.x era). Files 1–181 deleted — upgrade floor is 16.0.0. |

---

## `migrations/` — Doctrine Migrations

Not present as a visible directory; managed via `migrations.php` CLI config and the `MigrationRunner` class. Migration classes live in `src/Piwigo/Migrations/`.

---

## `tests/` — test suite

```
tests/Unit/             PHPUnit unit tests (no DB required)
  Auth/ Cache/ Config/ Core/ Http/ Image/ Job/ Log/ Menu/ Plugins/
  Routing/ Search/ Session/ Storage/ Tag/ Template/ Url/ Users/ Ws/
tests/Integration/      PHPUnit integration tests (need .env.local + DB)
  Job/
tests/e2e/              Playwright end-to-end tests
  config/               Playwright fixtures and test configuration
  helpers/              Page-object helpers
```

---

## `tools/` — development scripts

| Path | Purpose |
|---|---|
| `tools/phpstan/` | PHPStan bootstrap and extensions (custom rules for Piwigo patterns) |
| `tools/i18n/` | Translation extraction and validation scripts |
| `tools/language/` | Language file management helpers |
| `tools/ws/` | WS method documentation generators |
| `tools/check-conf-shape.php` | Validates `Config::SCHEMA` against the DB `config` table |
| `tools/triggers_list.php` | Documents all `trigger_notify`/`trigger_change` hooks |
| `tools/phpstan-bootstrap.php` | PHPStan bootstrap (defines constants, `IN_ADMIN=false`, etc.) |

---

## `bin/` — CLI commands

Symfony Console commands (e.g. `bin/piwigo messenger:consume async`).

---

## `build/` — Vite build helpers

Custom Vite plugins: `piwigo-manifest-plugin.ts` (generates Piwigo-format `manifest.json`).

---

## `dev/` — development fixtures

`dev/fixtures/piwigo-16.x.sql` — minimal DB snapshot for integration and E2E tests.

---

## `language/` — translations

~110 language directories, each containing:

```
language/<locale>/
  common.lang.php     Main translation strings
  admin.lang.php      Admin-specific strings
  about.html          About page content (locale-specific)
  help/*.html         Popup help content per admin page
```

Excluded from Rector and PHPStan permanently.

---

## `plugins/` — bundled plugins

Five plugins shipped with the repo:

| Plugin | Description |
|---|---|
| `LocalFilesEditor` | In-admin CSS/template file editor |
| `nbc_ThemeChanger` | Per-user theme switcher |
| `piwigo-openstreetmap` | OpenStreetMap integration for geotagged photos |
| `piwigo-videojs` | Video.js player for video files |
| `user_tags` | User-submitted tag annotations |

Mirrors of these exist as typed stubs in `src/Piwigo/Plugins/`.

---

## Runtime directories (not in git)

| Directory | Purpose |
|---|---|
| `_data/i/` | Derivative image cache (thumbnails, resized variants) |
| `_data/cache/` | Generic persistent cache |
| `_data/combined/` | Legacy JS/CSS combiner cache (used without Vite build) |
| `_data/logs/` | Application logs |
| `_data/templates_c/` | Smarty compiled templates |
| `galleries/` | Original uploaded photos (default path; configurable) |
| `upload/` | Upload staging area |
| `local/` | Site-local config overrides (`local/config/database.inc.php`, watermarks, etc.) |
