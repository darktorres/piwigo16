# Remaining `global $X;` declarations

Snapshot taken after Phase 1–8 of task #6. The function-internal sweep removed everything that fit the registry / reference-bridge patterns; what remains needs **real architectural refactors**, not workarounds. File-top declarations are deferred — they need the MVC refactor in Tier 3.

| Scope | Sites | Disposition |
|---|---|---|
| Function-internal (in-app) | 49 | Tier 1 + Tier 2 below |
| Function-internal (tools/) | 4 | out of scope (dev-only scripts) |
| File-top declarations | ~78 lines across 89 files | Tier 3 (MVC refactor) |
| **Total** | **~131** | |

This document is **the plan to remove every one of them**, not a list of accepted exceptions. Each section below describes the proper modern replacement — typed classes, value-objects, services — with no `&$GLOBALS` reference-bridges and no `@var` annotations.

The unifying issue these refactors solve: `global $X;` from the bootstrap stub gives PHPStan tighter typing than `&$GLOBALS['X']` reference-bridges, but the *real* problem is that nested-array state (`$X['a']['b']`) and method-on-array-value (`$X['mail_template']->assign(...)`) are themselves the smell. Fixing the smell — typed properties, typed services — fixes the typing automatically.

---

## Tier 1 — High-leverage typed-class refactors (33 sites, ~1-2 weeks)

Smallest blast radius, highest value per hour. Five independent refactors; can be done in any order, can ship one per session.

### 1.1 — `EventDispatcher` + `LoadedPluginRegistry` (8 sites)

**Removes:** `include/functions_plugins.inc.php` lines 46, 78, 115, 161, 195, 213, 256, 346.

**New classes:**
- `Piwigo\Plugins\EventDispatcher`
  - `addListener(string $event, callable $handler, int $priority = 50): void`
  - `dispatch(string $event, mixed ...$args): mixed` (filter chain — replaces `trigger_change`)
  - `notify(string $event, mixed ...$args): void` (fire-and-forget — replaces `trigger_notify`)
  - `removeListener(string $event, callable $handler): void`
  - Internal storage: `array<string, \SplPriorityQueue<callable>>` — typed, no nested-array smell
- `Piwigo\Plugins\Plugin` value-object: `id`, `path`, `version`, `data: array<string,mixed>` (with typed getters)
- `Piwigo\Plugins\LoadedPluginRegistry`
  - `register(Plugin $plugin): void`
  - `get(string $id): ?Plugin`
  - `all(): iterable<Plugin>`
  - `setData(string $id, string $key, mixed $value): void`
  - `getData(string $id, string $key): mixed`

**Backward compatibility:** keep `add_event_handler()`, `trigger_change()`, `trigger_notify()`, `remove_event_handler()`, `set_plugin_data()`, `get_plugin_data()`, `load_plugin()`, `load_plugins()` as 1-line wrappers. Third-party plugins keep working unchanged.

**Cost:** 4-6 hours. **Risk:** low — public API stays binary-compatible.

---

### 1.2 — `MailNotificationDispatcher` service (12 sites)

**Removes:** all `global $env_nbm;` in `admin/include/functions_notification_by_mail.inc.php` (lines 55, 163, 195, 226, 246, 260, 273, 285, 316, 349) + `admin/notification_by_mail.php` (58, 104, 211).

**New classes:**
- `Piwigo\Notification\MailNotificationContext` value-object — typed properties for every key the array currently holds:
  - `startTime: float`
  - `sendmailTimeout: int`
  - `isSendmailTimeout: bool`
  - `sentMailCount: int`
  - `errorOnMailCount: int`
  - `mailTemplate: Template` (no more `->assign()` on `mixed`)
  - `saveUser: User`
  - `emailFormat: string` (enum candidate: `text/html` vs `text/plain`)
  - `sendAsName: string`
  - `sendAsMailFormatted: string`
  - `msgInfo: string`
  - `msgError: string`
- `Piwigo\Notification\MailNotificationDispatcher` service — owns the context, exposes:
  - `begin(): void` (replaces `begin_users_env_nbm`)
  - `end(): void` (replaces `end_users_env_nbm`)
  - `dispatchTo(User $user, NotificationPayload $payload): MailResult` (replaces `set_user_on_env_nbm` + send)
  - `recordSuccess(): void` (replaces `inc_mail_sent_success`)
  - `recordFailure(): void` (replaces `inc_mail_sent_failed`)
  - `summary(): MailNotificationSummary` (replaces `display_counter_info`)
  - `checkSendmailTimeout(): bool`
- `Piwigo\Notification\MailSubscriptionService::process(...)` — replaces `do_subscribe_unsubscribe_notification_by_mail`
- `admin/notification_by_mail.php` becomes a thin controller wiring user input → dispatcher

**Cost:** 1.5 days. **Risk:** medium — NBM is exercised in real installs but underspec'd in tests. Add an e2e for the mail-send path *before* refactoring.

---

### 1.3 — `RequestCache` with typed namespaces (5 sites)

**Removes:** `include/functions.inc.php:1907,1908,2133`, `include/functions_html.inc.php:91,239`.

**New classes:**
- `Piwigo\Cache\RequestCache` — per-request memoization
  - `namespace(string $ns): TypedNamespace`
  - Each `TypedNamespace` exposes `get`, `set`, `has`, `remember(string $key, callable $compute): mixed`, `clear()`
  - Backing storage: `array<string, array<string, mixed>>` but accessed only through typed namespace API — no raw nested-key writes leaking out

**Migrations:**
- `$cache['get_icon']['title']` → `RequestCache::namespace('icons')->remember('title', fn() => ...)`
- `$cache['cat_names']` → `RequestCache::namespace('category_names')`
- `$cache[__FUNCTION__][$tag_name]` → `RequestCache::namespace('tag_alpha')->remember($tag_name, ...)`
- `$user['nb_available_comments']` is a different cluster — should be a typed property `User->nbAvailableComments: ?int` lazily computed by a `CommentCounter` service (not a raw cache hit)

**Cost:** 3-4 hours. **Risk:** low.

---

### 1.4 — Keyed errors on `PageState` (5 sites in password.php)

**Removes:** `password.php:42, 130, 131, 227, 275`.

Templates intentionally check specific error keys (`{if isset($errors['login_page_error'])}`) — that contract is good UX, just back it with typed accessors instead of a raw nested array.

**Changes to `src/Piwigo/Core/PageState.php`:**
- `private array<string, string> $keyedErrors = [];` (typed, not mixed)
- `addKeyedError(string $key, string $message): void`
- `getKeyedError(string $key): ?string`
- `getKeyedErrors(): array<string, string>` — returns the template-friendly map; PageState's existing `attachGlobals()` mirrors it into `$GLOBALS['page']['errors']` for templates
- Existing `addError()` / `errors[]` (positional) stays — keyed and positional coexist

**Migration:** `$page['errors']['login_page_error'] = l10n('...')` → `PageState::current()->addKeyedError('login_page_error', l10n('...'))`.

**Cost:** 1 hour. **Risk:** very low.

---

### 1.5 — `DerivativeService` for i.php (3 sites)

**Removes:** `i.php:174, 271, 344`.

i.php's "fast bootstrap" is largely a myth — it already `include`s `common.inc.php`. The accessor singletons are initialized.

**New classes:**
- `Piwigo\Image\DerivativeRequestContext` value-object: `originalPath`, `originalSize: ImageSize`, `rotationAngle: int`, `derivativePath`, `sourcePath`, `params: DerivativeParams`, `coi: ?CenterOfInterest`, `watermark: ?WatermarkConfig`
- `Piwigo\Image\DerivativeService`
  - `parseRequest(array<string,string> $query): DerivativeRequestContext`
  - `trySwitchSource(DerivativeRequestContext $ctx): DerivativeRequestContext`
  - `send(DerivativeRequestContext $ctx): void`
- `i.php` becomes ~30 lines: parse → service → send

**Cost:** 1 day. **Risk:** medium — i.php is hot-path and image-format-sensitive. Needs careful e2e on resize / crop / watermark / rotation across JPEG/PNG/WebP.

---

## Tier 2 — Page rendering + language stack (16 sites, ~1 week)

These two refactors are larger but they unlock Tier 3 (the file-top MVC work), so they pay double.

### 2.1 — `LanguageStack` service (16 sites)

**Removes:** all `global $lang_info, $language_files, $switch_lang, $lang, $conf_mail` declarations across `include/functions.inc.php` (lines 759, 1245, 1621, 1651, 1730, 1731), `include/functions_mail.inc.php` (234, 235, 298, 299, 586, 895), `admin/include/functions_notification_by_mail.inc.php` (163, 164, 195, 196, 226, 227).

**New classes:**
- `Piwigo\Lang\LanguageContext` value-object:
  - `code: string` (e.g. `en_GB`)
  - `info: LangInfo` (already exists from Phase 7b — reuse)
  - `strings: array<string, string|array<string,string>>` — the giant lang dict, but only ever read via typed accessors below
  - `loadedFiles: array<string>`
  - `mailConfig: MailConfig` value-object (replaces `$conf_mail` array)
- `Piwigo\Lang\LanguageStack`
  - `push(string $code): void` (replaces `switch_lang_to`)
  - `pop(): void` (replaces `switch_lang_back`)
  - `current(): LanguageContext`
  - Internal storage: `\SplStack<LanguageContext>` — typed, no raw `$switch_lang` array
- `Piwigo\Lang\LanguageLoader::load(string $domain, ?string $code = null): LanguageContext` — replaces `load_language()`'s closures and merge logic
- Typed nested accessors on `Lang` (the existing `Lang::t()` namespace):
  - `Lang::day(int $dow): string` — replaces `$lang['day'][$dow]`
  - `Lang::month(int $m): string`
  - `Lang::dayShort(int $dow): string`
  - `Lang::monthShort(int $m): string`

**Side migrations:** every site that reads `$lang['day']` / `$lang['month']` / similar nested keys (~25 sites in functions_html, functions_calendar, functions_comment) moves to the typed accessor.

**Cost:** 1 day. **Risk:** medium — touches `load_language()` internals (~150 lines) and many call sites; need full e2e + manual smoke on locale switching.

---

### 2.2 — `PageRenderer` for header/tail (5 sites in `redirect_html`)

**Removes:** `include/functions.inc.php:1026, 1027, 1028, 1029` (the `global $lang_info, $template, $t2, $debug; global $user; global $lang; global $page;` block in `redirect_html`).

Root cause: `include 'page_header.php'` and `include 'page_tail.php'` execute in the calling function's scope, so the file-scope reads inside those files require the function to have declared the same globals.

**New classes:**
- `Piwigo\Page\PageRenderer`
  - `renderHeader(HeaderContext $ctx): string`
  - `renderTail(TailContext $ctx): string`
- `Piwigo\Page\HeaderContext` value-object: `title`, `pageMeta`, `notesForHeader`, `themeAssets`, debug timing
- `Piwigo\Page\TailContext` value-object: `debugQueries`, `loadTime`, `themeAssets`

`page_header.php` and `page_tail.php` get rewritten as templates rendered by `PageRenderer` — no more file-scope global reads. Then `redirect_html()` calls `PageRenderer::renderHeader(...)` instead of `include`-ing.

**Cost:** 4 hours for redirect_html alone — but this same refactor unlocks 17+ entry-scripts in Tier 3, so the real ROI is enormous.

---

## Tier 3 — File-top globals via MVC refactor (~78 declarations, 89 files, ~4-6 weeks)

This is the real modernization. The reason every entry-script declares `global $template, $user, $page, $persistent_cache, $lang;` is that they're 200-2000-line procedural scripts reading these at file scope. The proper fix is to convert each into a Controller class.

### 3.1 — Front controller + routing

- New `public/index.php` front controller (or extend the Kernel boot already present)
- `Piwigo\Http\Router` mapping URLs to Controller classes
- `Piwigo\Http\Request` and `Piwigo\Http\Response` value-objects (or pull in `symfony/http-foundation`)

### 3.2 — Controllers (one per entry-script, ~72 controllers)

Each entry-script in §3.2 below becomes a Controller class:

- `Piwigo\Controller\IndexController::handle(Request): Response`
- `Piwigo\Controller\PictureController::handle(Request): Response`
- `Piwigo\Controller\PasswordController::handle(Request): Response`
- ... and 69 admin controllers (`Piwigo\Controller\Admin\AlbumsController`, etc.)

Controllers receive their dependencies (User, PageState, Template, Lang, MailNotificationDispatcher, etc.) via constructor injection — no globals, no `Registry::current()` calls inside the controller body. The Kernel constructs and wires them.

### 3.3 — Page-builder DTOs (~15 typed contexts)

The scattered page-builder locals (`$category`, `$collection`, `$base_url`, `$admin_album_base_url`, `$admin_photo_base_url`, `$maint_actions`, `$title`, `$url_self`, `$picture`, `$related_categories`, `$comment_action`) become typed properties on per-page Context DTOs:

- `AlbumPageContext` — `category`, `subAlbums`, `photos`, `pagination`, `baseUrl`
- `PicturePageContext` — `picture`, `relatedCategories`, `commentAction`, `urlSelf`
- `AdminAlbumPageContext` — extends with `adminBaseUrl`, `permissions`
- `BatchManagerContext` — `collection`, `baseUrl`, `selectedFilters`
- (etc.)

Controllers populate the context, pass it to the Template / partial — no shared global state.

### 3.4 — Includes become services or partial templates

The 22 pre-boot includes in `include/*.inc.php` (page_header, page_tail, picture_comment, picture_metadata, picture_rate, section_init, filter, search_filters, no_photo_yet, selected_tags, etc.) become either:
- **Services** (if they do logic): `SectionInitializer::initialize(Request): SectionContext`, `FilterResolver::resolve(User): FilterState`
- **Partial templates** (if they're just rendering): `templates/partials/picture_comment.tpl` rendered via `$template->fetch()`

The 7 admin includes become services or partials similarly.

### 3.5 — Inventory by directory

#### Root entry-scripts (15 files)

| File | Line | Becomes |
|---|---|---|
| `comments.php` | 7 | `CommentsController` |
| `feed.php` | 5 | `FeedController` |
| `i.php` | 5 | `DerivativeController` (uses Tier 1.5 service) |
| `identification.php` | 5 | `IdentificationController` |
| `index.php` | 5 | `IndexController` |
| `install.php` | 5 | `InstallController` |
| `notification.php` | 5 | `NotificationController` |
| `password.php` | 5 | `PasswordController` (uses Tier 1.4 keyed errors) |
| `picture.php` | 5 | `PictureController` |
| `profile.php` | 5 | `ProfileController` |
| `register.php` | 5 | `RegisterController` |
| `search.php` | 5 | `SearchController` |
| `tags.php` | 5 | `TagsController` |
| `upgrade.php` | 5 | `UpgradeController` |
| `ws.php` | 5 | `WebServiceController` |

#### Admin entry-scripts (57 files in `admin/`)

| File | Line | Extra vars (today) | Becomes |
|---|---|---|---|
| `admin/album.php` | 18 | — | `Admin\AlbumController` |
| `admin/album_notification.php` | 18 | `$category, $admin_album_base_url` | `Admin\AlbumNotificationController` (uses `AdminAlbumPageContext`) |
| `admin/albums.php` | 15 | — | `Admin\AlbumsController` |
| `admin/batch_manager.php` | 24 | `$logger, $pwg_loaded_plugins` | `Admin\BatchManagerController` (Tier 1.1 plugin registry) |
| `admin/batch_manager_global.php` | 26 | `$logger, $pwg_loaded_plugins` | `Admin\BatchManagerGlobalController` |
| `admin/batch_manager_unit.php` | 25 | `$pwg_loaded_plugins, $cache` | `Admin\BatchManagerUnitController` (Tier 1.3 RequestCache) |
| `admin/cat_list.php` | 15 | — | `Admin\CategoryListController` |
| `admin/cat_modify.php` | 15 | `$category, $admin_album_base_url` | `Admin\CategoryModifyController` |
| `admin/cat_options.php` | 18 | — | `Admin\CategoryOptionsController` |
| `admin/cat_perm.php` | 15 | `$category, $admin_album_base_url` | `Admin\CategoryPermissionsController` |
| `admin/comments.php` | 18 | — | `Admin\CommentsController` |
| `admin/configuration.php` | 20 | — | `Admin\ConfigurationController` |
| `admin/element_set_ranks.php` | 25 | — | `Admin\ElementSetRanksController` |
| `admin/extend_for_templates.php` | 33 | — | `Admin\ExtendForTemplatesController` |
| `admin/group_list.php` | 18 | — | `Admin\GroupListController` |
| `admin/group_perm.php` | 15 | — | `Admin\GroupPermissionsController` |
| `admin/help.php` | 16 | — | `Admin\HelpController` |
| `admin/history.php` | 27 | — | `Admin\HistoryController` |
| `admin/intro.php` | 20 | `$logger, $pwg_loaded_plugins` | `Admin\IntroController` |
| `admin/languages.php` | 18 | — | `Admin\LanguagesController` |
| `admin/languages_installed.php` | 18 | — | `Admin\LanguagesInstalledController` |
| `admin/languages_new.php` | 18 | — | `Admin\LanguagesNewController` |
| `admin/maintenance.php` | 18 | — | `Admin\MaintenanceController` |
| `admin/maintenance_actions.php` | 23 | `$maint_actions` | `Admin\MaintenanceActionsController` (uses `MaintenanceContext`) |
| `admin/maintenance_env.php` | 20 | — | `Admin\MaintenanceEnvController` |
| `admin/maintenance_sys.php` | 15 | — | `Admin\MaintenanceSysController` |
| `admin/menubar.php` | 19 | — | `Admin\MenubarController` |
| `admin/notification_by_mail.php` | 22 | — | `Admin\NotificationByMailController` (Tier 1.2 dispatcher) |
| `admin/permalinks.php` | 79 | — | `Admin\PermalinksController` |
| `admin/photo.php` | 18 | — | `Admin\PhotoController` |
| `admin/photos_add.php` | 18 | — | `Admin\PhotosAddController` |
| `admin/photos_add_applications.php` | 5 | — | `Admin\PhotosAddApplicationsController` |
| `admin/photos_add_direct.php` | 19 | `$logger, $pwg_loaded_plugins` | `Admin\PhotosAddDirectController` |
| `admin/photos_add_ftp.php` | 5 | — | `Admin\PhotosAddFtpController` |
| `admin/picture_coi.php` | 20 | — | `Admin\PictureCoiController` |
| `admin/picture_formats.php` | 19 | — | `Admin\PictureFormatsController` |
| `admin/picture_modify.php` | 19 | `$admin_photo_base_url, $cache` | `Admin\PictureModifyController` |
| `admin/plugins.php` | 18 | — | `Admin\PluginsController` |
| `admin/plugins_installed.php` | 18 | — | `Admin\PluginsInstalledController` |
| `admin/plugins_new.php` | 18 | — | `Admin\PluginsNewController` |
| `admin/popuphelp.php` | 17 | — | `Admin\PopupHelpController` |
| `admin/profile.php` | 15 | — | `Admin\ProfileController` |
| `admin/rating.php` | 19 | — | `Admin\RatingController` |
| `admin/rating_user.php` | 18 | — | `Admin\RatingUserController` |
| `admin/site_manager.php` | 18 | — | `Admin\SiteManagerController` |
| `admin/site_update.php` | 18 | `$logger, $pwg_loaded_plugins` | `Admin\SiteUpdateController` |
| `admin/stats.php` | 15 | — | `Admin\StatsController` |
| `admin/tags.php` | 18 | — | `Admin\TagsController` |
| `admin/themes.php` | 18 | — | `Admin\ThemesController` |
| `admin/themes_installed.php` | 18 | — | `Admin\ThemesInstalledController` |
| `admin/themes_new.php` | 18 | — | `Admin\ThemesNewController` |
| `admin/themes_standard_pages.php` | 18 | — | `Admin\ThemesStandardPagesController` |
| `admin/updates_ext.php` | 18 | — | `Admin\UpdatesExtController` |
| `admin/updates_pwg.php` | 18 | — | `Admin\UpdatesPwgController` |
| `admin/user_activity.php` | 15 | — | `Admin\UserActivityController` |
| `admin/user_list.php` | 25 | — | `Admin\UserListController` |
| `admin/user_perm.php` | 17 | — | `Admin\UserPermissionsController` |

#### Admin includes (`admin/include/*.inc.php`, 7 files)

| File | Line | Becomes |
|---|---|---|
| `admin/include/albums_tab.inc.php` | 5 | `AlbumsTabRenderer` service |
| `admin/include/batch_manager_filters.inc.php` | 15 | `BatchManagerFilterResolver` service |
| `admin/include/configuration_sizes_process.inc.php` | 20 | `ConfigurationSizesProcessor` service |
| `admin/include/configuration_watermark_process.inc.php` | 19 | `WatermarkProcessor` service |
| `admin/include/photos_add_direct_prepare.inc.php` | 5 | `PhotosAddDirectPreparer` service |
| `admin/include/user_tabs.inc.php` | 5 | `UserTabRenderer` service |

#### Pre-boot includes (`include/*.inc.php`, 22 files)

| File | Line | Vars (today) | Becomes |
|---|---|---|---|
| `include/category_cats.inc.php` | 5 | `$persistent_cache, $logger` | `CategoryCatsRenderer` service |
| `include/category_default.inc.php` | 5 | `$persistent_cache` | `CategoryDefaultRenderer` service |
| `include/constants.php` | 5 | `$prefixeTable` | `Config::dbPrefix()` |
| `include/filter.inc.php` | 5 | full set | `FilterRenderer` service |
| `include/no_photo_yet.inc.php` | 5 | full set | `NoPhotoYetRenderer` service |
| `include/page_header.php` | 5 | + `$title, $debug, $t2` | partial template via `PageRenderer::renderHeader()` (Tier 2.2) |
| `include/page_tail.php` | 5 | + `$title, $debug, $t2` | partial template via `PageRenderer::renderTail()` (Tier 2.2) |
| `include/picture_comment.inc.php` | 5 | + `$url_self, $picture, $related_categories, $comment_action` | partial template, context via `PicturePageContext` |
| `include/picture_metadata.inc.php` | 5 | + same picture-page set | partial template |
| `include/picture_rate.inc.php` | 5 | + same picture-page set | partial template |
| `include/search_filters.inc.php` | 5 | full set | `SearchFilterRenderer` service |
| `include/section_init.inc.php` | 5 | + `$logger, $filter` | `SectionInitializer::initialize()` service |
| `include/selected_tags.inc.php` | 5 | `$persistent_cache` | `SelectedTagsRenderer` service |
| `include/user.inc.php` | 5 | `$persistent_cache, $service` | `UserBootstrap` service |
| `include/ws_core.inc.php` | 5 | `$persistent_cache` | merge into `PwgServer` |
| `include/ws_init.inc.php` | 5 | `$persistent_cache` | merge into `PwgServer::boot()` |
| `include/ws_functions/pwg.categories.php` | 5 | `$persistent_cache` | `Ws\CategoriesEndpoints` class |
| `include/ws_functions/pwg.extensions.php` | 5 | `$persistent_cache` | `Ws\ExtensionsEndpoints` class |
| `include/ws_functions/pwg.images.php` | 5 | `$persistent_cache` | `Ws\ImagesEndpoints` class |
| `include/ws_functions/pwg.php` | 5 | `$persistent_cache` | `Ws\GeneralEndpoints` class |
| `include/ws_functions/pwg.tags.php` | 5 | `$persistent_cache` | `Ws\TagsEndpoints` class |
| `include/ws_functions/pwg.users.php` | 5 | `$persistent_cache` | `Ws\UsersEndpoints` class |

---

## Out of scope: function-internal globals in `tools/`

Dev-only utility scripts that don't go through main bootstrap. Not part of the runtime app and not subject to this refactor.

| File | Line | Function | Globals |
|---|---|---|---|
| `tools/translation_analysis.php` | 109 | (translation helper) | `$lang, $user` |
| `tools/translation_analysis.php` | 124 | (translation helper) | `$metalang, $page` |
| `tools/test_piwigo.php` | 63 | `create_database` | `$mysqli` (creates its own connection) |
| `tools/test_piwigo.php` | 250 | `add_picture` | `$mysqli` |

---

## Variable inventory (cross-cutting — what each refactor removes)

| Variable | File-top sites | Function-internal sites | Cleared by |
|---|---|---|---|
| `$template` | ~75 | 1 (redirect_html) | Tier 2.2 (redirect_html) + Tier 3 (file-top) |
| `$user` | ~75 | 6 | Tier 1.3 (cache reads) + Tier 3 (file-top) |
| `$page` | ~75 | 16 | Tier 1.4 (keyed errors) + Tier 1.5 (i.php) + Tier 3 |
| `$persistent_cache` | ~28 | 0 | Tier 3 only |
| `$lang` | ~75 | 6 | Tier 2.1 (LanguageStack) + Tier 3 |
| `$logger` | ~5 | 0 | Tier 3 only |
| `$service` | ~2 | 0 | Tier 3 (`PwgServer` becomes service) |
| `$prefixeTable` | ~3 | 0 | Tier 3 (`Config::dbPrefix()`) |
| `$pwg_event_handlers` | 0 | 4 | Tier 1.1 |
| `$pwg_loaded_plugins` | ~5 | 4 | Tier 1.1 + Tier 3 (file-top) |
| `$env_nbm` | 0 | 12 | Tier 1.2 |
| `$lang_info` | 0 | ~15 | Tier 2.1 |
| `$language_files` | 0 | 2 | Tier 2.1 |
| `$switch_lang` | 0 | 2 | Tier 2.1 |
| `$conf_mail` | 0 | 1 | Tier 2.1 |
| `$cache` | ~2 | 4 | Tier 1.3 (function-internal) + Tier 3 (file-top) |
| `$filter` | ~1 | 0 | Tier 3 (`FilterResolver` service) |
| `$debug`, `$t2`, `$last_time` | ~2 | (page_header/tail, redirect_html) | Tier 2.2 |
| `$title`, `$url_self`, `$picture`, `$related_categories`, `$comment_action`, `$category`, `$collection`, `$base_url`, `$admin_album_base_url`, `$admin_photo_base_url`, `$maint_actions`, `$change_name`, `$nb_photos_in`, `$nb_sub_photos`, `$is_forbidden` | scattered | 0 | Tier 3 (typed page-context DTOs) |

---

## Tooling that enforces the "no new globals" rule

`tools/phpstan/NoGlobalInSrcRule.php` flags `global $X;` in `src/` for the GUARDED variable set, with a suggested replacement message. Current GUARDED list:

```
conf, page, user, lang, template, logger, mysqli, persistent_cache, service,
pwg_event_handlers, pwg_loaded_plugins, env_nbm, header_notes, themeconfs,
cache, filter
```

`tools/phpstan-bootstrap.php` provides the typed shapes that file-top `global` declarations bridge from — entries there can be dropped only when their last consumer migrates (i.e. one entry per Tier 3 batch).

---

## Estimated total cost

| Tier | Sites removed | Cost | Risk |
|---|---|---|---|
| Tier 1 (5 typed-class refactors) | 33 | ~1.5 weeks | low–medium |
| Tier 2 (LanguageStack + PageRenderer) | 16 | ~1 week | medium |
| Tier 3 (MVC refactor) | ~78 file-top + ~5,000–10,000 read sites | ~4–6 weeks | high |
| **Total** | **~131** | **~6.5–8.5 weeks** | |

End state: zero `global $X;` declarations anywhere in `src/`, `include/`, `admin/`, or root entry-scripts. `tools/` remains as a documented carve-out for dev scripts that bootstrap themselves.
