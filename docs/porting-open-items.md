# Plugin & Theme Porting — Open Items

A working punch list, not a narrative doc. Everything here was revalidated
2026-09-12 directly against real current source (`piwigo17-rewrite`,
`../piwigo16-plugins`, `../piwigo16-themes`) — not just re-read from the
source docs linked in each section. Check an item off by fixing it *and*
updating the source doc it came from (`CLAUDE.md` "Known gaps" section,
or the relevant `*-port-analysis.md`'s own Tier/verdict entry).

Source docs:
- `../piwigo16-plugins/CLAUDE.md` + `PLUGIN_PORTING_AUDIT.md`
- `../piwigo16-themes/CLAUDE.md` + `THEME_PORTING_AUDIT.md`
- `docs/plugin-porting/gdthumb-port-analysis.md`
- `docs/plugin-porting/rv-tscroller-port-analysis.md`
- `docs/theme-porting/modus-port-analysis.md` (substantively complete — 1 item left, below)
- `docs/theme-porting/bootstrap-darkroom-port-analysis.md` (in progress — P61 Phases 1-7 done, 8-10 open)

---

## Plugins

### Core prerequisites blocking a specific, already-designed port

- [x] ~~**gdThumb** — new `GetCategoryDerivativeParams` event~~ — **done 2026-09-12**: `src/Piwigo/Image/Event/GetCategoryDerivativeParams.php` (co-located with its sibling, not `Category\Event\` as first proposed), dispatched from `CategoryCatsRenderer.php`. Also closed a test-coverage gap found along the way: neither renderer previously asserted a handler's derivative-size override actually survives to the result — both now covered and mutation-verified. See `gdthumb-port-analysis.md` §3a.
- [x] ~~**gdThumb** — `public/plugins` symlink + Caddyfile carve-out + Apache vhost rule~~ — **done 2026-09-12**: symlink added, `/plugins/*` removed from `@deniedRelocated` (no Apache vhost change was actually needed — it never had an equivalent deny rule to begin with). Scope grew by one real item found along the way: `themes/` was already letting its own PHP source (e.g. `Theme.php`) execute via direct URL with zero guard — closed for both `themes/` and `plugins/` at once via a new `.php`-under-either-tree deny in both `docker/Caddyfile` and `public/.htaccess`, verified against real local Apache + FrankenPHP/Caddy instances, plus updated `.github/workflows/ci.yml` assertions. See §3b.
- [x] ~~**rv_tscroller** — extract `SectionPopulator::resolveSectionItems()` (a new explicit-parameter, side-effect-free method) + a new `SectionItemQuery` VO, so page-1 rendering and the plugin's own AJAX route share one source of truth.~~ — **done 2026-09-13**: `resolveSectionItems()` + `resolveOrderBy()` landed on `SectionPopulator`, `SectionItemQuery` VO with named constructors (`src/Piwigo/Section/SectionItemQuery.php`). Scope grew by two real items found while landing it: `SectionRepository::findTopByHitsImageIds()`/`findTopRatedImageIds()` widened to `?int $limit = null` (whole-corpus pagination for MostVisited/BestRated, a real production behavior change for every install, not just the plugin — their link titles' now-inaccurate `{$topNumber}` prefix was also dropped), and a pre-existing test gap in `testPopulateAppliesTheCategorysOwnCustomImageOrder()` (asserted item *count* only, never real ordering) was found via mutation-testing the new `resolveOrderBy()` extraction and fixed. `Section::Search`/`RecentCats` are deliberately NOT covered by `resolveSectionItems()` — see `rv-tscroller-port-analysis.md` §3 for why.

### Framework-level gaps (block any future plugin, not just the 2 above)

- [ ] No general raw-DB read/write surface. 170/406 plugins call `pwg_query()` in their maintain hook, 97/406 call it live in `main.inc.php`. Only narrow `ExtensionContext::images()`/`users()`/`themes()` (read) and `imagesWrite()`/`categoriesWrite()` (write) facades exist — nothing for e.g. "fetch an arbitrary image row by id."
- [ ] No Smarty compile-time-hook equivalent (`set_prefilter()`/`set_extents()`) — this fork compiles Latte, nothing hooks into that compile step. Affects: `custom_download_link_14.a`, `de_activate_all_languages_13.0.a`, `download_formats_buttons_16.a`, `edit_filename_12.a`, `hide_title_on_browse_path_12.a`, `NoPassword`, `pAnchor_0.5b`, `tag_groups_14.f`.
- [ ] No `ExtensionContext` accessor for a *shared* core session key beyond the 3 already covered (`coreIndexDeriv()`/`corePictureDeriv()`/`isShowMetadataEnabled()`). Real example: `bot_protection/admin/bot_protection_admin.php` writes into `$_SESSION['page_infos']`, a shared core key with no named accessor.
- [ ] No request/query-parameter accessor reachable from `boot()` or an early-firing event — blocks anything needing `$_GET` at plugin-init time.

### Bundled/first-party plugin targets — not started

- [ ] `AdminTools_16.3.0` (unstarted in `piwigo17-rewrite/plugins/`, confirmed empty of it)
- [ ] `LocalFilesEditor_16.3.0`
- [ ] `TakeATour_16.3.0`

### Open design/human decisions

- [x] ~~gdThumb license~~ — **decided**: `GPL-2.0-or-later`, per `changelog.txt`'s own FSF boilerplate text. No SPDX identifier exists, but the boilerplate is the real, deliberate call — use it as-is, not a placeholder pending further confirmation.
- [x] ~~rv_tscroller license~~ — **decided 2026-09-13**: `GPL-2.0-or-later`, by policy (officially hosted under the `github.com/Piwigo` org, distributed via the official extension directory, tightly-coupled derivative of Piwigo core's own GPL-2.0-or-later code) — checked upstream directly first (`gh api repos/Piwigo/piwigo-tscroller`) and confirmed genuinely zero license text exists anywhere, unlike gdThumb's ambiguous-but-present boilerplate. See `rv-tscroller-port-analysis.md` §7 item 1.
- [x] ~~rv_tscroller — exact `SectionItemQuery` VO field shape~~ — **settled 2026-09-13** as part of landing `SectionPopulator::resolveSectionItems()` (see the "Core prerequisites" section above): named constructors (`categories()`, `combinedCategories()`, `flatCategory()`, `wholeGalleryFlat()`, `tags()`, `favorites()`, `recentPics()`, `mostVisited()`, `bestRated()`, `imageList()`), not a flat boolean-soup shape. This settles the *query-identity* half only — the separate "which page" (start/total/per-page) wire contract between the TS client and the plugin's own route is still open, not covered by this VO.
- [x] ~~rv_tscroller — fragment-rendering primitive~~ — **resolved 2026-09-13, no new code needed**: `Renderer::render(new ThumbnailsView(...))` is already a genuine bare-fragment render (`thumbnails.latte` has no `{layout}`) — the literal same call `GalleryController` makes for page-1 rendering. Locked in with a new mutation-verified regression test (`CategoryDefaultRendererTest::testRenderedThumbnailsHtmlIsABareFragmentWithNoPageChrome()`) rather than left as a one-time manual read. See `rv-tscroller-port-analysis.md` §5/§7 item 3.
- [x] ~~rv_tscroller — sequencing: land the `SectionPopulator` extraction as its own standalone commit first, or as the first commit of the plugin-port branch?~~ — **done 2026-09-13**: landed standalone, ahead of the plugin.

### Not started at all

- [ ] gdThumb implementation — design complete (`gdthumb-port-analysis.md`), zero code written.
- [ ] rv_tscroller implementation — design complete (`rv-tscroller-port-analysis.md`), zero code written.
- [ ] The remaining ~400 plugins beyond these two named targets — only static-pattern audit data exists (`PLUGIN_PORTING_AUDIT.md`), no per-plugin design/implementation pass has been done.

---

## Themes

### modus (P29.6) — substantively complete; one remaining framework gap

- [ ] Config-value migration format for a real 16.x→17.x upgrade. `ConfigService::confGetParam()`/`confUpdateParam()` do a bare `json_decode()`/`json_encode()` round-trip with **no fallback for a legacy PHP `serialize()`d value**. A real upgraded install's admin would silently see defaults instead of their saved settings the first time they open *any* ported extension's settings page. Framework-level, affects every extension with persisted settings — not modus-specific, and not yet resolved anywhere.

### bootstrap_darkroom (P61) — in progress

- [ ] Icon/font strategy — fold a Font Awesome subset into the existing `photography-icons` font (or an alternative). Confirmed zero icon/font/vendor files exist yet anywhere in the real `bootstrap_darkroom_17.0.0/` source.
- [ ] Packaging: a real `manifest.json` entry (`../piwigo16-themes/manifest.json` has no `bootstrap_darkroom_17.0.0` key yet) + a packaged zip (none exists on disk). The *directory location* question is already settled — it lives directly in `../piwigo16-themes`, no new sibling repo — only the bookkeeping step remains.
- [ ] Phase 8 — i18n: convert all 26 locale `theme.lang.php` files to `.po` via `tools/i18n/php-to-po-fn.php`.
- [ ] Phase 9 — packaging + legacy cleanup: strip `obsolete.list`/`pem_metadata.txt`/index stubs/`showUpgradeWarning()`, remove the dropped library dependencies (Bootstrap, slick-carousel, jQuery-Touch-Events, jquery-migrate, jquery.awesomeCloud, jquery.equalheights), confirm nothing references them.
- [ ] `docs/PLAN.md`'s own P61 status line is internally inconsistent right now — its detailed narrative says Phase 7-A and 7-B ("thumbnail carousel", "real PhotoSwipe click-to-open wiring") are done, but the same entry's trailing sentence still says "Phases 7-10 not started." Confirmed on disk that Phase 7's real files (`BootstrapDarkroomCarouselItem.php`, `js/gallery.ts`, `template/picture_carousel.latte`) do exist — the trailing sentence is the stale half. Not fixed here since another session is actively editing that file; whoever picks this up should reconcile it.

### Bundled/first-party theme targets — not started

- [ ] `elegant_16.3.0`
- [ ] `smartpocket_16.3.0`

### Framework-level gaps (theme side — same family as plugins)

- [ ] No general raw-DB surface — 22/137 themes call `pwg_query()` in `admin/maintain.inc.php`, 7/137 live in `themeconf.inc.php`.
- [ ] No *generic* Smarty-compile-hook equivalent. Modus's own specific 4 DOM-edit prefilter transforms got resolved via the `{block}`-refactor of `themes/default` (P29.6-L..T) — a real, reusable pattern for "child theme needs to edit a fixed seam in a parent template" — but that's a per-instance design applied once, not a capability any future theme can assume exists for an arbitrary Smarty compile hook.

### Legacy themes with no viable porting path at all (structural mismatches, not missing accessors)

- [ ] **13 themes** — legacy pre-2.5 "template family" shape (`'template' => 'yoga'` + `'template_dir'`, depends on a shared template package this fork doesn't have): `Bubble_1.0.5`, `Csn_1.0.2`, `Terra_1.0.4`, `WinterForest_1.0.2`, `blacknblue`, `darkbrown`, `floPure_v2_1_1`, `hk-darkblue-left_1.0`, `pwg_template_hpsam_1.7.c`, `pwg_theme_hpsam-bleu-3`, `rainbow_beta2`, `yoga-grey`, `zouz`.
- [ ] **5 themes** — theme packs bundling multiple sub-themes in one directory, each real sub-theme needing its own separate port: `Borealis_1.0.1` (6 color variants), `Pack_D1_1_0_4`, `Pack_blue_v2_0_5`, `Pack_grey_v2_0_5` (2-3 sub-themes each), `yoga_os_2` (an OS_* family pack).
- [ ] **10 themes** — PhpWebGallery-era, not real installable Piwigo 2.x+ themes at all (predate `themeconf.inc.php` entirely): `mixmax-1.0.0`, `phpwebgallery-jillij-v7`, `pwg-JoesYoga-v1.0`, `pwg_template_hpsam-beige-2`, `pwg_template_hpsam-bleu-2`, `pwg_template_phpBB-1`, `pwg_template_wood-4`, `yoga-black`, `yoga-roomy-rev31`, plus the `thumbnails/` PEM-catalog screenshot folder (not a real extension at all).

### Not started at all

- [ ] The remaining ~134 themes beyond modus/darkroom — only static-pattern audit data exists (`THEME_PORTING_AUDIT.md`), no per-theme design/implementation pass has been done.
