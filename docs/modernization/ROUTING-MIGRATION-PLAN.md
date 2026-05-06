# Routing Migration — Completed

All six phases from the original plan have landed. See `ROUTING-AUDIT.md` in the repo root for the current architecture snapshot.

## What was done

| Phase | Summary | Key commits |
|-------|---------|-------------|
| 1 | Dead code: deleted `random.php`; fixed `qsearch.php` redirect to `UrlGenerator::searchPage()` | `chore: delete random.php...` |
| 2 | New kernel-routed controllers: `AboutController` (`/about`), `NbmController` (`/nbm`), `PopuphelpController` (`/popuphelp`); deleted source files; updated email links | `feat: route about/nbm/popuphelp...` |
| 3 | `action.php` classified as permanent shim (like `i.php`); added `UrlGenerator::actionDownload()` / `actionFormat()`; removed hardcoded strings from `PhotoController`, `BatchManagerController`, `PictureController` | same commit |
| 4 | Admin popuphelp routed through kernel (`?page=popuphelp&help=xxx`); 10 `U_HELP` assignments migrated to `UrlGenerator::adminPopupHelp()`; `admin/popuphelp.php` deleted | same commit |
| 5 | Wave-B: all 63 `admin/*.php` page-body files deleted; logic already inlined in typed controllers; dead `else require` fallback branches removed | `refactor: Wave-B complete...` |
| 6 | `FallbackHandler` deleted; `ControllerInvokerMiddleware` returns 404 directly; pipeline terminal is an unreachable `LogicException` guard | `refactor: remove FallbackHandler...` |

## Permanent non-kernel files

| File | Reason |
|------|--------|
| `index.php` | Kernel entry point |
| `i.php` | Image derivative server — minimal bootstrap, no full stack |
| `action.php` | Binary file server — `readfile()` with direct HTTP header control |
| `qsearch.php` | Thin redirect shim; redirects `?q=` to `/search` |
| `install.php` | Pre-DB bootstrap |
| `upgrade.php` | Mid-migration bootstrap |
| `upgrade_feed.php` | Feed-based DB upgrade runner, gated by config |
| `admin/site_reader_local.php` | Shared sync logic, `require`d by two sub-controllers |
| `admin/include/*.php` | Shared helper includes |
