# Plan: Pending Modernization Work

> **Status (2026-05-07): all 7 phases below are complete.** This file is kept
> as a record of how each phase landed against its original design — useful when
> reviewing Phase 5's value-object decision and the small remaining seams in
> `PageTailRenderer`. Active modernization work has moved to:
>
> - `ROADMAP-PHP.md` — PHP / backend
> - `ROADMAP-TS.md` — TypeScript / frontend glue
> - `ROADMAP-CSS.md` — CSS / themes
> - `STRUCTURE-PLAN.md` — folder/web-root restructure (separate, in flight)

---

## Phase 1 — Trivial deferred fixes — **Done**

### 1a. `Updates.php` — wrong page name in redirect — **Done**

`src/Piwigo/Admin/Updates.php:541` now redirects via
`Util::get()->redirect(ServiceLocator::get(UrlGenerator::class)->admin('updates'))`.
The `basename(__DIR__)` call (which produced `page=plugin-Admin`) is gone.
A second updates redirect at line 278 was fixed at the same time.

### 1b. `CurrentUser.php` — stale "Wave A:" comment — **Done**

`src/Piwigo/Users/CurrentUser.php:7–13` describes the boot flow without dated
roadmap terminology: "Static accessor for the authenticated user singleton.
Kernel::boot() calls attachGlobals() once include/user.inc.php has fully
populated $GLOBALS['user']…"

---

## Phase 2 — Move `LocalSiteReader` to `src/` — **Done**

- `src/Piwigo/Site/LocalSiteReader.php` exists (namespaced, typed).
- `admin/site_reader_local.php` deleted.
- `BatchManagerController` and `MaintenanceController` import the class via `use`.

---

## Phase 3 — Template.php: wire Piwigo free functions to typed callables — **Done**

`src/Piwigo/Template/Template.php:90–156` now registers:

| Modifier              | Bound to                                                                    |
| --------------------- | --------------------------------------------------------------------------- |
| `l10n`                | `Lang::t(...)`                                                              |
| `is_admin`            | `fn (string $s = '') => PermissionService::get()->isAdmin($s)`              |
| `is_classic_user`     | `fn (string $s = '') => PermissionService::get()->isClassicUser($s)`        |
| `get_device`          | `fn () => Util::get()->getDevice()`                                         |
| `get_gallery_home_url`| `fn (...) => ServiceLocator::get(UrlGenerator::class)->gallery()`           |
| `url_is_remote`       | `UrlService::urlIsRemote(...)`                                              |

Built-in PHP functions (`sprintf`, `json_encode`, `trim`, `htmlspecialchars`, …)
remain as string callables — they were always native, no change needed.

The legacy procedural functions `l10n()`, `is_admin()`, `is_classic_user()`,
`get_device()`, `get_gallery_home_url()`, `url_is_remote()`, `switch_lang_to()`,
`switch_lang_back()`, and `redirect_html()` were ultimately **deleted**, not
just unbound from Smarty. The original plan said to leave them in place for
Tier 3; Tier 3 retired them.

---

## Phase 4 — Tier 2.1: LanguageStack service — **Done (different home)**

- Final class lives at `src/Piwigo/Core/LanguageStack.php` (not
  `src/Piwigo/Lang/LanguageStack.php` as originally planned). Lang-package files
  are `src/Piwigo/Lang/LangService.php` and `src/Piwigo/Lang/Translator.php`.
- The free functions `switch_lang_to()` / `switch_lang_back()` are gone — call
  sites use `LanguageStack::push()` / `pop()` directly.
- No `global $lang | $lang_info | $language_files | $switch_lang | $conf_mail`
  declarations remain in `src/`.
- `LanguageContext` value object was not introduced — the stack snapshots
  `$lang` / `$lang_info` arrays directly via `$GLOBALS` to preserve the
  reference bridge that `Lang::attachGlobals()` sets up. Pragmatic choice;
  documented in the class header.

---

## Phase 5 — Tier 2.2: PageRenderer — **Done (different shape)**

- `include/page_header.php` and `include/page_tail.php` are deleted (the whole
  `include/` directory is gone).
- Replaced by `src/Piwigo/Page/PageHeaderRenderer::render()` and
  `src/Piwigo/Page/PageTailRenderer::render()` — two static-method renderers,
  not a single `PageRenderer` class.
- `redirect_html()` is now `Util::redirectHtml()` at
  `src/Piwigo/Core/Util.php:124`. No `global` declarations.
- The proposed `HeaderContext` / `TailContext` value objects were **not**
  introduced. Per-page typed contexts under `src/Piwigo/Page/Context/`
  (`AdminPageContext`, `AlbumPageContext`, `PicturePageContext`, …) cover the
  controller side instead.

**Remaining seams (low priority):**
- `PageHeaderRenderer.php:25` reads `$GLOBALS['page']` and writes back at line 59.
- `PageTailRenderer.php:56,62` reads `$GLOBALS['debug']` and `$GLOBALS['t2']`.
- `NoPhotoYetRenderer.php:26` reads `$GLOBALS['user']`.

These are cheap to retire if the value-object design is later adopted, but they
are harmless given the reference-bridge model in `Kernel::boot()`.

---

## Phase 6 — Tier 3a: `admin/include/*.inc.php` → typed services — **Done**

| Original file                                       | Landed at                                          |
| --------------------------------------------------- | -------------------------------------------------- |
| `admin/include/albums_tab.inc.php`                  | `src/Piwigo/Admin/Album/AlbumsTabRenderer.php`     |
| `admin/include/batch_manager_filters.inc.php`       | `src/Piwigo/Admin/BatchManager/FilterResolver.php` |
| `admin/include/configuration_sizes_process.inc.php` | `src/Piwigo/Admin/Config/SizesProcessor.php`       |
| `admin/include/configuration_watermark_process.inc.php` | `src/Piwigo/Admin/Config/WatermarkProcessor.php` |
| `admin/include/photos_add_direct_prepare.inc.php`   | `src/Piwigo/Admin/Upload/DirectPreparer.php`       |
| `admin/include/user_tabs.inc.php`                   | `src/Piwigo/Admin/Users/UserTabRenderer.php`       |

The whole `admin/include/` directory is gone (and `admin/` itself has been
collapsed into `src/`).

---

## Phase 7 — Tier 3b: `include/*.inc.php` page-components → renderers — **Done**

| Original file                       | Landed at                                            |
| ----------------------------------- | ---------------------------------------------------- |
| `include/ws_core.inc.php`           | merged into `src/Piwigo/Ws/PwgServer.php`            |
| `include/ws_init.inc.php`           | merged into `PwgServer::boot()`                      |
| `include/ws_functions/pwg.*.php`    | `src/Piwigo/Ws/Method/{Categories,Comments,Extensions,General,Groups,Images,Permissions,Tags,Users}Endpoints.php` |
| `include/selected_tags.inc.php`     | `src/Piwigo/Tag/SelectedTagsRenderer.php`            |
| `include/no_photo_yet.inc.php`      | `src/Piwigo/Page/NoPhotoYetRenderer.php`             |
| `include/category_cats.inc.php`     | `src/Piwigo/Category/CategoryCatsRenderer.php`       |
| `include/category_default.inc.php`  | `src/Piwigo/Category/CategoryDefaultRenderer.php`    |
| `include/search_filters.inc.php`    | `src/Piwigo/Search/SearchFilterRenderer.php`         |
| `include/picture_comment.inc.php`   | `src/Piwigo/Picture/PictureCommentRenderer.php`      |
| `include/picture_metadata.inc.php`  | `src/Piwigo/Picture/PictureMetadataRenderer.php`     |
| `include/picture_rate.inc.php`      | `src/Piwigo/Picture/PictureRateRenderer.php`         |
| `include/menubar.inc.php`           | `src/Piwigo/Menu/MenubarRenderer.php`                |
| `include/page_header.php`           | `src/Piwigo/Page/PageHeaderRenderer.php` (Phase 5)   |
| `include/page_tail.php`             | `src/Piwigo/Page/PageTailRenderer.php` (Phase 5)     |

The whole `include/` directory is gone.

---

## Verification baseline (still applies for any future phase)

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10
- `npm run build` → 0 TS errors
- `vendor/bin/phpunit --testsuite Unit`
- Manual browser check of the affected surface (gallery page, admin page, redirect)
