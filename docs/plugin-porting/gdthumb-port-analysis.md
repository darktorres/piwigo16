# gdThumb → piwigo17-rewrite: Port Analysis

Source: `/home/torres/piwigo16-plugins/GDThumb_1.0.27` (v1.0.27, author Serguei Dosyukov, `http://blog.dragonsoft.us/`, no SPDX/license marker anywhere in the source — `changelog.txt` carries GPL-2.0-or-later boilerplate text, not a machine-readable declaration)

Target: `/home/torres/piwigo17-rewrite`. Per `../piwigo16-plugins/CLAUDE.md`'s established porting convention, the port itself lands in a new sibling directory in *this* repo — `/home/torres/piwigo16-plugins/GDThumb_17.0.0/` — never inside `piwigo17-rewrite/plugins/` directly (that directory is a runtime install target, populated by the PEM-mirror fetch/extract flow, not the plugin's development location).

Prior audit: `PLUGIN_PORTING_AUDIT.md` row `|GDThumb_1.0.27|class-php|yes|-|risky*|-|-|-|-|-|` — `Admin: risky*` ("specifically uses `global $template`, which resolves to `null` on this fork and fatals immediately on `->assign()`") independently corroborates §4's admin-page finding below, found here from the real source, not just the audit's static grep. The audit's `Live-SQL` column reads `-` (no `pwg_query()` in `main.inc.php`) but the audit only scans each plugin's *root* file — `admin.php`'s own two raw-SQL features (§4) are exactly the kind of gap `CLAUDE.md` warns "read every `-` in `PLUGIN_PORTING_AUDIT.md` as 'no hit in the root file', not 'verified clean'" about. Not one of the three named P29.6 remaining targets (`AdminTools`/`LocalFilesEditor`/`TakeATour`) — this is ad-hoc scope, same footing as `rv_tscroller`'s own port analysis in this directory.

## 1. Executive summary

gdThumb replaces the index/category thumbnail grid with a masonry-style variable-height layout, plus an enlarged "hero" first thumbnail and configurable per-thumbnail caption modes. Legacy implements the masonry/hero features entirely client-side (`js/gdthumb.js`, DOM measurement + CSS positioning against a server-rendered `<li>` grid) — the plugin's Smarty `set_filename()` template swap exists only to add a few CSS classes, rearrange one caption `<span>`, and load the plugin's own assets, none of which need different server-rendered markup once redesigned against this fork's own conventions.

**Two design passes were wrong before landing on the validated architecture below:**

1. **First pass:** assumed the masonry feature needs a new core template-override capability (a generic event/registry letting a plugin substitute an entirely different `View`/`.latte` file for `thumbnails.latte`), because legacy's own mechanism is a wholesale Smarty template swap. **Wrong** — this was porting the legacy *mechanism* 1:1, not asking what the *feature* needs against this fork's typed-`View`/Latte architecture. No such capability was ever built (checked directly: zero `TemplateHook`/registry-shaped extension point exists anywhere in `src/Piwigo/`), and building one would have been solving the wrong problem.
2. **Second pass:** redesigned it as a pure client-side (CSS/TS) enhancement layer over the *existing* core-rendered markup — correct in outline, but an adversarial 7-agent validation workflow found several of its concrete mechanism claims were themselves wrong, re-derived from real code rather than trusted at face value:
   - **CSS composition claim was wrong.** The plan cited page-specific stylesheets (`themes/default/css/pages/thumbnails.css`) as "the real layout stylesheet"; the actual always-loaded base layout is `themes/<theme>/theme.css` (order `-10`, every page): `.thumbnails LI { display: inline }` (silently no-ops width/height/float/position on `<li>` until overridden), `.thumbnails .wrap1 { display: inline-block; margin: 0 7px 5px 7px }`, `.thumbnailCategories LI { float: left; width: 49.7% }`, and `.thumbnails SPAN.thumbLegend { display: block; height: 4em }` at CSS specificity `(0,2,1)` — a same-shape `.thumbLegend.<mode>` modifier class is only `(0,2,0)` and **loses** that cascade battle regardless of load order.
   - **Asset-ordering claim was inverted.** `build/collectScriptEntries.ts` only scans `<root>/src` for Vite entries, so a plugin's own `.ts` can never become a Vite module entry and always renders as a classic, synchronous `<script src>` — while core's own bundled scripts (`thumbnails.loader.ts`) render as deferred `type="module"`. A classic script runs at its HTML parse position, *before* any deferred module script anywhere in the document, regardless of `AssetContribution::script(..., dependsOn: [...])`'s tag-order guarantee. The plugin's masonry module must self-synchronize (`DOMContentLoaded`/`load`/`img.complete`), never assume script-tag order implies execution order.
   - **Category-grid derivative-size claim was backwards.** The plan argued CSS-upscaling a small core-rendered derivative "mirrors what legacy's own `GDThumb.resize()` already does" — legacy actually never did this: `main.inc.php`'s `GDThumb_process_thumb()`/`GDThumb_process_category()` both always requested an exactly-sized custom derivative via `ImageStdParams::get_custom()` for *both* grids; `gdthumb.js`'s `resize()` only fine-tunes an already-correctly-sized image. Core's real category-grid derivative is a hard, unconditional 144×144 fit-within cap (`CategoryCatsRenderer.php`, no event wraps it, unlike the image grid's `GetIndexDerivativeParams`) — smaller than gdThumb's own *default* config height of 200, an unconditional ~39%+ forced CSS upscale at defaults, before even considering the plugin's admin-configurable range up to 999.
   - **Plugin manifest was materially incomplete** and the media-type-icon feature's "compute client-side from the rendered URL" claim doesn't hold (the rendered `<img src>`/`<a href>` don't reliably carry the source file's real extension for non-image types) — both fixed in §4 below.

**Validated architecture:** everything ports as a plugin-owned client-side enhancement layer plus two small, explicitly-decided, precedented core changes (§3) — no generic template-override capability, no registry, no `src/Piwigo/` rendering-pipeline redesign.

---

## 2. Clean ports

Verified against real dispatch sites and constructors, not docs.

| Legacy | Target | Note |
|---|---|---|
| `add_event_handler('init', 'GDThumb_init')` | `Piwigo\Bootstrap\Event\Init` (notify) | Zero-payload marker — `init`'s real legacy effect (force `$user['nb_image_page']`) has no achievable equivalent; see §5, not a clean port for the *effect*, only the hook name |
| `add_event_handler('loc_begin_index', 'GDThumb_index', 60)` | `Piwigo\Controller\Event\IndexRendering` (notify) | |
| `add_event_handler('loc_end_index_thumbnails', 'GDThumb_process_thumb', 50, 2)` | `Piwigo\Category\Event\IndexThumbnailsRendered` (filter, mutable `tplThumbnailsVar: list<ImageThumbnail>`) | Dispatched `CategoryDefaultRenderer.php:232`. `ImageThumbnail::$srcImage` is non-nullable — direct 1:1 for legacy's `$tpl_vars[0]['src_image']` |
| `add_event_handler('loc_end_index_category_thumbnails', 'GDThumb_process_category', 50, 2)` | `Piwigo\Category\Event\IndexCategoryThumbnailsRendered` (filter, mutable `tplThumbnailsVar: list<CategoryThumbnail>`) | Dispatched `CategoryCatsRenderer.php:461`. `CategoryThumbnail::$representative` is a **nullable** `?SrcImage` under a different name than the image-grid event — not shape-compatible with the row above, needs its own null-guarded access path (an album can have no representative image) |
| `add_event_handler('get_admin_plugin_menu_links', 'GDThumb_admin_menu')` | `Piwigo\Admin\Event\GetAdminPluginMenuLinks` (filter, mutable `value: array`) | |
| `add_event_handler('loc_end_index', 'GDThumb_remove_thumb_size')` | `Piwigo\Controller\Event\IndexRendered` (notify) | Legacy handler body (`$template->clear_assign('image_derivatives')`) is a raw-Smarty-object mutation with no equivalent need — `Template::assign()`/its state aren't reachable from a plugin at all (private by design, see `../piwigo16-plugins/CLAUDE.md`); this handler can simply be dropped, its effect was Smarty-internal cleanup with no analog to replicate |
| `conf_update_param('gdThumb', $params)` / `unserialize($conf['gdThumb'])` | `ExtensionContext::getSetting()`/`setSetting()`/`deleteSetting()` | Confirmed JSON-backed (`ConfigService::confGetParam()`/`confUpdateParam()` call `json_encode()`/`json_decode()`, no `serialize()` anywhere) — gdThumb's flat scalar/bool/string config array round-trips losslessly under one `'gdthumb'` key |
| `maintain.class.php`'s `GDThumb_maintain` (install/update/activate/uninstall) | `ExtensionInterface::install()`/`activate()`/`deactivate()`/`uninstall()`/`update(string, string)` | `PluginRegistry` calls `$instance->boot($context)` immediately before every one of the 5 lifecycle hooks — `boot()` can cache `$context` for use in all of them. Legacy's `cleanUp()` (deletes `PWG_LOCAL_DIR . 'GDThumb'`) is dead code — nothing in this plugin version ever writes there; drop it, don't port it |
| `check_pwg_token()` | `ExtensionContext::checkCsrfOrFail()` | Exact same bare-statement call shape |
| `{combine_css}`/`{combine_script}` | `Piwigo\Asset\Event\GetPageAssets` via `subscribedEvents()`, contributions via `AssetContribution::css()`/`::script()`/`::inlineScript()` | Confirmed real and dispatched live (`Template::dispatchPageAssetsOnce()`) — one narrow exception: `Page\NoPhotoYetRenderer` swaps in a fresh `Template` mid-request, firing `GetPageAssets` twice for that one page path; harmless, `PageAssets::add()` merges idempotently by id |
| Custom per-grid derivative size (`ImageStdParams::get_custom()`), image-grid half only | `Piwigo\Image\Event\GetIndexDerivativeParams` (filter, mutable `params: DerivativeParams`) | Dispatched `CategoryDefaultRenderer.php:234`. **No equivalent exists for the category grid** — see §3 |
| Arbitrary data → client JS bridge (legacy: Smarty template variable assign) | `ExtensionContext::template()->exposeData($key, $value)` | Confirmed: thin, real, public delegate to `PageState::exposeData()`; surfaces as `<script type="application/json" id="page-data">` (emitted by every current theme's `layout.latte`), read client-side via `pwg_getPageData()` in `page-data.ts`. Confirmed safely callable from both `IndexThumbnailsRendered` and `IndexCategoryThumbnailsRendered` handlers — both dispatch strictly after `RequestBootstrap::finalize()` (last of 7 `BOOTSTRAP_MIDDLEWARE` entries, always before `ControllerInvokerMiddleware` invokes `GalleryController`), so `ExtensionContext::template()` never throws there |
| `data-image-id`/(new, gdThumb needs) per-category identifier | `themes/default/template/thumbnails.latte:18` `<li data-image-id="{$thumbnail->id}">`, `themes/default/template/mainpage_categories.latte:13-16` `<li data-category-id="{$cat->id}">` | Both confirmed present, both the only real grid-loop renderers of these two Views anywhere in `themes/` (no other theme renders a competing shape) |
| `jquery.ba-resize.min.js` (custom resize-event polyfill) | Native `ResizeObserver` | Confirmed already the house pattern (`themes/default/js/vendor/lineChart.ts`) — drop the legacy polyfill entirely |

---

## 3. Needs adaptation in piwigo17-rewrite

Two small, explicitly-decided core changes — not a generic capability.

### 3a. `GetCategoryDerivativeParams` event (new)

**Problem.** `CategoryCatsRenderer.php` (~line 460) hardcodes `$this->imageStdParams->getByType(ImageStdParams::THUMB)` for the category/album-grid derivative with zero event dispatch around it — unlike `CategoryDefaultRenderer.php:234`'s dispatched `GetIndexDerivativeParams` for the image grid. `THUMB` is `SizingParams::classic(144, 144)`, fit-within, no crop — smaller than gdThumb's own default configured height (200), an unconditional forced CSS-upscale at default settings with no override path today.

**Decision** (made explicitly, not silently worked around): add a new event mirroring the existing one exactly, rather than accept a visible v1 quality regression on the album grid or invent a CSS-only workaround legacy itself never used.

```php
// src/Piwigo/Category/Event/GetCategoryDerivativeParams.php
// same shape as Piwigo\Image\Event\GetIndexDerivativeParams:
final class GetCategoryDerivativeParams
{
    public function __construct(public DerivativeParams $params) {}
}
```

Dispatch in `CategoryCatsRenderer.php` at the existing `getByType(ImageStdParams::THUMB)` call site, same pattern as `CategoryDefaultRenderer.php:234`.

### 3b. `plugins/<id>/assets/*` isn't web-servable — for any plugin, not just gdThumb

**Problem** (found only by the validation workflow, not by either design pass): `docker/Caddyfile` explicitly 403s `/plugins/*` (`@deniedRelocated`, deliberately grouped with upload/galleries/local/language to close a `php_server` try-files fallthrough gap), and there is no `public/plugins` symlink — unlike the real, existing `public/themes → ../themes` and `public/dist → ../dist` symlinks that make theme/bundled assets reachable at all. `public/.htaccess` already has a comment describing the *intended* parity between themes and plugins that was simply never built.

**Fix**: add the `public/plugins` symlink (matching the real `public/themes`/`public/dist` shape) plus a Caddyfile carve-out removing `/plugins/*` from `@deniedRelocated`, and the equivalent Apache vhost rule. Re-read `docker/Caddyfile`'s current exact rule shape before editing — don't invent a new pattern. This blocks *every* future plugin shipping its own CSS/JS, not just gdThumb; worth fixing here rather than deferring.

---

## 4. Needs adaptation in the plugin itself (`GDThumb_17.0.0`)

### 4a. Admin settings page — full rewrite, not a raw include

`admin.php` does `global $template, $conf, $page;` then `$template->assign([...])` — `$template` resolves to `null` on this fork (`Template::assign()` is also private by design regardless), so any raw-include attempt fatals immediately. Confirmed independently by `PLUGIN_PORTING_AUDIT.md`'s own `Admin: risky*` classification for this exact plugin. There is no raw-include path anymore — `piwigo16-plugins/CLAUDE.md`'s own older guidance (`ExtensionContext::template()->assignVarFromTemplate('ADMIN_CONTENT', ...)`) is itself now superseded for the *plugin* case: the real, current mechanism (confirmed directly against `src/Piwigo/PluginConfig/SettingsPageInterface.php` and its one real plugin-facing caller, `Controller/Admin/PluginSubController.php`) is:

```php
interface SettingsPageInterface {
    public function handleSettingsRequest(ServerRequestInterface $request): View;
}
```

`PluginSubController::handle()` calls `handleSettingsRequest($request)` and renders the returned `View` directly via `Renderer::render()` — no `assignVarFromTemplate()`, no `PageState`/error-bag threading reachable from a plugin at all. gdThumb's own settings `View` must carry its own `errors: list<string>`/`warnings: list<string>` and render them inline in its own `.latte` template, replicating legacy's `array_push($page['errors'], ...)` validation (height/margin/`nb_image_page` `is_numeric()` checks) and the two cache-invalidating side effects on height/margin change locally. Read `$request->getParsedBody()`, never `$_POST`. Call `checkCsrfOrFail()` only on the `cachedelete`/`submit` paths (never on a bare GET render), matching legacy's own gate placement exactly.

### 4b. Masonry/CSS layout — explicit overrides required, not clean composition

Per §1's validated findings: the plugin's own CSS must actively override, not just add to, `theme.css`'s `.thumbnails LI { display: inline }` (flip to `inline-block`/`block` before width/height/float/position do anything to the `<li>` at all), `.wrap1 { display: inline-block; margin: ... }`, and `.thumbnailCategories LI { float: left; width: 49.7% }`. Caption-mode CSS needs matching-or-higher specificity than `.thumbnails SPAN.thumbLegend { display: block; height: 4em }` (specificity `(0,2,1)`) for the height/overflow override, plus `position: relative` added to `.wrap1` for `position: absolute` overlay-mode captions to anchor per-thumbnail rather than page-wide.

### 4c. Masonry JS module — new authorship, self-synchronizing

No existing masonry/grid-layout analog anywhere in `themes/` (confirmed, `grep -ril masonry themes/` = zero hits). New TS module (`GDThumb_17.0.0/assets/layout.ts`), operating on `#thumbnails li[data-image-id]`/`.thumbnailCategories li[data-category-id]`, reusing `themes/default/js/vendor/dom.ts` helpers. Per §1's validated finding: does **not** rely on `AssetContribution::script(..., dependsOn: [...])` or script-tag position for execution ordering relative to `thumbnails.loader.ts` or an inline config script — synchronizes itself via `DOMContentLoaded`/`window.load` + per-`<img>` `load`/`error` listeners (`img.complete` check first).

Legacy features to explicitly replicate, both found only by adversarial re-reading of `gdthumb.js`/`main.inc.php` in full (both were silently missing from the second design pass's own feature table):

- **`do_merge`** (`gdthumb.js:44-56`) — on a category page showing both sub-albums and direct photos, splice `.thumbnailCategories` and `#thumbnails` into one combined masonry grid instead of two separate ones.
- **Hero-mode aspect-ratio suppression** (`gdthumb.js:84-87`, legacy `check_pv`/`big_thumb_noinpw`) — suppress the enlarged first-thumbnail when its aspect ratio is extreme (`ratio > 2.2 || ratio < 0.455`), computed once the image's natural size is known client-side.

### 4d. Hero thumbnail + media-type icon — server-computed via `exposeData()`, not client-parsed

Two access paths, not one unified sentence (per §2's `ImageThumbnail`/`CategoryThumbnail` shape mismatch): build the enlarged `DerivativeImage` inside the `IndexThumbnailsRendered` handler from `$event->tplThumbnailsVar[0]->srcImage` (always present), and inside `IndexCategoryThumbnailsRendered` from `$event->tplThumbnailsVar[0]->representative` (nullable — no-op when null, an album with no representative image). Call `exposeData('gdthumb_hero', [...])` for the client layout module to read.

Media-type-by-extension (image/video/music/pdf/doc/xls/ppt) **cannot** be computed client-side as originally assumed: `$thumbnail->url` is the picture-detail-page permalink (`UrlService::duplicatePictureUrl()`), and `<img src>` is always an image-format derivative URL — neither reliably carries the source file's real extension for the non-image types this feature exists to distinguish. Compute it server-side in the same event handlers (reading `SrcImage`'s real filename), exposed via the same `exposeData()` channel as the hero thumb.

### 4e. `plugin.json` — must declare every required field, including `autoload.psr-4`

The schema (`docs/schemas/plugin.schema.json`) requires `id`, `name`, `version`, `description`, `license`, `minPiwigo`, `main`. `id` must equal the installed directory basename exactly. `autoload.psr-4` is schema-*optional* but boot-*fatal* if omitted — `PluginRegistry::registerAutoload()`/`bootInstance()` throws `PluginValidationException` ("does not exist (autoload missing)") the first time the main class is instantiated, confirmed against the registry's real code and matching the real fixture shape `tests/Browser/PluginsInstalledInteractionTest.php` already uses.

```json
{
  "id": "gdthumb",
  "name": "gdThumb",
  "version": "17.0.0",
  "description": "Apply Masonry style to album or image thumbs",
  "license": "GPL-2.0-or-later",
  "minPiwigo": "17.0.0",
  "main": "Piwigo\\Plugin\\GdThumb\\Plugin",
  "autoload": { "psr-4": { "Piwigo\\Plugin\\GdThumb\\": "src/" } },
  "hasSettings": true,
  "author": "Serge Dosyukov",
  "authorUri": "http://blog.dragonsoft.us"
}
```

`license: "GPL-2.0-or-later"` is an inference from `changelog.txt`'s GPL v2-or-later FSF boilerplate text — gdThumb ships no SPDX identifier anywhere. Flag this to whoever finalizes the port rather than treating it as a silently-settled fact; user sign-off already given for this session's own plan, worth re-confirming at implementation time in case the source is revisited.

Preserve both real attribution lines found in the source: Serguei Dosyukov as the current author/copyright holder (2009-2022 per `changelog.txt`), and `main.inc.php`'s own credit "Original work by P@t - GTHumb+" for the earlier plugin gdThumb was inspired by.

---

## 5. Investigated, dropped for v1

- **Forced site-wide `nb_image_page` override** (legacy `init` hook) — no `ExtensionContext` mutator exists for `CurrentUser`'s `nbImagePage`; `switchUser()` does a full DB-backed identity swap, not a partial-field override. Drop, or offer only as a real per-user profile default via the plugin's own settings.
- **Mobile-theme bypass** (`if (mobile_theme()) return;`) — `DeviceHelper::mobileTheme()` exists in core but is never wrapped by `ExtensionContext` (needs `SessionService`, not exposed). v1 always renders.
- **`$_GET['rvts']` "RV Thumbnails Scroller" compat toggle** — narrow legacy interop with an unrelated plugin (see `docs/plugin-porting/rv-tscroller-port-analysis.md` in this same directory for that plugin's own, unrelated, real port). No `ExtensionContext` accessor exists for reading the current request's query params from a rendering-path event listener (only `SettingsPageInterface::handleSettingsRequest()` gets a `ServerRequestInterface`, a different call path). Drop.
- **Cache-purge admin tool** (`delete_gdthumb_cache()`/`clear_derivative_cache_rec()`) — `DerivativeCacheService` (the real, already-existing equivalent) is not exposed via `ImageReadFacade`/`ImageWriteFacade` or any other `PluginConfig\Facade`. Real gap, not a workaround target — descope.
- **Missing-derivative-scan admin tool** (`getMissingDerivative` AJAX endpoint) — needs a paginated "list images missing a derivative of size X" repository/facade method that doesn't exist (no raw DB access is available to extensions, by design). Real gap — descope. If ported later, `DerivativeUrlStyleOverride` (confirmed real, 4th optional `DerivativeImage` constructor arg) cleanly replaces the legacy global-config-mutation hack (`$conf['question_mark_in_urls']`/etc.) this endpoint used.
- **`changelog.php` standalone lightbox endpoint** — fold changelog text inline into the settings page `View` instead of a separate route/colorbox popup.
- **`greydragon` theme soft-coupling** (admin CSS lookup gated on `GDTHEME_PATH`, the theme-gated "Overlay Ex" caption mode, `css/gdthumb.css`'s `body.theme-whitehawk` rules) — confirmed dead weight, no `greydragon`/`whitehawk` reference anywhere in `piwigo17-rewrite`. Drop entirely, including the theme-gated caption mode (reimplement plainly if wanted, no theme-conditional branch).

---

## 6. Repo-level bookkeeping (per `../piwigo16-plugins/CLAUDE.md`)

- New sibling directory `GDThumb_17.0.0/`, never editing `GDThumb_1.0.27/` in place.
- `manifest.json`: add a `GDThumb_17.0.0` key alongside the existing `GDThumb_1.0.27` key (never remove the legacy one). Reuse `extension_id`/`extension_name`/`author_name` from the legacy entry; `revision_id: "local-gdthumb-17.0.0"`; carry over `thumbnail: "thumbnails/gdThumb.jpg"` (confirmed present in this repo already) — omitting it leaves the admin "Add new" catalog tab with a broken thumbnail even though the image already exists locally.
- Zip packaging: `GDThumb_17.0.0.zip` must extract to the bare `gdthumb/` directory (matching `plugin.json`'s `id`), not a versioned folder — `PluginRegistry::loadManifest()` requires the extracted directory basename to equal `id` exactly.

## 7. Verification

Per `../piwigo16-plugins/CLAUDE.md`'s own explicit standard, raised after the 2026-08-25 revert found unit/PHP-parses-level "verified" ports still had real live bugs: **install for real against a running `piwigo17-rewrite` instance through the local PEM mirror** — fetch, compat filter, download, extract, install, activate, boot, and a real page render (both the image-grid index page and a mixed category/photo album page, exercising `do_merge`). Don't stop at PHPStan/ECS/unit-level checks. Additionally:

1. `vendor/bin/phpstan analyse` + ECS scoped to the 2 new `src/Piwigo/Category/` files (§3a) and the plugin's own tree.
2. Confirm `plugins/gdthumb/assets/*` returns 200 (not 403/404) through the real dev-stack Caddy config after §3b lands.
3. A live/browser check confirming: default index and category pages unaffected when the plugin is inactive; masonry layout, hero first-thumb (with aspect-ratio suppression), caption-mode classes, and the mixed-page grid merge all apply once active.
4. `git diff --stat` on `piwigo17-rewrite` should show changes confined to `src/Piwigo/Category/Event/GetCategoryDerivativeParams.php`, `src/Piwigo/Category/CategoryCatsRenderer.php`, and the `public/plugins` symlink + Caddyfile/vhost carve-out — nothing else.
