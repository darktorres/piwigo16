# Bootstrap Darkroom → piwigo17-rewrite: Port Analysis

Source: `/home/torres/piwigo16-themes/bootstrap_darkroom_16.d` (v16.d, Apache-2.0, upstream `github.com/Piwigo/piwigo-bootstrap-darkroom`)
Target: `/home/torres/piwigo17-rewrite`

## 1. Executive summary

The port is **large but not architecturally risky at its core** — every legacy hook darkroom uses already has a typed PSR-14 event in piwigo17-rewrite, every `$themeconf` field has a manifest property, and every structurally-similar Smarty template has a converted Latte sibling to copy the pattern from. Most of the ~2,500 lines of PHP, ~50 templates, and ~1,200 lines of custom SCSS is mechanical restyle/re-expression work with a real precedent to follow.

**The single biggest architectural mismatch is not in this repo's own contract — it's that darkroom is built entirely on Bootstrap 4 + jQuery, and piwigo17-rewrite has zero CSS framework and is mid-campaign eliminating jQuery repo-wide.** Nearly every template assumes Bootstrap's grid/utility/component vocabulary, and nearly every interactive widget (navbar, dropdown, carousel, lightbox, multi-select, tab switcher, star rating, tag cloud) is a jQuery plugin.

> **Decision (resolves open question 1):** no framework vendoring, at any version, and no 1:1 port — full rewrite against this codebase's own conventions, even where that costs materially more than a mechanical class-rename. There is no existing modern CSS pattern to imitate: `themes/default/theme.css` (1,014 lines) is still float/ID-selector-based legacy CSS with a handful of flexbox rules and zero custom properties; `standard_pages` and admin's own stylesheets are the same (confirmed by direct inspection). This port becomes the **first** hand-authored, framework-free, custom-property-driven CSS in the project.
>
> Concretely: Bootstrap's grid/utility/component vocabulary gets rebuilt from scratch as plain CSS (Grid/Flexbox, custom properties for the palette) — not vendored at any version, not mechanically renamed. Bootstrap's JS (dropdown/navbar-collapse/modal/carousel) gets rebuilt as hand-written native TS classes following the same pattern already used for `rating.ts`/`album_selector.ts` — no framework JS bundle, BS4 or BS5. A narrowly-scoped external widget with no in-house equivalent (PhotoSwipe for the lightbox) still gets narrow-ported into a TS wrapper, the same way `vendor/colorbox.ts` already does it here — that's the codebase's real convention for "when is an external dependency OK": a narrow single-purpose widget as a TS-wrapped port, never a whole framework as a vendored bundle.

The second major finding, independently surfaced by three of the five research passes, is that **darkroom is bloated with proliferating near-duplicate variants that should be pruned regardless of target platform**: ~30 bootswatch/material color skins (78MB of precompiled, mostly-generated CSS), 3 duplicate picture-info skins, and a whole PEM-installer `obsolete.list`/migration-shim layer with no target-side equivalent to attach to.

> **Decision (resolves open questions 4 and 9):** ship one fixed palette and one picture-info layout at v1. Of darkroom's ~30 bootswatch/material skins, 21 of the 22 bootswatch variants are confirmed thin 7–11 line re-export chains around a vendored library with zero original design content — only `darkroom-colors/_variables.scss` (97 lines) is genuinely custom. That's the only file worth porting; it becomes ~10 hand-authored CSS custom-property roles on `:root`. This dissolves the colorscheme runtime-switch problem entirely — with one palette there is nothing to switch to. A later settings-page color field, if wanted, writes a small typed Config VO to `ExtensionContext::setSetting()` and renders one inline `<style>` `:root{--dr-*}` override block after `theme.css` — no `!important` needed, ordinary cascade wins.
>
> Picture-info collapses to **cards** — but the report's own original reasoning for that pick was backwards (see §7, Q9): `picture_info_sidebar.tpl`, not `cards.tpl`, is actually the layout whose markup already matches core's own `dl#standard.imageInfoTable`/`div.imageInfo` convention (verified in `picture.latte`). Cards still wins on the real grounds — sidebar's only differentiator is an off-canvas drawer with no precedent anywhere in this front-end, and tabs' only purpose was device-conditional display, not a genuine alternate style — but the shared field-rendering partial should be authored against core's own `dt`/`dd` convention, with only the outer wrapper styled as a card.

Third, this port is not just a restyle — it establishes **new ground on the target side that no other extension exercises yet**: this repo has zero implementors of `SettingsPageInterface`, no theme that loads its own translation catalog, no lightbox/carousel dependency, no precedent for a *third-party, non-bundled* theme's packaging location at all, and no existing hand-authored modern CSS to build from either. **All four of these are now resolved with concrete designs** (see §7, Q3/Q5/Q11/Q2) — none of them are darkroom-specific difficulty; they're this repo's own settings-page/i18n-loading/lightbox/packaging design being invented for the first time, using darkroom as the forcing function.

**Packaging recommendation (still needs the project owner — see §7, Q2):** a new sibling repo (e.g. `darktorres/piwigo17-themes`) hosting real v17-contract `theme.json` directories plus a root `manifest.json` in the exact shape `Admin\Extensions\PemCatalog` already consumes, wired via `PIWIGO_ALT_THEMES_PEM_URL` — the same shape as `elegant`/`modus`/`smartpocket`. The fetch/extract plumbing is already built and generic; this is a maintenance-ownership commitment, not a technical gap.

**Scope estimate:** small/trivial items (manifest fields, thin page-shell templates, i18n conversion) are a few days combined. The genuinely novel pieces gate most of the rest and are each multi-day on their own: the settings page, `getAllThumbnailsInCategory`'s SQL/derivative-params rearchitecture, the from-scratch framework-free CSS/JS foundation, and — newly scoped by this round — generalizing this repo's own translation-loading mechanism for every future theme/plugin (§7, Q11) and building PhotoSwipe as a shared, non-darkroom-private capability (§7, Q5).

---

## 2. Clean ports

Essentially no structural change required.

- **`assets/photography-icons/`** (custom Glyphter-generated icon font: `css/PhotographyIcons.css` + `fonts/*.{eot,svg,ttf,woff}`) — same shape as the existing `themes/default/vendor/fontello/` vendoring precedent (CSS + font files, `@font-face` mapping). Only needs a `woff2` added and ideally a `config.json` regeneration source. Vendor unchanged under the theme's own `vendor/` directory.

Everything else in this theme needs at least a mechanical re-expression — manifest translation, Smarty→Latte, or file-format conversion. No other item across all five research areas qualifies as a byte-for-byte copy.

---

## 3. Needs adaptation in piwigo17-rewrite

Grouped by area. "Target precedent" is the concrete file/class to copy the pattern from.

> **Reading the templates table (3b):** effort ratings there describe the Smarty→Latte structural conversion only. Per the framework-free decision in §1, every Bootstrap-classed element in every template is additionally re-marked-up against the new hand-authored CSS from §3c/§5.4, not mechanically carried over — real added effort the table doesn't re-state row by row.

### 3a. PHP contract & lifecycle

| Item | Source | Target precedent | Effort |
|---|---|---|---|
| Static `$themeconf` fields (name/parent/load_parent_css/load_parent_local_head/local_head/url/use_standard_pages) | `themeconf.inc.php` | `themes/default/theme.json` + `docs/schemas/theme.schema.json` | trivial |
| `colorscheme` static default (`'dark'`) | `themeconf.inc.php` | `theme.schema.json` `colorscheme` enum | trivial |
| `pwg_set_session_var('show_metadata', true)` | `themeconf.inc.php` | **Corrected (§7, Q14): the naive `ExtensionContext::session()` route is WRONG — it would silently write an unread key.** `ExtensionSession` namespaces as `ext_<themeId>_show_metadata`, and `SessionService` additionally prefixes `pwg_` — but core's `PictureController` calls `SessionService::setSessionVar()`/`isShowMetadataEnabled()` **directly**, bypassing `ExtensionContext` entirely, reading the bare `pwg_show_metadata` key. Needs one new, narrowly-scoped `ExtensionContext` method for this specific legacy-shared flag. | small — was "wire an existing method," is now "design + add one new narrow method" |
| `$conf['file_ext']` video-extension registration (mp4/m4v) | `themeconf.inc.php` | **Confirmed (§7, Q12):** `CurrentConfig::$fileExtensions` is a plain public array with a `set` hook auto-sanitizing to `list<string>`; `ExtensionContext::config()` returns the same shared instance and its docblock explicitly says safe to mutate from `boot()`. `$context->config()->fileExtensions = [...$context->config()->fileExtensions, 'mp4', 'm4v'];` works as originally sketched, no new capability needed. | small |
| `ThemeController::init()` — 8 legacy hooks | `include/themecontroller.php` | `docs/events-legacy-map.md`; typed PSR-14 events, all exist, most confirmed to have real dispatch sites this round (see §7, Q13/Q15/Q16) | medium |
| `assignConfig()`/`setInitValues()` template-var assignment | `include/themecontroller.php` | `ExtensionContext::template()`/`getSetting()` | small |
| `hideMenus()` — remove comments block when Disqus active | `include/themecontroller.php` | **Confirmed (§7, Q13):** `BlockManagerApply::$menublock` is the live `Menu\BlockManager` instance itself, exposing a public `hideBlock(string $block_id): void` directly — `$event->menublock->hideBlock('mbLinkComments')`, no rearchitecture needed. | small |
| `checkIfHomepage()` / `stripBreadcrumbs()` | `include/themecontroller.php` | **Corrected (§7, Q16): neither `PageHeaderRendering` nor `PageHeaderRendered` is usable as-is.** Both are confirmed genuinely-dispatched but structurally-empty marker classes (`final readonly class {}`, zero properties) — `prepareContext()` never computes `is_homepage` at all. See §5.14, a new rearchitect item this round surfaced. | medium — was "small," now blocked on a new event design |
| `exifReplacements()` — configurable EXIF tag string-replace filter | `include/themecontroller.php` | `Metadata/Event/FormatExifData.php` | small |
| `registerPictureTemplates()` — Smarty handle-based partial assembly | `include/themecontroller.php` | Latte `{include}` directly, or a View-level `Html` fragment property | medium |
| `returnPageStart()` — START_ID from `$page['start']` | `include/themecontroller.php` | **Confirmed (§7, Q15):** `IndexThumbnailsRendering` genuinely fires from `CategoryDefaultRenderer::render()`, and `$start` is already a local variable in scope at that exact dispatch site — carrying it is a one-line constructor addition. Correction: it fires on every render with at least one item, not unconditionally (an empty category/page never reaches the dispatch). | trivial |
| `Config` class — versioned JSON-blob persistence, magic `__get`/`__set` | `include/config.php` | `ExtensionContext::getSetting()`/`setSetting()`/`deleteSetting()` — drop the magic-property style for a real readonly VO/DTO, keep the versioned-migration logic | medium |

### 3b. Templates

| Item | Source | Target precedent | Effort |
|---|---|---|---|
| `header.tpl`+`footer.tpl` → shell | — | `themes/default/template/layout.latte` — mechanism transfers, asset list fully re-authored against `AssetContribution`/`PageAssets` (see §7, Q20) | medium |
| `menubar.tpl` + 11 `menubar_*.tpl`/`menublock_*.tpl` | — | `themes/default/template/menubar*.latte` family — direct correspondence confirmed for categories/tags/links/specials/menu/identification; `menubar_user_collections.tpl`'s Smarty `{function}` macro has no Latte equivalent | medium |
| `infos_errors.tpl` | — | `infos_errors.latte` | trivial |
| `navigation_bar.tpl` | — | `navigation_bar.latte` | trivial |
| `notification.tpl`, `about.tpl`, `redirect.tpl`, `profile.tpl` | — | matching `.latte` siblings, thin shells | trivial |
| `password.tpl`, `identification.tpl`, `register.tpl` | — | matching siblings; `register.latte` additionally exposes `$pluginRegisterFields`/`$pluginAuthButtons` darkroom has no equivalent for — must be added | small |
| `comments.tpl`+`comment_list.tpl` | — | `comments.latte`/`comment_list.latte` — follow core's `data-confirm` conversion | small |
| `tags.tpl` | — | `tags.latte` — flat cloud mode ports directly; "fancy" canvas cloud mode dropped (§6) | small |
| `thumbnails.tpl` | — | `thumbnails.latte`; lazy-load calls `thumbnails.loader.ts` | small |
| `mainpage_categories.tpl` | — | `mainpage_categories.latte` | small |
| `month_calendar.tpl` | — | `month_calendar.latte` | small |
| `index.tpl` (379 lines) | — | `index.latte` — grid/list toggle, PhotoSwipe wiring (now resolved, §7, Q5), video-badge injection | medium |
| `picture.tpl`+`picture_nav.tpl` | — | `picture.latte`, `picture_nav_buttons.latte` — core folded the nav template into `#imageToolBar` | medium |
| `picture_content.tpl` | — | `picture_content.latte` — inline `<video>` (.mp4/.m4v) branch is new logic | small |
| `picture_nav_buttons.tpl` | — | `picture_nav_buttons.latte` — confirmed (§7, Q5) structurally unrelated to the lightbox: it does real full-page `window.location.href` navigation, not a lightbox-state concern | small |
| `profile_content.tpl` | — | `profile_content.latte` — use core's typed `$pluginProfileFields`/`$pluginFormProviders` | small |
| `admin/template/about.tpl` | — | any static admin-page shell | trivial |

### 3c. CSS / build

| Item | Source | Target precedent | Effort |
|---|---|---|---|
| Bootstrap grid/utility/component vocabulary itself | `scss/bootstrap-darkroom.scss` | **none — new ground.** Rebuilt from scratch as plain CSS | large |
| Custom darkroom SCSS (24 partials, ~1,200 lines) | listed in raw findings | plain CSS with custom properties — see §1's palette decision for the ~10-role token set | large — 34 `!important` declarations get real specificity fixes (§7, Q18, provisional pending a dedicated audit pass) |
| Bootstrap JS bundle | `node_modules/bootstrap@4.3.1` | `rating.ts`/`album_selector.ts` methodology — hand-written native TS, no framework bundle | large |
| PhotoSwipe (lightbox) | `node_modules/photoswipe@4.1.3` | **Resolved (§7, Q5):** PhotoSwipe v5, narrow-ported as `themes/default/js/vendor/photoswipe.ts` following `colorbox.ts`'s exact real pattern (`WeakMap<Element,Options>` + registration list, lazy singleton overlay, `computeRelated()`/`relatedAt()` gallery grouping). Built as a **shared, non-darkroom-private** capability — any theme can adopt it. | medium |
| Build pipeline: yarn/node-sass/postcss/clean-css → Vite | `package.json`, `postcss.config.js` | **Corrected (§7, Q20):** the real, live asset-ordering mechanism is `Asset\AssetContribution` (`css(order:)`, `script(dependsOn:, loadMode:)`) + `Asset\PageAssets` — a genuine successor to Smarty's old `combine_css`/`combine_script order=`/`require=`, not something ES-module import order alone replaces. The legacy `ScriptLoader`/`CssLoader` combiner **no longer exists in the codebase at all** (deleted by a prior commit) — there's no legacy competitor to reconcile against. `build/collectScriptEntries.ts` already auto-derives a real Vite entry for ~85 real `AssetContribution::script()` registrations today — treat Vite bundling as the default for any new `.ts` file this port registers, not a future-phase concern. | medium |

### 3d. i18n

| Item | Source | Target precedent | Effort |
|---|---|---|---|
| `theme.lang.php` (26 locales, ~87 flat keys each) → gettext `.po` | `language/<locale>/theme.lang.php` | `tools/i18n/php-to-po-fn.php`; `language/fr_FR/common.po` for shape | small — output is theme-scoped `theme.po` per locale |
| Locale coverage / RTL / drift | `language/he_IL/`, `ru_RU/theme.lang.php` | `Translator::translate()`'s msgid-fallback makes missing locales safe | trivial |

### 3e. JS (darkroom's own hand-authored files)

| Item | Target precedent | Effort |
|---|---|---|
| `js/theme.js` — page glue | Real-ES6-class, no-jQuery pattern of `rating.ts`/`album_selector.ts`. Overlaps the CSS area's Bootstrap-JS/PhotoSwipe items — don't double-count effort. | medium |
| `rating.js` (star-rating widget, duplicated across all 3 picture-info skins) | `themes/default/js/rating.ts` — **a reuse target, not new work.** | small |

---

## 4. Needs adaptation in the source theme itself

Things worth fixing in darkroom's own design, independent of target platform.

- **`ThemeController::showUpgradeWarning()`** — a one-time migration off pre-2018 theme-selector values with no data path that can ever be reached on a fresh port. **Drop entirely.**
- **`picture_info_cards.tpl` / `picture_info_tabs.tpl` / `picture_info_sidebar.tpl` / `picture_info_comments.tpl`** — confirmed field-for-field duplicates (same ~30-line inline rating-init script triplicated verbatim, same element ids). **Resolved (§7, Q9): collapse to one shared partial, cards layout, authored against core's own `dl`/`dt`/`dd` convention** — not, as originally reasoned, because cards' own markup already matched core (it didn't; sidebar's did) but because sidebar's differentiator needs off-canvas-drawer JS with no precedent here, and tabs only ever existed for device-conditional display.
- **Font Awesome 5 Free** (~3.1MB vendored for ~15 hardcoded glyphs). **Resolved (§7, Q19, provisional): fold into one extended, self-hosted `photography-icons` font** — a vendored FA subset is the same category of thing the framework-free decision already rejected for CSS/JS. Verify against real repo state before implementation: the actual 15 glyphs, their license terms, and any existing icon-font convention in `themes/default`/`themes/admin/default` were not inspected this round.
- **`slick-carousel` fork** — an unpublished personal fork of an unmaintained library, for a job native CSS scroll-snap solves cleanly. See §5.5/§6.
- **`obsolete.list`** (~2,000 lines of PEM-installer bookkeeping) — independent evidence of the same "vendored bloat checked in as build output" pattern the CSS findings already flagged.

---

## 5. Rearchitect / no direct equivalent

Genuinely new design needed.

### 5.1 Theme settings admin page — **resolved (§7, Q3)**
**Sources:** `admin/admin.inc.php`, `include/config.php`, `admin/template/settings.tpl` (597-line tabbed form), `admin/template/about.tpl`.
**Decision:** server-side branching inside `handleSettingsRequest()`, reading `ExtensionTabRequest::fromArray($request->getQueryParams(), ...)` against a theme-local tab pattern, returning one distinct typed View per tab via `match()` — **no client-side tab JS, full page reload per tab link.** `ExtensionTabRequest` is real and already used by 3 other controllers; `SettingsPageInterface`'s signature needs zero changes. `Controller\Admin\ConfigurationSubController` (1,619 lines) is the one real multi-section precedent to follow: a `?section=` param driving a server-side switch to distinct typed View/Data VO pairs.
**Correction:** `ExtensionTabRequest::fromArray()`'s own internal default when `tab` is unset is the literal string `'installed'` (built for the languages/plugins/themes list page) — describe that only as the request-level fallback, not as `'general'`; the theme's own `match()` still resolves correctly via its `default =>` arm.
Effort: **large**, highest-effort single item in the whole port.

### 5.2 Runtime clear/dark colorscheme switch — **dissolved (§7, Q4)**
With the palette decision in §1 (one fixed palette, not 30 skins), there is no second skin to switch to. `colorscheme` reverts to the trivial static manifest field it already is in §3a. No design work needed here at all.

### 5.3 `getAllThumbnailsInCategory()` — carousel data assembly
**Source:** `include/themecontroller.php`. Raw `pwg_query()` against `IMAGES_TABLE`, `SrcImage`, 4× `ImageStdParams::get_by_type()` calls. `ImageReadFacade` exposes none of this.
**Proposal:** add `ImageReadFacade::findByIdsOrdered(array $ids): list<ImageRow>` plus a derivative-params-bundle helper. Effort: **large** — largest single logic chunk in the PHP layer.

### 5.4 Bootstrap 4 SCSS as the layout base — **decided (§1)**
No framework vendoring at any version. Hand-authored plain CSS — Grid/Flexbox, custom properties — matching each Bootstrap component's real behavior without importing Bootstrap's code. Effort: **large**, by design.

### 5.5 Slick Carousel thumbnail filmstrip
**Proposal:** drop the library, build CSS `scroll-snap-type`/`scroll-snap-align` plus a small TS class for center-scaling/infinite-loop behavior. Effort: **medium**.

### 5.6 Selectize multi-select — **moot (§7, Q6)**
Only used in `search.tpl`, which is now recommended for dropping entirely (see §5.9). No replacement work needed unless search is built.

### 5.7 `grid_classes.tpl` responsive-column calculator
**Proposal:** replace with `grid-template-columns: repeat(auto-fill, minmax(...))` CSS Grid, eliminating the pixel-math entirely. Effort: **medium**.

### 5.8 PhotoSwipe integration (`_photoswipe_div.tpl` + `_photoswipe_js.tpl`) — **resolved (§7, Q5)**
See §3c — PhotoSwipe v5 as a shared `vendor/photoswipe.ts` capability, following `colorbox.ts`'s pattern. Scope confirmed: `index.tpl`/`thumbnails.tpl`/`_photoswipe_*.tpl`, plus an optional additive "view fullscreen" button on `picture.tpl`. `picture.ts`/`picture_nav_buttons.ts` confirmed structurally unrelated (real page-navigation, not lightbox state) — no rearchitecture needed there. Recommend landing this as its own task ahead of the darkroom-specific work — core's default theme can adopt it too. Effort: **medium**.

### 5.9 Advanced search form page (`search.tpl`) — **owner decision, strong recommendation (§7, Q6)**
**Confirmed:** `SearchController` genuinely only redirects (its own docblock: `redirect()` is typed `never`; no `HtmlRenderingInterface::render` anywhere in the file). The existing inline `search_filters.inc.latte` refine bar is a **strict superset** of darkroom's page in every dimension checked: 7 match fields vs. darkroom's 3, real hierarchical date drill-down with live counts vs. a flat date-type, plus added-by/filetype/ratio/rating/filesize/dimension range filters and an expert-mode query box darkroom has no equivalent for.
**Recommendation: drop `search.tpl` entirely.** Building a second, weaker full-page form would be a regression against what already ships. Effort if declined (recommended): **zero**.

### 5.10 Third-party-plugin-override template family — **resolved (§7, Q8)**
**Sources:** `button_user_collections_*.tpl` (7 files), `stuffs_*.tpl` (4 files), `menu_templates/*.tpl` (4 files), `language_switch_flags.tpl`.
**Decision:** defer all 4 families entirely from v1, uniformly, with **no graceful-degradation shim built** — none is needed. `Menu\BlockManager::apply()` already generically hides any block with null/empty content before the menubar renders — verified directly in source. A theme rendering the real menubar already shows nothing for an absent plugin, with zero per-plugin special-casing required. Of the 4 backing plugins, only `language_switch` is even in this repo's bundled/maintained set; the other 3 (UserCollections, PWG_Stuffs, AdminMenuManager) aren't. Effort: **zero** — not merely deferred, genuinely nothing to build.

### 5.11 `add_photos.tpl` — front-end community-upload page — **owner decision, reclassified (§7, Q7)**
**Confirmed:** hard-depends on the Community plugin's own web-service surface (`community.images.uploadCompleted`) and moderation copy — it belongs with §5.10's plugin-dependent family, not as a standalone template port; there is no target to mechanically port to regardless of the answer below.
**The real, narrower question:** does piwigo17-rewrite want *any* non-admin front-end photo-contribution capability at all, built fresh, independent of Community and darkroom? **If pursued, recommend:** extend the existing TUS 1.0.0 upload pipeline (`TusUploadCreateController`, currently hard-gated `AdminGuard`-only) with a new `UploadPermissionGuard` backed by a per-user-per-category grant table (modeled directly on `PermissionService::addPermissionOnCategory()`'s existing VIEW-grant pattern) and a real `validated`/pending state on `ImageEntity` with an admin approval queue. Front end: native `<input type=file multiple>` + drag-drop over `tus-js-client`, no plupload/jgrowl/piecon/colorbox.
**This is not a theme-porting sub-item if pursued** — it's a multi-phase new-core-capability project (permission model + moderation model + guard swap + new page), sized well beyond the darkroom port and sequenced independently of it. Effort if declined (no recommendation given, genuine product call): **zero**.

### 5.12 Theme's own translations integrating with the l10n loader — **resolved with a concrete design (§7, Q10 + Q11)**
**Sources:** whole `language/` tree. `Lang\LangService::loadLanguageForPlugin()`/`isInstalledLocale()` have **zero production callers** and are strictly narrower than the generic `Core\Lang::load()`.
**Timing (Q10), verified fact:** boot()-time `Lang::t()` resolves correctly for a **theme** specifically — `Lang::t()` never depends on `attachGlobals()`, and `ThemeRegistry::bootCurrent()` runs strictly *after* `LanguageMiddleware` has already loaded translations. (Plugin `boot()` runs *before* that point — a materially different timing, see below.)
**Design (Q11), resolved:** delete `LangService::loadLanguageForPlugin()`/`isInstalledLocale()` and wire auto-load **asymmetrically**, because plugin and theme `boot()` genuinely run at different pipeline positions:
- **`ThemeRegistry`** gains a `Lang` dependency and calls `$this->lang->load('theme.lang', $themeDir)` directly inside `bootCurrent()`'s existing loop, alongside `$instance->boot(...)`.
- **`PluginRegistry`** cannot do the equivalent directly (`bootActive()` runs before the real per-request locale provider is wired). Instead it implements `Core\SubscriberInterface` and self-registers, subscribing to the already-dispatched-but-currently-dormant `Lang\Event\LoadingLang` event (fired by `LanguageMiddleware` *after* the locale provider is wired) — its handler loops booted plugin instances and loads each one's `plugin.lang`.

**Three corrections to apply before implementing this exactly as designed:**
1. The interface is `Piwigo\Core\SubscriberInterface`, not `PluginConfig\SubscriberInterface`.
2. The load-call filenames **must** be `'theme.lang'`/`'plugin.lang'`, not bare `'theme'`/`'plugin'` — `Lang::load()` only rewrites a `.lang.php` suffix to `.po`; a bare filename silently fails `is_readable()` and loads nothing. Every real call site in the codebase already passes the `.lang`-suffixed form for exactly this reason.
3. Deleting `LangService` removes an explicit, separately-unit-tested (8 tests) `[SEC-26]` path-traversal guard (`isInstalledLocale()`) with only an implicit `file_exists()`/`is_readable()` gate as replacement. Not a new regression — every other `Lang::load()` call site already relies on the same implicit gate — but the replacement tests on `PluginRegistry`/`ThemeRegistry` must explicitly cover a crafted-locale traversal attempt, not just a happy path.

Once this lands, darkroom's own `boot()` needs **zero** language-loading code — dropping `theme.po` in place is sufficient. Effort: **medium**, and — because every future theme/plugin benefits — worth landing as its own small core change ahead of or alongside Phase 1 of the darkroom port itself, not folded into darkroom's own `boot()` as a one-off.

### 5.13 Packaging location — **owner decision, concrete recommendation (§7, Q2)**
**Confirmed:** `.gitignore` allowlists no `themes/*` entry that fits a third-party theme; `../piwigo16-themes` is confirmed PEM-fixture data (137 scraped entries pointing at real piwigo.org URLs, used only via `.env`/`.env.test` to exercise `PemCatalog`'s fetch/filter logic) — **not** a real port destination, and darkroom itself is already present in it as one of those scraped fixture entries.
**Recommendation:** a new sibling repo (e.g. `darktorres/piwigo17-themes`) hosting real v17-contract `theme.json` directories plus a root `manifest.json` in the exact shape `PemCatalog` already consumes, wired via `PIWIGO_ALT_THEMES_PEM_URL` in production — the same shape as `elegant`/`modus`/`smartpocket`. The fetch/extract mechanism needs **zero new application code**; this is purely a packaging/CI/release-process commitment. Effort: **medium** (process, not code).

### 5.14 New: a real "page-header state" event — surfaced this round (§7, Q16)
**Not in the original report.** `checkIfHomepage()`/`stripBreadcrumbs()` were proposed to map onto `Page\Event\PageHeaderRendering`/`PageHeaderRendered` — confirmed this round that both are genuinely-dispatched (15+ real call sites) but are structurally **empty marker classes** with zero properties; `PageHeaderRenderer::prepareContext()` never computes `is_homepage` at all today.
**Proposal:** a new, later, richer event fired from `GalleryController` after `SectionContext` is resolved, carrying real typed `is_homepage`/section/item-count properties — following the same typed-payload-event convention already established by `IndexThumbnailsRendering`/`BlockManagerApply`. This is a genuine new capability gap this analysis surfaced, not something forceable onto the two existing marker events. Effort: **medium**.

---

## 6. Recommend dropping or replacing

| Item | Source | Replace with | Why |
|---|---|---|---|
| Bootstrap itself — CSS and JS, any version | `scss/bootstrap-darkroom.scss`, `node_modules/bootstrap@4.3.1` | hand-authored plain CSS + native TS components | Explicit project-owner call: no framework dependency, ever |
| `admin/index.php` + `index.php` stubs | both | nothing | No theme subdirectory is directly web-reachable in this fork |
| `obsolete.list` | — | nothing | `ExtensionInterface::update()` is a code hook, not a file-manifest diff |
| `pem_metadata.txt` | — | nothing | New PEM mechanism uses a static `manifest.json`, no per-file provenance |
| `_slick_js.tpl` + slick-carousel library | — | native CSS scroll-snap + small TS class (§5.5) | Unmaintained fork; the job doesn't need a full carousel library |
| `_plugin_fixes_js.tpl` (181 lines) | — | nothing, until/unless a referenced plugin is ported | Reshapes output of 11 plugins, none of which exist on piwigo17-rewrite |
| `http_scheme.tpl` | — | a canonical-URL property from PSR-7 `Uri` | Manually re-derives HTTPS from raw superglobals in a template |
| `contact_form-wip.tpl` | — | nothing | Filename admits unfinished upstream work; depends on plugins that don't exist |
| `search.tpl` + Selectize (§5.9, §5.6) | — | nothing — the existing `search_filters.inc.latte` refine bar is a strict superset | Confirmed this round: darkroom's page offers strictly less than what already ships |
| Third-party-plugin-override template family (§5.10) | `button_user_collections_*`, `stuffs_*`, `menu_templates/*`, `language_switch_flags.tpl` | nothing — `BlockManager` already hides empty blocks generically | Confirmed this round: no shim is architecturally necessary, not just deferred |
| ~30 bootswatch/material color skins (78MB precompiled CSS) | `scss/bootswatch/*`, `scss/material/*` | one fixed palette, as CSS custom properties | Confirmed this round: 21/22 bootswatch variants are thin re-export chains with zero original design content |
| jQuery-Touch-Events + jquery-migrate | `node_modules/` | native touch/pointer event listeners | Redundant once jQuery and PhotoSwipe v5 (own gesture handling) are adopted |
| `jquery.cookie.js` | theme's own JS | `themes/default/js/vendor/cookie.ts` (already exists) | Fully superseded already |
| `jquery.awesomeCloud.js` (~900-line canvas cloud renderer) | theme's own JS | core's flat `#fullTagCloud` | Solves the same visual goal at a fraction of the cost |
| `jquery.equalheights.js` | theme's own JS | CSS Grid / Flexbox `align-items: stretch` | Fully obsolete on the target's evergreen browser floor |

---

## 7. Status of the original 20 open questions

Question 1 (Bootstrap vendoring) was resolved in a prior pass — see §1. Questions 2–20 were worked through in a dedicated follow-up pass; **full detail, evidence, and corrections are in §7, the resolutions ledger.** Status at a glance:

| # | Question | Status |
|---|---|---|
| 2 | Packaging location | Owner decision — recommendation: sibling repo + PEM mirror (§5.13) |
| 3 | Settings-page shape | **Resolved** — server-side `?tab=` via `ExtensionTabRequest` (§5.1) |
| 4 | Skin/palette scope | **Resolved** — one fixed palette; dissolves §5.2 | 
| 5 | Lightbox strategy | **Resolved** — PhotoSwipe v5 as shared `vendor/photoswipe.ts` (§5.8) |
| 6 | Advanced search page | Owner decision — recommendation: drop entirely (§5.9) |
| 7 | Front-end community upload | Owner decision — reclassified as a net-new core project, not a theme item (§5.11) |
| 8 | Third-party-plugin-override templates | **Resolved** — dropped, zero shim needed (§5.10) |
| 9 | Picture-info skin count | **Resolved** — cards, corrected rationale (§4) |
| 10 | Theme translation load timing | **Verified fact** — boot()-time works for themes (§5.12) |
| 11 | Theme/plugin l10n auto-load design | **Resolved** — `ThemeRegistry` direct load, `PluginRegistry` via `LoadingLang` event (§5.12) |
| 12 | Mutable `file_ext` on `CurrentConfig` | **Verified fact** — yes, works as originally sketched (§3a) |
| 13 | `BlockManagerApply` payload capability | **Verified fact** — `hideBlock()` directly available (§3a) |
| 14 | Session-flag namespacing | **Verified fact — original proposal was WRONG**, needs a new method (§3a) |
| 15 | `IndexThumbnailsRendering` dispatch | **Verified fact** — real dispatch site, carries a start offset (§3a) |
| 16 | `PageHeaderRendering`/`Rendered` payload | **Verified fact — both are empty markers**, new event needed (§5.14) |
| 17 | Bootstrap 4→5 rename ownership | **Moot** — no framework vendored at all (§1) |
| 18 | `!important` resolution policy | Resolved by precedent (provisional, not independently fact-checked) — case-by-case fixes (§3c) |
| 19 | Font Awesome glyph strategy | Resolved by precedent (provisional, not independently fact-checked) — fold into `photography-icons` (§4) |
| 20 | Vite vs. Smarty combine semantics | **Verified fact, with a correction** — `AssetContribution`/`PageAssets` is the real mechanism; a fabricated quote in an earlier pass has been removed (§3c) |

---

## 8. Suggested phased execution order

Following this repo's convention of small, git-committed, independently-verifiable phases, foundational pieces first.

**Phase 0 — Decision gate (no code).** Resolve the 3 remaining owner decisions: packaging location (§5.13), advanced search (§5.9), front-end upload (§5.11). Everything else is now a resolved engineering decision (§7) and can proceed without further sign-off.

**Phase 1 — Shared infrastructure this port surfaces but isn't specific to it.** Land ahead of or alongside the theme itself, since other extensions benefit:
- Generalize theme/plugin translation auto-loading (§5.12): delete `LangService::loadLanguageForPlugin()`/`isInstalledLocale()`, wire `ThemeRegistry`/`PluginRegistry` as designed, with the 3 corrections applied and new traversal-attempt test coverage.
- `themes/default/js/vendor/photoswipe.ts` (§5.8) as a shared lightbox capability, following `colorbox.ts`'s pattern.
- The new page-header-state PSR-14 event (§5.14).
- The one new narrow `ExtensionContext` session method for the legacy-shared `show_metadata` flag (§3a).

**Phase 2 — Manifest + `ExtensionInterface` skeleton.** `theme.json`, a `Theme.php` implementing `ExtensionInterface`: static field mapping, `boot()`-time `file_ext`/session mutations (now both confirmed workable), the legacy-hook `subscribedEvents()` registration wired to the now-confirmed real event dispatch sites, with a bare unstyled `layout.latte` shell. Verify: theme appears in admin's theme switcher, pages render unstyled.

**Phase 3 — Config VO + settings page.** Typed `Config` VO with `getSetting`/`setSetting` persistence, `SettingsPageInterface` implementor using the now-resolved server-side `?tab=` shape (§5.1) following `ConfigurationSubController`'s pattern, `settings.latte`+`about.latte`. High-risk and foundational — establishes a pattern with zero other precedent, and later phases need real config values to branch on.

**Phase 4 — CSS foundation.** Hand-author plain CSS from scratch — a small Grid/Flexbox layout system, darkroom's own custom design work reworked onto the ~10-role custom-property palette from §1, Vite wiring via `AssetContribution`, every `!important` resolved with a real specificity fix (after the dedicated audit pass §3c/§7, Q18 calls for). The largest single phase in the plan.

**Phase 5 — Core page templates.** The bulk of small/trivial near-1:1 restyles: `layout.latte`, menubar family, infos_errors/navigation_bar/notification/about/redirect/profile, password/identification/register, comments, tags (flat-cloud only), thumbnails, mainpage_categories, month_calendar, profile_content.

**Phase 6 — Index + picture pages.** `index.tpl`, `picture.tpl`/`picture_nav.tpl`, `picture_content.tpl` (+ video branch), `picture_nav_buttons.tpl`, and the picture-info consolidation (one shared partial, cards layout, core's `dt`/`dd` convention per §4/§7, Q9). Depends on Phase 3's config values and Phase 1's PhotoSwipe wrapper.

**Phase 7 — JS interactivity.** Hand-written native TS classes replacing every Bootstrap JS behavior (navbar collapse, dropdown, modal, carousel), `rating.ts` reuse for the star widget, grid/list-view toggle, thumbnail filmstrip via scroll-snap, the confirmed-workable legacy-hook handlers (`exifReplacements`/`hideMenus` via `BlockManager::hideBlock()`/`checkIfHomepage` via the new Phase 1 event), and the `getAllThumbnailsInCategory` rearchitecture (§5.3) — large enough for its own sub-phase and commit.

**Phase 8 — i18n.** Run `php-to-po-fn.php` over all 26 locales, producing `theme.po` per locale — with Phase 1's loader generalization already landed, the theme's own `boot()` needs zero language-loading code at all.

**Phase 9 — Packaging + legacy cleanup.** Execute the Phase 0 packaging decision, strip `obsolete.list`/`pem_metadata.txt`/index stubs/`showUpgradeWarning()`, remove the dropped library dependencies, confirm nothing references them.

**Phase 10 — Out of scope for v1, confirmed not merely deferred.** The third-party-plugin-override template family (§5.10, zero shim needed — architecturally resolved, not blocked on anything) and the advanced search page (§5.9, recommended dropped outright) need no further work regardless of Phase 0's outcome on them. Front-end community upload (§5.11), if the owner decides to pursue it, is a separate multi-phase core project sequenced independently of this port, not a sub-phase of it.
