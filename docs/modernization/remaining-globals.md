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

## Tier 3 — Partially complete (~40 declarations remaining, ~2-3 weeks)

The real modernization. The reason every entry-script declares `global $template, $user, $page, $persistent_cache, $lang;` is that they are 200-2000-line procedural scripts reading those at file scope. The fix is MVC.

Tier 3 is **distributed across `ROADMAP-PHP.md`** rather than executed as a single megacommit. Each bucket below is owned by a roadmap item; each wave that lands lets `tools/phpstan-bootstrap.php` shed one stub entry and unblocks step 4–5 of roadmap item #6 incrementally.

| Bucket                                      | Owning roadmap item                                            | Status | What lands there                                                                                                                                                                                                 |
| ------------------------------------------- | -------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front controller + PSR-7/15 routing         | **#22** steps 1–4                                              | ✅ Done | `index.php`, `Router`, middleware pipeline, 27-route table                                                                                                                                                       |
| Root controllers (15 files)                 | **#22** Wave A                                                 | ✅ Done | One controller per repo-root entry-script; source files deleted                                                                                                                                                  |
| Admin controllers (63 files)                | **#22** Wave B                                                 | ✅ Done | All 63 `admin/*.php` page bodies inlined into 10 typed sub-controllers; files deleted                                                                                                                            |
| Per-page Context DTOs (~15)                 | **#22** step 5c                                                | ⏳ Remaining | `AlbumPageContext`, `PicturePageContext`, etc. — built alongside Tier 3 rendering work                                                                                                                           |
| Pre-boot includes that are _services_       | **#17** ("Pre-boot and admin includes → services" sub-section) | ⏳ Remaining | `SectionInitializer`, `UserBootstrap`, `FilterResolver`, `PwgServer`, `Ws\Method\*Endpoints`, `Config::dbPrefix()`                                                                                               |
| Admin includes that are _services_          | **#17**                                                        | ⏳ Remaining | `AlbumsTabRenderer`, `BatchManager\FilterResolver`, `Config\SizesProcessor`, `Config\WatermarkProcessor`, `Upload\DirectPreparer`, `Users\UserTabRenderer`                                                       |
| Pre-boot includes that are _pure rendering_ | **#24** Wave 0                                                 | ⏳ Remaining | `page_header`, `page_tail`, `picture_comment`, `picture_metadata`, `picture_rate`, `no_photo_yet`, `search_filters`, `selected_tags`, `category_cats`, `category_default` → `.latte` partials with typed context |

The inventory tables below remain the source of truth — cross rows off as each roadmap-item wave lands.

### Inventory by directory

#### ✅ Root entry-scripts (15 files) — Wave A complete

All 15 root entry-point `.php` files converted to controllers and deleted. Source files no longer exist in the repo.

#### ✅ Admin entry-scripts (63 files) — Wave B complete

All 63 `admin/*.php` page-body files deleted. Logic inlined into 10 typed sub-controllers in `src/Piwigo/Controller/Admin/`. Remaining in `admin/`: `site_reader_local.php` (still `require`d by two controllers) and `admin/include/*.php` shared helpers.

<!-- legacy table removed; kept for reference in git history -->

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

| Tier                                                            | Sites        | Cost       | Status    |
| --------------------------------------------------------------- | ------------ | ---------- | --------- |
| Tier 1 — 5 typed classes                                        | 33           | ~1.5 weeks | **Done**  |
| Tier 2 — LanguageStack + PageRenderer                           | 16           | ~1 week    | Remaining |
| Tier 3 Wave A/B — root + admin controllers                      | ~78 file-top | —          | **Done**  |
| Tier 3 remaining — admin/include + pre-boot includes → services | ~40          | ~2–3 weeks | Remaining |

End state: zero `global $X;` in `src/`, `include/`, `admin/`, root entry-scripts. `tools/` is a documented carve-out.
