# Routing Migration Audit

This document captures the state of the 16.x rewrite routing migration. The migration is **complete**: all entry-point files have been converted to kernel-routed controllers or classified as permanent non-kernel shims, and all procedural `admin/*.php` page bodies have been inlined into typed sub-controllers.

---

## 1. Route Table (Kernel-Routed)

All routes defined in `config/routes.php`. Every request matching these paths goes through `index.php` → `Kernel::handle()` → middleware pipeline → controller.

| Route name        | Path                        | Controller                          |
|-------------------|-----------------------------|-------------------------------------|
| `gallery`         | `/`                         | `GalleryController`                 |
| `gallery_cat`     | `/category/{rest}`          | `GalleryController`                 |
| `gallery_pic`     | `/picture/{rest}`           | `PictureController`                 |
| `tags`            | `/tags`                     | `TagsController`                    |
| `gallery_tags`    | `/tags/{rest}`              | `TagsController`                    |
| `search`              | `/search`                   | `SearchController`                  |
| `gallery_search`      | `/search/{id}`              | `GalleryController`                 |
| `gallery_search_paged`| `/search/{id}/{rest}`       | `GalleryController`                 |
| `favorites`       | `/favorites`                | `GalleryController`                 |
| `recent_pics`     | `/recent`                   | `GalleryController`                 |
| `best_rated`      | `/best-rated`               | `GalleryController`                 |
| `most_visited`    | `/most-visited`             | `GalleryController`                 |
| `recent_cats`     | `/recent-albums`            | `GalleryController`                 |
| `random`          | `/random`                   | `GalleryController`                 |
| `identification`  | `/identification`           | `IdentificationController`          |
| `register`        | `/register`                 | `RegisterController`                |
| `password`        | `/password`                 | `PasswordController`                |
| `profile`         | `/profile`                  | `ProfileController`                 |
| `comments`        | `/comments`                 | `CommentsController`                |
| `notification`    | `/notification`             | `NotificationController`            |
| `feed`            | `/feed`                     | `FeedController`                    |
| `image`           | `/i/{rest}`                 | `ImageDerivativeController`         |
| `ws`              | `/ws{rest}`                 | `WsController`                      |
| `admin`           | `/admin{rest}`              | `AdminController`                   |
| `about`           | `/about`                    | `AboutController`                   |
| `nbm`             | `/nbm`                      | `NbmController`                     |
| `popuphelp`       | `/popuphelp`                | `PopuphelpController`               |
| `install`         | `/install`                  | `InstallController`                 |
| `upgrade`         | `/upgrade`                  | `UpgradeController`                 |

URL mode: with `php_extension_in_urls=true` and `question_mark_in_urls=true` (defaults), the above paths are reached as `index.php?/routename`.

---

## 2. Root-Level Legacy Entry Points

Files at `/` that Apache serves directly as PHP scripts, **bypassing the kernel**:

### 2a. Intentional non-kernel files (keep as-is)

| File | Reason |
|------|--------|
| `index.php` | **The kernel entry point** — routes all requests through `Kernel::handle()` |
| `index.php?/install` | Custom bootstrap — no DB exists yet, must not load `common.inc.php` |
| `index.php?/upgrade` | Custom bootstrap — DB schema may be mid-migration |
| `i.php` | **Performance shim** — image derivative server; deliberately avoids the full Piwigo stack to minimize per-image overhead. Calls `ImageDerivativeController` directly after only `ConfigLoader`. |
| `migrations.php` | Doctrine Migrations CLI config — not a request handler |
| `rector.php` | Rector static analysis config — not a request handler |
| `upgrade_feed.php` | Feed-based DB upgrade runner; gated by `Config::checkUpgradeFeed()`; custom bootstrap, not kernel-routed |
| `action.php` | **Performance shim** — binary file server (downloads, format variants); 223-line handler with direct HTTP header control and `readfile()`; kernel overhead would defeat its purpose. `UrlGenerator::actionDownload()` / `actionFormat()` generate the correct URLs. |

### 2b. Legacy PHP entry points — not yet routed

These files are served directly by Apache and have no kernel route. They each define `PHPWG_ROOT_PATH` and call `include/common.inc.php` (or a subset of it).

| File | What it does | Notes |
|------|-------------|-------|
| `qsearch.php` | Quick-search redirect: reads `?q=` and redirects to `/search` routed URL | Kept as shim; internal redirect now uses `UrlGenerator::searchPage()` |

*(All other unrouted entry points have been migrated: `about.php` → `/about`, `nbm.php` → `/nbm`, `popuphelp.php` → `/popuphelp`, `random.php` deleted, `check_admin.php` deleted.)*

---

## 3. The `admin/` Directory

Wave-B is **complete**. All 63 procedural `admin/<page>.php` page-body files have been deleted; their logic is inlined into typed private methods of the 10 sub-controllers.

### Architecture

```
HTTP request → index.php → Kernel → AdminController
    → dispatches to typed sub-controllers
    → each method contains the page logic directly — no require of admin/*.php
```

### Controllers in `src/Piwigo/Controller/Admin/`

| Controller | Pages handled |
|---|---|
| `AdminController` | Dispatcher; renders admin shell template |
| `AlbumController` | album, albums, album_notification, cat_list, cat_modify, cat_options, cat_perm, element_set_ranks |
| `BatchManagerController` | batch_manager, batch_manager_global, batch_manager_unit, queue |
| `ConfigurationController` | configuration |
| `ExtensionsController` | plugins, plugins_installed, plugins_new, plugin, themes, themes_installed, themes_new, themes_standard_pages, theme, languages, languages_installed, languages_new, updates, updates_ext, updates_pwg, extend_for_templates |
| `GroupsController` | group_list, group_perm |
| `MaintenanceController` | maintenance, maintenance_actions, maintenance_env, maintenance_sys, stats, history, site_manager, site_reader_local, site_update |
| `MiscController` | comments, menubar, notification_by_mail, permalinks, popuphelp, rating, rating_user, tags, profile, help, intro |
| `PhotoController` | photo, picture_modify, picture_coi, picture_formats, photos_add, photos_add_direct, photos_add_ftp, photos_add_applications |
| `UsersController` | user_list, user_perm, user_activity |

### What remains in `admin/`

| File | Status |
|---|---|
| `admin/site_reader_local.php` | Still `require`d by `BatchManagerController` and `MaintenanceController`; contains the local site-reader sync logic |
| `admin/include/*.php` | Shared helper includes (`functions.php`, `functions_notification_by_mail.inc.php`, etc.) loaded via `require_once` throughout the sub-controllers |

---

## 4. Remaining Hardcoded `.php` URL References

After the mass URL cleanup (replacing `admin.php`, `ws.php`, `identification.php`, etc. across ~120 files), two categories remain:

### 4a. `action.php` — download links

`action.php` is a permanent lightweight shim (like `i.php`). URL generation is centralised:
- `UrlGenerator::actionDownload(int $id, string $part, string $pwgToken): string`
- `UrlGenerator::actionFormat(int $formatId): string`

All former hardcoded `'action.php?...'` strings in `PhotoController`, `BatchManagerController`, and `PictureController` now use these methods.

### 4b. `admin/popuphelp.php` — help button links

All 10 `U_HELP` assignments now use `UrlGenerator::adminPopupHelp(string $helpPage)` which routes through the kernel (`?page=popuphelp&help=xxx`). The `help=` param avoids collision with the admin dispatcher's own `page=` routing param. `MiscController::popupHelp()` was updated to read `$_GET['help']`. `admin/popuphelp.php` has been deleted.

---

## 5. Routing Shims and Migration Scaffolding

`FallbackHandler` has been deleted. `ControllerInvokerMiddleware` now returns a 404 response directly for unmatched routes; the pipeline terminal handler is an unreachable `\LogicException` guard.

### Remaining DEFERRED items in source

| Location | Issue |
|---|---|
| `src/Piwigo/Admin/Updates.php:486` | `redirect()` to `page=plugin-Admin` — leftover from when the updater was a plugin; wrong page name |
| `src/Piwigo/Users/CurrentUser.php:10` | "Wave A: Kernel::boot() calls attachGlobals()" |

---

## 6. Summary: What Needs Routing Work

| Item | Status | Notes |
|---|---|---|
| `check_admin.php` — dev debug script | ✅ Done | Deleted |
| `random.php` — dead code | ✅ Done | Deleted |
| `qsearch.php` — fix redirect | ✅ Done | Now targets `UrlGenerator::searchPage()` |
| `action.php` — permanent shim decision | ✅ Done | Stays as shim; `UrlGenerator::actionDownload/Format()` added |
| `about.php` → `/about` route | ✅ Done | `AboutController` + route added |
| `nbm.php` → `/nbm` route | ✅ Done | `NbmController` + route added; email links updated |
| `popuphelp.php` → `/popuphelp` route | ✅ Done | `PopuphelpController` + route added |
| `admin/popuphelp.php` help links → kernel-routed | ✅ Done | 10 `U_HELP` assignments use `adminPopupHelp()`; file deleted |
| `admin/*.php` page bodies → typed controllers | ✅ Done | 61 files deleted; all logic inlined |
| Remove `FallbackHandler` | ✅ Done | Deleted; `ControllerInvokerMiddleware` returns 404 directly |
