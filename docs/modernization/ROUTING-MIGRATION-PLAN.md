# Routing Migration Plan

Executes every action item in `ROUTING-AUDIT.md` in dependency order.

---

## Phase 1 — Dead code cleanup

### 1a. Delete `random.php`
Route `/random` → `GalleryController` already exists in `config/routes.php`. The root-level file is dead code.

### 1b. Fix `qsearch.php` redirect
Currently redirects to `search.php` (gone). Fix to use `UrlGenerator::searchPage()` + `?q=` param.

---

## Phase 2 — New public controllers

### 2a. `AboutController` — `/about` (GET)
- New: `src/Piwigo/Controller/AboutController.php`
- Lift logic from `about.php` (47 LOC): check_status, load_language about.html, trigger hooks, render about.tpl
- Add `UrlGenerator::about()`
- Delete `about.php`

### 2b. `NbmController` — `/nbm` (GET+POST)
- New: `src/Piwigo/Controller/NbmController.php`
- Lift logic from `nbm.php` (59 LOC): check_status(ACCESS_FREE), load admin functions, token validation, subscribe/unsubscribe, render nbm.tpl
- Add `UrlGenerator::nbm()`
- Update `src/Piwigo/Admin/Notification/NotificationAdminService.php` lines 151–152: `get_gallery_home_url() . '/nbm.php'` → `UrlGenerator::nbm()`
- Delete `nbm.php`

### 2c. `PopuphelpController` — `/popuphelp` (GET)
- New: `src/Piwigo/Controller/PopuphelpController.php`
- Lift logic from `popuphelp.php` (64 LOC): check_status, define PWG_HELP, validate `?page=`, load_language help HTML, trigger_change, render popuphelp.tpl
- Add `UrlGenerator::popuphelp(string $page)`
- Delete root-level `popuphelp.php`

---

## Phase 3 — `action.php` as permanent shim

`action.php` is a 223-line binary file-server (permission checks, HTTP headers, caching, readfile). Keep as permanent shim like `i.php` — kernel overhead would defeat its purpose.

- Add `UrlGenerator::actionDownload(int $id, string $part, string $pwgToken): string`
- Add `UrlGenerator::actionFormat(int $formatId): string`
- Update `PhotoController.php` (2 occurrences): hardcoded `'action.php?id=...'` → UrlGenerator calls
- Update `BatchManagerController.php` (1 occurrence): same
- Update `ROUTING-AUDIT.md`: move `action.php` to section 2a "intentional non-kernel files"

---

## Phase 4 — Admin popuphelp routing

The 10 hardcoded `U_HELP` strings use `admin/popuphelp.php?page=xxx`. The `?page=` param conflicts with the admin dispatcher's own routing param.

**Fix:** rename the help-page param from `page` to `help`:
- URL becomes `index.php?/admin?page=popuphelp&help=cat_options`
- `MiscController::popupHelp()` already handles dispatch; change `$_GET['page']` → `$_GET['help']`
- Add `UrlGenerator::adminPopupHelp(string $helpPage): string`
- Update all 10 `U_HELP` assignments (AlbumController ×2, ConfigurationController, ExtensionsController, MaintenanceController ×4, MiscController ×2)
- Delete `admin/popuphelp.php`

---

## Phase 5 — Wave-B: inline admin/*.php into typed controllers

63 procedural `admin/*.php` files remain. Each typed sub-controller still `require`s them. Migrate in ascending complexity order:

| Order | Controller | Est. LOC to inline |
|-------|-----------|---------------------|
| 1 | `GroupsController` | ~100 |
| 2 | `ConfigurationController` | ~50 |
| 3 | `UsersController` | ~200 |
| 4 | `MiscController` | ~400 |
| 5 | `ExtensionsController` | ~600 |
| 6 | `AlbumController` | ~700 |
| 7 | `BatchManagerController` | ~800 |
| 8 | `PhotoController` | ~800 |
| 9 | `MaintenanceController` | ~1000 |

Per controller: inline page logic into typed methods, delete the procedural file, run PHPStan level 9.

---

## Phase 6 — FallbackHandler removal (blocked on Phase 5)

Once every route has a working controller:
1. Delete `src/Piwigo/Http/Middleware/FallbackHandler.php`
2. Update `ControllerInvokerMiddleware`: remove NOT_FOUND delegation, throw proper 404 instead
3. Update `Kernel.php`: remove FallbackHandler from pipeline, remove migration scaffolding comments
4. Update `ROUTING-AUDIT.md` section 5

---

## Key files

| File | Change |
|---|---|
| `config/routes.php` | Add `/about`, `/nbm`, `/popuphelp` routes |
| `src/Piwigo/Url/UrlGenerator.php` | Add `about()`, `nbm()`, `popuphelp()`, `adminPopupHelp()`, `actionDownload()`, `actionFormat()` |
| `src/Piwigo/Controller/AboutController.php` | New |
| `src/Piwigo/Controller/NbmController.php` | New |
| `src/Piwigo/Controller/PopuphelpController.php` | New |
| `src/Piwigo/Controller/Admin/MiscController.php` | Phase 4: `$_GET['page']` → `$_GET['help']` in `popupHelp()` |
| `src/Piwigo/Controller/Admin/PhotoController.php` | Phase 3: UrlGenerator action methods |
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | Phase 3: UrlGenerator action methods |
| `src/Piwigo/Admin/Notification/NotificationAdminService.php` | Phase 2b: nbm URL |
| `src/Piwigo/Http/Middleware/FallbackHandler.php` | Phase 6: delete |
| `src/Piwigo/Http/Middleware/ControllerInvokerMiddleware.php` | Phase 6: remove fallback |
| Ref: `src/Piwigo/Controller/NotificationController.php` | Pattern for new public controllers |
