# RV Thumb Scroller → piwigo17-rewrite: Port Analysis

Source: `/home/torres/piwigo16-plugins/rv_tscroller_12.a` (v12.a, author `rvelices`, upstream `github.com/Piwigo/piwigo-tscroller`, no SPDX/license marker anywhere in the source)
Target: `/home/torres/piwigo17-rewrite` (plugin lands in `/home/torres/piwigo16-plugins/rv_tscroller_17.0.0/`, per the porting convention in `../piwigo16-plugins/CLAUDE.md`)

Prior attempt: flagged `stopped:other-translation-gap` in `../piwigo16-plugins/PLUGIN_PORTING_AUDIT.md`'s 2026-08-14 migration run (one of 26/93 "genuinely clean" plugins that turned out not to be). This document is the real gap analysis that entry never got.

## 1. Executive summary

RV Thumb Scroller is an infinite-scroll enhancement for the index/category listing page: as the user scrolls, it AJAX-fetches the next batch of thumbnails and appends them, instead of a "next page" click. Legacy implements this entirely inside the monolithic `index.php` request — it hijacks the same route via `$_GET['rvts']`, mid-request swaps in an AJAX-only code path, and short-circuits with Smarty's `pparse()` + `exit` to emit a bare HTML fragment.

**Nearly everything the plugin's non-AJAX half needs — session storage, URL building, translation, the four legacy hooks it listens on, script/inline-JS injection — has a clean, verified 1:1 equivalent in piwigo17-rewrite already** (§2). The real design work is entirely in the AJAX "load more" mechanism, because the mechanism it depends on (`pparse()`, raw `$_GET` hijack of the index route, Smarty script-loader introspection) doesn't exist anymore, on purpose (§5).

Two design passes got this wrong before landing on the validated architecture below, and it's worth recording why, since both wrong turns are easy mistakes to repeat:

1. **First pass:** reuse `SectionPopulator`/`CategoryDefaultRenderer` internals directly and add a new core mutable event (`SectionNbImagePageResolving`) purely to replicate legacy's session-persisted adaptive page-size heuristic. **Wrong** — this was optimizing for 1:1 behavioral fidelity to a legacy implementation detail (a round-trip-avoidance trick from a slow synchronous era) rather than asking what the feature needs in this codebase. Dropped.
2. **Second pass:** have the plugin's frontend TypeScript call piwigo17-rewrite's existing general-purpose `GET /api/v1/categories/images` / `GET /api/v1/tags/images` JSON endpoints directly, rendering thumbnail markup client-side. **Wrong on two independent grounds**, both confirmed by direct code reading, not inference:
   - **Extensibility regression.** `thumbnails.latte`'s overlay slots (`Template::addThumbnailOverlay()`), all three `IndexThumbnails*` events, and `CategoryDefaultRenderer`'s own per-section business rules (best-rated/most-visited name prefixes, `RecentIconResolver`, comment/hit-count gating) are wired exclusively through the PHP render path. The JSON endpoints carry none of it. Any other plugin using the already-shipped overlay API would work on page 1 and silently vanish from every infinite-scrolled batch.
   - **Wrong architecture per this fork's own documented design intent.** `ApiRouteProviderInterface`'s reserved `/api/v1/plugin-routes/{id}/` namespace exists specifically so a plugin owns a contract core won't casually reshape — the documented replacement for the deleted legacy `ws_add_methods` hook. A plugin depending on a core general-purpose endpoint's shape (zero stability promise to third parties) is the opposite of that. There is zero existing precedent for plugin-to-core-API consumption in this codebase — the only real usage of `/api/v1/*` from theme JS is core's own bundled theme code calling core's own API.

**Validated architecture:** the plugin registers its own routes under `ApiRouteProviderInterface`'s reserved `/api/v1/plugin-routes/rv_tscroller/...` namespace. Each route composes the same already-DI-injectable core services `GalleryController` itself uses (`CategoryDefaultRenderer`, `CategoryCatsRenderer`, `SectionRepository`, `TagService`, `SearchService`, `UserService`, `PermissionService`, `CalendarRenderer`) to render **server-side HTML fragments identical to a page-1 load** — not client-reconstructed markup. Because it is the literal same render path, overlay/event/permission parity hold *by construction*, not by re-verification (§3, §4).

**Every legacy section type resolves to an already-real, already-DI-reachable service call — nothing is deferred to a later pass.** Categories, flat mode, combined_categories, tags, quick search, saved/advanced search, favorites, recent_pics, best-rated, most-visited, calendar (leaf-bucket and grid-navigation), and recent_cats (album tiles) were traced individually down to real constructors/method signatures, not just route existence (§4, §7 table).

**One real core change is required**, and it's small and mechanical: extract `SectionPopulator`'s per-section item-resolution dispatch (currently inline in `populate()`, roughly lines 264–700) into a new explicit-parameter, side-effect-free method, so the real page-1 render and the plugin's route share one source of truth instead of the plugin permanently duplicating and drifting from core's dispatch logic (§3). This is the only piece of `piwigo17-rewrite` core that needs to change; everything else is plugin-side composition against contracts that already exist.

---

## 2. Clean ports

Verified against real dispatch sites and constructors, not docs.

| Legacy | Target | Note |
|---|---|---|
| `pwg_get_session_var('rvts_mult', 1)` / `pwg_set_session_var(...)` | `ExtensionContext::session()` → `ExtensionSession::get()`/`set()` | Auto-namespaced per plugin (`ext_rv_tscroller_rvts_mult`) — but see §6, this key may not need to exist at all in the redesign |
| `duplicate_index_url(['start'=>N])` / `add_url_params($url, [...])` | `ExtensionContext::url()` → `UrlServiceInterface::duplicateIndexUrl()`/`addUrlParams()` | Identical signatures |
| `get_root_url()` / `get_absolute_root_url(false)` | `UrlServiceInterface::getRootUrl()`/`getAbsoluteRootUrl()` | Same call shape; `getRootUrl()` needs `SectionContext` already registered (i.e. call after `SectionPopulator::populate()` has run) or it falls back to a relative-path computation |
| `script_basename()` | `PageFilterHelper::scriptBasename(CurrentConfig)` | Same fallback chain, DI'd `CurrentConfig` instead of a global |
| `load_language('lang', dirname(__FILE__).'/')` / `l10n()` | `ExtensionContext::lang()->load('lang', <pluginDir>)` | `Lang::load()` has the identical `$dirname . 'language/'` shape |
| `loc_begin_index` | `Piwigo\Controller\Event\IndexRendering` (notify) | Dispatched `GalleryController` near the top of index rendering |
| `loc_end_index` | `Piwigo\Controller\Event\IndexRendered` (notify, gained 3 nullable fields: `categoryId`/`categoryName`/`categoryComment`) | Dispatched near the end of index rendering, after thumbnails/pagination are resolved |
| `loc_end_index_thumbnails` (filter) | `Piwigo\Category\Event\IndexThumbnailsRendered` (filter, mutable `tplThumbnailsVar`) | **Must mutate `$event->tplThumbnailsVar` in place and return void** — this fork's `dispatch()` never reads a handler's return value; the legacy `return $thumbs;` idiom silently no-ops if translated literally |
| `$template->func_combine_script([...])` | `AssetContribution::script($id, $path, LoadMode, dependsOn, version)` via the `GetPageAssets` filter event | Dependency-ordering story is intact; see §6 for the `require: 'jquery'` anchor, which no longer resolves to anything |
| `$template->block_footer_script(null, "...")` | `AssetContribution::inlineScript()` | Purpose-built for exactly this (a small inline JS config block); **not** `concat()`, which targets a named content zone, not the footer script area |

---

## 3. Needs adaptation in piwigo17-rewrite

One real change.

### `SectionPopulator::resolveSectionItems()` extraction

**Problem.** `SectionPopulator::populate()` is the modern, section-agnostic replacement for legacy's `$page['items']` population — one method, one `elseif` branch per section type (Categories/flat/combined_categories at ~line 200-386, Tags ~440-483, Search ~484-555, Favorites ~511-528 area, RecentPics/BestRated/MostVisited ~556-628, chronology ~654-688). It cannot be called directly from the plugin's own AJAX route:

- It derives section identity by parsing `$_SERVER['PATH_INFO']` directly inside `SectionInitializer::parse()` — not a pure function callable with an explicit parameter set; for a request hitting `/api/v1/plugin-routes/rv_tscroller/...`, the current request's own URL is simply the wrong input.
- It carries real, page-load-only side effects unsafe to re-run on every scroll tick: a 301 permalink redirect, a category-restriction access-denied redirect, an `EventDispatcher->dispatch(RenderCategoryDescription)`, and a session write (`unsetSessionVar('image_order')`).

**Fix.** Extract the per-section item-id-resolution branches into a new, explicit-parameter, side-effect-free public method:

```php
SectionPopulator::resolveSectionItems(Section $section, SectionItemQuery $params): array
```

where `SectionItemQuery` is a small new value object carrying already-known section identity (category id(s) + flat/combined flags, tag ids + mode, `searchId`, chronology field/date/style/view) — no URL parsing, no redirects, no session writes, no event dispatch. `populate()` itself is refactored to call this new method internally instead of holding the logic inline, so page-1 rendering and the plugin's route share exactly one source of truth. If a future core patch changes one of these branches (a new filter dimension, a bugfix to a condition), both paths pick it up automatically — the alternative (the plugin duplicating this dispatch logic itself) is a permanent, silent drift risk.

This is a mechanical extract-method refactor plus one small VO — not new query logic, not a new REST surface.

**Bundled decision, not an afterthought:** as part of this extraction, make explicit what's currently an accident of query construction — whether BestRated/MostVisited/whole-gallery-flat-mode should page through the **entire** permission-filtered corpus (legacy capped at a fixed `CurrentConfig::topNumber`). Recommendation: page through the whole corpus — a real, deliberate improvement over the legacy cap, made an explicit tested contract of `resolveSectionItems()` rather than an incidental side effect.

---

## 4. Needs adaptation in the plugin itself (`rv_tscroller_17.0.0`)

### 4a. Route + rendering architecture

Register `ApiRouteProviderInterface::registerApiRoutes()` (manifest: `hasApiRoutes: true`). Route(s) under `/api/v1/plugin-routes/rv_tscroller/...`, `_controller` resolved via plain DI (confirmed: not via `ExtensionContext` — a plugin route can inject `SectionPopulator`, `CategoryDefaultRenderer`, `CategoryCatsRenderer`, `PermissionService`, etc. exactly like a core controller).

Per-request flow for the "load more" route:
1. Read section identity + pagination state from query params (mirroring what legacy read off `$page`/`$_GET['rvts']`/`$_GET['adj']`) — the client echoes back whatever section context it captured from the initial page-1 load.
2. Call `SectionPopulator::resolveSectionItems()` (§3) to get the item-id list for that section/query.
3. Call `CategoryDefaultRenderer::render(items, start, nbImagePage, section)` (or `CategoryCatsRenderer::render(...)` for the RecentCats album-tile case) — the exact same call `GalleryController` makes, dispatching the same `IndexThumbnailsSelected`/`IndexThumbnailsRendering`/`IndexThumbnailsRendered` events and applying the same best-rated/most-visited/`RecentIconResolver`/comment-hit-gating business rules.
4. Build a `ThumbnailsView` from the result (`ThumbnailsView`'s constructor is a plain public `readonly` class — same composition `GalleryController` does) plus `$template->thumbnailOverlays()`.
5. Render and return an HTML fragment response via `ResponseFactory::html()`.

Every legacy section type maps to an already-real, already-DI-reachable service, verified directly (constructors/signatures read, not assumed):

| Section type | Item-id resolution — verified real | New core code needed |
|---|---|---|
| Categories (single/non-recursive) | `CategoryRepository::findImageIdsForCategories()` (same method `CategoryImagesController` already uses) | None |
| Flat mode (single category) | Same, `recursive=true` / uppercats branch | None |
| combined_categories | Same, multiple `catIds` | None |
| Tags | `TagService::getImageIdsForTags()` (same call site `TagImagesController` already uses) | None |
| Search (quick) | `SearchService::getQuickSearchResults()` (same as `ImageSearchController`) | None |
| Search (saved/advanced, by `searchId`) | `SearchService::getSearchResults($searchId, ...)` — real method, just never wrapped by a public GET; plugin calls it directly | None |
| Favorites | `UserService::getVisibleFavoriteImages()` (same as `FavoriteListController`) | None |
| RecentPics | `UserService::getRecentPhotosCondition()` + `SectionRepository::findRecentImageIds()` — explicit `SqlCondition`/`orderBySql` params, no URL/session coupling | None |
| BestRated | `SectionRepository::findTopRatedImageIds(SqlCondition, int $limit)` — explicit params | None (whole-corpus decision, §3) |
| MostVisited | `SectionRepository::findTopByHitsImageIds(SqlCondition, int $limit)` — same shape | None (whole-corpus decision, §3) |
| Calendar leaf-bucket listing | Shared `fMinDateAvailable`/`fMaxDateAvailable`/`fMinDateCreated`/`fMaxDateCreated` condition-building already common to the existing image-listing endpoints' `ImageFilterCriteriaBuilder` pipeline | None |
| Calendar grid navigation (year/month/day drill-down counts) | `CalendarRenderer`/`CalendarService` — verified plain constructor-DI classes | None |
| RecentCats (album tiles, not images) | `CategoryCatsRenderer::render(Section, ?Category, int $startcat)` — verified explicit-parameter, no URL coupling (confirmed from `GalleryController`'s own call site) | None |

### 4b. Frontend rewrite

`rv_tscroller.js` is a jQuery plugin end-to-end (`.ajax`, `.fn.extend`, event triggers). jQuery was removed from core (P49-C); `require: 'jquery'` no longer resolves to anything registered. Needs a real vanilla-TS rewrite, following the pattern already established by `themes/*/js/*.ts` (real ES6 classes, no jQuery) — not a shimmed jQuery include. Behavior to preserve: scroll-triggered "load more" (`checkAutoScroll`), scroll-up support for deep-linked mid-gallery loads (`loadUp`), history `replaceState` on scroll, hiding the traditional pagination bar, and the "see remaining N photos" fallback link past a size threshold. The `String.fromCharCode` URL-obfuscation hack (a defensive measure against a specific 2010s-era Googlebot behavior) is dropped — see §6.

### 4c. Initial-page-render hook

`IndexRendered`/`IndexThumbnailsRendered` subscription decides whether to activate infinite scroll at all (total item count vs. currently-shown count) and contributes the TS module + a small inline JS config (section identity, current count, total, per-page size) via `AssetContribution`, exactly like legacy's `on_index_thumbnails()` did via `func_combine_script`/`block_footer_script`.

---

## 5. Rearchitect / no direct equivalent (resolved by the §3–4 redesign, not ported as-is)

These legacy mechanisms have no modern equivalent, and — critically — don't need one, because the redesign in §3/§4 solves the underlying problem differently:

- **`$template->assign('thumbnails', $thumbs); $template->pparse('index_thumbnails'); exit;`** — `Template::pparse()` was deleted outright (P41-D; its last real caller was `InstallWizard.php`). No replacement single-call method exists. Not needed: the plugin's own `ApiRouteProviderInterface` route (§4a) renders through `Renderer::render(View)` and `ResponseFactory::html()` directly, which is the real, live pattern this fork already uses for isolated-fragment rendering (`CommentsController`'s `comment_list.latte` render is a working precedent for a fragment `View` with no `{layout}`), just not yet used standalone as a full HTTP response body.
- **`include(PHPWG_ROOT_PATH.'include/category_default.inc.php')` on an AJAX sub-request** — no single drop-in replacement; superseded entirely by calling `SectionPopulator::resolveSectionItems()` (§3) + `CategoryDefaultRenderer::render()` directly from the plugin's own route, which needs no page-context "rebuild" at all since it never hijacks the index route in the first place.
- **`$template->scriptLoader->get_all()`** (introspecting registered scripts to find an anchor with no dependents) — structurally obsolete, not just unported. The new `AssetContribution`/`PageAssets` model requires statically-known dependency ids at registration time and exposes no runtime introspection into what else has registered (by design — a private collector, not a queryable registry). The specific case this served (chaining onto "whatever async script currently has no dependents") is doubly moot since jQuery itself no longer exists as a registered asset.

One implementation-level detail flagged, not a design gap: whether `Renderer` can render `thumbnails.latte`'s inner `<li>`-repeating loop as a bare partial without triggering `ThumbnailsView`'s full page-asset-assembly pipeline (`HasPageAssets`/`ExposesPageData` are meant for a full page load, not a repeating AJAX fragment). Worst case, the plugin owns a small Latte partial mirroring `thumbnails.latte`'s inner loop — a real, but narrow, implementation task, not an architecture change.

---

## 6. Recommend dropping or replacing

- **Session-persisted adaptive page-size multiplier (`rvts_mult`)** — legacy grows the *initial* full-page thumbnail count based on a per-session-learned value, to reduce round-trips for users who scroll a lot. This was a round-trip-avoidance optimization for a slow synchronous era, not core functionality. The client already knows its own viewport height and already fires an immediate follow-up fetch on mount if the first batch doesn't fill the screen (legacy's own `engage()`/`checkAutoScroll()` does exactly this client-side already). **Drop the server-side heuristic entirely** — no core mutation point is needed for it (this also retires the earlier, now-superseded `SectionNbImagePageResolving` core-event proposal from the first design pass, §1). If a "remember it for next visit" nicety is still wanted, it's a `localStorage` concern client-side, not a server session concern.
- **`String.fromCharCode` AJAX-URL obfuscation** — a defensive hack against a specific era of Googlebot that followed AJAX-style hrefs despite `nofollow`. Under the redesign there is no `<a href>` for a bot to crawl at all (the request is `fetch()`-driven from TS, not a clickable link) — the hack has nothing left to defend.
- **Legacy `topNumber` cap on BestRated/MostVisited** — see §3's bundled decision: replaced with real pagination through the full permission-filtered corpus.

---

## 7. Open questions requiring a decision before real porting work starts

1. **License.** rv_tscroller ships no SPDX identifier or license file anywhere in its source or `manifest.json` entry (unlike `bootstrap_darkroom`, which had real `changelog.txt` FSF boilerplate to anchor a GPL-2.0-or-later decision). Needs a decision before publishing a `_17.0.0` port — check upstream `github.com/Piwigo/piwigo-tscroller` directly for a `LICENSE` file not mirrored into this manifest snapshot.
2. **`SectionItemQuery` VO shape and the client↔route contract.** Exact fields needed to fully describe "which section, which query, which page" round-trip between the TS client and the plugin's route — this mirrors how legacy's JS already carried `$page`-derived state (`start`, `total`, `perPage`, `urlModel`) from the page-1 render into each AJAX call, but needs a real typed shape now rather than ad hoc `$_GET` keys.
3. **Fragment-partial rendering primitive** (§5's flagged implementation detail) — confirm whether `Renderer`/`Template` can render `thumbnails.latte`'s inner loop as a bare partial, or whether the plugin needs and should own a small mirroring Latte partial.
4. **Timing: extract `SectionPopulator::resolveSectionItems()` (§3) as a standalone prerequisite commit, or as the first commit of the plugin-port branch itself?** Either works; doing it standalone first makes the core change reviewable independently of plugin-specific concerns.

---

## 8. Suggested phased execution order

1. `piwigo17-rewrite`: extract `SectionPopulator::resolveSectionItems()` + `SectionItemQuery` VO (§3), with unit tests covering every section-type branch being extracted. No behavior change to `populate()`'s own callers.
2. `piwigo16-plugins/rv_tscroller_17.0.0/`: `plugin.json` (`hasApiRoutes: true`, `id: rv_tscroller`) + `Plugin.php` implementing `ExtensionInterface` + `ApiRouteProviderInterface`, wiring the four clean-port event subscriptions (§2) and the AJAX route(s) (§4a) — categories/tags/flat/combined first (covers the overwhelming majority of real installs), then the remaining section types (§4a table) once the core extraction (step 1) is in place for all of them.
3. Frontend: vanilla-TS rewrite of the scroll/AJAX-append logic (§4b).
4. Repo bookkeeping per `../piwigo16-plugins/CLAUDE.md`: `manifest.json` `rv_tscroller_17.0.0` entry, zip packaging (bare `<id>` top folder, not versioned), thumbnail path carryover if the legacy entry has one.
5. End-to-end verification: install for real against a running `piwigo17-rewrite` instance (fetch, compat filter, download, extract, install, activate, boot, real page render, real scroll interaction) for at minimum categories and tags — not just "PHPStan parses."
