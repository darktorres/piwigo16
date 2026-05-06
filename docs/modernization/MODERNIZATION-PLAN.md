# Plan: Pending Modernization Work

## Context

All routing migration work is complete. This plan covers the remaining items
mentioned in the session: trivial deferred fixes, moving the last procedural
file out of `admin/`, swapping Template.php's free-function registrations to
typed callables, Tier 2 global elimination (LanguageStack + PageRenderer), and
Tier 3 remaining (`admin/include/` + `include/*.inc.php` → services).

---

## Phase 1 — Trivial deferred fixes (one commit)

### 1a. `Updates.php:486` — wrong page name in redirect

**File:** `src/Piwigo/Admin/Updates.php` line ~489

Currently: `redirect(ServiceLocator::get(UrlGenerator::class)->admin('plugin-'.basename(__DIR__)));`
`basename(__DIR__)` = `"Admin"` → produces `page=plugin-Admin` — stale plugin-era name.

**Fix:** Change to `->admin('updates')` so the redirect lands on the updates page.

### 1b. `CurrentUser.php:10` — stale Wave A comment

**File:** `src/Piwigo/Users/CurrentUser.php` lines 6–12

Remove "Wave A:" prefix and rephrase to describe the current boot flow without
dated roadmap terminology.

---

## Phase 2 — Move `LocalSiteReader` to `src/`

**Commit: `refactor: move LocalSiteReader class to src/Piwigo/Site/`**

`admin/site_reader_local.php` defines the `LocalSiteReader` class (196 lines).
It is `require`d by `BatchManagerController` (1 site) and `MaintenanceController`
(2 sites) purely to access the class. Nothing else about the file is procedural.

### Steps

1. Create `src/Piwigo/Site/LocalSiteReader.php` — copy the class body, add
   `namespace Piwigo\Site;` and any needed `use` statements (the class uses
   `get_fs_directories()`, `get_extension()`, `get_filename_wo_extension()`,
   `get_sync_metadata_attributes()`, `get_sync_metadata()` — all free functions,
   so keep `require_once` of their include files or ensure they are already
   loaded by the time the class methods are called).

2. In `BatchManagerController.php`: remove the `require_once` line, add
   `use Piwigo\Site\LocalSiteReader;`.

3. In `MaintenanceController.php`: same for both require lines.

4. Delete `admin/site_reader_local.php`.

5. Run PHPStan level 9 → 0 errors.

---

## Phase 3 — Template.php: wire Piwigo free functions to typed callables

**Commit: `refactor: wire Template Smarty plugins to typed callables`**

`src/Piwigo/Template/Template.php` lines ~103–147 register Smarty modifiers.
Built-in PHP functions (`sprintf`, `json_encode`, `trim`, etc.) stay as-is —
they are already native. Only Piwigo-specific free functions need swapping.

| Line | Modifier | Current callable | Typed callable |
|------|----------|-----------------|----------------|
| 137 | `l10n` | `'l10n'` | `Lang::current()->t(...)` — use closure `fn(string $k) => Lang::current()->t($k)` |
| 139 | `is_admin` | `'is_admin'` | `CurrentUser::isAdmin(...)` — verify signature, wrap if needed |
| 140 | `is_classic_user` | `'is_classic_user'` | `CurrentUser::isClassicUser(...)` — verify signature |
| 145 | `get_gallery_home_url` | `'get_gallery_home_url'` | `ServiceLocator::get(UrlGenerator::class)->gallery(...)` — wrap with closure |
| 135 | `url_is_remote` | `'url_is_remote'` | check `src/Piwigo/Url/UrlService.php` for typed equivalent; if none, leave for now |
| 141 | `get_device` | `'get_device'` | no typed equivalent yet — leave as free function for now |

**Note:** The free functions (`l10n()`, `is_admin()`, etc.) are still called from
hundreds of PHP files. Do NOT delete them here — only the Smarty registration
changes. Free function deletion is part of Tier 3.

**Verify:** PHPStan level 9, `npm run build`, and browser-test a gallery page
(checks l10n renders), an admin page (checks is_admin), and the about page.

---

## Phase 4 — Tier 2.1: LanguageStack service

**Commit: `refactor: LanguageStack service — remove 16 function-internal globals`**

Removes all `global $lang, $lang_info, $language_files, $switch_lang, $conf_mail`
from function bodies across 3 files (16 declaration sites).

### Files affected
- `include/functions.inc.php` — 6 sites (`$lang_info`, `$language_files`, `$lang`)
- `include/functions_mail.inc.php` — 6 sites (`$switch_lang`, `$lang_info`, `$lang`, `$conf_mail`)
- `admin/include/functions_notification_by_mail.inc.php` — 4 sites (`$lang_info`, `$lang`)

### New classes (per `remaining-globals.md` design)
- `src/Piwigo/Lang/LanguageContext.php` — value object: `code`, `info`, `strings`, `loadedFiles`, `mailConfig`
- `src/Piwigo/Lang/LanguageStack.php` — `push(string $code): void`, `pop(): void`, `current(): LanguageContext`; backed by `SplStack`
- `src/Piwigo/Lang/LanguageLoader.php` — `load(string $domain, ?string $code): LanguageContext`; replaces `load_language()` internals

### Side migrations
- ~25 call sites reading `$lang['day']` / `$lang['month']` (in functions_html,
  functions_calendar, functions_comment) → `Lang::day(int $dow)` / `Lang::month(int $m)`
- `switch_lang_to()` / `switch_lang_back()` free functions → delegate to
  `LanguageStack::push()` / `pop()`

**Verify:** PHPStan level 9 → 0 errors. Run integration tests. Send a test NBM email.

---

## Phase 5 — Tier 2.2: PageRenderer

**Commit: `refactor: PageRenderer — eliminate globals from redirect_html() and page rendering path`**

`redirect_html()` in `include/functions.inc.php` does `include 'page_header.php'`
and `include 'page_tail.php'` at function scope, forcing 7 global declarations.
This also blocks Tier 3 rendering work.

### New classes
- `src/Piwigo/Page/PageRenderer.php` — `renderHeader(HeaderContext $ctx): void`,
  `renderTail(TailContext $ctx): void`
- `src/Piwigo/Page/HeaderContext.php` — value object: `title`, `bodyId`, `metaRobots`,
  `pageBanner`, `notesForHeader`, `themeAssets`, debug timing
- `src/Piwigo/Page/TailContext.php` — value object: `debugQueries`, `loadTime`,
  `themeAssets`

### Migration
- `page_header.php` and `page_tail.php` remain as files but are called through
  `PageRenderer` instead of bare `include` in `redirect_html()`. The 7 globals
  in `redirect_html()` are removed.
- All controllers already call `require PHPWG_ROOT_PATH . 'include/page_header.php'`
  directly — these stay unchanged for now (Tier 3 rendering wave handles them).

**Verify:** PHPStan level 9 → 0 errors. Test gallery page, admin page, and a
`redirect_html()` triggered error page.

---

## Phase 6 — Tier 3a: `admin/include/*.inc.php` → typed services

**Commit: `refactor: admin/include helpers → typed services`**

6 files in `admin/include/` contain procedural logic loaded via `require_once`
from admin sub-controllers. Each becomes a typed service in `src/Piwigo/Admin/`.

| Current file | New class | Used by |
|---|---|---|
| `admin/include/albums_tab.inc.php` | `src/Piwigo/Admin/Album/AlbumsTabRenderer` | `AlbumController`, `MiscController` |
| `admin/include/batch_manager_filters.inc.php` | `src/Piwigo/Admin/BatchManager/FilterResolver` | `BatchManagerController` (×2) |
| `admin/include/configuration_sizes_process.inc.php` | `src/Piwigo/Admin/Config/SizesProcessor` | `ConfigurationController` |
| `admin/include/configuration_watermark_process.inc.php` | `src/Piwigo/Admin/Config/WatermarkProcessor` | `ConfigurationController` |
| `admin/include/photos_add_direct_prepare.inc.php` | `src/Piwigo/Admin/Upload/DirectPreparer` | `PhotoController` |
| `admin/include/user_tabs.inc.php` | `src/Piwigo/Admin/Users/UserTabRenderer` | `UsersController` |

**Pattern per file:**
1. Read the file — identify the functions/logic it defines
2. Create the typed service class in `src/`; move logic into methods
3. In each controller that `require_once`d the file: add `use` statement, call typed class
4. Delete the include file
5. PHPStan level 9 after each file

---

## Phase 7 — Tier 3b: `include/*.inc.php` page-components → services/renderers

**One commit per component**

22 files in `include/` are page-component includes loaded at controller scope.
Each becomes either a typed renderer service or is merged into an existing service.

Priority order (simplest first):

| File | Becomes | Notes |
|---|---|---|
| `include/ws_core.inc.php` | merge into `PwgServer` | No file-top globals needed |
| `include/ws_init.inc.php` | merge into `PwgServer::boot()` | Same |
| `include/ws_functions/pwg.*.php` (6 files) | `Ws\*Endpoints` classes in `src/Piwigo/Ws/Method/` | Already partially done (#21) |
| `include/selected_tags.inc.php` | `src/Piwigo/Tag/SelectedTagsRenderer` | Small, no DB |
| `include/no_photo_yet.inc.php` | `src/Piwigo/Page/NoPhotoYetRenderer` | Already uses UrlGenerator |
| `include/category_cats.inc.php` | `src/Piwigo/Category/CategoryCatsRenderer` | Uses PersistentCache |
| `include/category_default.inc.php` | `src/Piwigo/Category/CategoryDefaultRenderer` | Same |
| `include/search_filters.inc.php` | `src/Piwigo/Search/SearchFilterRenderer` | Already uses ServiceLocator |
| `include/picture_comment.inc.php` | `src/Piwigo/Picture/PictureCommentRenderer` | Needs PicturePageContext |
| `include/picture_metadata.inc.php` | `src/Piwigo/Picture/PictureMetadataRenderer` | Same |
| `include/picture_rate.inc.php` | `src/Piwigo/Picture/PictureRateRenderer` | Same |
| `include/menubar.inc.php` | `src/Piwigo/Menu/MenubarRenderer` | Large; calls UrlGenerator already |
| `include/page_header.php` | integrated into `PageRenderer::renderHeader()` | After Phase 5 |
| `include/page_tail.php` | integrated into `PageRenderer::renderTail()` | After Phase 5 |

**Pattern per file:**
1. Read the file — identify its globals, dependencies, and what it outputs
2. Create typed renderer class; move logic into a `render(Context $ctx): void` method
3. Replace `require PHPWG_ROOT_PATH . 'include/file.inc.php'` at every call site
4. Delete the file
5. PHPStan level 9 after each file

---

## Key files

| File | Phase |
|---|---|
| `src/Piwigo/Admin/Updates.php:489` | 1a |
| `src/Piwigo/Users/CurrentUser.php:6–12` | 1b |
| `admin/site_reader_local.php` | 2 |
| `src/Piwigo/Site/LocalSiteReader.php` | 2 (new) |
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | 2 |
| `src/Piwigo/Controller/Admin/MaintenanceController.php` | 2 |
| `src/Piwigo/Template/Template.php:103–147` | 3 |
| `src/Piwigo/Core/Lang.php` | 3 (typed target for l10n) |
| `src/Piwigo/Users/CurrentUser.php` | 3 (typed target for is_admin) |
| `include/functions.inc.php` | 4, 5 |
| `include/functions_mail.inc.php` | 4 |
| `admin/include/functions_notification_by_mail.inc.php` | 4 |
| `src/Piwigo/Lang/LanguageStack.php` | 4 (new) |
| `src/Piwigo/Lang/LanguageLoader.php` | 4 (new) |
| `src/Piwigo/Page/PageRenderer.php` | 5 (new) |
| `include/page_header.php`, `include/page_tail.php` | 5 |
| `admin/include/*.inc.php` (6 files) | 6 |
| `include/*.inc.php` (14 files) | 7 |

## Verification (after each phase)

- `php vendor/bin/phpstan analyse --level=9 --memory-limit=512M` → 0 errors
- `npm run build` → 0 TS errors
- Manual browser check of the affected surface (gallery page, admin page, redirect)
