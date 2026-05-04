# Remaining `global $X;` declarations

This document tracks the full plan to remove every `global $X;` from the codebase via proper typed classes — no reference-bridges, no `@var` annotations. Updated after each tier lands.

| Scope                               | Sites                     | Status                                        |
| ----------------------------------- | ------------------------- | --------------------------------------------- |
| Function-internal (in-app) — Tier 1 | 33                        | **Done** (commit `refactor(globals): tier 1`) |
| Function-internal (in-app) — Tier 2 | 16                        | Remaining                                     |
| Function-internal (tools/)          | 4                         | Out of scope (dev-only scripts)               |
| File-top declarations               | ~78 lines across 89 files | Tier 3 (MVC refactor)                         |
| **Total remaining**                 | **~98**                   |                                               |

---

## ✅ Tier 1 — Done (33 sites, commit `refactor(globals): tier 1`)

Five typed-class refactors shipped together. All 33 function-internal sites removed.

| Refactor                      | Sites removed | New class(es)                             | Backward compat                                                                                                                                                                                                               |
| ----------------------------- | ------------- | ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1.1 Plugin event system       | 8             | `EventDispatcher`, `LoadedPluginRegistry` | `add_event_handler`, `trigger_change/notify`, `set/get_plugin_data`, `load_plugin/plugins` kept as 1-line wrappers; `$GLOBALS['pwg_event_handlers']` + `$GLOBALS['pwg_loaded_plugins']` ref-bridged for direct-global plugins |
| 1.2 NBM context               | 13            | `MailNotificationContext`                 | All 13 function sites in `functions_notification_by_mail.inc.php` + `notification_by_mail.php` migrated; no `$GLOBALS` bridge needed (purely internal)                                                                        |
| 1.3 Request memo cache        | 5             | `RequestCache`                            | `get_icon`, `get_cat_display_name_cache`, `tag_alpha_compare`, `get_nb_available_comments` use `RequestCache::remember/get/set/has`                                                                                           |
| 1.4 Keyed errors              | 5             | `PageState::addKeyedError`                | `$page['errors']['key'] = ...` in `password.php` → `addKeyedError('key', ...)`; `$GLOBALS['page']['keyed_errors']` bridged for template reads                                                                                 |
| 1.5 i.php derivative pipeline | 3             | `ImageDerivativeContext`                  | Full file-scope migration — `$ctx = new ImageDerivativeContext()` replaces `$page = [...]`; 3 functions rewritten to accept typed context; all file-scope `$page` accesses replaced with `$ctx->property`                     |

**What the implementations actually look like vs. the original plan:**

- `EventDispatcher` uses a flat `array<string, array<int, list<array{...}>>>` internally (not SplPriorityQueue — same structure as the original `$pwg_event_handlers` for zero migration risk on plugin code).
- `MailNotificationContext` is a typed singleton (not a full `MailNotificationDispatcher` service) — the procedural functions in `functions_notification_by_mail.inc.php` remain but read/write typed properties via `MailNotificationContext::current()`.
- `RequestCache` uses flat static methods (`remember(string $ns, string $key, callable)`) rather than a fluent namespace object.
- `ImageDerivativeContext` replaces the `$page` array throughout i.php (including file scope), not just in the 3 functions.

---

## Tier 2 — Remaining (16 sites, ~1 week)

These two refactors unlock Tier 3 (the file-top MVC work).

### 2.1 — `LanguageStack` service (16 sites)

**Removes:** all `global $lang_info, $language_files, $switch_lang, $lang, $conf_mail` declarations across:

| File                                                   | Lines                             | Variables                                                     |
| ------------------------------------------------------ | --------------------------------- | ------------------------------------------------------------- |
| `include/functions.inc.php`                            | 759, 1245, 1621, 1651, 1730, 1731 | `$lang_info`, `$language_files`, `$lang`                      |
| `include/functions_mail.inc.php`                       | 234, 235, 298, 299, 586, 895      | `$switch_lang`, `$lang_info`, `$lang`, `$conf_mail`           |
| `admin/include/functions_notification_by_mail.inc.php` | 163, 164, 195, 196, 226, 227      | `$lang_info`, `$lang` (alongside the now-migrated `$env_nbm`) |

**New classes:**

- `Piwigo\Lang\LanguageContext` value-object: `code: string`, `info: LangInfo`, `strings: array<string, string|array<string,string>>`, `loadedFiles: array<string>`, `mailConfig: MailConfig`
- `Piwigo\Lang\LanguageStack` — `push(string $code): void`, `pop(): void`, `current(): LanguageContext`; backed by `\SplStack<LanguageContext>`, no raw `$switch_lang` array
- `Piwigo\Lang\LanguageLoader::load(string $domain, ?string $code = null): LanguageContext` — replaces `load_language()`'s closures
- Typed nested accessors on `Lang`: `Lang::day(int $dow): string`, `Lang::month(int $m): string`, `Lang::dayShort`, `Lang::monthShort` — replace `$lang['day'][$dow]` etc.

**Side migrations:** every site reading `$lang['day']` / `$lang['month']` (~25 sites in `functions_html`, `functions_calendar`, `functions_comment`) moves to the typed accessor.

**Cost:** 1 day. **Risk:** medium — touches `load_language()` internals (~150 lines) and many call sites.

---

### 2.2 — `PageRenderer` for header/tail (5 sites in `redirect_html`)

**Removes:** `include/functions.inc.php` lines 1026-1029 — `global $lang_info, $template, $t2, $debug; global $user; global $lang; global $page;` inside `redirect_html()`.

Root cause: `redirect_html()` does `include 'page_header.php'` and `include 'page_tail.php'`, which execute in the calling function's scope and read `$user`, `$lang`, `$page`, `$template`, `$lang_info`, `$debug`, `$t2` at file scope.

**New classes:**

- `Piwigo\Page\PageRenderer` — `renderHeader(HeaderContext $ctx): string`, `renderTail(TailContext $ctx): string`
- `Piwigo\Page\HeaderContext` value-object: `title`, `pageMeta`, `notesForHeader`, `themeAssets`, debug timing
- `Piwigo\Page\TailContext` value-object: `debugQueries`, `loadTime`, `themeAssets`

`page_header.php` and `page_tail.php` become templates rendered by `PageRenderer`. Then `redirect_html()` calls `PageRenderer::renderHeader(...)` instead of `include`-ing, eliminating the need for the 5 globals.

**Note:** This same refactor unlocks 17+ entry-scripts in Tier 3 (`$page['root_path']`, template rendering, etc.) — do this before starting the MVC work.

**Cost:** 4-6 hours. **Risk:** medium — page_header/tail touch every page render path.

---

## Tier 3 — Remaining (~78 declarations, 89 files, ~4-6 weeks)

The real modernization. The reason every entry-script declares `global $template, $user, $page, $persistent_cache, $lang;` is that they are 200-2000-line procedural scripts reading those at file scope. The fix is MVC.

Tier 3 is **distributed across `ROADMAP-PHP.md`** rather than executed as a single megacommit. Each bucket below is owned by a roadmap item; each wave that lands lets `tools/phpstan-bootstrap.php` shed one stub entry and unblocks step 4–5 of roadmap item #6 incrementally.

| Bucket                                      | Owning roadmap item                                            | What lands there                                                                                                                                                                                                 |
| ------------------------------------------- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front controller + PSR-7/15 routing         | **#22** steps 1–4                                              | `public/index.php`, `Router`, middleware pipeline, route table                                                                                                                                                   |
| Root controllers (15 files)                 | **#22** step 5 (Wave A)                                        | One controller per repo-root entry-script                                                                                                                                                                        |
| Admin controllers (57 files)                | **#22** step 5b (Wave B)                                       | One controller per `admin/*.php` entry-script                                                                                                                                                                    |
| Per-page Context DTOs (~15)                 | **#22** step 5c                                                | `AlbumPageContext`, `PicturePageContext`, etc. — built alongside their controllers                                                                                                                               |
| Pre-boot includes that are _services_       | **#17** ("Pre-boot and admin includes → services" sub-section) | `SectionInitializer`, `UserBootstrap`, `FilterResolver`, `PwgServer`, `Ws\Method\*Endpoints`, `Config::dbPrefix()`                                                                                               |
| Admin includes that are _services_          | **#17**                                                        | `AlbumsTabRenderer`, `BatchManager\FilterResolver`, `Config\SizesProcessor`, `Config\WatermarkProcessor`, `Upload\DirectPreparer`, `Users\UserTabRenderer`                                                       |
| Pre-boot includes that are _pure rendering_ | **#24** Wave 0                                                 | `page_header`, `page_tail`, `picture_comment`, `picture_metadata`, `picture_rate`, `no_photo_yet`, `search_filters`, `selected_tags`, `category_cats`, `category_default` → `.latte` partials with typed context |

The inventory tables below remain the source of truth — cross rows off as each roadmap-item wave lands.

### Inventory by directory

#### Root entry-scripts (15 files)

| File                 | Line | Becomes                                                           |
| -------------------- | ---- | ----------------------------------------------------------------- |
| `comments.php`       | 7    | `CommentsController`                                              |
| `feed.php`           | 5    | `FeedController`                                                  |
| `i.php`              | 5    | file-top global only; function-internal already removed in Tier 1 |
| `identification.php` | 5    | `IdentificationController`                                        |
| `index.php`          | 5    | `IndexController`                                                 |
| `install.php`        | 5    | `InstallController`                                               |
| `notification.php`   | 5    | `NotificationController`                                          |
| `password.php`       | 5    | file-top global only; function-internal already removed in Tier 1 |
| `picture.php`        | 5    | `PictureController`                                               |
| `profile.php`        | 5    | `ProfileController`                                               |
| `register.php`       | 5    | `RegisterController`                                              |
| `search.php`         | 5    | `SearchController`                                                |
| `tags.php`           | 5    | `TagsController`                                                  |
| `upgrade.php`        | 5    | `UpgradeController`                                               |
| `ws.php`             | 5    | `WebServiceController`                                            |

#### Admin entry-scripts (57 files in `admin/`)

| File                                | Line | Extra vars (today)                 | Becomes                                                                         |
| ----------------------------------- | ---- | ---------------------------------- | ------------------------------------------------------------------------------- |
| `admin/album.php`                   | 18   | —                                  | `Admin\AlbumController`                                                         |
| `admin/album_notification.php`      | 18   | `$category, $admin_album_base_url` | `Admin\AlbumNotificationController`                                             |
| `admin/albums.php`                  | 15   | —                                  | `Admin\AlbumsController`                                                        |
| `admin/batch_manager.php`           | 24   | `$logger, $pwg_loaded_plugins`     | `Admin\BatchManagerController`                                                  |
| `admin/batch_manager_global.php`    | 26   | `$logger, $pwg_loaded_plugins`     | `Admin\BatchManagerGlobalController`                                            |
| `admin/batch_manager_unit.php`      | 25   | `$pwg_loaded_plugins, $cache`      | `Admin\BatchManagerUnitController`                                              |
| `admin/cat_list.php`                | 15   | —                                  | `Admin\CategoryListController`                                                  |
| `admin/cat_modify.php`              | 15   | `$category, $admin_album_base_url` | `Admin\CategoryModifyController`                                                |
| `admin/cat_options.php`             | 18   | —                                  | `Admin\CategoryOptionsController`                                               |
| `admin/cat_perm.php`                | 15   | `$category, $admin_album_base_url` | `Admin\CategoryPermissionsController`                                           |
| `admin/comments.php`                | 18   | —                                  | `Admin\CommentsController`                                                      |
| `admin/configuration.php`           | 20   | —                                  | `Admin\ConfigurationController`                                                 |
| `admin/element_set_ranks.php`       | 25   | —                                  | `Admin\ElementSetRanksController`                                               |
| `admin/extend_for_templates.php`    | 33   | —                                  | `Admin\ExtendForTemplatesController`                                            |
| `admin/group_list.php`              | 18   | —                                  | `Admin\GroupListController`                                                     |
| `admin/group_perm.php`              | 15   | —                                  | `Admin\GroupPermissionsController`                                              |
| `admin/help.php`                    | 16   | —                                  | `Admin\HelpController`                                                          |
| `admin/history.php`                 | 27   | —                                  | `Admin\HistoryController`                                                       |
| `admin/intro.php`                   | 20   | `$logger, $pwg_loaded_plugins`     | `Admin\IntroController`                                                         |
| `admin/languages.php`               | 18   | —                                  | `Admin\LanguagesController`                                                     |
| `admin/languages_installed.php`     | 18   | —                                  | `Admin\LanguagesInstalledController`                                            |
| `admin/languages_new.php`           | 18   | —                                  | `Admin\LanguagesNewController`                                                  |
| `admin/maintenance.php`             | 18   | —                                  | `Admin\MaintenanceController`                                                   |
| `admin/maintenance_actions.php`     | 23   | `$maint_actions`                   | `Admin\MaintenanceActionsController`                                            |
| `admin/maintenance_env.php`         | 20   | —                                  | `Admin\MaintenanceEnvController`                                                |
| `admin/maintenance_sys.php`         | 15   | —                                  | `Admin\MaintenanceSysController`                                                |
| `admin/menubar.php`                 | 19   | —                                  | `Admin\MenubarController`                                                       |
| `admin/notification_by_mail.php`    | 22   | —                                  | `Admin\NotificationByMailController` (function-internal already done in Tier 1) |
| `admin/permalinks.php`              | 79   | —                                  | `Admin\PermalinksController`                                                    |
| `admin/photo.php`                   | 18   | —                                  | `Admin\PhotoController`                                                         |
| `admin/photos_add.php`              | 18   | —                                  | `Admin\PhotosAddController`                                                     |
| `admin/photos_add_applications.php` | 5    | —                                  | `Admin\PhotosAddApplicationsController`                                         |
| `admin/photos_add_direct.php`       | 19   | `$logger, $pwg_loaded_plugins`     | `Admin\PhotosAddDirectController`                                               |
| `admin/photos_add_ftp.php`          | 5    | —                                  | `Admin\PhotosAddFtpController`                                                  |
| `admin/picture_coi.php`             | 20   | —                                  | `Admin\PictureCoiController`                                                    |
| `admin/picture_formats.php`         | 19   | —                                  | `Admin\PictureFormatsController`                                                |
| `admin/picture_modify.php`          | 19   | `$admin_photo_base_url, $cache`    | `Admin\PictureModifyController`                                                 |
| `admin/plugins.php`                 | 18   | —                                  | `Admin\PluginsController`                                                       |
| `admin/plugins_installed.php`       | 18   | —                                  | `Admin\PluginsInstalledController`                                              |
| `admin/plugins_new.php`             | 18   | —                                  | `Admin\PluginsNewController`                                                    |
| `admin/popuphelp.php`               | 17   | —                                  | `Admin\PopupHelpController`                                                     |
| `admin/profile.php`                 | 15   | —                                  | `Admin\ProfileController`                                                       |
| `admin/rating.php`                  | 19   | —                                  | `Admin\RatingController`                                                        |
| `admin/rating_user.php`             | 18   | —                                  | `Admin\RatingUserController`                                                    |
| `admin/site_manager.php`            | 18   | —                                  | `Admin\SiteManagerController`                                                   |
| `admin/site_update.php`             | 18   | `$logger, $pwg_loaded_plugins`     | `Admin\SiteUpdateController`                                                    |
| `admin/stats.php`                   | 15   | —                                  | `Admin\StatsController`                                                         |
| `admin/tags.php`                    | 18   | —                                  | `Admin\TagsController`                                                          |
| `admin/themes.php`                  | 18   | —                                  | `Admin\ThemesController`                                                        |
| `admin/themes_installed.php`        | 18   | —                                  | `Admin\ThemesInstalledController`                                               |
| `admin/themes_new.php`              | 18   | —                                  | `Admin\ThemesNewController`                                                     |
| `admin/themes_standard_pages.php`   | 18   | —                                  | `Admin\ThemesStandardPagesController`                                           |
| `admin/updates_ext.php`             | 18   | —                                  | `Admin\UpdatesExtController`                                                    |
| `admin/updates_pwg.php`             | 18   | —                                  | `Admin\UpdatesPwgController`                                                    |
| `admin/user_activity.php`           | 15   | —                                  | `Admin\UserActivityController`                                                  |
| `admin/user_list.php`               | 25   | —                                  | `Admin\UserListController`                                                      |
| `admin/user_perm.php`               | 17   | —                                  | `Admin\UserPermissionsController`                                               |

#### Admin includes (`admin/include/*.inc.php`, 7 files)

| File                                                    | Line | Becomes                               |
| ------------------------------------------------------- | ---- | ------------------------------------- |
| `admin/include/albums_tab.inc.php`                      | 5    | `AlbumsTabRenderer` service           |
| `admin/include/batch_manager_filters.inc.php`           | 15   | `BatchManagerFilterResolver` service  |
| `admin/include/configuration_sizes_process.inc.php`     | 20   | `ConfigurationSizesProcessor` service |
| `admin/include/configuration_watermark_process.inc.php` | 19   | `WatermarkProcessor` service          |
| `admin/include/photos_add_direct_prepare.inc.php`       | 5    | `PhotosAddDirectPreparer` service     |
| `admin/include/user_tabs.inc.php`                       | 5    | `UserTabRenderer` service             |

#### Pre-boot includes (`include/*.inc.php`, 22 files)

| File                                      | Line | Vars (today)                                                  | Becomes                                                        |
| ----------------------------------------- | ---- | ------------------------------------------------------------- | -------------------------------------------------------------- |
| `include/category_cats.inc.php`           | 5    | `$persistent_cache, $logger`                                  | `CategoryCatsRenderer` service                                 |
| `include/category_default.inc.php`        | 5    | `$persistent_cache`                                           | `CategoryDefaultRenderer` service                              |
| `include/constants.php`                   | 5    | `$prefixeTable`                                               | `Config::dbPrefix()`                                           |
| `include/filter.inc.php`                  | 5    | full set                                                      | `FilterRenderer` service                                       |
| `include/no_photo_yet.inc.php`            | 5    | full set                                                      | `NoPhotoYetRenderer` service                                   |
| `include/page_header.php`                 | 5    | + `$title, $debug, $t2`                                       | partial template via `PageRenderer::renderHeader()` (Tier 2.2) |
| `include/page_tail.php`                   | 5    | + `$title, $debug, $t2`                                       | partial template via `PageRenderer::renderTail()` (Tier 2.2)   |
| `include/picture_comment.inc.php`         | 5    | + `$url_self, $picture, $related_categories, $comment_action` | partial template, context via `PicturePageContext`             |
| `include/picture_metadata.inc.php`        | 5    | + same picture-page set                                       | partial template                                               |
| `include/picture_rate.inc.php`            | 5    | + same picture-page set                                       | partial template                                               |
| `include/search_filters.inc.php`          | 5    | full set                                                      | `SearchFilterRenderer` service                                 |
| `include/section_init.inc.php`            | 5    | + `$logger, $filter`                                          | `SectionInitializer::initialize()` service                     |
| `include/selected_tags.inc.php`           | 5    | `$persistent_cache`                                           | `SelectedTagsRenderer` service                                 |
| `include/user.inc.php`                    | 5    | `$persistent_cache, $service`                                 | `UserBootstrap` service                                        |
| `include/ws_core.inc.php`                 | 5    | `$persistent_cache`                                           | merge into `PwgServer`                                         |
| `include/ws_init.inc.php`                 | 5    | `$persistent_cache`                                           | merge into `PwgServer::boot()`                                 |
| `include/ws_functions/pwg.categories.php` | 5    | `$persistent_cache`                                           | `Ws\CategoriesEndpoints` class                                 |
| `include/ws_functions/pwg.extensions.php` | 5    | `$persistent_cache`                                           | `Ws\ExtensionsEndpoints` class                                 |
| `include/ws_functions/pwg.images.php`     | 5    | `$persistent_cache`                                           | `Ws\ImagesEndpoints` class                                     |
| `include/ws_functions/pwg.php`            | 5    | `$persistent_cache`                                           | `Ws\GeneralEndpoints` class                                    |
| `include/ws_functions/pwg.tags.php`       | 5    | `$persistent_cache`                                           | `Ws\TagsEndpoints` class                                       |
| `include/ws_functions/pwg.users.php`      | 5    | `$persistent_cache`                                           | `Ws\UsersEndpoints` class                                      |

---

## Out of scope: function-internal globals in `tools/`

Dev-only utility scripts that don't go through main bootstrap. Not subject to this refactor.

| File                             | Line | Function             | Globals                                |
| -------------------------------- | ---- | -------------------- | -------------------------------------- |
| `tools/translation_analysis.php` | 109  | (translation helper) | `$lang, $user`                         |
| `tools/translation_analysis.php` | 124  | (translation helper) | `$metalang, $page`                     |
| `tools/test_piwigo.php`          | 63   | `create_database`    | `$mysqli` (creates its own connection) |
| `tools/test_piwigo.php`          | 250  | `add_picture`        | `$mysqli`                              |

---

## Variable inventory (cross-cutting — current state)

| Variable                                                                                                                                                                                 | File-top sites | Function-internal sites remaining | Cleared by                                                            |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- | --------------------------------- | --------------------------------------------------------------------- |
| `$template`                                                                                                                                                                              | ~75            | 1 (redirect_html)                 | Tier 2.2 + Tier 3                                                     |
| `$user`                                                                                                                                                                                  | ~75            | 0 ✅                              | Tier 3 only (file-top)                                                |
| `$page`                                                                                                                                                                                  | ~75            | 0 ✅                              | Tier 3 only (file-top)                                                |
| `$persistent_cache`                                                                                                                                                                      | ~28            | 0 ✅                              | Tier 3 only                                                           |
| `$lang`                                                                                                                                                                                  | ~75            | 6                                 | Tier 2.1 + Tier 3                                                     |
| `$logger`                                                                                                                                                                                | ~5             | 0 ✅                              | Tier 3 only                                                           |
| `$service`                                                                                                                                                                               | ~2             | 0 ✅                              | Tier 3 only                                                           |
| `$prefixeTable`                                                                                                                                                                          | ~3             | 0 ✅                              | Tier 3 (`Config::dbPrefix()`)                                         |
| `$pwg_event_handlers`                                                                                                                                                                    | 0              | 0 ✅                              | Done — `EventDispatcher`                                              |
| `$pwg_loaded_plugins`                                                                                                                                                                    | ~5             | 0 ✅                              | Done (function-internal) — `LoadedPluginRegistry`; file-top in Tier 3 |
| `$env_nbm`                                                                                                                                                                               | 0              | 0 ✅                              | Done — `MailNotificationContext`                                      |
| `$lang_info`                                                                                                                                                                             | 0              | ~10                               | Tier 2.1                                                              |
| `$language_files`                                                                                                                                                                        | 0              | 2                                 | Tier 2.1                                                              |
| `$switch_lang`                                                                                                                                                                           | 0              | 2                                 | Tier 2.1                                                              |
| `$conf_mail`                                                                                                                                                                             | 0              | 1                                 | Tier 2.1                                                              |
| `$cache`                                                                                                                                                                                 | ~2             | 0 ✅                              | Done (function-internal) — `RequestCache`; file-top in Tier 3         |
| `$filter`                                                                                                                                                                                | ~1             | 0 ✅                              | Tier 3 (`FilterResolver` service)                                     |
| `$debug`, `$t2`, `$last_time`                                                                                                                                                            | ~2             | 1 (redirect_html)                 | Tier 2.2                                                              |
| `$title`, `$url_self`, `$picture`, `$related_categories`, `$comment_action`, `$category`, `$collection`, `$base_url`, `$admin_album_base_url`, `$admin_photo_base_url`, `$maint_actions` | scattered      | 0 ✅                              | Tier 3 (typed page-context DTOs)                                      |

---

## Tooling

`tools/phpstan/NoGlobalInSrcRule.php` GUARDED list (any use of these in `src/` is a PHPStan error with a typed-accessor suggestion):

```
conf, page, user, lang, template, logger, mysqli, persistent_cache, service,
pwg_event_handlers, pwg_loaded_plugins, env_nbm, header_notes, themeconfs,
cache, filter
```

`tools/phpstan-bootstrap.php` provides typed shapes for the global variables. An entry can be dropped only when its last consumer migrates — one entry per Tier 3 batch.

---

## Effort summary

| Tier                                        | Sites        | Cost       | Status    |
| ------------------------------------------- | ------------ | ---------- | --------- |
| Tier 1 — 5 typed classes                    | 33           | ~1.5 weeks | **Done**  |
| Tier 2 — LanguageStack + PageRenderer       | 16           | ~1 week    | Remaining |
| Tier 3 — MVC (file-top + 5k–10k read sites) | ~78 file-top | ~4–6 weeks | Remaining |

End state: zero `global $X;` in `src/`, `include/`, `admin/`, root entry-scripts. `tools/` is a documented carve-out.
