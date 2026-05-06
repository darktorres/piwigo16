# Routing Migration Audit

This document captures the state of the 16.x rewrite routing migration as of the current branch. It answers three questions: what legacy entry points remain, what routing shims still exist, and why `admin/` has not been folded into `src/`.

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
| `install.php` | Custom bootstrap — no DB exists yet, must not load `common.inc.php` |
| `upgrade.php` | Custom bootstrap — DB schema may be mid-migration |
| `i.php` | **Performance shim** — image derivative server; deliberately avoids the full Piwigo stack to minimize per-image overhead. Calls `ImageDerivativeController` directly after only `ConfigLoader`. |
| `migrations.php` | Doctrine Migrations CLI config — not a request handler |
| `rector.php` | Rector static analysis config — not a request handler |
| `upgrade_feed.php` | Feed-based DB upgrade runner; gated by `Config::checkUpgradeFeed()`; custom bootstrap, not kernel-routed |

### 2b. Legacy PHP entry points — not yet routed

These files are served directly by Apache and have no kernel route. They each define `PHPWG_ROOT_PATH` and call `include/common.inc.php` (or a subset of it).

| File | What it does | Notes |
|------|-------------|-------|
| `action.php` | Serves image file downloads (part=e/r/f) and format downloads | Referenced by download links in PhotoController, BatchManagerController |
| `qsearch.php` | Quick-search redirect: reads `?q=` and redirects to search results | Redirects to legacy `search.php` (broken — should target `/search`) |
| `about.php` | Renders the gallery "About" page via Smarty | Not in routes.php; no controller |
| `random.php` | Generates a random list of images and redirects to a gallery URL | Route `random` now exists in routes.php — this file is dead code |
| `nbm.php` | Notification-by-mail subscribe/unsubscribe handler | Used in email links; not routed |
| `popuphelp.php` | Gallery-side help popup | Also exists as `admin/popuphelp.php`; neither is routed |
| `check_admin.php` | **Dev debug script** — dumps admin user's password hash and tests two hardcoded passwords | **Delete immediately** — exposes credential material; was never a real entry point |

**Action items:**
- `check_admin.php` → delete immediately (exposes password hashes; dev artifact)
- `action.php` → add `/action` route + `ActionController`, update download URL generation in `PhotoController` and `BatchManagerController`
- `qsearch.php` → fix redirect target from `search.php` to the routed search URL; or route `/qsearch` directly
- `about.php` → add `/about` route + `AboutController`
- `random.php` → route already exists (`/random`); this file is now dead code — remove it
- `nbm.php` → add `/nbm` route + `NbmController`

---

## 3. The `admin/` Directory — Why It Exists Alongside `src/`

The admin pages are being migrated to `src/Piwigo/Controller/Admin/` in what the codebase calls **Wave B**. The migration is **partially complete**: typed sub-controllers exist, but the page bodies remain as procedural `admin/*.php` includes.

### Architecture

```
HTTP request → index.php → Kernel → AdminController
    → dispatches to: AlbumController | BatchManagerController | PhotoController
                     ExtensionsController | GroupsController | MiscController
                     MaintenanceController | ConfigurationController | UsersController
    → each sub-controller calls: require PHPWG_ROOT_PATH . 'admin/<page>.php'
```

### What is in `src/Piwigo/Controller/Admin/`

| Controller | Pages handled |
|---|---|
| `AdminController` | Dispatcher; renders admin shell template |
| `AlbumController` | album, albums, album_notification, cat_list, cat_modify, cat_options, cat_perm, element_set_ranks |
| `BatchManagerController` | batch_manager, batch_manager_global, batch_manager_unit, queue |
| `ConfigurationController` | configuration |
| `ExtensionsController` | plugins, plugins_installed, plugins_new, themes, themes_installed, themes_new, themes_standard_pages, languages, languages_installed, languages_new, updates, updates_ext, updates_pwg, extend_for_templates |
| `GroupsController` | group_list, group_perm |
| `MaintenanceController` | maintenance, maintenance_actions, maintenance_env, maintenance_sys, stats, history, site_manager, site_update |
| `MiscController` | comments, menubar, notification_by_mail, permalinks, rating, rating_user, tags, profile |
| `PhotoController` | photo, picture_modify, picture_coi, picture_formats, photos_add, photos_add_direct, photos_add_ftp, photos_add_applications |
| `UsersController` | user_list, user_perm, user_activity |

### What remains in `admin/*.php`

~60 procedural page scripts. They are **not independent entry points** — each file begins with:

```php
defined('PHPWG_ROOT_PATH') or throw new AuthException('Hacking attempt!');
global $template, $user, $page, $persistent_cache, $lang;
```

They are loaded via `require PHPWG_ROOT_PATH . 'admin/<page>.php'` from within typed sub-controller methods. The page logic still lives procedurally; only routing, dispatch, and URL generation have been lifted into the typed controllers.

**Why not moved to `src/` yet:** Each `admin/*.php` file does a full Smarty-based page render with complex procedural logic (queries, conditionals, template assignments). Migrating them fully to typed controller methods requires converting every `$template->assign(...)` call and removing all reliance on `$GLOBALS`. This is ongoing Wave-B work.

---

## 4. Remaining Hardcoded `.php` URL References

After the mass URL cleanup (replacing `admin.php`, `ws.php`, `identification.php`, etc. across ~120 files), two categories remain:

### 4a. `action.php` — download links

Three locations still generate `action.php?id=...` URLs because `action.php` has no kernel route yet:

| File | Usage |
|------|-------|
| `src/Piwigo/Controller/Admin/PhotoController.php` | `'U_DOWNLOAD' => 'action.php?id=...'` (photo download) |
| `src/Piwigo/Controller/Admin/PhotoController.php` | `'download_url' => 'action.php?format=...'` (format download) |
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | `'U_DOWNLOAD' => 'action.php?id=...'` (batch download) |

**Fix:** Add `UrlGenerator::action()` + `/action` route, or treat `action.php` as a permanent lightweight shim like `i.php`.

### 4b. `admin/popuphelp.php` — help button links

10 locations across admin sub-controllers assign `U_HELP` pointing to `admin/popuphelp.php`. The file exists on disk and works; these links are not broken.

| Controller | Page |
|---|---|
| `AlbumController` | cat_options, cat_perm |
| `ConfigurationController` | configuration |
| `ExtensionsController` | extend_for_templates |
| `MaintenanceController` | maintenance (×2), history, synchronize |
| `MiscController` | notification_by_mail, permalinks |

Low priority — not broken, just not routed.

---

## 5. Routing Shims and Migration Scaffolding

### `FallbackHandler`
`src/Piwigo/Http/Middleware/FallbackHandler.php`

Returns a bare `<h1>404 Not Found</h1>` for any request the router cannot match. **Temporary scaffolding** for the Wave-A/B migration — prevents crashes when a route is defined but the controller class doesn't exist yet. Remove when all routes have working controllers.

### `ControllerInvokerMiddleware`
`src/Piwigo/Http/Middleware/ControllerInvokerMiddleware.php`

If the route result is NOT_FOUND or the controller class doesn't exist, delegates to `FallbackHandler`. Explicitly marked as migration scaffolding in the source comment.

### Wave-A/B DEFERRED items in source

| Location | Issue |
|---|---|
| `src/Piwigo/Admin/Updates.php:486` | `redirect()` to `page=plugin-Admin` — leftover from when the updater was a plugin; wrong page name |
| `src/Piwigo/Core/Kernel.php:77` | Comment notes FallbackHandler is temporary migration scaffolding |
| `src/Piwigo/Users/CurrentUser.php:10` | "Wave A: Kernel::boot() calls attachGlobals()" |

---

## 6. Summary: What Needs Routing Work

| Item | Priority | Effort |
|---|---|---|
| `check_admin.php` — dev debug script, exposes password hashes | **Critical** | Delete file |
| `random.php` — route exists, file is dead code | Low | Delete file |
| `qsearch.php` — fix redirect target to `/search` | Low | 1 line |
| `action.php` — add route or promote to permanent shim | Medium | New controller or shim decision |
| `about.php` — add `/about` route | Low | New controller |
| `nbm.php` — add `/nbm` route | Low | New controller |
| `admin/popuphelp.php` help links — add `/popuphelp` route | Low | New controller |
| `admin/*.php` page bodies → typed controller methods | High (ongoing) | Wave-B continuation |
| Remove `FallbackHandler` once all routes have controllers | Low | Delete + cleanup |
