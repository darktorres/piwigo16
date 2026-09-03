# Plan and build history

Phase-by-phase record of `17.x-rewrite`: what was planned, what shipped,
and where the two diverge. This file and `docs/REFERENCE.md` are the only
two planning documents, deliberately — an earlier structure of 18
per-phase files drifted against each other and was consolidated into
these two.

`17.x-rewrite` replays `16.x-rewrite`'s modernization as 55 sequential
backbone phases (P0–P55, in 10 epochs A–J), rebuilt from `origin/16.x`
rather than upgraded in place. Every backend phase is sequenced before
every frontend phase. The work is dual-purpose: a *replay* of work that
has a reference implementation on `16.x-rewrite`, plus *greenfield*
capabilities with no counterpart there.

## How to read this file

- **Present tense is a claim about live code**, checked when the line was
  last edited. Where a claim was cheap to make machine-checkable, it
  carries a `doc-drift-check` marker (invisible when rendered) that
  `composer check:doc-drift` re-runs on every CI build.
- **Commit counts are indicative**, carried forward from when each phase
  landed. They are not re-derived and not worth re-deriving.
- **"Open question"** marks something genuinely unresolved — the intent
  was never recorded, or two sources of truth disagree. It is not a
  to-do; it is a flag that guessing here would be wrong.
- **Detail lives closest to the code.** Where a campaign had its own plan
  file, per-file specs stay there and only the outcome is recorded here.
- **The ~150-row feature comparison against `16.x-rewrite` is gone.**
  Nearly every row mapped to a phase already marked "not started" below,
  and the handful describing landed work is covered in its own phase
  section. It is recoverable from git history as `docs/PLAN-REPLAY.md`,
  as that file existed before the consolidation.

### Known coverage gap

Roughly 550 commits landed between 2026-08-11 and 2026-08-15, across
several concurrent worktrees. The two largest — the WS layer
decomposition and a repo-wide naming campaign — are summarized in Epoch
G below from git history and live code, not from a full audit. Smaller
work in that window (CSRF constructor injection across 36 files, admin
theme/icon fixes, a Rector/ECS modernization pass) is in `git log` only.

## Numbering and commit tags

**Commit-message phase tags do not track this file's numbers.** A tag
records which phase the author was working under at the time; three
successive renumberings have moved the boundaries since. Never infer a
phase from a tag — use the table below.

| Tag or label you'll encounter | What it actually covers | Phase today |
| --- | --- | --- |
| `(p24)`, `(p27)`, `(sql)`, `(di)`, `(lang)` | Post-P23 remediation & hardening | P24 |
| `(p25)` | PHP `mixed`-type elimination, phases 1–2 | P24 |
| `(p31.x)`, `P31.n` | Smarty → Latte conversion | P31 |
| `(p32)` in `style(templates)` | Latte reformat across the tree | P32 |
| `(p33a)`–`(p33h)` | Latte idiomatic sub-items A–H | P33A–H |
| `chore(p32): delete doc/` | A one-off cleanup that borrowed the tag | — |
| `feat(events): A1`–`A6`, "P32 Stage A" in commit bodies/`docs/events-legacy-map.md` | Event system rewrite | P34 |
| `(P25/G19)`, `(P19.n)` | WS layer decomposition into typed handlers | Epoch G / P25 |
| Original plan's "P24 Vite + TypeScript" | Frontend track | P36 / P46 |
| Original plan's "P27 Type correctness" | Merged into remediation | P24 |
| Original plan's "P32 CSS modernization" | CSS architecture | P52 |

Three structural changes produced that drift:

- **The original P27 ("Type correctness + mixed elimination") merged into
  P24.** It is the same class of work as P24's own remediation sub-tracks
  — superglobal and raw-array-offset access, type correctness beyond what
  P0–P23 shipped — not a separable phase. The freed number cascaded a
  one-step shift through everything after it.
- **Epoch J was renumbered on 2026-08-15 so phase-number order is
  execution order.** The previous numbering appended the
  templating/extension rewrite at the tail even though its real position
  is immediately after asset-pipeline and inline-JS/CSS extraction, and
  the three completed Latte phases carried higher numbers than
  not-started work that must precede them.
- **P25 split into three phases on 2026-08-15/16, cascading +2 through
  everything after it.** A review found the old P25 ("REST resource
  layer + OpenAPI") bundled two different jobs — modernizing the legacy
  WS API's internals and replacing it with REST — which is why it had sat
  at "not started" as a monolith. Split into **P25** (WS layer
  modernization — typed internals + PSR-7 lifecycle, no wire-format
  change), **P26** (admin fragment surface — the UI-facing WS methods
  move off the JSON/XML envelope), and **P27** (public API v1 — REST +
  OpenAPI 3.1 + tus, WS deleted here). Old P26–P53 shift to new P28–P55
  unchanged in scope and order — only the numbers move.

## Status

| Phase | Scope | Status | Commits |
| --- | --- | --- | --- |
| P0 | PHP tooling + baselines | Done | 8 |
| P1 | Frontend tooling + baselines | Done | 6 |
| P2 | Test harness | Done | 7 |
| P3 | CI pipeline | Done | 16 |
| P4 | Containerization + runtime image | Done | 4 |
| P5 | Composer + Rector + PHPStan | Done | 653 |
| P6 | PSR-4 namespace migration | Done | 34 |
| P7 | Kernel + boot skeleton | Done — worker mode (SEC-60) never built | 4 |
| P8 | DI container | Done | 4 |
| P9 | PSR-15 middleware + routing | Done | 6 |
| P10 | Observability | Done | 4 |
| P11 | Cache + session + messenger + `opcache.preload` | Done — no failed-job visibility | 4 |
| P12 | CLI tool + backup/restore | Done | 7 |
| P13 | Config service | Done | 4 |
| P14 | DB layer + Doctrine ORM | Done | 4 |
| P15 | Schema migration + multi-provider | Done | 6 |
| P16 | Typed facades + constants + language | Done | 7 |
| P17 | Domain tier 1 | Done | 14 |
| P18 | Domain tier 2 | Done | 4 |
| P19 | Domain tier 3 | Done — 2 `Common` gaps remain | 12 |
| P20 | Domain tier 4 | Done | 10 |
| P21 | Admin controller migration | Done | 4 |
| P22 | Frontend controller migration | Done | 7 |
| P23 | Legacy deletion & cleanup | Done — later-audit gaps all closed | 123 |
| P24 | Post-P23 remediation & hardening | In progress — see Epoch F | 646 |
| P25 | WS layer modernization — typed internals + PSR-7 lifecycle | Done — Stage 3's remaining items targeted `Piwigo\Ws\*`/`tests/Contract/`, both deleted outright by P27; see Epoch G | ~50 |
| P26 | Admin fragment surface — UI-facing WS methods off the envelope | Done — the WS layer no longer exists at all; every admin UI surface already renders via Latte pages/fragments, not a JSON/XML envelope | ~15 |
| P27 | Public API v1 (REST + OpenAPI 3.2 + tus) — WS deleted here | Done — 134 `Controller\Api\*` files, 88 registered `/api/v1` routes, full tus 1.0.0 chunked-upload protocol (6 dedicated controllers), RFC 9457 problem+json errors, hand-authored OpenAPI 3.2 spec (88 operations/11 domains) with a `redocly lint` CI gate + Gesso runtime contract enforcement, a generated TypeScript client, REST-body `Content-Type` validation (SEC-39), and an opt-in `Idempotency-Key` replay store (SEC-65); see Epoch G | ~151 |
| P28 | Security hardening | Not started | 0 |
| P29 | Plugin / Theme contracts + bundled extensions | In progress — P29.6 unstarted | 22 |
| P30 | Layer decoupling + repository restructure | Done — deptrac's 6-layer model enforces 0 violations in CI (established P6); the pre-consolidation repository-restructure plan's load-bearing goals were already met by the simpler `public/`-as-sibling-directory approach that shipped | 1 |
| P31 | Smarty → Latte template migration | Done | 80 |
| P32 | Latte lint/format tooling | Done — enforcement is P45 | 11 |
| P33 | Latte idiomatic modernization | Done — all 8 sub-items | 8 |
| P34 | Event system rewrite | Done — all 5 items complete and verified, including all 6 named core hooks (see Epoch J) | 13 |
| P35 | Browserslist decision + IE back-compat removal | Done | 1 |
| P36 | Asset-pipeline foundation (ViteManifest) | Done | 1 |
| P37 | Typed page-data exposure (PHP half) | Done | 1 |
| P38 | Inline JS extraction | Done — all 7 batches (P38-A–G) | 7 |
| P39 | Inline CSS extraction | Done — all 5 batches (P39-A–E) | 5 |
| P40 | Typed view objects + `Template` split | Done — Batches 1–9 + the 3 include-only-partials + the Mail domain batch all landed and fully validated (see below); every remaining `TemplatePageContext` class confirmed either P41 shell scope or a permanent ambient wrapper, exhausting P40's own actual scope. The physical `Renderer`/`TemplateLocator`/`ThemeChain` class split was never P40's own work — this section's own "Scope correction" note reassigned it to P41's one-time cutover from the start | 2 |
| P41 | Shell-last rendering + `PageState` split | Done — Batches A–E landed (see above). Part 2 (P41-G/H, asset-pipeline swap) landed too — `CssLoader`/`ScriptLoader`/`FileCombiner` replaced by `PageAssets`/`AssetContribution`, file-combining intentionally dropped (Vite migration replaces it later), 6 dead `header.latte`/`footer.latte` files removed; P41-I (capture-based, more-idiomatic-Latte follow-up replacing the placeholder-tag mechanism) never landed under its own name — superseded by P42's own declarative redesign instead (see below), which is where its scope actually completed | 8 |
| P42 | Declarative page assets & exposed data (View-level, supersedes P41-I) | Done — mechanism + P42-A (11-partial conversion + 4 theme-base pieces) fully landed; P42-B (945-call-site migration) fully landed, including the MenubarBlockView/MonthCalendarView design gap (see below); final step landed too — `combineScript`/`combineCss`/`htmlHead`/`footerScript` (0 remaining real callers, template or PHP) deleted from `Template`/`PiwigoExtension`, `exposeData`/`exposeString` kept as real PHP-only methods (`Renderer::render()`/`AdminShell` still call them directly, just no longer Latte-callable). `finalizeHtml()` itself was **not** deletable, correcting this entry's own prior claim — it's permanent, load-bearing architecture with ~20 real controller callers, and `getCombinedScripts()`/`getCombinedCss()`/`getPageDataScript()` stay real, live Latte functions every `layout.latte` calls directly (see below) | 6 |
| P43 | Typed contributions + plugin-owned routes | Done — all batches (A–G) landed. P43-G landed (constructor-inject `Template`'s 4 hidden `Kernel::container()`-resolved dependencies, plus a hardened `ImageStdParams` container factory). P43-B landed (`math()`/`eval()` removal, 22 zero-use `PiwigoExtension` registrations pruned, `cat`/`count`/`join`/`strip_tags` migrated onto Latte builtins, `htmlOptions`/`htmlRadios` replaced by native `{foreach}`). P43-A fully landed: `ButtonContribution`/`ActionContribution`/`PanelLink`/`PictureInfoRow`/`ProfileField`+`FieldType`/`AuthButton`/`ThumbnailOverlay`/`MenuItem`/`FieldOverride`/`FormProvider` (`Piwigo\Contribution\`), replacing every real `addIndexButton()`/`addPictureButton()`/`concat('PLUGIN_INDEX_ACTIONS'\|'PLUGIN_PICTURE_ACTIONS')`/`set_prefilter(...)` mechanism (also deleted a dead `$PLUGINS_PROFILE`/dynamic-`{include}` mechanism along the way). P43-C landed (`data-image-id`/`data-category-id` stable DOM hooks + indexed rating-button ids across the picture/thumbnail family, plus deletion of 6 more confirmed-dead raw-HTML plugin hooks). P43-D landed (`ExtensionContext::render(View): Html`, `SettingsPageInterface::handleSettingsRequest()` now returns `View`; also fixed 2 real P43-B regressions found via full Browser verification — a `stripTags`/`replace` filter-chain-order bug and a stale test assertion). P43-F fully landed: introduced `Controller\Admin\Projection\AdminPageResult`, converted `AdminSubControllerInterface::handle()` and all 36 real implementers plus all 40 `Piwigo\Admin\*PageRenderer` classes from directly assigning `AdminContentPageContext` to returning `render(): AdminPageResult`, and `AdminDispatcher::dispatch()` is now the one place that turns that into the ambient `AdminContentPageContext` — closes the "76 real files" scope the batch's own plan text named. Along the way found and fixed 2 real regressions the migration itself introduced (a not-yet-converted parent `SubController` silently discarding an already-converted renderer's output in `ThemesSubController`/`PhotoSubController`), caught via golden-HTML diffs. P43-E fully landed: new `PluginConfig\PageRouteProviderInterface`/`Routing\PageRouteRegistrarInterface` (manifest `hasPageRoutes`) let an active plugin register real public-facing routes onto `RoutingMiddleware`'s own live `RouteCollection`, mirroring `ApiRouteProviderInterface`'s existing layered shape exactly; new `PluginConfig\AdminPageProviderInterface` (manifest `hasAdminPages`) lets an active plugin contribute its own `admin.php` `?page=` slug, aggregated by `PluginRegistry::adminPages()` and merged onto the static `config/admin_pages.php` map by a new `AdminDispatcher::pageMap()` — found and fixed a real gap the plan text itself hadn't named: `Admin\AdminShell` independently re-read the static config file for its own slug validation before ever reaching `AdminDispatcher`, so a plugin-contributed slug would have passed dispatch but still 404'd there without also fixing that call site. Also fixed a real P43-F regression found along the way (`PluginSettingsPageDispatchTest.php` still reading `ADMIN_CONTENT` via the old `getTemplateVars()` path after `PluginSubController::handle()` had already been converted to return `AdminPageResult`) | 2 |
| P44 | Escaping, Input Validation & Security Hardening Campaign | Complete — all of P44-A/B/C/D/F/H/I/J/K/L/M landed (see below); P44-A's full `\|noescape` corpus reclassification sweep found and fixed 2 previously-unknown XSS bugs (`InstallWizard`, `NoPhotoYetRenderer`) across 3 rounds; P44-G (this doc's own entry) kept current per batch | 11 |
| P45 | Latte lint/format enforcement | Not started | 0 |
| P46 | JS → TS mechanical conversion | Done — 79 files (not the 68 originally guessed at), full validation green | 19 |
| P47 | `getPageData<T>()` typing + `any` reduction | Done — all 168 `pwg_getPageData()` call sites across 57 files typed, 782+ `: any`/`as any`/`<any>` sites eliminated; 6 new `@types/*` vendor packages added (jqueryui, selectize, jquery.colorbox, jquery.cookie, chart.js, plupload); `eslint.config.ts`'s blanket P46/P47 any-tolerance override narrowed to 6 first-party files with a confirmed-justified remaining `any` (`search_filters.ts`, `mcs.ts`, `history.ts`, `common.ts`, `datepicker.ts`, `profile.ts`) plus `jquery-plugins.d.ts`'s own unsourced vendor entries (`jquery-confirm`, `jquery.cluetip`, `jquery.Jcrop`, `jquery.jgrowl`, `jquery.ajaxmanager`, `jquery.progressbar`, `jquery.sort`, `jquery.autogrow-textarea`, DataTables). Full validation green: `typecheck`/`lint:js`/`format`/`knip`/`vite build`, Unit+Arch (5526), Integration (2172), golden-html (74), Visual Regression (66), Browser (787) | 27 |
| P48 | Refactor TS into modules | Done — every shared-library file converted to real `export`/`import`, then shipped as genuine ES modules with shared chunks. No more `window.X = X` cross-file latching outside confirmed-permanent category-2/3/queue-coordination exceptions: `common.ts`, `page-data.ts` (the 2 with most consumers, 48+ and 30+ files), `scripts.ts`, `album_selector.ts`, `LocalStorageCache.ts` (non-module IIFE → real module), `doubleSlider.ts`, `switchbox.ts`, `search_filters.ts`, `addAlbum.ts`, `datepicker.ts`, `autosize.ts`, `toaster.ts`, `albums.ts`, `intro.ts`/`intro_tooltips.ts`, `batchManagerGlobal.ts`/`batch_manager_global.ts`, `plugins_installed_config.ts`/`plugins_installated.ts`. `footer.ts` and default-theme `rating.ts` are deliberate non-folds (footer.ts: centrally injected by `Template::finalizeHtml()`, no per-page hook; rating.ts: conditional registration). **The `?dup` Rollup-duplication plugin was an intermediate step, now gone.** It existed because entries loaded as IIFE-wrapped classic scripts, where a shared chunk's `import` is a SyntaxError, so every page bundle had to be self-contained. Built entries now render as `<script type="module">` and Rollup shares code normally: **10 shared chunks, `dist/assets` 631,463 → 359,172 bytes (−43%)** — that figure measures the `?dup` retirement alone, before the CDN vendors below were bundled; folding those in afterwards takes `dist/assets` to 872,112 bytes, 453,007 of it the `stats.ts` entry carrying moment's full locale set, with transitive `<link rel="modulepreload">` hints (emitted queryless, so the hint and the module import are one request, not two). The classic/module decision keys on the `dist/` prefix `PageAssets::resolvePath()` adds for manifest-resolved assets — deliberately not "is it local?", since four vendored jQuery plugins are served from `themes/**` and must stay classic (`jquery.geoip.js` assigns a bare `GeoIp` global, which throws under a module's strict mode). Retiring `?dup` also deleted its ambient wildcard `.d.ts` and a 43-file ESLint allowlist that had been disabling five `no-unsafe-*` rules: measured directly, `?dup` really did resolve to `any` under `tsc` (an identical bogus call is a TS2554 through a plain import and silent through a `?dup` one), so those reports were an accurate signal, not the "tool divergence" the config claimed. With the rules restored those files report 0 errors. Sharing also *fixed* a documented loss: `album_selector.ts`'s two independent class copies on `batch_manager_unit`/`batch_manager_global` are now one, so `activeAlbumSelector`'s single-active-popup coordination spans both widgets again. The last 4 non-jQuery CDN vendors are bundled too (chart.js, moment-with-locales, tus-js-client, piecon), leaving every remaining CDN registration jQuery-family. Two needed their resolution pinned rather than trusted: tus-js-client resolved to its **node** build (`TypeError: Super expression must either be null or a function` live, and 56 KB larger), and moment needed its with-locales build so a non-English gallery localises — verified live on a Portuguese install, where the chart's x-axis reads "jun/jul/ago 2026". Full validation green: `typecheck`/`lint:js`/`format`/`knip`/`vite build`, `lint:php`/`analyse:phpstan`, Unit+Arch (5533), golden-html (74), Visual Regression (66, zero visual change from the module switch), install-flow. Live-verified per page: deferred inline `pwg_tryFocus` still focuses, all four classic vendor scripts still execute, no TDZ at `batch_manager_global`. | 0 |
| P49 | Remove jQuery + retire other abandoned/outdated vendored JS deps | Done — P49-A done (every first-party call site converted off jQuery to native DOM/fetch, `themes/default/js/vendor/dom.ts`/`ajax.ts`). P49-B done: `jquery.geoip.js`'s only real call target (freegeoip.net) was long dead, so its mechanism was replaced entirely by a self-hosted DB-IP geolocation lookup (`GET /api/v1/geoip`) rather than ported as-is; `jquery.sort.js`/`jquery.autogrow-textarea.js` (both real, single-consumer micro-plugins with no findable upstream source) ported natively to `themes/default/js/vendor/sortElements.ts`/`autogrow.ts`. `jquery.cookie.js` ported to `themes/default/js/vendor/cookie.ts` (real source read from the CDN, `jquery.cookie@1.4.1`). `jquery.ajaxmanager.js` ported to a concurrency-limited FIFO queue, `themes/default/js/vendor/ajaxQueue.ts` (real source read from `github:aFarkas/Ajaxmanager#3.12`), including a cross-file refactor (`batchManagerGlobal.ts`'s `getDerivativeUrls()` now takes its queue as an explicit parameter instead of relying on the library's own global string-keyed manager registry). `jquery.tipTip.js` ported natively to `themes/default/js/vendor/tiptip.ts` (real source read from `github:drewwilson/TipTip#277e33629e`) across all 27 real call sites in 10 admin `.ts` files; found and removed 2 genuinely dead registrations along the way (`TagsView`'s own `tiptip` entry -- `tags.ts` never called it -- and `SearchFiltersView`'s front-end `jquery.tipTip` entry -- no front-end `.ts` file ever did either, despite `.tiptip`-classed markup in `search_filters.inc.latte`). This closes out P49-B group 2 entirely. `jgrowl` (P49-B group 3) ported to `themes/default/js/vendor/jgrowl.ts` (real source read from the CDN, `jgrowl@1.3.0`) -- both real call sites (`updates_ext.ts`'s update/ignore-extension toasts), including the queued-render-one-per-250ms-tick and hover-pauses-every-notification-in-the-container behaviors, faithfully preserved since real bulk actions exercise both; a new Browser test closes a real, pre-existing gap (jGrowl's own rendering was never behaviorally asserted, jQuery-based or not). jQuery UI's slider widget (P49-B group 4) ported to `themes/default/js/vendor/slider.ts` (real source read from the vendored `jquery-ui@1.10.4` bundle) across `user_list.ts` (19 call sites), `plugins_new.ts` (6), and `doubleSlider.ts`'s own first-party `pwgDoubleSlider` wrapper (converted from a `jQuery.fn` extension to a plain function in the same pass, its 2 real consumers -- `batchManagerFilter.ts`, `mcs.ts` -- updated); `jquery.ui`'s own script registration dropped from the 3 pages (`UserListView`, `PluginsNewView`, `SearchFiltersView`) that only ever needed it for the slider, kept on pages still needing `pwgDatepicker` (group 5). New Browser tests (mutation-verified) close a real gap: no prior test, jQuery-based or not, ever drove any slider interactively. `jquery-confirm` (P49-B group 5, `$.confirm`/`$.alert` only -- `$.dialog` and the `$.fn.confirm` jQuery.fn form were never used by any real call site) ported to `themes/default/js/vendor/jconfirm.ts` (real source + CSS read from the CDN, `jquery-confirm@3.3.4`), across 15 admin `.ts` files (~29 call sites total, plus `common.ts`'s own `pwg_jconfirm_follow_href` first-party wrapper, converted from a `jQuery.fn` extension to a plain function alongside it, and its 7 consumers updated). Every real call site sets the same `draggable`/`theme`/`animation`/`useBootstrap`/`animateFromElement`/`typeAnimated`/`backgroundDismiss` values with no deviation, so none of the drag-to-reposition, theme system, bootstrap grid, or pulsing type-color animation machinery was ported -- only a single fixed "modern"/"zoom" modal. `content` as a function returning this app's own `ajax()` thenable (loading-spinner-then-`setContent()`-from-the-callback, not from the resolved value) is real, load-bearing usage, faithfully ported; `tags.ts`'s own bulk-delete flow returns a bare native `Promise` instead, which the original library's own `.always()` detection genuinely can't see either, a pre-existing quirk (blank dialog content) preserved exactly rather than fixed. `onClose` is the one callback option real usage needed (`plugins_installated.ts`'s incompatible-activation revert, since `backgroundDismiss` means the cancel button's own action never runs for a backdrop/Escape dismissal). CDN script registrations dropped from all 21 consuming Views (CSS kept); `HistoryView`'s own registration was dead weight (no real call site) and removed outright. A new, mutation-verified Browser test closes a real gap (`cat_modify.ts`'s delete-album dialog's ajax-loaded content was never behaviorally asserted, jQuery-based or not). selectize.js (P49-B group 6A -- jqtree and Jcrop, both loosely bundled under the same in-tree "group 6" label, are separate follow-ups, both since completed below) ported to `themes/default/js/vendor/selectize.ts` (real source read from the CDN, `selectize.js@v0.11.2`), across 13 admin/front-end `.ts` files (~20 real call sites, `LocalStorageCache.ts`'s own 4 Cache classes included) -- narrowed to the real subset every call site uses: only the `remove_button` plugin (a real, unconditional no-op for single-select, matching the original's own `if (mode === 'single') return;`), no optgroups/remote search/custom score, but search-term highlighting and the `create: true` inline-item-creation flow are real, always-on behavior and were ported, not dropped. Faithfully replicated several non-obvious real mechanics found only by reading the vendored CSS/JS directly rather than assumed: the original's own class-copying (`$wrapper.addClass(classes)`) onto the control/dropdown wrapper, its `items`/`has-options`/`full` state classes several page-specific stylesheets key off, its `autoGrow()` text-input sizing (no CSS rule sizes the input to its own content otherwise), its `updatePlaceholder()` (removes the placeholder attribute entirely while any item is selected, not just visually), its `isFocused`-gated dropdown-reopen suppression during silent/programmatic item seeding (else a preselected value force-opens the dropdown), and critically `updateOriginalInput()`'s own full `<option>`-list *regeneration* from the current items on every change (caught via `RatingPageInteractionTest.php`'s own pre-existing test, which failed against an initial, incorrect toggle-`.selected`-on-existing-options implementation -- real API-sourced `<select>`s here start with zero real `<option>` children). `triggerChange()` dispatches a real native `change` event on the underlying `<select>` (unlike the original's jQuery-internal trigger), letting `rating.ts`'s and `batchManagerUnit.ts`'s own native `"change"` listeners convert too. CDN script registrations dropped from all 13 consuming Views (CSS kept); `@types/selectize` and the `selectize` npm dependency removed. A new, mutation-verified Browser test closes a real gap (no prior test, jQuery-based or not, ever drove selectize's search input or keyboard handling, only its zero-state render or direct API/DOM state) -- 3 existing tests needed fixing since they read the removed `.selectize` instance property directly rather than simulating real interaction or reading rendered DOM. jqtree (P49-B group 6B) ported to `themes/default/js/vendor/jqtree.ts` (real source read from `github:mbraak/jqtree`'s own `lib/*.js`), across `albums.ts`'s drag-and-drop-orderable album tree (~32 real call sites, the one real consumer). Narrowed hard: selection is permanently disabled at this consumer (`onCanSelectNode: () => false`), so the click-handling/select-node-handler/key-handler machinery and jqtree's own internal `.jqtree-toggler`/`.jqtree-title` markup (which `onCreateLi` unconditionally wipes and replaces anyway) aren't ported at all; `autoOpen`/`saveState` are always the real init's own `false`, collapsing the original's initial-state-restore/auto-open dance to a no-op (`getState()`'s own `open_nodes` list *is* real and kept); remote `dataUrl` loading is never used and isn't ported. Drag-and-drop -- real, load-bearing, and genuinely the hardest single piece of new code in the whole P49 campaign -- is ported in full: hit-area generation (including the original's own exact, slightly odd group-flush arithmetic, ported literally rather than "fixed"), the ghost/border drop-hint, the folder auto-expand-on-hover-during-drag timer, scroll-during-drag, and the `tree.move` cancelable event with its `do_move()` callback. `jquery.tree`'s own CDN script registration dropped from `AlbumsView` (CSS kept); `jqtree` npm dependency and its bundled `.d.ts` (`INode`/`IJQTreePlugin`/the ambient `AlbumTreeNode`/`AlbumJqTreeNode`) removed from `build/jquery-plugins.d.ts`, replaced by real exported types in the new module. New, mutation-verified Browser tests close a real gap (drag-and-drop was never behaviorally tested, jQuery-based or not -- 16.x-v2's own version of this suite found jqTree's jQuery-internal mouse-event state machine unreachable from Playwright; the native port's plain `mousedown`/`mousemove`/`mouseup` listeners have no such problem): one for same-level reordering (`position: "after"`), one for re-parenting (`position: "inside"`, the distinct border-drop-hint/`changeParent` branch). A live browser session verifying the drag-and-drop port surfaced a real, independent, and more serious bug: a single click on `.AddAlbumSubmit` silently created 2 real categories via 2 real `POST` requests. Root cause was a page-wide asset-loading defect, not anything specific to albums or jqtree -- `cat_search.ts` statically imports `albums.ts`'s own `data` export (`dependsOn: ['albums']`), and Vite's own emitted inter-chunk `import` specifier for that carries no cache-busting query string, while `albums`'s own independently-registered `<script src=".../albums-{hash}.js?v17.0.0">` tag did -- two different URLs for the same module is two separate module instances to the browser, double-registering every `ready()` handler in the file. Fixed by making every script tag stop appending a version query unconditionally (`PageAssets::resolveScripts()`, `Template::makeAssetSrc()`) -- content-hashed Vite filenames make it redundant for built assets, and the same rule now applies uniformly rather than conditionally, so no other script (Vite-built or the one raw vendored plugin file) can hit this class of bug either. `TemplateInstanceTest.php`'s own 10 unit tests asserting the old versioned-script behavior updated to match. Adversarial pass over the whole of `AlbumTreeTest.php` (not just the 2 new tests) mutation-verified all 5 pre-existing tests too, and found one real, independent weakness: the existing "add-album" test's own `assertSee($newName)` + delete-all-matching-by-name cleanup would have passed identically whether 1 or 2 real categories got created -- exactly blind to the bug just found -- strengthened to assert an exact match count, mutation-verified by reproducing the double-POST shape directly. Jcrop (P49-B group 6, the last "group 6"-labeled library -- `jquery-ui`'s datepicker+`jquery-timepicker-addon` remains the one real, separate, still-unstarted P49-B surface outside this numbered-group scheme (`jquery.colorbox` is since completed too, below); `jquery-cluetip` is since completed too, below) ported to `themes/default/js/vendor/jcrop.ts` (real source read from `github:tapmodo/Jcrop#v0.9.12`'s own `js/jquery.Jcrop.js`), across `picture_coi.ts`'s center-of-interest cropper (the one real call site, an `<img>` target only -- the library's own separate "crop an arbitrary `<div>`" mode, and the `shade` darkened-background option it forces on for that mode, is real, unreachable dead weight here and isn't ported). `aspectRatio`/`maxSize`/`minSize`/`minSelect` are never set (always their own falsy/zero defaults), collapsing `Coords.getFixed()`'s aspect-ratio branch and `getRect()`'s min/max-size clamps to dead code -- not ported; the bounds-clamping that *is* reachable is, including one exact pre-existing quirk in the original's own `getRect()` (its `x1 > boundx` branch computes `delta` from `boundy`, not `boundx`), ported literally rather than "fixed", same policy as `vendor/jqtree.ts`'s own hit-area arithmetic. `allowSelect`/`allowMove`/`allowResize`/`keySupport`/`drawBorders`/`dragEdges` all default `true` and are never overridden -- real, always-on behavior (draw/move/8-handle-resize/arrow-key-nudge/Escape-to-release) and are ported in full. Mouse and touch are unified through native Pointer Events rather than the original's own separate mouse/touch listener pairs -- a deliberate simplification, not a literal translation, since every real target browser here already dispatches pointer events for both. A live browser session (creating a real test photo via the TUS upload API, confirming the port live rather than assuming) found and fixed 2 real bugs before landing: (1) cloning `#jcrop` re-triggers the clone's own independent, asynchronous image decode regardless of the original's own load state, so reading the clone's rendered size synchronously right after `cloneNode()` raced that decode and `presize()` measured a 0x0 box -- fixed by explicitly sizing the clone from the already-known original dimensions first, matching the real source's own `$img.width($origimg.width())`, and by porting the real source's own `$.Jcrop.Loader` wrapper (poll `img.complete`, defer via `load` otherwise) that this port had first skipped as supposedly unreachable; (2) a corner-resize handle (e.g. `se`) re-anchored the *wrong* corner (the one just dragged, not its opposite), silently relocating the whole selection instead of resizing it in place -- a `setPressed`/`setCurrent` argument swap in `startDragMode()`. Both were manually reproduced and fixed live against real drag interactions, then covered by 2 of 3 new, mutation-verified Browser tests (`PictureCoiInteractionTest.php`: draw a new selection, move-then-corner-resize -- the second is the direct regression net for bug (2) -- and Escape-releases); none of jcrop's own interactive behavior was ever tested before, jQuery-based or not. The pre-existing `PictureCoiPageRendererTest.php`'s own "round-trips a stored center of interest" test (written during P49-A, already anticipating exactly this class of "measured the wrong box" bug for whichever Jcrop implementation was live) continues to pass unmodified against the native port. Jcrop's own CDN script registration dropped from `PictureCoiView` (CSS kept); the `jquery.jcrop` npm dependency and its ambient `.d.ts` `Jcrop()` declaration removed from `build/jquery-plugins.d.ts`. `jquery-cluetip` ported to `themes/default/js/vendor/cluetip.ts` (real source read from the vendored `jquery-cluetip@1.2.6` package), across its 2 real, live call sites -- `install.ts`'s newsletter-subscribe span (`positionBy: "bottomTop"`) and `languages_new.ts`'s per-language external-link cells (the real default, `positionBy: "auto"`) -- both call `.cluetip({width: 300, splitTitle: "|"[, positionBy]})` and nothing else: no `rel`-attribute/ajax/local content source, no click/focus activation (always hover), no arrows/sticky/mouseOutClose/tracking/hoverClass/truncate, `multiple` is always its own real `false` default (one shared tooltip element, not one per call site), and the delimiter is hardcoded rather than exposed as an option since every real call site passes the same `"|"`. A third call site, `intro.ts`'s own `.cluetip()` registration, was genuinely dead (no `.cluetip`-classed markup anywhere on the admin intro page, statically or dynamically -- its own newsletter-promo box uses `.tiptip`, already ported) -- removed outright, along with `IntroView.php`'s CDN script registration, rather than ported as an always-no-op call; `InstallView.php`'s own bare `jquery` registration became dead weight the same way once its only real consumer (`jquery.cluetip`) went, and was removed too. `dropShadow`'s real effect (every real target browser supports `box-shadow`) collapses to one inline style rather than porting the original's own old-browser div-based fallback; the shared tooltip element stays permanently `display: block`, toggled via `visibility` instead of the original's `.hide()`/`.show()`, so it stays measurable (`offsetHeight`) at any time without needing the original's own internal unhide-to-measure trick. `delayedClose`'s real 50ms default is ported (a quick re-hover cancels the pending hide instead of flickering), confirmed via a live browser session and mutation-verified via a dedicated test. New, mutation-verified Browser tests close a real gap (no prior test, jQuery-based or not, ever drove cluetip's hover/position/content/deactivate cycle): `LanguagesNewInteractionTest.php` covers the "auto" positioning branch (both the right-of-link and overflow-driven left-of-link sub-cases, computed from the same real geometry the port itself reads, not guessed) plus native-title suppression/restore and the delayed-close-cancels-on-re-hover behavior; `InstallTest.php`'s own pre-existing install-flow test gained the equivalent "bottomTop" coverage inline (reaching that page at all already pays a real DB-wipe cost, so a separate test would pay it again for nothing). Two real bugs surfaced during this verification and were fixed at the source rather than in the test: `StatsPageRendererGetMonthOfLastYearsTest.php` (Integration) and `StatsPageRendererTest.php` (Browser) both computed their own expected "now" via a raw `new DateTime()` instead of `Env::now()` (which the app's own `StatsPageRenderer` uses, frozen by `PIWIGO_TEST_NOW` in test mode) -- a real, pre-existing, unrelated bug that silently passed only by coincidence whenever the real wall-clock month matched the frozen one, and broke deterministically (not a boundary race) once the real date rolled into a different month; fixed by switching both tests to `Env::now()`, matching the SUT's own time source. Colorbox ported to `themes/default/js/vendor/colorbox.ts` (real source read from the vendored `jquery.colorbox` package, `github:jackmoore/colorbox#1.5.14`), across its 8 real call sites in 7 admin `.ts` files (`batchManagerGlobal.ts`/`picture_modify.ts`/`batchManagerUnit.ts`'s own `photo:true` popups, `themes_installed.ts`/`configuration_main.ts`'s own auto-detected-via-`photoRegex` screenshot popups, `admin_help.ts`'s own site-wide ajax/HTML-fallback help popup, and `photos_add_applications.ts`'s own 9-item `rel:"group1"` grouped gallery -- the one real multi-item group, so the only page where next/prev/counter/loop is reachable at all). `addAlbum.ts`'s own `jQuery.fn.pwgAddAlbum` (the one real `inline`-mode consumer) converted from a `jQuery.fn` extension to a plain function in the same pass, its one real caller (`batchManagerGlobal.ts`) updated to call it directly; its own `jQuery.error(...)` calls converted to plain `throw new Error(...)`, removing the file's last jQuery dependency entirely. `scalePhotos`/`retinaImage`/`retinaUrl`/`maxWidth`/`maxHeight`/`innerWidth`/`innerHeight`/`top`/`bottom`/`left`/`right`/`fixed`/`className`/`slideshow`/`iframe`/ajax-POST-`data` are all real, never-set-by-any-call-site options and aren't ported; positioning is always the original's own "center in the viewport" default, and text (`current`/`previous`/`next`/`close`/`xhrError`/`imgError`) is the original's own hardcoded English literals, never overridden. The "elastic" grow/reposition transition (real, default, never overridden to "fade"/"none") needed a continuous per-frame callback dom.ts's own `animate()` has no hook for, so this port hand-rolls a small `requestAnimationFrame` tween reusing dom.ts's own `swing()` easing, rather than extending the shared helper for a need only this module has; the close fade goes through dom.ts's `fadeTo()`/`stop()` directly. CDN script registration dropped from `ColorboxView` (CSS kept, every id/class this module creates matches the original's own naming); stale `jquery.colorbox`/(`jquery` where now-unused) `dependsOn` entries removed from 8 consuming Views; `@types/jquery.colorbox`/`jquery.colorbox` npm packages and the ambient `.d.ts`'s own `.colorbox()`/`pwgAddAlbum()` declarations removed. New, mutation-verified Browser tests close 2 real gaps (no prior test, jQuery-based or not, ever drove colorbox's own click-to-open/group-navigation/counter/close behavior, only its registration marker and `AddAlbumInteractionTest.php`'s own end-to-end `inline`-mode flow): `PhotosAddApplicationsInteractionTest.php` covers group open/next/counter/Escape-close (tolerating the group's own real screenshot URLs being unreachable in test mode -- colorbox's own real `imgError` path still runs `prep()`, so the counter/title chrome this test asserts on is unaffected either way), `ConfigurationMainInteractionTest.php` gained the ajax/HTML-fallback-mode coverage inline. Verifying this live (pixel-by-pixel golden-html/VR comparison, not a visual sample) surfaced 2 real, independent, pre-existing bugs unrelated to colorbox and fixed alongside it: `AdminShell.php`'s own stats-history link built its year/month from the real wall clock (`date('Y')`/`date('n')`) instead of `Env::now()`, silently drifting out of sync with `PIWIGO_TEST_NOW` across a real calendar-month rollover; and `themes_standard_pages.ts`'s own "scroll mini to show the selected skin" used dom.ts's jQuery-style `position()`, which is offsetParent-relative -- `.std_pgs_mini_previews` has no `position` rule of its own, so it was never the real offsetParent of its `<img>` children, and `position()` returned the *container's* own distance from an unrelated positioned ancestor instead, scrolling the real default skin (needing zero scroll) to an arbitrary offset every time. Fixed with `scrollIntoView({block: "nearest"})`, which needs no offsetParent assumption, plus waiting for every mini-preview `<img>` to settle before scrolling (a separate, real image-load race). Mutation- and stability-verified (3 independent fresh-fixture VR runs, all green). The rest of that same diff was legitimate already-shipped-but-never-rebaselined drift, not a bug: the what's-new banner correctly stays hidden once `show_whats_new_17` is persisted `false` (an already-landed `>=` comparison fix), and the already-ported `jquery-cluetip` script tag was already gone from every admin page. jQuery UI's datepicker widget + `jquery-timepicker-addon` (`pwgDatepicker`) -- the last unstarted P49-B surface -- ported to `themes/default/js/vendor/datepicker.ts` (real source read from the vendored `jquery-ui@1.10.4` bundle's own `ui/datepicker.js` and `jquery-timepicker-addon@v1.4.4`'s own `src/jquery-ui-timepicker-addon.js`), across all 4 real call sites: `batchManagerGlobal.ts`/`batchManagerUnit.ts`/`picture_modify.ts`'s own `{showTimepicker: true, cancelButton: ...}` creation-date pickers, and `history.ts`'s own plain (no time, no cancel button) `start`/`end` search-range pair. `datepicker.ts`'s own former `jQuery.fn.pwgDatepicker` wrapper (including its own real customization replacing jQuery UI's year `<select>` with a free-typed number `<input>`) folded directly into the new module rather than kept as a separate wrapper layer, and is deleted outright, along with `include/datepicker.inc.latte`/`DatepickerView.php`/`DatepickerViewTest.php` (contract-only, never rendered via `Renderer::render()` -- its own bare `{include}` in `history.latte` deleted too) and `pages/history.ts` (the `historyPage` bundle entry existed only to trigger Rollup's shared chunking of `datepicker.ts` via a side-effect import, with no other real code of its own -- moot now that all 4 real call sites import the new module directly). Narrowed hard to what these 4 real call sites actually reach: every real picker is "linked" (`data-datepicker` always matches a real hidden `<input>`), so the original's own unlinked/standalone branch isn't ported; every real visible input is `readonly`, so `constrainInput`/keyup-parses-what-you-typed sync aren't ported; single month view only, no inline mode, no `beforeShowDay`/`showOtherMonths`; `yearRange`'s own min/max-year arrow-disabling isn't ported since the year `<select>`-to-number-`<input>` customization already replaces its only other real effect (bounded typing) with unbounded free typing; time is hour+minute only (`timeFormat` always `"HH:mm"`), reusing the already-ported `vendor/slider.ts` for the two always-visible sliders rather than reimplementing jQuery UI's slider widget again. Locale IS real and load-bearing here, unlike most other P49 ports: `DatepickerView.php`'s own former per-request `jqueryCode` picked which of jQuery UI's 67 real `ui/i18n/jquery.ui.datepicker-*.js` files and jquery-timepicker-addon's own 39 real `i18n/jquery-ui-timepicker-*.js` files to load for this install's 72 real installed languages -- `vendor/datepickerLocales.ts` carries both real, authoritative locale sets verbatim (extracted programmatically via a Node.js script that `eval()`s the real vendor files inside a minimal sandboxed `$`-shim to capture their own `$.datepicker.regional[code]`/`$.timepicker.regional[code]` object literals as JSON, rather than risking hand-transcription of 106 locale files), keyed the same way `Lang::langInfo()['jquery_code']` resolves; a new `jquery_code` page-data key (exposed by all 4 consuming Views, sourced from each PageRenderer's already-computed `$jquery_code`) supplies the current request's own code client-side, falling back to the same English defaults every other P49 port hardcodes when it matches neither list -- a real, pre-existing production gap replicated exactly, not introduced (e.g. Basque's real `jquery_code` "eus" vs. jQuery UI's own "eu" already silently fell back to English via `DatepickerView.php`'s own `in_array()` gate). `firstDay`/`isRTL`/`showMonthAfterYear` (all real, non-default for some of the 72 installed languages) are honored in the calendar/header rendering, not just the flat string tables. `DatepickerView.php`'s own CSS-only registrations (`jquery-ui.css`, `jquery-ui-timepicker-addon.min.css`, still real -- the native port reuses jQuery UI's own class names for free theming) relocated directly into the 4 consuming Views; their own `jquery.ui.timepicker-addon`/per-locale CDN script `dependsOn` chains removed entirely, along with the dead direct `jquery.ui` JS-only registrations on `BatchManagerGlobalView`/`BatchManagerUnitView` (confirmed no other real jQuery UI widget usage on either of those 2 specific pages -- unlike `UpdatesExtView`/`RatingUserView`/`MenubarView`/`ElementSetRanksView`, which keep real `jquery.ui` JS for their own separate `.sortable()`/`.tooltip()` usage, a distinct, not-yet-ported P49-B gap this work newly surfaced but didn't touch); `jquery-ui`/`jquery-timepicker-addon` npm dependencies and the ambient `.d.ts`'s own `pwgDatepicker()`/`datetimepicker()`/`datepicker()`/`JQueryStatic.timepicker`/`JQueryUI.Datepicker` declarations removed. Two real, VR-catching regressions surfaced during verification and were fixed at the source: (1) the original's own `set(date, true)` unconditionally calls through to `_updateDateTime()` (a real `.trigger("change")` on the visible field) for every linked picker at init, real prior value or not -- `history.ts`'s own `.date-start`/`.date-end` change listeners (native now, since the port's own `writeValue()` dispatches a real bubbling native "change" event, unlike jQuery's internal-only `.trigger()`) depend on exactly this to fire the page's very first, unfiltered search on load; registering the input with a bare `input.value = ...` write instead of calling `writeValue()` silently dropped this, confirmed live and fixed. (2) `$.datepicker`'s own real `markerClassName` ("hasDatepicker", stamped onto every attached input) and its own hardcoded-`true` `autoSize` (sizes the visible field to the longest real day/month name in the active locale, +6 characters when `showTimepicker`) were both dropped, a real, non-decorative regression (`history.css`'s own `.hasDatepicker` rule supplies the field's border/padding/max-width) caught only by pixel-diffing 4 routes' VR baselines, not by golden-html (server-rendered HTML predates the client-side class/size writes) -- fixed by porting both exactly, mutation-verified via 3 fresh VR runs (0 baseline changes needed once fixed, confirming the visual match is exact, not merely close). A third, unrelated pre-existing bug was found and fixed alongside this work: `PhotosAddApplicationsInteractionTest.php`'s own `$opened['total']` (from `H::scriptJson()`, typed `mixed`) failed PHPStan's `binaryOp.invalid` on string concatenation -- narrowed via a `photosAddApplicationsInteractionOpened()` helper, matching the established `commonInteractionRow()`-style narrowing pattern used elsewhere in this suite. New, mutation-verified Browser tests close a real gap (no prior test, jQuery-based or not, ever drove the calendar/time-slider/cancel/unset UI itself, only the *consequences* of a field change): `DatepickerInteractionTest.php` covers open/select-a-day/adjust-hour-and-minute-sliders/commit-on-Done (`picture_modify.php`, the widest single real configuration), Cancel reverting to the original value, the unset link clearing the field, and `history.php`'s own real `data-datepicker-start`/`data-datepicker-end` cross-linking (closing the start picker constrains the end picker's own calendar, disabling every day before it) -- mutation-verified by temporarily reverting the cross-linking assignment and confirming the new test catches it. This closes out P49-B's entire numbered-group scheme. P49-C (scope extension, direct instruction): finish off every remaining real jQuery consumer, then broaden the phase to retire every other genuinely outdated or abandoned vendored JS dependency too, not just jQuery-based ones -- both halves driven by an exhaustive, grep-verified audit (real `jQuery(`/`.trigger(`/`dependsOn` call sites, not assumption), done: underscore.js removed outright (confirmed completely unused); the 9 confirmed-stale dependsOn entries removed; the 8 bare .trigger() call sites converted to dom.ts's own native trigger() (tags.ts: 1, user_list.ts: 7, fixing a real, confirmed pre-existing bug along the way -- selectionMode()'s own former jQuery(...).trigger("change") never actually reached select[name=selectAction]'s own real native "change" listener, so toggling selection mode left #applyActionBlock visibly stuck open); Piecon ported natively (vendor/piecon.ts) and its abandoned npm package removed; jQuery UI's sortable widget ported natively (vendor/sortable.ts, both real call sites), finding and fixing 2 more real bugs (a placeholder that didn't inherit its own real `float: left` layout, and preventDefault() on pointerdown breaking a nested checkbox's native click-forwarding -- fixed properly by porting the original's own real `distance` threshold, not just papering over the symptom). Done for jQuery UI's `.tooltip()`+datatables.net's `.dataTable()`/`.DataTable()` (`rating_user.ts`, ported together as one unit per the coupling note above -- native `vendor/dataTable.ts`/`vendor/tooltip.ts`, both real call sites; `RatingUserView`'s own `jquery.dataTables`/`jquery.ui` script registrations dropped entirely, zero real jQuery/jQuery-UI/datatables.net calls left in `rating_user.ts`). Done for plupload too (`photos_add_direct.ts`, native `vendor/uploadQueue.ts`) -- a narrowed HTML5-only port of `plupload.Uploader` + `jquery.plupload.queue.js`'s own file-list widget (real source read from the vendored `moxiecode/plupload@v2.1.2` tag), dropping the dead multi-runtime negotiation, real upload/chunking state machine (the app's own transport was already tus, not plupload's own uploader, before this campaign started), and every UI element this app's own theme.css keeps permanently hidden (header, column-header row, auto-generated buttons, progress bar). Found and fixed a real bug in the port itself before landing it: `bind()`/`trigger()`'s own `fn(this, ...args)` calling convention (matching real plupload's own `fn(up, ...)`) means a listener's first argument is the uploader, not the payload -- the module's own 3 internal listeners (`Error`'s alert, `FileUploaded`/`UploadProgress`'s status/progress rendering) were all reading `args[0]` instead of `args[1]`, caught by manually driving a real rejected upload and finding the alert silently never fired. Still open: chart.js+moment.js (`stats.ts`, the single biggest remaining lift, and the last item in this extension). Remaining real jQuery surface: `photos_add_direct.ts`'s own `$("#uploader").pluploadQueue({...})` (plupload, `github:moxiecode/plupload#v2.1.2` -- abandoned upstream, real CDN `plupload.full.min.js`/`jquery.plupload.queue.min.js` scripts) -- the only real jQuery/jQuery-UI/datatables.net consumer left anywhere in the app, confirmed via a repo-wide grep (`.sortable()`, `.tooltip()`/`.dataTable()`, bare `.trigger()` call sites, and every stale `dependsOn: ['jquery'/'jquery.ui']` registration are all done now). Done: with plupload's port landing, a repo-wide grep found jQuery itself had zero remaining real consumers anywhere (2 more stale ambient-type leftovers turned up in the process -- `enableShiftClick()`/`.size()`, both already real plain functions with no jQuery call site, just never had their old `interface JQuery` augmentation cleaned up; and `BatchManagerGlobalView`'s own `dependsOn: ['jquery']` plus its `jquery.progressBar` script registration, both dead the same way). `ThemeBaseAssets`'s own unconditional `jquery` script registration (all 3 real layout families, every single page) is removed outright, the dead `interface JQuery` augmentation block is gone from `build/jquery-plugins.d.ts`, and `jquery`/`@types/jquery`/`@types/jqueryui`/`datatables.net`/`plupload`/`@types/plupload` are all out of `package.json`. Verified against the whole app, not just this phase's own files: 89 of 91 golden-html fixtures changed, every single diff being exactly the jQuery/plupload/progressBar script-tag removal and nothing else; all 82 visual-regression baselines confirmed pixel-identical (one, `admin-batch-unit-paged-first`, needed a re-capture after a real but unrelated pre-existing flakiness source turned up -- a fixture photo's own hit counter drifting between test runs earlier in the same suite, not this change); the full Unit/Arch (5578) and Browser suites both green (5 Browser failures were the same pre-existing parallel-run flakiness confirmed earlier in this campaign, not a regression -- all 39 passed in isolation). Non-jQuery scope extension: `chart.js` (2.9.3, current major is 4.x) + `moment.js` (2.26.0, its own maintainers declared it "legacy" in 2020) -- both real, `stats.ts`'s own graph rendering (a repo-wide grep confirmed it as the only real consumer of either) -- done: replaced by a purpose-built canvas line chart, `themes/default/js/vendor/lineChart.ts`, not a generic Chart.js workalike -- narrowed to the one real chart this app ever rendered, in its two real axis modes (a single time-scaled series with a gradient fill; several category-scaled series with a legend, "compare mode"). `stats`'s own built bundle dropped from 453kB to ~11kB (`.size-limit.json` regenerated via `bun run size:update`, which also picked up genuinely stale budgets left over from the jQuery-removal commit above, which never ran it). Two real, confirmed pre-existing behaviors were preserved rather than "fixed": `changeData()`'s own wholesale `chart.options` reassignment silently dropped `maintainAspectRatio: false` on every call after the first (Chart.js's `updateConfig()` re-merges the *current* options against its own defaults, not the original config, and the global default is `true`), so the real rendered chart was always locked to the `<canvas width="400" height="150">` markup's own 400:150 ratio, not "fill the container" -- reproduced directly against the container's real width rather than reintroducing a `maintainAspectRatio` concept this app never got to use; and the gradient fill's own `ctx.createLinearGradient(0, 400, 0, 0)` kept its hardcoded 400px span regardless of the canvas's real ~241px rendered height. One real behavior was deliberately NOT preserved: `moment.locale(lang_code)` never actually took effect in production -- no `moment/locale/*` file was ever imported anywhere in this app (a separate repo-wide grep), so every real deployment silently rendered every date in English regardless of the admin's own language -- `Intl.DateTimeFormat` needs no separate locale data file, so the native port's real `LangCode`-derived BCP-47 locale is a genuine improvement, not a preserved quirk. Verified against the whole app: golden-html regenerated cleanly (the `stats` page's own script-tag/CSS-link changes, plus a `rolldown-runtime` shared chunk that dropped out of 2 unrelated pages' own modulepreload lists once chart.js/moment's own CJS/UMD interop needs went with them); `stats`'s own VR baseline re-captured (a real, expected full-pixel change, not a regression -- a different charting engine renders different pixels by design); typecheck/lint/knip/build all clean. Found and fixed 2 unrelated stale-comment leftovers while touching `build/jquery-plugins.d.ts` for the last time before its own rename (below): `rating_user.ts` still referenced a `declare const GeoIp` this file hasn't carried since geoip was ported to a real endpoint (P49-B group 1), and `eslint.config.ts`'s own any-relaxation comment still blamed jquery-confirm/cluetip/Jcrop/DataTables/plupload for needing it, when none of those types live there any more (only `global_params`/`fullname_of_cat`/one real variadic do). `build/jquery-plugins.d.ts` itself renamed to `build/ambient-globals.d.ts` (P49-C's own final act, user-flagged): the old name stopped describing its real content once the last jQuery-plugin-shaped entries left it, and everything remaining was always genuinely first-party (`Window.SwitchBox`, page-data globals, `AlbumSelector`/`StorageDetails` types, ...), never a jQuery plugin at all -- every real reference to the old path updated alongside it. A second, deeper audit pass (user request, "check the codebase to see if we didn't miss anything") found 3 more dead jQuery-only mechanisms the first pass's own grep-for-the-word-"jquery" sweep didn't surface, since none of the 3 mention jQuery by name at their own call sites: `vite.config.ts`'s own `moment` alias (dead the moment chart.js/moment left, nothing imports "moment" any more); `PageAssets`'s own jQuery-UI known-script-by-naming-convention resolver (`$knownPaths`/`isKnownId()`/`knownPath()`/`knownRequires()`/`resolveMissingDependencies()`/`fillKnownScript()`) -- its only 2 real entries ever, `'jquery'`/`'jquery.ui'`, unreachable from any real `dependsOn` anywhere in the app; and `plupload_code` (`Lang.php`/`Template.php`/4 language packs' own `.po` headers/`tools/i18n/php-to-po-fn.php`), dead since the plupload port removed its only real reader. All 3 removed, verified via full PHPStan/ECS/Unit-Arch plus a golden-html regeneration showing zero diff across all 91 fixtures (confirming each was truly unreachable dead code, not just untested). `underscore` (1.5.2, ancient) -- done, removed outright (confirmed completely unused, zero real call sites anywhere in `themes/`). `piecon` (`github:lipka/piecon#0.5.0`, an abandoned upstream fork pin) -- done, ported natively (`vendor/piecon.ts`, real source read from `node_modules/piecon/piecon.js`) and its abandoned npm package removed. `knip.json`'s own stale `ignoreDependencies` entries for `jquery-timepicker-addon`/`jquery-ui`/`underscore` and its `entry` array's own already-deleted `themes/admin/default/js/pages/history.ts` -- done, cleaned up. | 0 |
| P50 | Lit component catalog (conditional on P49) | Skipped — P49-B ported every vendored widget natively to vanilla TS, including this entry's own named candidates (selectize for tag autocomplete, jqtree for tree picker); no widget was left needing a framework, and no `lit`/`lit-element` dependency exists anywhere in `package.json` | 0 |
| P51 | TS modernization | In progress — P51-A through E done (P51-D closed with a narrower final scope than planned — see its own entry below for the `album_selector.ts` cluster excluded outright); P51-F through L scoped, not started; P51-M (third-party plugin exploration) measured and deferred until P51-A–L land | 17 |
| P52 | CSS architecture modernization | Not started — Tailwind call resolved (not adopted), work itself unstarted | 0 |
| P53 | Picture pipeline (new feature) | Not started | 0 |
| P54 | Dark mode (new feature) | Not started | 0 |
| P55 | Real quality gates | Not started | 0 |
| P56 | Codebase-wide non-DI audit | Not started — found during P43-G's own review, extended codebase-wide; see its own plan detail below | 0 |
| P57 | `default`/`standard_pages` theme-duplication investigation | Done — documentation-only phase, no code changed; recommends keeping both trees pending 2 prerequisites (see plan detail below) | 0 |
| P58 | phpstan-latte CAMPAIGN-PENDING: type the View→template boundary, then modernize the templates | **DONE** — **A 843 → 0, B 376 → 0**, and the CAMPAIGN-PENDING block is gone from `phpstan.neon`. All 26 identifier-wide ignores retired, each forced out by `reportUnmatchedIgnoredErrors` rather than noticed; 2 more left the *permanent* groups (`empty.variable`, `foreach.valueOverwrite`). Twenty-three live bugs found and fixed along the way, and four gaps closed in the compile step itself | 1 |

Two adjacent, non-phase-numbered tracks, both not started:

- **FrankenPHP worker mode** (SEC-60, a P7 gap) — `docker/Caddyfile` is
  still plain `php_server`, with no `worker` block.
- **Legacy import tool** (`bin/piwigo import:legacy`) — no
  `import:legacy` or `ImportLegacy` reference exists anywhere. This is
  T2 adoption tooling, not a cuttable rider.

## Conventions

- **Kind**: REPLAY (a reference implementation exists on `16.x-rewrite`,
  reproduce it) vs. GREENFIELD (net-new, needs its own design step
  first).
- **Tier**: T1 Core-parity (required to match `16.x-rewrite` behavior),
  T2 Modernization (clear-ROI infra/quality), T3 Stretch (cuttable
  without blocking a release).
- **Working rule**: no change lands unless all CI gates pass on a clean
  checkout — CI, not a local worktree, is the source of truth for
  "green." Tool baselines ratchet; issue counts only go down. A later
  "resolve N failures" commit is a smell, not a milestone.
- **Additive-only foundation**: P0–P1 install tooling and record
  baselines without modifying first-party code; the first code-modifying
  pass is gated on the P2 regression harness being green against
  pristine `origin/16.x`.
- **Reference branch**: `16.x-rewrite` (`../piwigo16-rewrite`) is a
  read-only design target. Reproduce behavior; never `git checkout` or
  cherry-pick from it.

## Phase detail

Current tool and system state lives in `docs/REFERENCE.md` and is not
duplicated here. This section records what each phase delivered and what
is still open.

### Epoch A — Foundation (P0–P4)

**P0 — PHP tooling + baselines.** Pest and plugins, pcov, ECS, PHPStan,
Psalm, Rector, Deptrac (config deferred to P6), ComposerRequireChecker
and ComposerUnused, PHPBench, roave/security-advisories — additive only.
Baselines recorded, not yet gated. ECS and Rector became code-modifying
passes later; Psalm's history is in P5.

**P1 — Frontend tooling + baselines.** bun, Vite, TypeScript, ESLint,
Stylelint, Vitest, knip, size-limit, commitlint, Lighthouse CI and
`web-vitals`. `web-vitals` was installed but never wired to an endpoint;
closed with `build/vitals.ts` + `VitalsController` + route, log-only.

**P2 — Test harness.** Env split (`.env.test`, `X-Piwigo-Env: test`),
fixture DB (`tests/Fixtures/piwigo-17.0.sql`), Pest Browser E2E and WS
Contract suites.

**P3 — CI pipeline.** `ci.yml` job layout, matrix, caching; actionlint,
commitlint, SBOM and OSV jobs, OpenSSF Scorecard. 32 jobs today.

**P4 — Containerization + runtime image.** Multi-stage Dockerfile
(FrankenPHP plus Apache-fallback targets), Compose, Helm chart,
`/health` and `/ready`, restore drills, SEC-01 web-root deny rules
across all three server targets.

### Epoch B — Composer/Rector/PHPStan + PSR-4 (P5–P6)

**P5 — Composer + Rector + PHPStan.** The largest phase by commit count.
Whole-codebase ECS `--fix`; PHPStan bleeding-edge rules applied
file-by-file across the legacy tree; vendored third-party library
replacement per the native-platform-first policy (PHPMailer → Symfony
Mailer, Emogrifier → `pelago/emogrifier`, phpqrcode → `endroid/qr-code`,
vendored Smarty → `smarty/smarty`, phpass → native `password_hash()`,
`mdetect.php` dropped with no replacement).

*Rector* is fully configured today: `withPhpSets(php85: true)` and
`withPreparedSets(typeDeclarations: true, instanceOf: true)` are both
active, plus `withImportNames()` and `withParallel()`. Both rule sets
were applied tree-wide (`c49a00014d`, `0bfc324f59`). Still narrower than
the reference implementation's set (no `withComposerBased`, no explicit
`SetList::TYPE_DECLARATION`, no strict-types or dead-tag rules), and the
`rector` CI job stays `continue-on-error: true`.

*Psalm* had a long history: gating paused here when its global-function
resolution failed against the still-non-namespaced legacy tree
(investigated properly — cache staleness and parallel-worker races ruled
out; concluded a real tool limitation at this codebase's shape). Dropped
as a dependency 2026-08-07 over a Pest 5 conflict, then reinstated
2026-08-11 (`4118adbb85`, pinned to `7.x-dev` because the latest tagged
release caps `sebastian/diff` below Pest 5's floor) after fixing
`psalm.xml`'s drifted paths and patching a real `7.x-dev` crash via
`composer-patches` (`StatementsAnalyzer` reads two properties it never
declares). Live dependency today, still non-gating — no CI job, no
composer script.

#### PHP language features not yet adopted

Every 7.0–8.3 feature is either heavily used or correctly inapplicable.
Real remaining candidates:

- **Multi-catch (7.1)** — `Http\HttpClientService.php:245-247`: two
  adjacent catches both `return null;`. The only real candidate; every
  other adjacent-catch site has genuinely different per-type handling or
  a deliberate rethrow-vs-swallow split that must stay separate
  (`Controller\ImageDerivativeController`'s `ResponseReadyException`
  rethrow past a broader `Exception` catch is security-critical — a
  private album's derivative was once served to an anonymous request
  when that ordering broke).
- **`json_validate()` (8.3)** — unaudited. Any `json_decode($x) !== null`
  used only for validity is a direct replacement.
- **`array_find`/`array_any`/`array_all`/`array_find_key` (8.4)** —
  unaudited. `foreach`+`break` and `array_filter()`+count-check patterns
  across the domain services are the target.
- **Native `#[\Deprecated]` (8.4)** — not currently needed (zero shims
  remain), but the right default if a transitional shim is ever needed
  again.
- **`array_first()`/`array_last()` (8.5)** — unaudited.
  `reset()`/`end()`/`$arr[0]`/`$arr[count($arr) - 1]` are the target.
- **`#[\NoDiscard]` (8.5)** — unaudited. Methods returning a validation
  result or a success flag a caller could silently ignore.
- **Pipe operator (8.5)** — 34 call sites with 3+ levels of nested calls
  found as a candidate pool, not individually read.

Property hooks and asymmetric visibility (8.4) are **done**, not a
candidate: `Config\CurrentConfig` declares every key as
`public private(set) TYPE $name` (5225 lines down to 2626, real
boilerplate removed), call sites were converted project-wide
(`e6bdedf369`), and `ConfigService::confUpdateParam()`'s external write
path uses `ReflectionProperty::setValue()` against the
asymmetric-visibility property rather than a setter.

**Open question — device detection.** `Core\DeviceHelper::getDevice()`
has a single writer, unconditionally sets `'desktop'` on every new
session, and no User-Agent parsing exists anywhere; the only path to the
mobile theme is an explicit `?mobile=1`. Its own comment says this is
deliberate ("the v17 responsive CSS removes the need for a separate
mobile theme via device detection"). The reference implementation kept
`mobiledetect/mobiledetectlib` and built a real
`Http\DeviceDetectionService`. Nothing records whether that approach was
deliberately rejected in favor of responsive CSS, or whether the comment
is unvalidated rationale nobody reversed.

**P6 — PSR-4 namespace migration.** Extracted every first-party class and
interface declaration out of `include/` and `admin/include/` procedural
files into `src/Piwigo/` under the `Piwigo\` prefix — 66 classes across
33 origin files. Extraction and namespacing only: no renaming to modern
casing, no DI, no behavior changes beyond what the move forced.
Established the 6-layer Deptrac model (L0Data → L4Integration, with an
L2a/L2b domain split), enumerated per-namespace rather than by
catch-all regex so a later phase adding a namespace must deliberately
choose its layer.

Deptrac 4.6.2 silently breaks ruleset resolution when a layer name
contains a hyphen — the original `L0-Data`-style names made every legal
cross-layer dependency misreport as a violation. Fixed by dropping
hyphens from every layer name.

### Epoch C — Kernel & HTTP foundation (P7–P12)

**P7 — Kernel + boot skeleton.** `Kernel`, `CommonBootstrap`,
`public/index.php`, fast paths.

**Open — SEC-60, worker mode.** The FrankenPHP worker loop was never
implemented: classic per-request execution on the FrankenPHP binary, not
true worker mode. Originally deferred past P23 on the reasoning that
bootstrap-chain replacement changes what state needs resetting; P23 is
long done and this has not been picked back up. The related `reset()`
arch-test coverage *was* closed (31 classes today, up from 13).

**P8 — DI container.** `Container`, `config/container.php`, PHP-DI
autowire-by-default.

**P9 — PSR-15 middleware + routing.** Originally a 7-stage pipeline
(`ExceptionHandler`, `SecurityHeaders`, `Session`, `ServerTiming`,
`Sentry`, `Routing`, `ControllerInvoker`); grew to 13 stages under
workstream C3 Phase 1 (see below) — `ConfigBootstrap`,
`PluginBootstrap`, `Admin\LoadedPlugins`, `UserResolution`, `Language`
and `FinalizeBridge` now sit between `Sentry` and `Routing`, per
`RequestPipeline::DEFAULT_MIDDLEWARE`'s own current list. Routes, an
extensible `SecurityHeadersMiddleware`, cross-server SEC-01 deny rules.
SEC-11/SEC-12 were closed here: `CsrfService` used `hash_hmac('md5', …)`
plus `===` long after the identical pattern was fixed in the sibling
`AuthService`/`EphemeralKeyService`; it now uses `sha256` and
`hash_equals()`. (SEC-12's own claim of "closed here" held for
`CsrfService` itself but not for the WS layer's independent copy of the
same check — see the SEC-12 checklist row below.)

**Open question — pipeline composition.** The reference implementation's
own pipeline (in its `Core\Kernel.php`) has eight stages:
`SecurityHeaders`, `ExceptionHandler`, `Session`, `Auth`, `Filter`,
`Csrf`, `Routing`, `ControllerInvoker`. This is not "one stage missing"
but a different composition: the reference has `Auth`/`Filter`/`Csrf` as
real pipeline stages that this fork has no equivalent class for at all,
while this fork adds `ServerTiming`/`Sentry` that the reference lacks.
Whether auth, CSRF and filter checks moved into services and controllers
deliberately or by omission is not established anywhere. SEC-42 ("CSRF
middleware: remove the `/admin*` exemption") implies no such middleware
yet exists to have an exemption from.

**P10 — Observability.** Monolog channels, Server-Timing,
OpenTelemetry-first (OTLP → Sentry/Tempo/Jaeger). Greenfield.

**P11 — Cache + session + messenger + `opcache.preload`.**
`symfony/cache` pools, session handler, Messenger, preload list. The
named-pool design (`config`, `permissions`, `category_tree`, `tag_cloud`,
`rate_limiter`, `general`, each with its own TTL) was initially never
built — `CacheFactory` produced one generic pool with no real consumers.
Closed with `CachePools`; `rate_limiter` stays unbuilt as genuine P28
scope. Messenger itself is real and wired (`config/messenger.php`, five
`Piwigo\Job\*` classes plus handlers).

**Open — a failed job is invisible and unmanageable.** Nothing anywhere
queries the `messenger_messages` transport table. If a
`SendNotificationEmailJob`, `GenerateDerivativeJob`, `BatchUploadJob`,
`ReindexImagesJob` or `RegenerateAllDerivativesJob` fails, there is no
way to see it, retry it, or purge it. The reference implementation has
`Job\MessengerRepository`/`Job\FailedJob` backing an admin batch-manager
queue dashboard; building the equivalent repository plus a small admin
view is the fix.

**P12 — CLI tool + backup/restore + graceful shutdown.** `bin/piwigo`,
`BackupService`, `ShutdownHandler`/SIGTERM cleanup, PHPBench. All four
`maintenance:*` commands (`orphan-tags`, `purge-history`,
`purge-sessions`, `repair-db`) were planned but initially unbuilt; all
four are real now, `repair-db` last because its backing logic lived in a
legacy file P23 still had to absorb.

### Epoch D — Config/DB/language (P13–P16)

**P13 — Config service.** 277-entry `SCHEMA`, `ConfigLoader`, typed
accessors. The `$conf` → `Config` migration had stalled at 72 files
still reading `global $conf`, not from an incomplete migration but
because `Config::` accessors were provably unsynced with DB-persisted
values: `ConfigService::loadConfFromDb()` wrote into the legacy `$conf`
global and never into `Config::$data`. That was the root cause of a real
shipped bug (`CsrfService` reading an empty `secret_key`). Fixed by
making the DB write paths update the live config object too, and
finished by Track A below — zero `global $conf` reads today.

The class names in that story have all changed since: `ConfigDb` was
merged into `Piwigo\Config\ConfigService`, and `Config::override()` and
`::delete()` no longer exist — the typed-`CurrentConfig` refactor
replaced them with reflection-based property writes. The underlying fix
holds under the new names.

**Open — `#[Required]`/`#[Sensitive]` are read but never called.** Both
are still empty attribute classes, but each now has a genuine
reflection-based reader: `ConfigLoader::validateRequired()` throws
`MissingRequiredConfigException` for a missing `#[Required]` property,
and `CurrentConfig::dumpForLog()` returns every property with
`#[Sensitive]` ones replaced by `str_repeat('*', 8)`. Neither is called
from anywhere. The remaining task is wiring those two calls into boot and
the error handler, not building them. Auditing which other properties
should carry `#[Sensitive]` (mail and API credentials) is also open;
today only `secretKey` and `smtpPassword` do.

**P14 — DB layer + Doctrine ORM.** The "repositories as real
`EntityRepository` subclasses from day one" design was initially followed
only for `ConfigRepository`; every domain repository built in P17–P21
used `AbstractRepository` + `Tables::` (DBAL) instead. That was migrated
under P24 and is finished: `Db/AbstractRepository.php` no longer exists
and nothing extends it.

39 domain repositories today, split two ways. (The glob below counts 40:
`Db/TypedRepository` also matches it and is not one — it is the helper
that narrows a generic `getRepository()` return to the concrete custom
class.)

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find src/Piwigo -iname "*Repository.php" | wc -l' expect="40" -->

- **24 extend `Doctrine\ORM\EntityRepository`** — not Symfony's
  `ServiceEntityRepository`, which stays unused because this codebase
  does not run on the Symfony framework or DoctrineBundle.
- **15 extend nothing**, holding `EntityManagerInterface` by constructor
  injection: `Permission`, `Auth`, `Auth\ApiKey`, `Auth\Password`,
  `Metadata`, `Permalink`, `Admin\Maintenance\Db`,
  `Admin\Extensions\Extension`, `Calendar`, `Search`, `Section`,
  `Notification`, `Mail\Recipient`, `Category`, `Users\User`. Each
  touches tables *other* repositories own, reaching them via DQL for
  simple writes or plain DBAL for reads and dynamic fragments, never
  claiming ownership of a table itself. `SearchRepository`'s docblock
  states the rationale: query, column and operator combinations that vary
  per caller "has no DQL representation."

*Doctrine Migrations history*, which explains a confusing artifact: the
decision was reversed on 2026-07-24, before any real install existed, in
favor of a static hand-maintained `install/piwigo_structure-mysql.sql`;
`doctrine/migrations` was briefly not a dependency at all. Migrations
were reinstated for real during the pgsql-support pass. See "Migration
path" below for the mechanism today.

**P15 — Schema migration + multi-provider.** InnoDB and utf8mb4
uniformly, 7 new tables, FK constraints, `audit_log` (SEC-57). The cache
tables originally got engine and charset only, with type normalization
skipped; `user_cache` and `user_cache_categories` were later dropped
entirely once every consumer moved onto TTL cache pools, and
`history_summary` got its own type fix (`summary_id` AUTO_INCREMENT PK).

**P16 — Typed facades + constants retirement + language.** `Paths`,
`CurrentUser` and `PageState` facades, 52 `define()` constants retired,
`.po` migration, ICU MessageFormat pluralization. `src/Piwigo/Template/`
had zero dedicated Unit coverage (only indirect Browser exercise); all
eight classes with real logic have real `tests/Unit/Template/` coverage
now.

### Epoch E — Service layer (P17–P23)

**P17–P20 — Domain tiers 1–4.** ~35 domain namespaces migrated in
dependency order, each tier depending only on the ones before it. **Tier
1** URL, Cookie, Session, HTML, Storage, Csrf, Permalink, Site, Feed.
**Tier 2** Mail, Filter, Users, Auth, Tag, Comment, Rate, Group, Caddie,
History, Activity. **Tier 3** Category, Search, Image, Calendar,
Notification, Metadata, Telemetry, Validation, Common. **Tier 4** Page
renderers, Menu, PluginConfig, Section, Job. Each domain's legacy
`include/` file was deleted immediately after its migration, not batched
to the end.

Two naming notes that look like gaps and are not: Cookie was built as
`Piwigo\Auth\CookieService` rather than a standalone namespace, and the
User namespace is `Users`, plural.

**Open — two `Common` gaps.** `src/Piwigo/Common/` is real: 19
`ValueObject/` classes, 3 `Enum/`, 2 `Dto/`, built from `063fd2ae30`
onward. Two originally-named items were never built: no `AbsPath`/
`RelPath` path-value-object layer exists anywhere, and no centralized
`Privacy` enum exists (only `Users\UserStatus`, which stays
domain-local by design).

**P21 — Admin controller migration.** "62 admin pages" was never a target
count of services to build — it is the `origin/16.x` raw `admin/*.php`
file count being replaced. `config/admin_pages.php` maps 37 page slugs to
`AdminSubControllerInterface` services, matching the 36 classes that
implement the interface.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rl "implements AdminSubControllerInterface" src/Piwigo --include="*.php" | wc -l' expect="36" -->

Dispatch is `Bootstrap\AdminDispatcher::dispatch()`, built decomposed
from the start: the reference implementation's god-classes
(`MaintenanceController`, `MiscController`, `BatchManagerController`)
were never reproduced as monoliths here, and the same rule was applied to
admin PEM services.

**P22 — Frontend controller migration.** Scoped as 21 controllers; 19
were built. The two absences are both deliberate consequences of design
decisions, verified against branch-scoped history (not `git log --all`,
which mixes in `16.x-rewrite`'s unrelated history):

- `Install` was never meant to be a controller. `public/install.php`
  stays a special unrouted entry point — it must work before any DB or
  config exists, so it cannot go through the DI-resolved router — backed
  by `Bootstrap\InstallBootstrap` + `Admin\Install\InstallWizard`.
- `Upgrade`/`UpgradeFeed` were never built on any branch of this fork.
  Consistent with the clean-fork stance and the later deletion of the
  entire `DbPatch`/`VersionUpgrade` chain: there is no upgrade mechanism
  left for them to drive.

`GalleryController` initially only relocated
`include/section_init.inc.php`'s `include()` call into the controller —
the ~450 lines of SQL logic that belonged there (`$page['items']`,
favorites, next/prev navigation) was never absorbed. Folded into P23's
Gallery/Picture absorption batch.

**P23 — Legacy deletion & cleanup.** `include/` and `admin/` are fully
deleted as directories, all `$GLOBALS` and static-bridge globals are
retired, the legacy `Tables`/`AbstractRepository` DBAL layer is gone, and
the event-dispatch, `l10n()` and URL free-function bridges are retargeted
onto real classes. Zero `global $x` statements, zero live `$GLOBALS`
reads, zero bare legacy free-function calls in `src/Piwigo/` — each
guarded by a zero-tolerance Arch test.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rE "^[[:space:]]*global[[:space:]]" src --include="*.php" | wc -l' expect="0" -->

Four documented divergences from the original P23 plan:

- `include/` kept a 4-file bootstrap seam through P23's own batches,
  since SEC-60 needs `define()`s to stay out of `src/Piwigo/`. Closed
  later anyway — the seam collapsed into
  `Piwigo\Bootstrap\RequestBootstrap` during Track A7.
- Root entry points (`admin.php`, `picture.php`) were kept as thin shells
  rather than collapsed into one front controller; this fork keeps
  Piwigo's original URL surface. They have since moved into `public/` as
  part of web-root isolation.
- The `$GLOBALS` retirement bullets were audited and deliberately not
  executed in P23: the plan's premise (zero callers remain after
  `include/` deletion) did not hold, since ~230 `src/` files had real
  live `global $x` contracts preserved verbatim by the migration
  discipline. Tracked as Track A under P24 instead.
- `Tables.php` and `AbstractRepository` were kept pending the post-P23
  ORM remediation, now done.

Gaps that a 2026-07-13 full P0–P22 audit found in P23's own manifest are
all closed: 43 column-type migrations (mechanism changed with the
Migrations reversal; four columns' `serialize()` leaks fixed end-to-end,
plus a fifth, `activity.details`, found the same way); the typed
DTO/Projection pattern; per-namespace Unit coverage, 11 of 11; `CachePools`
wiring; `die()`/`exit()` elimination in the image-processing paths;
`reset()` arch-test coverage; `maintenance:repair-db`; install/upgrade
legacy constants plus a real `PWG_CHARSET` bug; and a repo-wide legacy
sweep round 2.

**Partially closed — the request-lifecycle architecture (workstream
C3).** 11 real `die()`/`exit()` call sites remain project-wide, across 9
files (`tests/Arch/StructuralTest.php`'s own count-based allowlist is the
live, machine-checked source of truth for this number). One is correct
by design (`Core\ShutdownHandler`'s `exit(143)`, the conventional
128+SIGTERM code). The `header()`+`echo`+`exit()` / `: never`-return
contract question this workstream exists to answer is no longer
"designed in outline only" — Phases 0 and 1 landed real code:

- **Phase 0 (done).** `Http\MiddlewarePipeline::handle()` now catches
  `Http\ResponseReadyException` at every nesting level, not just the
  innermost middleware — a real, previously-untested gap where a
  short-circuit thrown by an outer-ish middleware would have been logged
  as an unhandled error, Sentry-reported, and answered with a generic
  500 instead of the real response, silently losing security headers
  and the `Server-Timing` header along the way.
- **Phase 1 (done).** `Bootstrap\RequestBootstrap::connect()` (~180
  lines) is deleted outright and the first half of `finalize()` (~220
  lines) decomposed into 6 real PSR-15 middleware
  (`Http\Middleware\ConfigBootstrapMiddleware`/`SessionMiddleware`/
  `PluginBootstrapMiddleware`, `Admin\LoadedPluginsMiddleware`,
  `Bootstrap\UserResolutionMiddleware`, `Http\Middleware\
  LanguageMiddleware`) plus a `Bootstrap\FinalizeBridgeMiddleware`
  bridging into `finalize()`'s still-Template-dependent remainder,
  wired into `RequestPipeline::DEFAULT_MIDDLEWARE` (P9, above).
  `bootEntryPoint()` shrinks to just `configure()` +
  `InstallationFlag::mark()`. Caught and fixed along the way: `public/
  admin.php` never called `RequestPipeline::handle()` at all (a
  pre-existing bypass — see item 1 below, now closed) and would have
  silently lost the entire admin panel's DB/config/session/plugin/user/
  language bootstrap once `bootEntryPoint()` stopped doing that work
  directly; fixed with a new `RequestPipeline::runBootstrapPhase()`
  entry point `admin.php` calls explicitly.
- **Phase 2 (not started, gated).** The still-legacy theme/`Template`
  construction remainder of `finalize()` needs P40/P41's own `Renderer`/
  typed-view-object shape to land first — building middleware around the
  current `Template` class would mean redoing the work once that class
  is deleted.
- **Phase 3 (not started, investigation only).** Whether `Admin\
  AdminShell`/`admin.php` become real `ControllerInterface`s routed
  through the unified pipeline, or stay a deliberately separate
  dispatcher, is not yet decided.

**Open question — site-local config overrides.** A real bug was found and
fixed on 2026-07-21 (`338217f48`): nothing in `src/Piwigo/` ever read a
site's local config override file on a real request, silently ignoring
any non-DB-credential key (`order_by_custom`, `data_location`,
`guest_id`) a site had customized. The fix
(`LocalConfigOverrides::read()`) was deleted three days later
(`feede75c9`) as part of a much larger deliberate redesign.
`ConfigLoader::applyDefaults()` and `applyEnvOverrides()` are genuine
no-op bodies today, and the only surviving local-file mechanism is
`Piwigo\Config\DeploymentPolicy`, sourced from a differently-formatted
`local/config/config.php` and explicitly scoped to security-boundary
settings that its own docblock says "never overlaps with CurrentConfig
(DB)." Whether arbitrary site-local overrides of ordinary settings are
meant to be reachable some other way now, or whether this is an
unintentional regression, is not recorded anywhere.

### Epoch F — Post-P23 remediation & hardening (P24)

**In progress.** This formalizes the `(p24)` commit-tag convention as
this file's real P24, rather than leaving a status-table row diverging
from the tags. What landed under `(p24)`, plus the `(sql)`, `(di)`,
`(lang)` and `(p27)` work that is the same effort in substance, is the
post-P23 remediation several phases above promised. These tracks were
tracked in their own planning documents at the time
(`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`); folded in
here so there is one record.

#### DBAL → ORM migration

The P14 remediation. Every domain repository moved off
`AbstractRepository` + `Tables::` onto real Doctrine `EntityRepository`
plus attribute-mapped entities, or onto a directly injected
`EntityManagerInterface`. `SectionRepository` was last (`c4125eeb43`), at
which point `Db/AbstractRepository.php` was deleted outright.

The real finding was the shared Doctrine identity map serving stale data
after bulk or raw writes outside the ORM, needing `HINT_REFRESH` for
reads or `clear()` after bulk operations. Hit twice while converting
repositories, then found to be repo-wide: a dedicated audit
(`cb956266b`) found **33 call sites across 13 files** where
Controller/`Ws`/Admin classes bypass their domain repository and write
via `BatchWriter` or raw `executeStatement()` against a table an entity
now maps.

This needed a new accessor, not a per-call-site fix.
`Piwigo\Db\EntityManagerFactory::build()` is not memoized — it always
constructs a fresh `EntityManager`, so clearing a locally built instance
protects nothing. The only genuinely shared identity map lives on the DI
container's `EntityManagerInterface` singleton, reachable only through
`Kernel::container()`, itself arch-test-restricted to `Bootstrap/`.
`Bootstrap\InfrastructureAccessor::entityManager()` was added so
L4Integration classes can legally reach and clear that shared instance.

Two sites were deliberately left unfixed, not missed:
`InstallWizard.php`'s pre-seed writes (the container's entity manager
would wrap a connection built from stale pre-seed credentials — clearing
it would be risky and pointless) and
`UserService::checkAndSaveUserInfos()` (its only real caller builds a
fully isolated factory/repository chain with no container involvement, so
there is no shared identity map in that path).

#### Track A — `$GLOBALS`/static-bridge retirement

Batches A1–A8, smallest and lowest-risk first. `$template` →
`Piwigo\Template\CurrentTemplate`; `$lang` → `Lang::t()` plus new bulk
accessors; `$user` → `CurrentUser::get()`; `$conf` → the config service;
`$page` → `PageState` (nine sub-batches, the one global without a
complete existing target — real design work, not a mechanical retarget);
then ~25 smaller globals (`$my_base_url`, `$logger`, `$mysqli`,
`$prefixeTable`, `$filter`, `$pwg_loaded_plugins` and more); then
collapsing `include/common.inc.php`'s raw seeding into real object
construction (A7) and deleting the `attachGlobals()` bridge shape (A8).

Two real production bugs surfaced here rather than being mere retargets:
`CurrentUser::get()` had never actually worked for real users — it only
ever seeded a guest placeholder — and the config sync bug described under
P13. A8 is partial by documented judgment: the method *names* were kept
as the per-request seeding entry point on `CurrentUser`, `PageState` and
`Lang`, since nothing needs a `$GLOBALS` bridge anymore but a seeding
call still runs once per request.

#### Track B — event dispatch retarget

Free-function elimination landed first: `add_event_handler()`,
`trigger_change()` and `trigger_notify()` deleted, all 240 call sites
retargeted onto the dispatcher directly. Then the actual point — typed
event objects replacing bare-string-keyed dispatch — across 12 domain
batches. 157 event classes at the time; P34 (below) later pruned dead
ones to 127, then added 2 more closing its own catalogue gap.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find src -path "*/Event/*.php" | wc -l' expect="128" -->

`triggerChange()`/`triggerNotify()` were originally kept as "permanent"
for `'trigger'`, their own internal meta-notification channel, then
deleted outright once it turned out nothing had ever registered a handler
against `'trigger'` in the first place. A token-aware arch test now
enforces zero string-keyed dispatch call sites at all, with no
exception.

**Superseded by P34.** P29.11 recommended keeping the hand-rolled
`EventDispatcher` and closing its gaps in place rather than swapping to
Symfony's, on the reasoning that `addEventHandler()`'s string-keyed
legacy handlers, `includePath`-based lazy inclusion and
`callablesEqual()`'s closure-identity dedup are Piwigo-specific mechanics
Symfony does not provide, so adopting it would mean rebuilding all of
that on top of rather than instead of Symfony's class. **P34 reverses
that**, because it deletes those three mechanics rather than preserving
them — none has a production caller. The gaps P29.11 did close stand:
PSR-14 conformance, descending priority order matching Symfony's
convention, and `StoppableEventInterface` support.

One P29.11 finding is still open and unrelated to the swap: three of 23
registrations in `RequestBootstrap.php` eagerly construct a one-off
service regardless of whether the event ever fires. That is a call-site
problem, not a dispatcher-class limitation.

#### Track C — `l10n()`/`get_root_url()` retarget

`Lang/functions.php`, `Url/functions.php`, `Category/functions.php` and
`Http/functions.php` all deleted; `composer.json` has zero
`autoload.files` entries left. Real finding: `Piwigo\PluginConfig` had to
split across two Deptrac layers mid-migration, because a free-function
call creates no Deptrac dependency edge but the direct class reference
this migration introduces does — 14 real L1/L2a callers of
`EventDispatcher` were invisible to Deptrac until then. Same class of
finding as P6's hyphen bug: suspect the tool's model of the code, not
just the code.

#### Sweeps and stabilization

- **Repo-wide legacy sweep round 2** (2026-07-18/19). Six `global` sites
  outside `src/Piwigo/` fixed; `Ws/PwgImages.php`'s five raw `die()` JSON
  calls retargeted onto its typed error path, fixing a real latent bug
  (the old `die()` always emitted JSON even when the client asked for
  `format=rest`); `LegacyRenderCapture`'s void-renderer pattern converted
  to return-string. The DbPatch/VersionUpgrade bound-parameter work in
  this sweep is moot: the entire subsystem (153 files) was deleted the
  next day (`8224f23a3`) as contradicting the project's own clean-fork
  design — it had been ported over mechanically before anyone caught the
  conflict.
- **Test coverage** (`9f5198bfe`, 2026-07-26). Closed a 70-class
  zero-coverage gap found via combined Unit+Arch+Integration+Contract+
  Browser measurement. 220 of 539 classes were only invisible because
  Contract and Browser coverage was not measurable before that session's
  pcov work. Combined line coverage went from 10.6%/15.6% siloed to
  65.07%.
- **Coverage-gap Wave 1** (2026-07-27/28). The tail after the above.
  Real bugs found: a metadata-sync bug, four in the `Ws` domain, and
  `PasswordController` silently discarding lockout and expiry errors.
- **Full-suite stabilization** (2026-07-28 to 07-31). Browser, Contract
  and combined Unit+Integration suites made green by root-causing every
  failure from a full re-run rather than re-running until it passed. Real
  bugs: a picture-derivative cookie test assuming an IPv4-only client, a
  watermark write-access test leaking permanent debris, added_by and
  multi-filter search tests sensitive to ambient config drift.
- **Mutation-testing sweep** (2026-08-01, batches 20–31+). Real
  previously-undiagnosed bugs that PHPStan and ECS did not catch:
  `SessionRepository::gc()` and `LoungeMaintenance::needsEmptying()` read
  the real wall clock instead of `Env::now()`;
  `UrlService::getAbsoluteRootUrl()` appended a stray trailing colon;
  `Inflector_fr.php` had corrupted `é` regex literals; `Translator`'s
  day/month reassembly had an untested gap;
  `SentryBootstrap::resolveOptions()` needed extracting to fix a
  risky-test SDK leak; a deprecated `trigger_error(E_USER_ERROR)` became
  `ErrorCollector::recordFatal()`; `UploadService` leaked its process
  umask. Confirmed-equivalent mutants were documented as such rather than
  suppressed.
- **SQL bound-parameter sweep** (2026-08-01, 16 commits). Remaining
  raw-string SQL splices converted to bound parameters across the Image,
  Category, Tag, Search, Comment, History, Notification, Group, Activity,
  Config and Calendar domains and the `Db/` layer itself (a new
  `SqlCondition` carrier introduced for it). Found and fixed several
  real live SQL injections, not just style: three in `ImageRepository`,
  one in Comment, one across History/Notification, a plugin-hook
  injection in `TagRepository::findIdByWhereFragment()` (SEC-19), and
  `CategoryRepository::countByVisible()` in a same-day re-audit.

#### Singleton/DI elimination campaign

2026-08-02 to 08-06, 74 commits, **complete**. A 10-phase campaign that
grew a close-out Phase 12 with six lettered sub-phases once a handful of
"permanent exception" shims turned out to be closeable after all.
Converted every static-singleton and service-locator anti-pattern — ~55
classes across three shapes, plus the entire `Piwigo\Ws\*` static-dispatch
layer as its own phase — to constructor-injected DI.

The motivation was not style: SEC-60 worker-mode request isolation needs
no process-persistent static state, and FrankenPHP worker mode is a
committed future direction incompatible with it as-is.

Mechanism: a transitional `@deprecated`-tagged static shim per class for
callers not yet converted, tracked via a shrinking arch-test allow-list,
with a hard "zero shims remain" gate. That gate is met for real — a
strict `^\s*\*\s*@deprecated\b` search returns zero hits. Phases 0–11
converted every class with production callers; Phase 12 closed the last
dozen shims that had zero production callers left but real test debt,
from 4 test sites (`CurrentLogger::getStatic()`) up to 1,382
(`CurrentConfig::current()`, the campaign's final shim). `Kernel` never
carried such a tag and was never a shim — it is the one principled DI
root every system needs.

#### Typed template contexts

2026-08-08, 87 `feat(template)` commits, **complete**. Every file calling
`Template::assign()` with a real key converted to a
`final readonly class FooPageContext implements TemplatePageContext` plus
a single `assignContext()` call. Zero `Template::assign()` calls with a
string or array key remain in `src/Piwigo`. 130 context classes shipped
when this phase closed; **28 remain**, P40 having replaced the rest with
typed `View` classes — which is also what shrank P58-A, since a
`{templateType}` template takes its variable types from the View's own
reflected properties rather than from a context's `array<*, mixed>`.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rl "implements TemplatePageContext" src/Piwigo --include="*.php" | wc -l' expect="28" -->

Four sites are correctly excluded and carry an explicit comment saying so
— the assign *key* itself is caller-chosen or per-instance-mutable, not a
fixed page var (`Calendar/CalendarBase.php`, `Category/CategoryService.php`,
`Html/HtmlService.php`, `Admin/Tabsheet.php`).

**Open — 18 correlated-nullable violations.** The campaign's own
convention (one flat `readonly` class, every var an independently
nullable property) is wrong whenever fields are actually correlated:
always null together, always set together, or representing a real
alternative. A flat bag then lets a caller construct combinations that
can never happen. A systematic audit of all 32 shipped classes with 2+
nullable constructor properties — the necessary precondition; the rest
are excluded by construction — found 18 real violations, none fixed.
One, `UpdatesPwgPageContext`, has a docblock actively claiming "every
optional field here is genuinely optional," which is false. Per-file fix
specs live in that campaign's own plan file.

#### Type correctness + mixed elimination

The original plan's P27, merged here. 89 `(p27)` commits continuing the
mixed-elimination sweep from the `(p25)` work: replacing ambient `$_POST`
and `$_GET` reads across dozens of controllers with typed Request DTOs,
one per action or param cluster. Real bugs fixed along the way: a SQL
injection via a raw `cat_id` superglobal read in the cat-modify renderer,
a stale `$_POST` dead write in `AlbumSubController`, comment
rejection-reason tracking moved off `$_POST` onto `PageState`. A SEC-40
arch test locking in "no raw superglobal reads outside a Request DTO" is
live and passing in `tests/Arch/StructuralTest.php` — the literal string
"SEC-40" no longer appears in the test's own text, persisting only in
~78 Request DTO docblocks and this file. Naming drift, not a functional
gap.

Not complete: no "0 remaining" claim has been verified.

**On measuring `mixed`.** The raw token count keeps climbing with new
code and is the wrong metric on its own, because a large legitimate
by-design residual will always exist: DBAL scalar-narrowing closures,
`ValueObject::tryFrom()`, `Db/Type::convert*()` vendor-dictated
signatures, the WS RPC layer's protocol params, PSR-3 logger context. The
useful question is the per-module Projection-wiring gap — a repository or
service method still declaring `array<string, mixed>` where a sibling
typed Projection already exists.

"Projection" is a real repo-wide directory convention
(`{Domain}/Projection/`), present in 40+ namespaces. The gap is uneven
and, where it was previously described as "not started," largely wrong:
`Image/Projection/` has 17 classes all referenced from
`ImageRepository.php`; `Users/Projection/` has 10, seven referenced from
`UserRepository.php`; `Category` is substantially fixed already.
`Comment`, `Tag` and `Group` are near-resolved at three occurrences each.
`Admin`, `Core` and `Controller` have real `Projection/` directories
(51/2/15 files) but have not been audited for this specific gap. `Ws` is
the one domain with no `Projection/` directory at all.

**Open — `SearchRepository`'s count is unchanged at 17** and genuinely
unexplained. Worth a single-file look.

**Open — superglobal access beyond the request superglobals.** Three
pockets remain, same "typed accessor over raw offset access" discipline:

1. **Prerequisite: wire `admin.php` through the shared PSR-7 pipeline —
   bootstrap half done (C3 Phase 1), routing half still open (C3 Phase
   3).** `public/index.php` calls `RequestPipeline::handle()` in full;
   `public/admin.php` calls `RequestBootstrap::bootEntryPoint()`, then
   `RequestPipeline::runBootstrapPhase()` (new in C3 Phase 1 — runs the
   same DB/config/session/plugin/user/language bootstrap middleware
   `index.php` gets, without which admin.php lost that work entirely once
   `bootEntryPoint()` stopped doing it directly), then still instantiates
   `AdminShell` directly rather than reaching `RoutingMiddleware`/
   `ControllerInvokerMiddleware` — `AdminShell::run()`'s own docblock still
   says so. Independently corroborated by SEC-42, from the CSRF angle.
   Everything the full wiring needs already exists (`Http\
   ControllerInterface`, the `ResponseReadyException` pattern, the
   string-returning `Template::parse()`/`PageTail::renderToString()`
   siblings), so this is scoped and tractable — C3 Phase 3's own job, not
   a rediscovery of its scope.
2. **`$_SESSION`/`$_SERVER`/`$_COOKIE`** — 168/68/18 direct-access sites
   across 40/30/8 files outside any designated typed home. The
   `$_SESSION` count has grown, not shrunk, since first scoped.
   `Session\Session` exists as a designated growth point and is threaded
   through `SessionMiddleware`, but is still genuinely empty (37 lines);
   `Auth\CookieService` is already the right home for `$_COOKIE`;
   `Core\CurrentServerRequest` would be a new sibling in the `Current*`
   family. **Open question**: `SessionService` already has 15 named
   accessors for a different, non-overlapping slice of `$_SESSION` (the
   `filter_*`/`device`/`mobile_theme` family), so whether new keys become
   more named accessors or populate the empty `Session` VO is unresolved.
   One slice has a ready design: `page_infos`/`page_errors`/`message_tags`
   — the cross-request flash-message pair `HtmlService` still
   reads and writes as raw `$_SESSION` keys — map directly onto the
   reference implementation's `Session\FlashBag`
   (`add()`/`consume()`/`peek()`). Worth porting rather than
   redesigning.
3. **Raw DBAL row arrays** consumed by string-keyed offset. Real, but its
   prior sizing document no longer exists, so it needs a fresh count
   before scoping. Roughly two-thirds of the files doing this are page
   renderers, controllers and `Ws/*` classes running raw SQL inline, not
   repositories at all.

The WS `$params` pocket that used to be item 4 here is **closed** — see
Epoch G.

#### Table-prefix + `Tables::` removal

2026-08-09/10, 62 commits, **complete**. `PIWIGO_DB_PREFIX` (upstream's
`$prefixeTable`, defaulting to `piwigo_`) was removed entirely, not just
made non-configurable: tables get their bare names unconditionally. The
prefix existed to let multiple installs share one database — a real
constraint on 2000s shared hosting, no longer the mainstream shape, and
this project's own Compose and Helm configs already assume one dedicated
database per install.

This supersedes P14's original claim that `AbstractRepository` + `Tables::`
"had become the real, working, tested pattern." `Tables::`'s 39 static
methods were deleted outright rather than simplified: their opacity to
static analysis (`'SELECT * FROM ' . Tables::images()` is not a literal
string) defeated `staabm/phpstan-dba` and IDE SQL tooling, both of which
only recognize a literal SQL string. Every call site — 129 in
`src/Piwigo`, ~1,540 in `tests/` — was inlined from a verified mapping,
then the class was deleted along with `TablePrefixListener` and every
`PIWIGO_DB_PREFIX` reference across the install flow, backup manifests,
deploy config, migrations and CLI tooling.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "Tables::" src --include="*.php" | wc -l' expect="0" -->

Real bugs found along the way, not just renames: `UniqueExecLock`,
`PwgImages` and `UploadService` used `db_prefix` as hash *entropy* for
`GET_LOCK()` lock names — server-wide MySQL lock names need a per-install
disambiguator — switched to the database name, still unique per install
and more reliably so; a leftover `bmDbPrefix()` call site that survived
an earlier cleanup; a column-name-case mismatch (`'IP'` vs. the Postgres
migration's lowercase `ip`); an int/bool mismatch (`'validated' => 1` vs.
the real boolean column).

Once every table name became a literal, phpstan-dba could resolve exact
live-schema column types for the first time, surfacing ~178 new real
findings — all fixed, mostly now-provably-dead `is_numeric()`/`(int)`
wrappers around schema-known-int reads — plus four confirmed tool
limitations, each root-caused against the live schema and now narrowly
suppressed in `phpstan.neon`, replacing a blanket `dba.keyValue`
suppression that existed only because `Tables::` made every table name
non-literal: synthetic jsonb-placeholder sample values, MySQL-dialect SQL
validated against the one Postgres connection the tool has, Postgres
`::text` casts misparsed as named bind placeholders, and
dynamically-shaped `tearDown()` snapshot-restore arrays.

A full non-parallel `composer test:integration` run — apparently the
first in a while — also surfaced two pre-existing Kernel-boot isolation
bugs and five stale hardcoded fixture-photo hashes, all fixed, all
unrelated to the prefix removal but found only because this pass finally
exercised the suite end to end.

Also closed here: `ecs.php` had excluded `tests/` since the project's
first commit, originally with a "deferred to P5" comment that a later
sweep stripped while leaving the exclusion in place, silently making it
permanent. `e44aeb8f2a` removed the exclusion and ran the full fixer set
across all 882 test files, with no new fixer exclusions added.

#### Still open from a 2026-07-25 full-sweep review

All 470 `src/Piwigo/` files were read in full, not sampled. Most findings
are resolved by later work above. Genuinely still open:

- **Six `@todo` markers.** Five were triaged by that review:
  `DerivativeParams::isIdentity()`'s docblock and `HtmlService`'s four
  "nice display if $template loaded" markers. The sixth,
  `DerivativeImage::build():242`, was never named by it. The
  TODO/FIXME/HACK/XXX literal-marker convention the review separately
  counted at 50 is fully gone, as a side-effect of some later cleanup
  pass not tracked anywhere.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "@todo" src --include="*.php" | wc -l' expect="6" -->

### Epoch G — WS layer & REST (P25–P27)

**P25 — WS layer modernization (typed internals + PSR-7 lifecycle).**
Mostly done. A review found the WS layer serves two audiences through one
surface — 71 of 94 methods are `requiresAuth: true` admin plumbing
exposed as a public contract, and 61 of 93 are reachable from the
first-party UI while 32 are the real machine surface (auth, browse, the
9-method upload protocol, image metadata, favorites) — which is why the
old plan's single P25 ("REST resource layer + OpenAPI, WS removed") had
sat at "not started" as a monolith. Split into three phases, execution
order: **P25** modernizes the legacy layer's internals without changing
one wire byte (verified by the Contract suite passing *unmodified*
throughout); **P26** (not started) moves the ~15 UI-facing methods off
the JSON/XML envelope onto server-rendered fragments; **P27** (not
started) ships `/api/v1` — REST + OpenAPI 3.1 + a generated typed TS
client, tus replacing the 9-method chunk-upload protocol — and deletes
the entire `Ws/` layer (`Server`, all 94 handlers, the encoders,
`NamedArray`/`NamedStruct`, `public/ws.php`) in the same phase. The 39
`Ws*Test` Contract tests get rewritten against the new surface there,
not before.

**Ship-first: seven security findings, fixed 2026-08-15**, found during
the P25 review and landed ahead of the modernization work itself:

1. Global `addslashes()` on every superglobal, every request — data
   corruption repo-wide (SEC-10). Fixed.
2. API-key session laundered into an unrestricted session via
   `pwg.images.uploadAsync` — `UserBootstrap.php` unconditionally
   overwrote a correctly-marked `ws_session_login_api_key` connection
   type with `'pwg.images.uploadAsync'`, making all 8
   `apiKeyForbiddenMethods` callable. Fixed.
3. `pwg.images.addChunk` wrote a file from unvalidated `original_sum`/
   `type` params — an authenticated arbitrary-directory write. Fixed.
4. `pwg.themes.performAction` bypassed the UI's own `isWebmaster()` gate
   its `plugins` sibling already had. Fixed.
5. CSRF was optional on three mutating methods (the token doubled as an
   unrelated "allow HTML" flag), one of them GET-reachable. Fixed by
   separating the two concerns.
6. WS compared CSRF tokens with `!==`, not `hash_equals()` (SEC-12).
   Fixed.
7. Four `exec()` sites escaped nothing (SEC-16). Fixed.

**Stage 1 — typed internals.** The registration god-method
(`WsDefaultMethods::register()`, 1,322 lines) split into 13 per-domain
registrars. The recursive `$server->invoke()` dispatch pattern deleted
(12 call sites now call the sibling handler directly), removing all 9
copies of a duplicated `narrowGetListResult()` helper. `WsParamType`/
`WsParamFlag` moved `Piwigo\Core` → `Piwigo\Ws`; `WsError` became a
backed enum (89 call sites updated, wire-visible codes unchanged).
`WsHelper` (an event listener + CSRF guard + SQL builder + URL builder +
tree builder in one class) split into 7 single-purpose classes.
`Server`'s reference-parameter setters and per-request `uksort()` on its
method list removed. **Two items were investigated and deliberately
descoped, not attempted**: making `WsAction`'s `array $params` typed end
to end, and building `Ws\Request\*` DTOs from the PSR-7 request — both
would touch only code P27 deletes outright, so building either now is
pure throwaway work against P27's own timeline. **Three items are real
but incomplete**: dropping the `Server $server` parameter from every
handler signature (blocked — `Images/UploadAsyncHandler` still threads
it into `UploadService`, resolved by a Stage 2 item below, not yet
revisited); a typed sort-spec replacement for `WsHelper`'s raw-SQL
`stdImageSqlOrder()`; retyping `Server::$methods` (blocked on a decision
for its 2 remaining legacy-callback registrations).

**Stage 2 — lifecycle (deleted the `exit()`s).** `Server::run()` returns
a real `ResponseInterface`; `WsController` returns it directly, deleting
its own `exit()`. `WsErrorResponse` is now a pure value object — the
status-code decision moved into `Server::sendResponse()`, the only place
building a real response. `UploadService` throws a new
`UnsupportedMediaTypeException` instead of reaching into a `Server` to
`sendResponse()`+`exit`. `UserBootstrap`'s 2 `exit()` sites (invalid
api_key, failed `uploadAsync` login) now throw
`Http\ResponseReadyException` — closing a real, previously-documented
test gap (both branches were "genuinely unsafe to invoke" per
`UserBootstrapTest.php`'s own prior docblock) and setting up workstream
C3 Phase 1's `Bootstrap\UserResolutionMiddleware` to wrap this logic as
real middleware. `connected_with` (5 string literals + one
variable-valued write) became a typed `Core\ConnectedWith` enum.
`WsInitializer`'s memoized `Server` had a real worker-mode-readiness
leak — `responseFormat`/`responseEncoder` were computed once per
`Server` lifetime instead of once per request — fixed. `pwg.extensions.
checkUpdates`'s session-keyed cache moved onto a real PSR-6 cache pool.
Two wire-compatible bugs fixed: `json_encode()` could silently emit an
empty body on failure (now `JSON_THROW_ON_ERROR`), and
`categoriesFlatlistToTree()` could hit an undefined array key for a
category whose parent was filtered out of scope.

**Stage 3 — tests and docs.** The Contract suite (604 tests, all 94
methods covered) stayed unmodified throughout, the real gate for a
phase that changes no wire byte. Still open: 39 of 94 handlers lack a
dedicated Unit test (`Ws/Users/` has none at all); 18 dangling
`{@see \Piwigo\Ws\...}`-style docblock references to deleted
god-classes.

**Landed 2026-08-13/14, before the Stage 1–3 work above** (under
`feat(P25/G19)` and `feat(P19.n)` tags — kept here for the historical
record, since these were the foundation Stage 1/2 built on):

- **94 `WsAction` handlers** replace the `*Endpoints` god-classes. Each
  is a constructor-injected class with an `__invoke()`, not a
  string-callback entry in a registration array.
- **78 `WsParams` DTOs** replace `array $params` indexed by string key —
  the "zero typed accessors" gap this file used to list under P24's
  superglobal pockets.
- **94 `MethodDefinition` registrations** with typed `ParamDefinition`
  entries replace the legacy callback-array shape.
- `Ws/Images.php`, the last god-class, was deleted in `6573f728c2`. The
  namespace is now 204 files across per-domain subdirectories.

**Superseded**: the entire `Piwigo\Ws\*` namespace and `tests/Contract/`
described throughout this P25 history section were deleted outright by
P27 (the WS layer deletion, its own section below) — nothing here is
verifiable against the current tree anymore; kept as historical record
only, no doc-drift-check marker.

One registration deliberately stayed on the legacy `addMethod()` path:
`pwg.activity.downloadLog` pointed at an undefined function and fataled
if invoked. It was permanently dead and covered by its own regression
test in `tests/Contract/WsHistoryTest.php`; both are gone now along with
the rest of `Ws/*` and `tests/Contract/`.

Follow-up fixes in the same window: `#[\Override]` added to all 94
handlers, `Server` no longer resolving handlers via `Kernel::container()`,
CSRF checks consolidated into `WsHelper::checkSecurityToken()`, and
`WsHelper::stdImageSqlFilterCriteria()` returning an error response
instead of `exit()`ing.

Older names in this area are stale everywhere they appear outside this
file: the `Pwg` prefix was dropped repo-wide on 2026-08-11
(`PwgTags.php` → `Tags.php`, `PwgError` → `WsErrorResponse`, `PwgServer`
→ `Server`).

**P26 is done.** Its goal — moving the ~15 UI-facing WS methods off the
JSON/XML envelope onto server-rendered fragments — holds by
construction: the WS layer is gone entirely, and every admin UI surface
renders through a `*PageRenderer`/`*SubController` onto a Latte
template, never a WS envelope.

**P27 is done.** `Controller\Api` holds 134 files; `RouteDefinitions.php`
registers 88 real `/api/v1` routes across categories, comments,
extensions, groups, history, images (including filtered search),
session/preferences/API keys/favorites/caddie, tags, uploads, and users.
Uploads use a full tus 1.0.0 chunked-upload protocol (`Uploads/
TusUpload*`, 6 dedicated controllers) in place of the old 9-method WS
chunk-upload protocol. Every error response is RFC 9457
`application/problem+json` (`Http\Middleware\ApiErrorMiddleware` for
routing-level 404/405, `Http\Middleware\ExceptionHandlerMiddleware`
app-wide for uncaught exceptions — SEC-36/SEC-37). `Http\AdminGuard`
(401 vs 403) is injected into 69 of the 134 controllers (SEC-38).

An OpenAPI 3.2 spec (`openapi/openapi.yaml` + `openapi/paths/*.yaml`, 88
operations across 11 domains, hand-authored from real controller/DTO/
service source) is gated in CI by `bun run lint:openapi` (Redocly) and a
structural test (`tests/Unit/OpenApi/SpecStructureTest.php`, via
`openapiphp/openapi`'s `Reader` — never its own `->validate()`, which
hard-rejects `3.2.0`); `studio-design/gesso` enforces it against real
PSR-7 request/response pairs at runtime (`tests/Browser/Api/*`) and
tracks per-operation coverage via `OpenApiCoverageExtension`. Every
controller has a typed `*Input` DTO for its request body
(`ImageSetMd5sumController`/`ImageMissingDerivativesController`/
`ImageFilteredSearchCreateController` were the last 3). A generated
TypeScript client (`openapi/client/schema.d.ts` via `openapi-typescript`,
`openapi/client/index.ts`'s `openapi-fetch` wrapper) is regenerated and
diffed in CI to catch drift.

`Http\JsonBody::decode()` validates `Content-Type: application/json` on
any non-empty body, rejecting anything else with 415 (SEC-39).
`Http\Middleware\ApiIdempotencyMiddleware` provides an opt-in
`Idempotency-Key` replay store (SEC-65) scoped to `/api/v1` mutating
methods, excluding tus (its own resumability protocol already covers
retries): a repeated key with the same body replays the stored response
without re-invoking the controller; a different body gets 400.
Concurrent-duplicate-request locking is a deliberate non-goal — a
replay cache, not cross-process locking.

### Epoch H — Security (P28)

**P28 — Security hardening.** Not started: WebAuthn/passkeys, OIDC SSO,
nonce-based CSP, COOP/COEP, CSP reporting. Depends on P24. The clearest
concrete marker that it has not begun is `rate_limiter`, the one P11
cache pool deliberately left unbuilt as P28 scope.

One pattern to borrow when CSP work is scoped: the reference
implementation has `composer lint:no-inline-scripts` →
`tools/check-no-executable-inline-scripts.php`, scanning `.latte` and
`.php` for `<script>` tags missing `type=` or carrying one outside a
CSP3-safe allow-list. It exists there *because of* its own
`script-src 'self'` hardening. It was deliberately not pulled into P32
just because it is Latte-shaped — reference repos are a pattern source,
not a scope target.

### Epoch I — Plugins/Layering/Repo-restructure (P29–P30)

**P29 — Plugin / Theme contracts + bundled extensions.** In progress.
P29.0–P29.5 and P29.7–P29.15 are done and landed on this branch. **P29.6,
porting the 7 bundled extensions onto the new contract, has not started
here**: `plugins/` holds nothing but `index.php` and `trash`, and
`themes/` holds no bundled third-party theme. Ownership of P29.6 moved to
this session on 2026-08-14. Do not treat P29 as done until it lands.

Sub-item tags: P29.0 EventDispatcher PSR-14 conformance +
`Piwigo\Listener\*`; P29.1/P29.2 `ExtensionInterface` + manifests + JSON
schemas + the `ExtensionContext` SDK; P29.3 `PluginRegistry`/
`ThemeRegistry`; P29.4 request-time boot retarget; P29.5 admin lifecycle
retarget + page-renderer listing merge; P29.7 SEC-49, `eval_visible`
replaced by a typed `CheckMenuLinkVisibility` event; P29.8 dead
`PluginMaintain`/`ThemeMaintain`/`insertPlugin()` removal; P29.9
`AppInfo::VERSION` bump to `17.0.0` plus a local PEM mirror; P29.10 full
legacy plugin/theme file-support retirement (unplanned, prompted by a
live `elegant` theme rendering bug); P29.11 stoppable events + priority
direction; P29.12 `deleteSetting()`; P29.13 `mail()`; P29.14
`users()`/`themes()` facades; P29.15 the settings-page rendering
mechanism.

*Survey grounding.* Every real plugin in `../piwigo16-plugins` (~400
extensions) and every real theme in `../piwigo16-themes` (113 files) was
read, to ground the design in actual usage rather than guessing. 162
distinct legacy plugin events exist in the wild; 11 of the top 12 by
frequency already have a shipped 1:1 typed event class — the sole
exception, `ws_add_methods` (#7 by frequency), briefly became a dead
end: at the time this was written it was believed to be just another
typed event (`Ws/Event/WsAddMethods.php`), but the entire legacy
`Piwigo\Ws\*` namespace that class lived in was later deleted outright by
P27, replaced with typed `/api/v1` REST routes with nothing replacing
the plugin-extensibility half. Closed by P29.6:
`PluginConfig\ApiRouteProviderInterface`, a manifest-declared
(`hasApiRoutes: true`) capability mirroring `SettingsPageInterface`'s
own shape, lets an active plugin register real routes under a reserved
`/api/v1/plugin-routes/{id}/...` prefix from `Http\Middleware\
RoutingMiddleware::process()` — see that interface's own docblock.
Every other mapped event did not need inventing — only a real
registration surface wired onto dispatch machinery that already
existed.

*Reference-implementation verdict.* `../piwigo16-rewrite` actually built
`PluginInterface`/`ThemeInterface`/`PluginRegistry`/`ThemeRegistry` —
read in full and traced call-site by call-site rather than taken at face
value. Real, reusable prior art for the JSON manifest shape and the
Doctrine-migrations-per-plugin design, but the interface design does not
hold up:

- Both interfaces were written in one commit and never touched again, and
  their own test helper's docblock admits "unused inside this repository
  — there are no in-tree plugins yet."
- `getId()`, `getVersion()`, `getName()`, `getParentId()`,
  `loadParentCss()`, `getAssetDir()` and `getLocalHeadTemplate()` have
  zero call sites across the whole codebase; every real consumer reads
  the same facts from the manifest DTO. Once those are dropped, the two
  interfaces reduce to an identical shape, overturning that reference's
  own "keep them separate" design.
- `boot(ContainerInterface $container)` hands a plugin the entire
  unrestricted app container, with no scoped binding, contradicting this
  fork's own precedent (`Admin/PluginMaintain.php` takes two narrow named
  collaborators).
- PSR-4 autoloading is parsed into the manifest but never wired.

*Design adopted instead.* One shared
`PluginConfig\ExtensionInterface` for plugins and themes; a narrow
`ExtensionContext` SDK object passed to `boot()` instead of the raw
container, its accessor list sized from a frequency survey of what real
plugins actually call; **no raw DB access at all** — every real
`pwg_query()` use case sampled maps onto existing typed repositories,
`ConfigService`, or ordinary Doctrine entities per plugin, and one real
plugin's own comment admits using raw SQL specifically "to bypass
permission checks," which is a concrete argument against ever exposing
it; core-data reads routed through new purpose-built read-only facades
rather than the existing 105-method `CategoryService`/`ImageService`
(most of whose methods take internal collaborators as parameters and many
of which are unrestricted mutations); and a separate shared "extensions"
`EntityManager` — not the core one, and not one per plugin — so a
metadata error in one plugin's entity cannot take down every other active
plugin's data access.

The JSON manifest format is kept from the reference design.
`opis/json-schema` and `composer/semver` are already resolved in
`composer.lock` as transitive dependencies, so nothing new has to be
introduced to validate manifests or compare versions.

**P30 — Layer decoupling + repository restructure.** Both halves done.

Layer decoupling: `deptrac.yaml`'s 6-layer model (L0Data→L4Integration,
established at P6) enforces 0 violations with no `skip_violations`
escape hatch, gated in CI (`vendor/bin/deptrac analyse`, the job scaffolded
at P3, given real teeth once P6 defined the ruleset) -- meets the
original pre-consolidation plan's own target (`SCC ≤ 15,
layer violations = 0`) outright. The 0-violation count is a live
ratchet, not just "no violations having accumulated": a 2026-08-15 P25
review found it had regressed to 16 real violations
(`Config\ConfigService`/`CurrentConfig` in L1Infrastructure depending on
`Image\OrderBy` in L2aCoreDomain, introduced by `7c281ee97c`, which left
`OrderBy` unplaced in `deptrac.yaml`), fixed the same day; a
2026-08-18 `ApiIdempotencyMiddleware` regression (L3Presentation
depending on 5 concrete L4Integration controllers) was caught and fixed
the same way, via route-level metadata (`RouteResult::
$bypassIdempotency`) instead of a controller-class reference -- see
`Http\ControllerInterface`'s own docblock for the same L3/L4
dependency-inversion pattern.

Repository restructure: the pre-consolidation `docs/STRUCTURE-PLAN.md`
(a fully separate source folder outside the web root anywhere the user
wants, plus a `public-template/` shim generated by a setup script) was
never built as written -- what shipped instead is simpler:
`public/` as a plain sibling directory inside the same repo, holding
only the real entry-point scripts plus `themes`/`dist`/`_data/combined`
symlinks for the static assets that must stay web-reachable. That
covers every load-bearing goal the old plan had (`galleries/`,
`upload/`, `install/`, `vendor/`, `src/` are all outside `public/`, none
directly HTTP-reachable; `src/` holds only PHP, no TS mixing; no stray
root-level working-note files). Audited fresh against the current tree
2026-08-18: the old plan's remaining proposals (renaming `_data`/
`_analysis`, moving `template-extension/` into a `resources/` tree) are
cosmetic, and `template-extension/` itself is live code (`Template.
php`'s override-resolution chain, `AdminUiHelper`,
`PrecompileTemplatesCommand`), not dead weight to clean up. No further
restructure work is scoped.

The one commit tagged `chore(p32): delete doc/` is an unrelated narrow
cleanup that borrowed a pre-consolidation number for this same phase.

### Epoch J — Presentation, templating & extension surface (P31–P55)

Sequenced after every backend phase. Order within the epoch: the
completed Latte foundation, then refactor and modernization (same
behavior, different implementation), then new features, then a closing
gate.

The tree was 135 templates and 119,752 lines when this epoch was
scoped, of which **93,420 lines (78%) were auto-generated `{varType}`
boilerplate** — every template carried the same 692-line block while
referencing 11.5 distinct variables on average, forced by
`Template::$vars` being one request-global bag. P40 is what removes it,
and has: **121 templates, 19,719 lines, 1,212 `{varType}` occurrences
left across 9 templates.**

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find themes template-extension -name "*.latte" | wc -l' expect="121" -->
<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rho "{varType" themes template-extension --include="*.latte" | wc -l' expect="1212" -->

#### Completed Latte foundation

**P31 — Smarty → Latte template migration.** Done, 80 commits. All 139
real templates at the time converted (P31.1–P31.6), then the Smarty
engine fully retired (P31.7): `Template.php` has no Smarty dependency at
all, the `smarty/smarty` package and its three patches are gone, and the
Smarty-reach-around arch test was retired because PHP's own private-method
visibility on `Template::assign()`/`append()` now enforces the same
invariant.

Scope was narrowed from the original plan: the "+ asset pipeline" clause
is split out to P36 and P53. Every `p31.x` commit is a `.tpl` → `.latte`
conversion or Smarty cleanup, nothing manifest-, combiner- or
image-format-related.

**P32 — Latte lint/format tooling.** Done. Both halves exist; **only
enforcement is missing, and that is P45's job**, not a gap here.

*Lint half*: `tools/latte-lint.php` + `composer lint:latte`, a thin
wrapper around Latte's own bundled `Latte\Tools\Linter` registering
`PiwigoExtension` so Piwigo filters do not false-positive as unknown. It
runs as a subprocess specifically because `Linter` is `final` and writes
warnings only to stderr through a private error handler. Plus
`bin/piwigo precompile:templates`, which calls `warmupCache()` on every
template so a syntax error is a build failure while warming the
production cache.

*Static analysis inside templates*: the reference implementation used
`efabrica/phpstan-latte` plus a locally patched copy. **Not ported.** A
deep upstream review found dead Nette-only machinery,
maintainer-confirmed performance overhead, and three real crashes in its
hand-rolled analysis loop. A Piwigo-native replacement was built instead:
`tools/phpstan/Latte/` + `bin/piwigo phpstan-latte:compile`, chained
inside `composer analyse:phpstan`. It compiles all 135 templates with
typed `@var` injection and shim-rewritten filter calls into
`_analysis/phpstan-latte/`, analysed by plain `phpstan analyse` with
errors mapped back to real `.latte` lines via an `errorFormatter.table!`
override. Two follow-up campaigns shrink its remaining scoped ignores;
they had no owner until **P58** picked them up, and the figures recorded
here originally (~1,400 and ~450) were both stale — re-measured
2026-08-28 as **843** and **376**. The first was also mis-described as
"context-docblock enrichment": P40's View migration has since made the
dominant cause a producer calling `->toArray()` on an already-typed VO
one line before handing it to the View, so the fix is deleting the
flatten rather than writing a docblock. See P58.

*Format half*: no prior art existed even in the reference, so it is
genuinely new work. `tools/latte-prettier/` is a real Prettier plugin —
a hand-written recursive-descent parser producing a typed AST, printed
through Prettier's own `Doc` builders, the same architecture
`prettier-plugin-laravel-blade` uses. All 135 files parse, format, are
idempotent, and are AST-semantically equivalent to their source.
`.prettierignore`'s blanket directory excludes were replaced with precise
per-extension ones, because gitignore semantics cannot re-include a path
whose parent directory is already fully excluded.

The reformat was then reviewed manually, every file, line by line — the
automated checks compare old-parse against new-parse with the *same*
parser, so a systematic parser bug is invisible to them by construction.
That review found three more real bugs (a mismatched-closing-tag
cascade that flattened element nesting, pure-CSS templates having their
hand-formatting collapsed onto one line, and `{contentType text}` mail
templates losing meaningful leading whitespace), all fixed; full detail
is in `tools/latte-prettier/README.md`. The reformat itself landed in
`4862a0f579`.

Cross-file composition was verified concretely rather than by inspection:
the mail templates, which are joined by raw PHP string concatenation in
`MailService.php`, were rendered through the real Latte engine for all
three content-template combinations and the concatenated output validated
with a real HTML parser.

**P33 — Latte idiomatic modernization.** Done, all eight sub-items. A
content pass over the templates for idiomatic Latte constructs, cleaning
up Smarty-era patterns that survived P31's mechanical conversion, with
the same rendered output.

- **P33A** — `Feature::Dedent` and `Feature::ScopedLoopVariables` enabled
  on the reformatted tree.
- **P33B** — `{varType}` blocks generated from the live `VariableMap` via
  `composer lint:vartype:fix`, plus a drift check. Not a hand pass.
- **P33C** — n:if/n:foreach sweep, 451 conversions across 91 templates,
  AST-based. Four templates skipped for a real structural edge case.
- **P33D** — verification only: `{spaceless}`'s runtime whitespace
  collapse confirmed unaffected by the Dedent and n:attribute work,
  inspected directly against the golden-html baselines.
- **P33E** — `|noescape` classified across all 1009 sites by an AST walk
  cross-checked against a raw-text count. 11 provably-redundant sites
  removed (bare var, plain-HTML-text position, PHPStan type exactly
  `\Latte\Runtime\Html`, confirmed against
  `Template::assignVarFromTemplate()`'s hard contract, not just static
  inference) and 379 `{='key'|translate…|noescape}` sites collapsed to
  the new `{_…}` tag. Explicitly deferred, not dropped: ~234 sites where
  removal would be a real behavior change (pre-built HTML strings from
  PHP helpers, not `Html`-typed); 14 sites inside a literal
  `<script>`/`<style>` body, which is a different JS-string escape path
  and invisible to the parser's AST walk by design; and the broader
  ~2,380-site `|translate` rollout beyond the noescape overlap.
- **P33F** — native `{_ …}`/`{translate …}` tags added to
  `PiwigoExtension::getTags()`, wired to the existing mechanism.
- **P33G** — `Engine::setLocale()` wired from `Lang::currentUserLanguage()`,
  and all four `|number_format[:N]` sites converted to `|number[:N]`.
  Original research found only one; three more in `rating_user.latte`
  used a `:N`-argument form a substring grep missed. Verified by a unit
  test proving `fr_FR`'s ICU output genuinely diverges from
  `number_format()` (narrow no-break-space separator, round-half-to-even),
  not just "renders without crashing."
- **P33H** — dev-only Tracy debug bar. `tracy/tracy` is a real
  `require-dev` dependency now, previously an unresolved transitive
  reference. `Piwigo\Bootstrap\TracyBootstrap` mirrors `SentryBootstrap`'s
  no-op-unless-opted-in shape behind `PIWIGO_TRACY_ENABLED`.
  `Env::isTracyEnabled()` lives in `Piwigo\Core`, not on `TracyBootstrap`,
  because deptrac forbids `LatteEngine` (L3Presentation) from depending
  upward on `Bootstrap` (L4Integration). `LatteEngine` registers
  `TracyExtension` conditionally, since its constructor unconditionally
  touches `Debugger::getBar()`.

#### Refactor/modernization track — lands first

**P34 — Event system rewrite. Done**, landed under the `feat(events):
A1`-`A6` tags (historically "P32 Stage A" — the numbering shift predates
the current scheme; see the commit-tags table above) plus one follow-up
`fix(events)` commit closing gaps a full Integration/Contract/Browser
run surfaced (7 test-fixture plugins with a heredoc-escaping byte
mismatch that made the co-location rename's `grep -F` search miss them,
2 further fixture handlers still using the pre-A2 `return $newValue`
idiom, a stale `{@see}` reference, a stale `deptrac.yaml` comment).
Independent of the rest of Epoch J: touches no template, asset or JS
file.

1. **Mutable payloads — done (A1).** Filterable fields on event classes
   dropped `readonly`; context fields kept it. Call sites read the value
   back off the event instead of relying on a returned instance.
2. **One verb — done (A2).** `dispatchChange()`/`dispatchNotify()`
   collapsed into PSR-14 `dispatch(object $event): object`
   (`PluginConfig\EventDispatcher::dispatch()`); the runtime "handler
   must return an instance" guard is gone. A handler that still
   `return`s a replacement value instead of mutating the event in place
   now fails silently — documented in `docs/events-legacy-map.md`.
3. **Symfony — done (A3).** `PluginConfig\EventDispatcher` wraps a real
   `Symfony\Component\EventDispatcher\EventDispatcher` directly;
   `addTypedHandler()` maps onto `addListener()`. Kept the closure-based
   `subscribedEvents()` shape rather than Symfony's own
   `EventSubscriberInterface`, whose static method-name strings collide
   with this repo's PHPStan ban on variable method calls.
4. **Delete the dead API — done (A4).** `EventHandler`,
   `addEventHandler()`, `removeEventHandler()`, `includePath`,
   `callablesEqual()`, and the legacy test file are all gone.
5. **Catalogue — done.** Every `Loc*` marker renamed to a
   tense-consistent, module-co-located name and event classes pruned to
   evidence (127 classes today, down from 157); `docs/
   events-legacy-map.md` shipped as the name-lookup reference. All 6
   named core hooks are covered: `invalidate_user_cache` →
   `Cache\Event\InvalidateUserCache` and `get_categories_menu_sql_where`
   → `Category\Event\GetCategoriesMenuRows` (both A5); `get_high_url` →
   the new `Image\Event\GetHighUrl` (dispatched from `ImageUrlBuilder::
   stdGetUrls()`'s `download_url` computation, distinct from
   `GetDerivativeUrl`, which fires for every resized derivative, not just
   the original-file download link); `user_list_columns` and
   `after_render_user_list` → one new `Users\Event\GetUserListRows`
   (dispatched once from `UserListController`, over the final row list --
   both legacy hooks wanted the same real capability, customize/augment
   each admin-user-list row, through a DataTables server-side-columns
   shape `GET /api/v1/users`'s plain JSON rows don't have, so they
   collapse into one filter here, the same move `GetCategoriesMenuRows`
   already made for its own now-nonexistent SQL-string mechanism);
   `add_elements` → already covered by the pre-existing `Admin\Upload\
   Event\UploadedFileAdded` (a different legacy hook,
   `loc_end_add_uploaded_file`, already dispatched at the real
   per-upload insert site for the same "react to a newly-added element"
   capability -- no new class needed).

**P35 — Browserslist decision + IE back-compat removal.** Done. Committed
`.browserslistrc` (Chrome/Edge ≥94, Firefox ≥93, Safari ≥15 — the
evergreen floor that actually supports `tsconfig.json`'s existing
`ES2022` target/lib, confirmed against it rather than picked
independently), and mirrored it into `vite.config.ts`'s
`build.target` as esbuild target strings (`browserslist` queries and
esbuild targets aren't interchangeable, so this is a second, matching
declaration, not a derived one). Removed everything that floor
obsoletes: `themes/default/js/pngfix.js` (IE6 PNG-alpha shim) and its
`<script>` reference in `header.latte`; the `fix-ie5-ie6.css`/
`fix-ie7.css` files and their `<!--[if IE]>` links in `local_head.latte`
(plus a dangling `admin/default/fix-ie7.css` link in `install.latte`
that pointed at a file that no longer existed even before this phase);
the four unreferenced IE7 fontello stylesheets
(`fontello-ie7[-codes].css` ×2 theme copies). Also swept every
`-ms-filter`, `zoom:1`/`*zoom:1`, `*`-hack property
(`*display`/`*cursor`/`*margin-top`), and legacy
`filter:progid(...)`/`filter:alpha(...)` declaration out of the 14
vendored plugin/theme CSS files that had them (`theme.css`,
`iconset.css`, `chosen.css`, `jquery.dataTables.css`,
`jquery.jgrowl.css`, `jquery.Jcrop.css`, `jquery.ui.progressbar.css`,
`selectize.clear/dark.css`, all 5 `colorbox/style*/colorbox.css`
variants) — broader than the shorthand "`-ms-filter`/`zoom:1`/`\9`"
description first used to scope this, once the real grep was run
end to end; the modern (non-IE) vendor-prefixed declarations
(`-webkit-`/`-moz-`/`-o-`) sitting alongside them were left alone as
out of scope. Verified: brace-balance check on every edited CSS file,
`bun run typecheck`/`lint:js` clean, then `composer test:visual` (66/66)
and `GoldenHtmlSnapshotTest` regenerated for the pages whose rendered
`<head>` lost the dead `<!--[if IE]>` markup.

Running the full (unfiltered) `composer test:visual` for this also
surfaced a genuine pre-existing latent bug unrelated to any of the
above, in `admin-user-activity`'s own VR/golden-HTML baselines: every
`needsAuth` route's `H::loginAsAdmin()` does a real, fresh POST login
each time (confirmed via a live debug trace on
`AuthService::logUser()`), and each one legitimately logs an `activity`
row. `admin-user-activity` renders that table as a full unpaginated list
(unlike `admin-dashboard`'s chart, which only needs deterministic weekly
bucketing), so its row count — and rendered height — depended on how
many other `needsAuth` routes happened to run before it, not on a fixed
baseline; the committed baseline itself already carried ~20 such
accumulated rows, meaning it was never actually deterministic, just
never previously exercised by a full unfiltered run. Fixed the same way
`admin-history` already handles the identical class of problem:
`H::truncateGuestActivity()` (new), called right before
`H::loginAsAdmin()` in both `VisualRegressionTest.php` and
`GoldenHtmlSnapshotTest.php`.

**P36 — Asset-pipeline foundation.** Done. The template-declared vs.
view-declared fork is **decided: view-declared**, resolved now rather
than left for P40/P41 to re-litigate.

Reasoning: only 10 of 158 registered scripts are `load: 'header'` (119
footer, 29 async) — template-declared's "must collect before `<head>`
renders" cost is almost entirely a CSS problem, not a JS one. CSS
layering already relies on fragile, tribal-knowledge magic numbers (a
real comment in `picture_formats.latte`: `{* order 10 is required, see
issue 1080 *}`); view-declared replaces that with one explicit ordered
list per page. Verified against Latte itself rather than assumed:
`Latte\Runtime\Template::render()` delegates to
`createTemplate($this->parentName, …)->render()` when `{layout}` is
present, so the layout's `<head>` genuinely precedes the child block —
unavailable under today's shell-last rendering. Natural fit with P40's
typed per-page view objects.

Adversarial pass against the real 76-template corpus found every real
`combineScript`/`combineCss` (file-based) call site fits one of three
sources, each with a different natural home once declaration is
per-page: (1) a theme's own unconditional base assets (`theme.css`,
`local/css/*-rules.css`, `print.css`) — resolved from theme config at
layout-render time, no event needed; (2) core's own per-page
conditional assets (e.g. `rating.js` only where ratings are enabled) —
conditional today on state the controller already decided before the
template ran, becomes a property on the page's typed view once P40
lands; (3) plugin-contributed assets — the one genuine extension
point, since plugins can't add properties to core's View classes, via
a new `Get*`-prefixed PSR-14 event (`GetPageAssets`, matching the
established filter-event convention). This preserves the *capability*
("a plugin/theme can still get an asset onto the page") without
preserving the old *mechanism* (arbitrary inline template calls) —
deliberately not a 1:1 port.

**Scope correction from the original plan text**: this phase does
**not** retire `ScriptLoader`/`CssLoader`/`FileCombiner`/`Combinable`/
`Script`/`Css` (~1,038 lines). Doing that now and bridging all 76
templates through an interim collector would be exactly the kind of
throwaway scaffolding P41 would immediately replace. Instead: the old
system stays completely untouched, serving all 76 templates exactly as
today, while this phase builds the new `Piwigo\Asset` infrastructure
(`ViteManifest`, `AssetContribution`, `PageAssets` collector,
`GetPageAssets` event) alongside it with **zero template edits**.
Migration onto the new mechanism happens once P40's page-family
campaign fully completes: `PageAssets`/`AssetContribution`/
`GetPageAssets` stay built but dormant through the whole of P40 (a
migrated page's controller calls `Template::combineCss()`/
`combineScript()` directly instead — see P40's own "Scope correction"
note), and become the real, sole asset-resolution path only as part of
P41's own single, one-time shell-last cutover, at the same point the
old `CssLoader`/`ScriptLoader` classes are finally deleted — not
per-page-family alongside each P40 batch.

Two real behaviors the new ordering pass must preserve, found via
direct template audit, not assumed: real multi-level `require:` chains
(e.g. `jquery.ui.timepicker-addon` → `jquery.ui.datepicker` → its own
transitive deps) need genuine topological resolution, not a
single-level check; and `rating_user.latte`'s `jquery.ui.tooltip`
registration (zero `path:`/`require:` params) plus
`jquery.ui.datepicker` (never explicitly registered anywhere) both
depend entirely on `ScriptLoader`'s naming-convention auto-resolution
— dropping that ~30-line resolver would silently break
`admin-rating-user` and every page including the datepicker.
`footerScript`'s 80 real call sites are inline JS with real
PHP-interpolated per-request data (not file references), so they stay
on the untouched old mechanism until P37 (typed JSON island) + P38
(extraction) can turn them into real static files.

**Shipped**: `Piwigo\Asset\{ViteManifest, ViteManifestEntry,
AssetContribution, AssetKind, LoadMode, PageAssets, ResolvedAsset,
Event\GetPageAssets}` — real, fully unit-tested infrastructure (24 new
tests, including the two jQuery-UI-resolver edge cases and the real
multi-level `require:` chain above), zero template edits, zero
behavior change to any of the 76 templates. `vitals.js`'s
`VITALS_SCRIPT_URL` (`PageTailRenderer`) now resolves through
`ViteManifest` instead of a hardcoded string, proving the
manifest-reading half end to end against the one real entry that
exists today — confirmed byte-identical output via the full VR suite
(66/66, zero baseline regeneration needed). Also fixed two stale
phase-number references in `vite.config.ts`'s own comments found while
touching this file ("P34's asset-manifest resolution" → P36, "68 real
entries land in P44" → P46, matching P46's own text below). Verified:
`composer analyse`/deptrac (0 violations, `Piwigo\Asset` lands in the
already-reserved L3Presentation slot)/ECS all clean; full
`composer test`/`test:integration`/`test:visual` green (one unrelated
pre-existing flaky failure in `ImageServiceTest.php`, a random-ID
collision under `--parallel`, confirmed passes in isolation).

**P37 — Typed page-data exposure (PHP half).** One typed payload per
page, emitted as a JSON island, replacing the ad-hoc PHP → JS smuggling:
68 in-template `json_encode` uses, `PageState::$bodyData`/`BODY_DATA`,
and the string-into-JS-literal pattern the 210 `escapeJavascript` sites
represent. This has to exist *before* P38, or P38 must invent an interim
mechanism that P47 then replaces. It is also the PHP counterpart to P40's
typed view objects — the same typed source feeds the template and the
JSON island, so design the two together even though P40 lands later.

**Shipped**: `Piwigo\Page\PageDataPayload` — a real, fully unit-tested
`{data, strings}` JSON-island builder (5 new tests: bodyData/exposedData
merge with collision precedence, `Lang::t()` string resolution including
a missing key, dedup on a repeated `exposeString()` call, a non-ASCII
round-trip, and a `</script>`/`<!--`/`&` neutralization check via the
real `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_HEX_TAG |
JSON_HEX_AMP` flags). `PageState::exposeData()`/`exposeString()` land as
the declaration surface (2 new tests: accumulate-and-dedup, and a full
`reset()` sweep across every property, closing a real pre-existing gap —
no prior test asserted `reset()` cleared every property exhaustively).
`Template::getPageDataScript()` backfills a `JSON_ISLAND_TAG` placeholder
in `finalizeOutput()`, the same pattern `COMBINED_SCRIPTS_TAG`/
`COMBINED_CSS_TAG` already use. The one real existing writer,
`PageState::$bodyData` (via `SectionPopulator`), is wired end to end:
`BODY_DATA`/`data-infos` is removed from `PageHeaderPageContext` and both
real front-end headers (`default`, `standard_pages` — `admin`'s header
never had it), replaced by a single `<script type="application/json"
id="page-data">` tag in all 3 real footers. `composer lint:vartype:fix`
resynced the global `{varType}` union across all 135 templates (the
mail/install templates' own `{varType string $BODY_DATA}` boilerplate
line dropped along with it — confirmed neither path ever read that var).
The Latte-analysis shim class (`tools/phpstan/Latte/Generated/
LatteAnalysisShims.php`) was regenerated via `bin/piwigo
phpstan-latte:generate-shims` to pick up the 3 new
`exposeData`/`exposeString`/`getPageDataScript` Latte functions.
Explicitly out of scope, same as P36's own template-corpus boundary:
converting the 204+42 real `escapeJavascript`/`json_encode(translate(...))`
template call sites to `exposeString()` — that is P38's job, one template
at a time. Verified: PHPStan level 10/ECS/deptrac (0 violations) all
clean on every changed file; full `composer test` (5739 passed) and
`composer test:integration` (2120 passed) green; `composer test:visual`
66/66 green with zero baseline regeneration (the removed attribute and
new JSON `<script>` tag are invisible on-screen); `composer
test:golden-html` needed baseline regeneration for 72 of 74 routes,
exactly as predicted (only the `data-infos` removal + `<script
id="page-data">` addition changed in every diff, confirmed by inspection).

**P38 — Inline JS extraction.** Every `<script>` block in a template
moves to a plain `.js` file loaded through P36's manifest. Same behavior,
proven via `composer test:visual`. No TypeScript, no modularization, no
jQuery changes.

Inline JS is not only literal `<script>` blocks: 16 templates carry one,
but **80 `footerScript(` captures across 61 templates** carry the rest.
Critically, **all 210 `escapeJavascript` call sites are inside that
scope** — verified, none outside a `{capture}` or `<script>` region. Any
escaping or filter cleanup done before P38 is therefore discarded work,
which is why P38 and P39 must run ahead of P40–P44 and ahead of any
further template-content pass.

**Shipped**: every real corpus site — 419 `translate()`/`{_'...'}` calls
inside a `{capture}`/`<script>`/`on*=` region, plus the 4 real dynamic
values (`$CATEGORIES_NAV`, `$CSRF_TOKEN`, `$ROOT_URL`, `$NB_ALBUMS`) —
converted to `exposeData()`/`exposeString()` + a companion `.js` file,
across 6 batches (P38-A mechanism through P38-G retirement). Two
incidental, real behavior fixes landed as a side effect of the
conversion rather than a deliberate goal: `themes/default/js/
thumbnails.loader.js`'s `max_requests`/`error_icon` were always read
before their `footerScript()` producers had rendered (a genuine ordering
bug independent of P38), now resolved correctly via `page-data`'s
`require:`; and `plugins_installed.latte`'s `const isWebmaster =
{$isWebmaster};` — a raw PHP bool interpolated through Latte's
`ENT_NOQUOTES` text escaper — produced `const isWebmaster = ;`, a real
JS syntax error breaking the whole combined footer bundle for every
non-webmaster admin, fixed by `exposeData()`'s real `json_encode()`.
`PiwigoExtension::escapeJavascript()` and its filter-map entry are
removed (P38-G), along with its 2 unit tests;
`tools/phpstan/Latte/Generated/LatteAnalysisShims.php` regenerated.
Two real, pre-existing test-harness gaps were found and documented
rather than silently worked around: `themes/default/template/
search.latte`'s advanced-search block is unreachable by any registered
route (`SearchController` always redirects, never renders), and
`themes/standard_pages/template/profile.latte`'s new `exposeData()`
calls, while correct, aren't exercised by `test:golden-html` either —
the golden test's `golden_html_test` fixture theme has no
`themeconf.inc.php` and never actually triggers the `use_standard_pages`
template swap, a P38-C-era gap in the test itself, not in this
conversion. Verified: `composer lint:latte`/`lint:js`/`analyse:phpstan`
clean on every batch; `composer test:visual` 66/66 green (including a
newly-found, deterministic, pre-existing `admin-themes-new` VR failure —
the same stale-cursor-triggers-a-real-hover class already fixed for
`admin-cat-list`, extended to cover it); `composer test:golden-html`
regenerated and reviewed for every route the conversion actually
touches; `composer test` (Unit/Arch) green throughout.

**P39 — Inline CSS extraction.** Every `<style>` block and `style="…"`
attribute moves to a real `.css` file: 20 templates with `<style>`, 243
`style="` attributes. Independent of P38 — different files, different
linter — so parallelizable with it. P39 also settles whether
`Template::htmlStyle()` (15 call sites, accumulating runtime inline CSS)
survives at all, or is superseded by real stylesheets plus the existing
`local/css/*-rules.css` mechanism. P41 should not carry it forward by
default.

**Shipped**: all 67 touched templates (46 admin / 16 default / 5
standard_pages) across 5 batches (P39-A mechanism through P39-E
retirement) — every `<style>` block, `{do htmlStyle(...)}` capture, and
`style="…"` attribute moved to a real `.css` file (new per-theme
`css/utilities.css` for repeated shapes, new `css/pages/<template>.css`
per template), registered via the existing `combineCss()` mechanism.
`no_photo_yet.latte` incidentally dropped its `n:syntax="off"` attribute,
no longer needed once its CSS left the template. `mail/text/html/
header.latte`'s dynamic `<style>` stayed inline (HTML-email
compatibility requirement), extended to `notification_admin.latte`'s and
`notification_by_mail.latte`'s static inline styles for the same reason.
`.stylelintrc.json` no longer double-lints every theme file through the
`public/themes`/`public/dist` symlinks; a `stylelint-suppressions.json`
baseline now separates pre-existing violations from new ones, though
`bun run lint:css`'s own exit code can't reach 0 (a real limitation in
stylelint 17's suppression feature, not a script bug) — verified per
touched file instead. `Template::htmlStyle()`, its `$htmlStyle`
accumulator, its `PiwigoExtension` filter-map entry, and its
`finalizeOutput()` splice are removed (P39-E); `htmlHeadElements`
handling is untouched. Several real, pre-existing bugs were found and
fixed along the way, none visible in golden-HTML's text diff: repeated
CSS-specificity regressions where a new class-based rule lost to an
existing higher-specificity selector in `theme.css` (hide/show toggles,
`margin`/`color`/`right`/`max-width` overrides, compound-class fixes);
a custom-property `url()` resolution bug (`url()` piped through a CSS
custom property resolves against the *consuming stylesheet's* location,
not the page's — kept inline instead, on `cat_modify.latte` and 2 more
shape-4 sites); a duplicate `class=` attribute from a bulk `style=`→
`class=` replace (`site_manager.latte`, a 3rd recurrence of the same
`install.latte`/other bug); and a genuine PHPStan `cast.string`
false-positive on `rating_user.latte`'s `{capture}`-produced value, root
caused to every compiled template's `extract($ʟ_args)` poisoning
untyped locals to `mixed` — fixed by teaching `LatteTemplateCompiler` to
inject a real `@var Html|string|false` docblock after every `{capture}`
target assignment, derived from Latte's own generated code shape.
Verified: `composer lint:latte` clean; `analyse:phpstan` 0 errors;
`lint:php` (ECS) 0 errors; `deptrac analyse` 0 violations;
`test:golden-html` 74/74; `test:visual` 66/66; `composer test`
(Unit/Arch) 5734 passed; `test:integration` 2119 passed.

**P40 — Typed view objects + `Template` split.** The largest single diff
in the epoch. Mitigate by converting one page-family at a time, after
proving the pattern end to end on a thin slice (`index.latte` +
`GalleryController`, see the "Scope correction" note below for why
`@layout.latte` is explicitly *not* part of this phase's thin slice)
gated on golden-HTML, VR and real Browser tests.

Per template: one `final readonly class XxxView` carrying
`#[Template('index.latte')]`, so the template header collapses to
`{templateType Piwigo\…\IndexView}`. This deletes all 93,420 `{varType}`
lines, `toArray()` from all 130 context classes, `Core\TemplatePageContext`,
and the `ALL_CAPS`/`U_`/`F_` naming — **474 of 781 mapped keys are
literally `'CAPS' => $this->camelCase`**. The 29 contexts with derived
values need a decision each, since those derivations are themselves
Smarty coercions (`? 1 : 0`, `->value`, `[$x]`). The 21 push-only classes
must **return** typed fragments for their caller to compose, and the 18
`getTemplateVars()` read-backs become real accessors.

Then split `Template` (1,370 lines, 36 public methods) into `Renderer`
(one method, `render(View): Html`), `TemplateLocator`, `ThemeChain` (a
typed `ThemeConf` replacing the Smarty `append(…, merge: true)`
parent-theme emulation), a thin `Assets` seam that P36 owns, a
contribution registry, and a trimmed `PiwigoExtension`. Delete
`TemplateAdapter` (`$pwg` — 0 template uses), the `defineDerivative`
Latte registration (0 template uses), and `Core\TemplateInterface`.
Remove the five `Kernel::container()` reach-arounds and
`CssLoader::getCss()`'s six parameters.

Delete the **template-extension feature** outright. It ships only samples
— four `.latte` files under `distributed/samples/`, an empty
`yoga/local/` — and has 6 real uses in the wild. Going with it:
`setExtent`/`setExtents`/`getExtent`, `TemplateExtentsRequest`,
`ExtendForTemplatesPageRenderer` (214 lines) with its context, admin page
and tab, the `extents_for_templates` config and sanitiser, 14
`getExtent()` template calls (re-audited against the real tree: 14, not
15 — `grep -rn "getExtent(" themes/ --include="*.latte"`), and its unit,
Browser and VR tests. Also dead once this lands:
`CategoryRepository::findActivePermalinks()`/`CategoryService::
getActivePermalinks()`, which exist solely to feed this feature's own
"selective URLs keyword" list and have no other caller.

Delete the tooling this obsoletes: `tools/phpstan/Latte/VarTypeSyncer.php`,
`VarTypeSyncResult.php`, `Command/PhpStanLatteSyncVarTypeCommand.php`,
their two test files, and the `lint:vartype`/`lint:vartype:fix` scripts.
Collapse `VariableMapBuilder`, `TemplateCallSiteScanner`,
`TemplateCallSiteVisitor` and `ContextVariableExtractor` to "read the
declared `{templateType}`". Keep `LatteTemplateCompiler`. **Add a
round-trip check**: every template's `{templateType}` class must declare
that template back via `#[Template]`.

Depends on P36, P37, P38 and P39.

**Scope correction from the original plan text**: the thin slice above
("`index.latte` + a new `@layout.latte` + `GalleryController`") reads
as introducing real `{layout}`/`{block}` composition incrementally,
per page-family, during P40 itself — meaning migrated and unmigrated
pages would render through two different, coexisting shell mechanisms
for the whole ~130-context-class length of the campaign.
`header.latte`/`footer.latte` and the `PageHeaderRenderer`/
`PageTailRenderer`/`PageState` infrastructure that composes them are
shared by all 135 templates, not gallery-specific, so genuinely
replacing shell-last composition means refactoring that shared
infrastructure now — exactly P41's own stated scope, and consistent
with this phase's own "Depends on..." line above not listing P41.
**Decided instead**: P40 never touches `header.latte`/`footer.latte`/
`PageHeaderRenderer`/`PageTailRenderer`/`PageState`/`@layout.latte` at
all. A migrated page's `Renderer::render(View): Html` output gets
appended into `Template::$output` exactly the way `parse($file,
true)`'s return value does today — the middle piece of the same
three-call sequence, just produced a different way — so P40 proceeds
as a long, safe, page-family-at-a-time campaign with one rendering
model live for the shell at any time, only the body mechanism varying
per page. P41 becomes a single, one-time cutover for every page at
once, done only after every page-family already has a typed View —
see P41's own section below for what that unlocks. This also means a
View's data comes from merging `Template::$vars`' request-ambient
globals (`ROOT_URL`/`ROOT_PATH`/`themeconf`/`themes`/`lang_info`, plus
whatever `PageHeaderRenderer` assigned earlier in the same request)
with the View's own properties, not from the View alone — `Renderer`
calls a new `Template::renderView()` that does exactly this merge,
rather than routing through `Latte\Engine`'s own native object-param
support directly.

**Batch 1 (landed)**: template-extension feature deletion, exactly as
scoped above, plus the `CategoryRepository`/`CategoryService` dead-code
chain it exposed and 6 pre-existing Browser tests found asserting
against pre-P37/P38/P39 template shapes (fixed, not deferred).

**Batch 2 (landed)**: the mechanism (`Core\View`, `Template\Latte\
Attribute\Template`, `Template\Renderer`, `Template::renderView()`/
`appendOutput()`/`indexButtons()`) plus `index.latte` + `include/
selected_tags.inc.latte` converted to `{templateType}`, replacing
`GalleryPageContext`/`GalleryThumbnailsPageContext` with one merged
`Controller\Projection\IndexView` (+ `SelectedTagsView`). Two real
corrections found only once `index.latte`'s actual body was
grepped, not assumed from its old `{varType}` prelude:

- **`U_CANONICAL` is shell data, not body data.** `header.latte`'s own
  `<link rel="canonical">` (`isset($U_CANONICAL)`) renders while
  `PageHeaderRenderer::render()` parses `header.latte` — before
  `GalleryController` ever constructs its `IndexView`, whose render
  happens too late for `header.latte` to see it. `uCanonical`/
  `useStandardPages` (the latter has no real template reader anywhere
  in the app — corpus-wide-fallback noise) both stay off `IndexView`;
  `U_CANONICAL` gets its own single-field `Controller\Projection\
  CanonicalUrlPageContext`, assigned via the old ambient
  `assignContext()` mechanism at the same point in the method
  `GalleryPageContext` used to be built, before `PageHeaderRenderer`
  runs.
- **`VariableMapBuilder`'s `{templateType}` branch doesn't need a
  hardcoded ambient table.** The design section above lists
  `ROOT_URL`/`ROOT_PATH`/`themeconf`/`themes`/`lang_info` as globals a
  View's data merges with at runtime, but `index.latte`'s own body also
  references several more ambient names with no IndexView property at
  all (`MENUBAR`, `CATEGORIES`, `CONTENT`, `THUMBNAILS`, `chronology`,
  `chronology_views`, `favorite`, `QUERY_SEARCH`, `SEARCH_ID`, the
  `PLUGIN_INDEX_CONTENT_*` slots) — all assigned by sibling renderers
  (`MenubarRenderer`/`CategoryCatsRenderer`/`CategoryDefaultRenderer`/
  `SearchFilterRenderer`/etc.) that stay completely untouched by this
  batch. Actual fix: `VariableMapBuilder`'s `{templateType}` branch
  populates a `{templateType}` template's `byTemplate` entry from the
  View's own reflected public properties (via `ContextVariableExtractor
  ::propertyTypes()`, widened to `public`) and leaves `VariableMap::
  forTemplate()`'s existing fallback-union + `$globals` merge
  completely unchanged — those sibling renderers' own context classes
  are still live, unconverted `TemplatePageContext`s, so they keep
  contributing to the same corpus-wide fallback every other template
  already draws on. No new hardcoded list to keep in sync as more
  page-families convert.

`VarTypeSyncer`/`PhpStanLatteSyncVarTypeCommand` (`lint:vartype`/
`lint:vartype:fix`) are **not** deleted by this batch, despite the
"Delete the tooling this obsoletes" line above — that line describes
the *end* of the whole P40 campaign, once every template has converted
and there's no classic per-template `{varType}` corpus left to sync.
Mid-campaign, the 128 still-unconverted templates' `{varType}` blocks
keep drifting as their own corpus-wide fallback union changes (e.g.
losing `GalleryPageContext`/`GalleryThumbnailsPageContext`'s fields
once Batch 2 deleted them), so `VarTypeSyncer` stays live for the
length of the campaign — it just gained one new rule: skip (no-op) any
template whose raw source already contains `{templateType ...}`,
instead of prepending a second, redundant block onto it.

Batch 1+2 verified: `composer lint:latte` clean (130 files);
`analyse:phpstan` 0 errors; `lint:php` (ECS) 0 errors; `deptrac
analyse` 0 violations; `lint:vartype` 0 drift (128 templates'
`{varType}` blocks shrank once `GalleryPageContext`/
`GalleryThumbnailsPageContext` left the fallback union, `index.latte`/
`selected_tags.inc.latte` untouched); a new round-trip test
(`ViewTemplateTypeRoundTripTest`) confirming every `View`'s
`#[Template]` file declares `{templateType}` back at that same class;
`test:golden-html` 73/73 byte-identical; `test:visual` 65/65 (66 minus
the one Batch 1 deleted); `composer test` (Unit/Arch) 5695 passed;
`test:integration` 2119 passed; `tests/Browser/GalleryControllerTest.php`
20/20 (the real regression net for this conversion — exercises the
`U_MODE_FLAT` clear, `SELECTED_TAGS_TEMPLATE` conditional render,
canonical URL and `IndexRendered` event wrinkles through a real
browser request, not just static output diffing).

**Batch 3 (landed)**: the admin `ADMIN_CONTENT` renderer sweep — every
remaining conversion candidate whose controller/renderer called
`Template::assignVarFromTemplate('ADMIN_CONTENT', …)` (directly, or via
a page-family's own `*PageRenderer`), one page-family at a time:
`Rating`, `CatPerm`, `UserActivity`, `ElementSetRanks`, `LanguagesNew`,
`ThemesNew`, `ThemesStandardPages`, `UserList`, `PluginsInstalled`,
`CatList`, `RatingUser`, `PluginsNew`, `AlbumNotification`,
`ThemesInstalled`, `Stats`, `Albums`, `CatModify`, `PictureModify`,
`BatchManagerUnit`, `BatchManagerGlobal`, `UpdatesPwg`, and
`ConfigurationSubController` (22 renderers total). Same per-conversion
pattern throughout: one new `#[Template]` View class, dead fields
dropped (verified against the template body and any paired `.js`
file's `pwg_getPageData()` reads), the old context class plus its own
unit test deleted, callers updated to inject `Renderer`.

`ConfigurationSubController` (last in the batch) needed two extra
wrinkles no earlier conversion did: its 7 tabs each needed their own
View class, selected via a `match` on `$page['section']` since
`#[Template]` requires one fixed compile-time string per class; and
its two POST-handler methods (`processSizes()`/`processWatermark()`)
were changed to *return* a plain internal DTO instead of mutating
template state directly, since each tab's field set is populated from
two different call sites (the POST handler and the main render-time
switch) in the same request.

`grep -rn "assignVarFromTemplate('ADMIN_CONTENT'" src/Piwigo` now
returns zero real call sites (one docblock comment reference in
`SettingsPageInterface.php` only) — this exhausts the pool this batch
targeted. The front-end and remaining non-`ADMIN_CONTENT` page
-families (44 `TemplatePageContext` classes still live, confirmed via
`grep -rl "implements TemplatePageContext" src/Piwigo`) are open for a
future batch, not yet scoped.

Validation deferred to a later checkpoint per explicit direction —
each conversion this batch verified only by `php -l` plus the
narrative-docblock grep sweep at commit time, not the full
`lint:latte`/`analyse:phpstan`/`lint:php`/`deptrac`/`test:*` gate list.
That full pass is still owed before this batch can be marked verified.

**Batch 4 (landed), Batches 5–9 (scoped, not yet executed)**: the remaining 44
`TemplatePageContext`-implementing classes, traced one by one against
their real caller and real template body — not assumed from class
names — to find which are genuine page-family work versus P41 shell
territory versus something else entirely. Two corrections this pass
found in what Batch 3's own text claimed:

- **The `ADMIN_CONTENT` pool wasn't actually exhausted.**
  `Admin\Integrity\CheckIntegrity.php:272` produces admin page content
  via a third call shape neither swept: `Template::concat('ADMIN_CONTENT',
  $template->parse('check_integrity.latte', true))`, not
  `assignVarFromTemplate('ADMIN_CONTENT', …)`. Re-grepping for every
  remaining `->parse(`/`assignVarFromTemplate(`/`->pparse(` call site
  app-wide (not just the one `ADMIN_CONTENT` shape) is what surfaced
  this and everything below.
- **`themes/default/template/search.latte` and `search_rules.latte`
  were dead code**, not conversion candidates. `SearchController`'s own
  docblock says it only builds a `$search` descriptor and redirects,
  never renders; a repo-wide grep for `'search.latte'`/`search_rules`
  found zero real callers anywhere in `src/` or cross-template
  `{include}`. **Deletion review resolved (2026-08-20): deleted both
  files.** Re-confirmed before deleting: `SearchController::__invoke()`
  always calls `$this->redirectService->redirect($search_url)` and
  never renders; the golden-html/VR `'search'` fixture
  (`VisualRegressionRoutes.php`) hits `/search.php` with
  `CURLOPT_FOLLOWLOCATION` on, so it was always capturing the
  redirect's landing page, never `search.latte` itself; `search.latte`
  doesn't even `{include}` `search_rules.latte` — both were separate,
  independently-unreachable standalone pages. `search_filters.inc.latte`
  (the live sidebar search widget, already converted in Batch 6) is
  unrelated despite the similar name. Verified after deletion: `php
  bin/piwigo phpstan-latte:compile` (2 stale outputs pruned, 128
  templates, 0 errors), `composer lint:latte` (128 files, 0 errors),
  full `composer analyse:phpstan`, `vendor/bin/deptrac analyse` (0
  violations), `composer lint:php` — all clean.

**Batch 4 (landed) — Picture page's remaining ambient fragments.** All
6 context classes (`PictureCommentsOrderPageContext`,
`PictureCommentListPageContext` — found during implementation, not in
the original scoping above — `PictureCommentAddPageContext`,
`PictureMetadataPageContext`, `PictureRateSummaryPageContext`,
`PictureRatingFormPageContext`) folded onto `PictureView`/
`SlideshowView` as new properties, landed as 3 commits (one per
renderer: `PictureMetadataRenderer`, `PictureRateRenderer`,
`PictureCommentRenderer`). `PictureMetadataRenderer`/`PictureRateRenderer`
now return their own small result types (`?array`,
`PictureRateResult`) instead of calling `assignContext()`; both lost
their now-unused `CurrentTemplate` constructor dependency entirely.
`PictureCommentRenderer` returns a `PictureCommentsResult` bundling
all 6 of its own fields. `PictureCommentRenderer`'s own use of
`comment_list.latte` (`assignVarFromTemplate('COMMENT_LIST',
'comment_list.latte')`) switched to the `CommentListView` class that
**already existed** — built for `CommentsController`'s own, separate,
already-converted use of the same template —
`CommentListView::$commentDerivativeParams` widened to nullable to
cover `PictureCommentRenderer`'s own comment rows, which never carry a
`src_image` (already looking at the one photo above, no per-comment
illustration needed) — the template's own `isset($commentDerivativeParams)`
guard already anticipated this. `picture.latte`'s body renamed to
match throughout, including converting one bare
`{include 'navigation_bar.latte'}` (relying on inherited scope) to an
explicit `navbar: $commentsNavbar` param, matching every other real
call site of that template. Verified end-to-end each commit:
`php -l` + `composer analyse:phpstan` + `lint:latte` +
`picture-1`/`slideshow` golden-html unchanged for the first
(Metadata) commit; `php -l` +
`lint:latte` only for Rate/Comments per this session's "skip
validation" direction at the time. **The deferred full pass has since
run**: a full `composer test:golden-html` caught one real regression
from the Rate commit — `picture.latte`'s "Average" rating-score block
(`{if $displayInfo['rating_score'] and isset($rate_summary)}` and its
two `$rate_summary[...]` reads inside) was left on the pre-conversion
snake_case name instead of the renamed `$rateSummary` property, so
`isset()` was always false and the block silently stopped rendering.
Fixed in a standalone commit (`0dd8d9008c`); full suite green
afterward (73/73 golden-html).

**Batch 5 (landed) — small, bounded, 1–2-caller fragments.**
`check_integrity.latte` (2 callers: `IntroSubController`'s dashboard
page, `MaintenanceActionDispatcher`) converted to `CheckIntegrityView`
— `CheckIntegrity::display()` now returns a plain `CheckIntegrityResult`
DTO (same data-returning-method split as `PictureMetadataRenderer`/
`PictureRateRenderer`, needed because `CheckIntegrityTest` inspects raw
pre-render anomaly data, not `Html`); `IntroSubController` constructs
`CheckIntegrityView` and renders it, combining the `Html` with the
existing admin content before assigning `AdminContentPageContext`.
`MaintenanceActionDispatcher`'s own `CheckIntegrity` construction
(calls `maintenance()`, never `display()`) dropped its now-stale
`CurrentTemplate` arg. `no_photo_yet.latte`
(`NoPhotoYetRenderer`, already has its own `Request`/`Event`
scaffolding — the 2 context variants, `NoPhotoYetAdminPageContext`/
`NoPhotoYetGuestPageContext`, merged into one `NoPhotoYetView`) also
landed. The admin theme's own `popuphelp.latte` turned out to be
**already converted** — `AdminPopuphelpController` already renders a
shared `PopuphelpView` (theme-chain-resolved, same class the front-end
`PopuphelpController` uses), landed earlier this session in commit
`5cef717009`, before this batch's scoping text was written; no new
work needed there. `redirect.latte` stays deferred/optional per this
batch's original note (crash-path code, out of scope here). Verified
end-to-end: `php -l`, scoped `composer analyse:phpstan`, `composer
lint:latte`, `composer lint:php`, the 34 relevant `composer
test:integration` tests (`CheckIntegrity`/`C13yInternal`/
`MaintenanceActionDispatcher`), and a full `composer test:golden-html`
(73/73) — all green.

**Batch 6 (landed) — `index.latte`'s remaining ambient contributors.**
`thumbnails.latte` (`CategoryDefaultRenderer`) and `mainpage_categories.latte`
(`CategoryCatsRenderer`) both converted to real `View`/`Renderer`
fragments: `Piwigo\Category\*` is L2aCoreDomain and may not depend on
`Renderer`/`View` (L3Presentation) directly, so both renderers now
return a plain result DTO (`CategoryDefaultResult`/`CategoryCatsResult`)
instead of rendering internally, and `GalleryController` (always L3/L4)
constructs the real `ThumbnailsView`/`CategoryCatsView`, renders it,
and writes the `Html` into `Template::$vars['THUMBNAILS']`/`['CATEGORIES']`
via a new one-field `ThumbnailsHtmlPageContext`/`CategoryCatsHtmlPageContext`
— `assignContext()` stays the sole way anything writes into the
template, so a bare already-rendered `Html` value still needs this
one-field wrapper, matching `CanonicalUrlPageContext`'s own established
shape. `CategoryCatsNavbarPageContext` (the separate `cats_navbar`
ambient var) needed no change: it's a plain `assignContext()` call
with no rendering involved, which `TemplateInterface` already lets an
L2a/L2b class call directly.

The search widget fragments (`SearchFilterPageContext`/
`SearchAlbumsFoundPageContext`/`SearchDateFilterPageContext`/
`SearchTagsFoundPageContext`, feeding `include/search_filters.inc.latte`,
not the dead `search.latte`) converted the same way — `Piwigo\Search\*`
is L2bExtendedDomain, same constraint. `SearchFilterRenderer::render()`
now returns a `SearchFilterResult` (the resolved search id, unrelated
to the sidebar itself, plus a nullable `SearchFilterData` bundling all
19 sidebar fields across what used to be 4 separate `assignContext()`
calls from `render()` and its 3 private helpers). `index.latte`'s old
`{if !empty($SEARCH_ID)}{include 'include/search_filters.inc.latte'}{/if}`
pair became `{if !empty($SEARCH_FILTERS)}{$SEARCH_FILTERS}{/if}`,
matching `CATEGORIES`/`THUMBNAILS` — kept as a 3-line `{if}\n{$var}\n{/if}`
block rather than one line, since Latte's own tag-alone-on-its-line
whitespace trimming shifted compiled output by a blank line when tried
as one line (caught by golden-html, harmless but worth matching
byte-for-byte). `index.latte`'s own separate `{elseif !empty($SEARCH_ID)}`
"no results" branch (a distinct use of the same raw search-id string)
needed a new `IndexView::$searchId` property, since a rendered `Html`
blob can't expose that value back to `index.latte`'s own body the way
ambient `Template::$vars` used to.

`SectionFavoritePageContext` turned out **not to be a render
conversion candidate at all**, on inspection: unlike the other three,
nothing ever calls `assignVarFromTemplate()`/`parse()` for it — it's
pure ambient data (`SectionPopulator::populate()`'s own `Section::Favorites`
branch feeds `index.latte`'s direct `{$favorite['U_FAVORITE']}` body
reference, already converted in Batch 2), the same permanent-ambient
shape `CanonicalUrlPageContext` already establishes. `SectionPopulator`
is L2bExtendedDomain and computes this value deep inside a much larger
method with no channel back to `GalleryController` other than the
ambient assign; folding it into `IndexView` would mean duplicating
that computation or reshaping `SectionPopulator`'s own public contract
for one field. Left as-is — correctly excluded from this batch, not
a missed item.

Verified end-to-end per fragment: `composer analyse:phpstan`,
`lint:latte`, `lint:php`, the relevant `composer test:integration`/
`composer test:browser` suites, a full `composer test` (Unit+Arch), a
full `composer test:golden-html`, and a full `composer test:visual` —
all green (two confirmed-unrelated single-test flakes along the way,
both non-reproducing in isolation, matching this session's established
flaky-test handling).

**Batches 7–9 and the include-only-partials open question (landed).**
All four landed in the same "keep pushing, no validation" push: full
`composer test`/`test:golden-html`/`test:visual` validation is a
still-owed checkpoint for this whole span (Batches 7–9 + the 3
contract-only conversions), same as Batch 4's own precedent of
deferring the expensive suite while iterating fast on `php -l` +
scoped `composer analyse:phpstan` + `phpstan-latte:compile` per
change.

- **Batch 7 (Menubar)**: `Piwigo\Menu\*` is L3Presentation (may depend
  on `Renderer`/`View` directly, unlike the L2a/L2b split Batch 6
  needed) — `BlockManager::apply()` renders `menubar.latte` itself via
  a new `MenubarView`, dropping its now-meaningless `$var`/`$file`
  params (exactly 1 real caller, `MenubarRenderer::render()`, always
  `'MENUBAR'`/`'menubar.latte'`). The 7 real sub-block templates
  (`menubar_links.latte`, etc.) are contract-only conversions sharing
  one `MenubarBlockView` (`block: DisplayBlock, id: string`) — reached
  only via `menubar.latte`'s own native `{include $block->template,
  block: ..., id: ...}`, never `Renderer::render()`. `BlockManager`'s
  new `Renderer` dependency threaded through `MenubarRenderer::render()`
  (11 real Controller call sites) and `MenubarPageRenderer::render()`.
  Deleted the now-dead `MenubarBlocksPageContext` + its test.
- **Batch 8 (Tabsheet)**: same L4Integration-may-depend-on-L3 shape —
  `Tabsheet::assign()` renders `tabsheet.latte` via a new
  `TabsheetView`, writing the `Html` into `Template::$vars` under
  `Tabsheet::$name`'s own genuinely-dynamic ambient key (kept dynamic,
  not hardcoded, even though all 29 real `new Tabsheet(...)` call
  sites use the bare no-args constructor — same judgment
  `TabsheetPageContext` already made for `$titlename`, the one field
  it still carries). Threading the new `Renderer` param through all 29
  call sites caught a real bug: a first mechanical pass wired 4 sites
  (`CatListPageRenderer`/`CatOptionsPageRenderer`/
  `GroupListPageRenderer`/`TagsPageRenderer`) with a bare `$renderer`
  instead of their own `$this->renderer` property — an
  undefined-variable error PHPStan caught before it ever ran.
  `TemplateCallSiteScannerTest`'s admin-scoping test lost its last
  real fixture (P40's admin sweep across Batches 3/5/7/8 converted
  every real `Piwigo\Admin\*` `assignVarFromTemplate()`/`parse()` call
  site in the repository) — rebuilt synthetically, matching the same
  test file's own "widens to the full tree" precedent.
- **Batch 9 (Calendar)**: `month_calendar.latte` is never rendered via
  `Renderer::render()` at all — `CalendarRenderer` only passes its
  filename as a string (`CalendarChronologyPageContext::
  $fileChronologyView`), which `index.latte`'s own body turns into a
  bare `{include $FILE_CHRONOLOGY_VIEW}` (full parent-scope
  inheritance). Contract-only `MonthCalendarView`, with a real
  wrinkle: its property names stay **snake_case**
  (`chronology_calendar`, `chronology_navigation_bars`), matching the
  actual ambient `Template::$vars` keys verbatim — inherited-scope
  names can't be renamed without touching the classes that assign
  them, unlike a real View's `get_object_vars()` merge.
- **Include-only partials** (the prior turn's own open question,
  resolved and landed): `navigation_bar.latte` (both theme variants,
  one shared `NavigationBarView` — 11 real call sites, 10 pass
  `navbar: $x` explicitly, `comments.latte`'s own one bare `{include}`
  relies on inherited scope from `CommentsView::$navbar` instead, same
  single dependency either way), `picture_nav_buttons.latte`
  (`PictureNavButtonsView`, all 7 fields already on both
  `PictureView`/`SlideshowView`), `infos_errors.latte`
  (`InfosErrorsView`, fed by the cross-cutting ambient
  `PageMessagesContext`, not tied to any one page's View). All 3 are
  contract-only, same shape as `MenubarBlockView`.

Every one of these ~10 new/touched View-adjacent classes was verified
with `php -l`, a full `phpstan-latte:compile`, and a scoped `composer
analyse:phpstan` on every touched PHP file — 0 errors throughout.

**Post-Batch-9 sweep (landed): full-suite validation + 5 real bugs found.**
Running the deferred full `composer analyse:phpstan` (project-wide, not
scoped) surfaced findings scoped per-file checks structurally can't
catch, since `shipmonk/dead-code-detector`'s dead-property/dead-method
analysis needs the whole call graph:

- **5 contract-only View classes read only via reflection.**
  `MenubarBlockView`/`MonthCalendarView`/`NavigationBarView`/
  `PictureNavButtonsView`/`InfosErrorsView` are never
  `Renderer::render()`-ed or constructed by any in-tree PHP — their
  sole reader is `VariableMapBuilder`'s own `ReflectionClass(...)
  ->getProperties()` walk, invisible to shipmonk's built-in
  `ReflectionUsageProvider` (which only tracks a statically-known
  class name, not a runtime string read back from a `{templateType}`
  scan). Rather than a blanket path-based `ignoreErrors` suppression,
  built `tools/phpstan/TypedViewPropertyUsageProvider.php`, a
  `ReflectionBasedMemberUsageProvider` mirroring the existing
  `GessoHookMethodUsageProvider` pattern — recognizes any class that
  appears as a `{templateType}` target across the whole `.latte` tree
  as having every public property "read," while still catching a
  genuinely dead property on any other class.
- **`TemplateInterface` shrunk to `assignContext()` only** —
  `assignVarFromTemplate()`/`clearAssign()` had zero remaining
  L1/L2a/L2b callers once Batches 7–9 finished; both stay as concrete
  `Template` methods (still used internally and by L3/L4), only the
  interface contract and their now-inapplicable `#[Override]`
  attributes dropped.
- **`CheckIntegrityTest`'s stale extra constructor arg** — a leftover
  6th `Renderer` arg (constructor only takes 5) that PHP silently
  tolerated (extra positional args are not a runtime error, unlike too
  few) — invisible to every test run, only caught by PHPStan's
  `arguments.count`.
- **A real deptrac violation**: `CommentListView` lived in
  `Piwigo\Controller\Projection` (L4Integration), but
  `PictureCommentRenderer` (L3Presentation) constructs and renders it
  directly — L3 can't depend upward on L4. Fixed by relocating the
  View to `Piwigo\Picture\Projection` (L3), the same layer as its L3
  caller, rather than reclassifying the layer boundary — including
  updating `comment_list.latte`'s own `{templateType}` declaration to
  match (a real "moving a View class needs both the PHP namespace and
  every referencing `.latte` file's `{templateType}` line updated"
  gotcha, only caught by a subsequent full-suite run flagging
  `ViewTemplateTypeRoundTripTest`).
- **`MenubarRendererTest` (Unit)** missed Batch 7's own call-site grep
  because it resolves `MenubarRenderer::render()`'s params from the
  container rather than a literal `new MenubarRenderer(...)` call —
  only surfaced via a full `composer test` run ("too few arguments").

Also fixed two narrower type/assertion issues caught by the full
PHPStan run (`PictureCommentRendererTest::renderedComments()`'s
docblock was `array<string, mixed>|null`, should have been
`list<array<string, mixed>>|null`, making its `array_values()` call
masking-not-fixing the wrong type; a few now-redundant
`assertIsArray()`/`assertIsString()` calls PHPStan had already
narrowed away) and rebuilt `ContextVariableExtractorTest`'s "dynamic
array-dim assignment" fixture (`TabsheetPageContext` lost that exact
AST shape when Batch 8 shrank it, leaving no real production class
exercising the test).

Full validation now green end-to-end: `composer analyse:phpstan` (0
errors), `lint:latte`, `lint:php`, `vendor/bin/deptrac analyse` (0
violations), `composer test` (5532 Unit+Arch), `composer test:golden-html`
(73/73), `composer test:visual` (65/65), plus the relevant
`test:browser`/`test:integration` suites — this whole span (Batches
7–9 + include-only partials + this sweep) is fully closed out.

**Batch 7 (landed) — Menubar, smaller than it first looked.** Only 2 real call
sites construct a `BlockManager` at all (`Menu\MenubarRenderer`, the
front-end menubar; `Admin\MenubarPageRenderer`, the admin menu editor),
both routing through the single `BlockManager::apply()` method — that
one method is the whole choke point, not 29 call sites to audit.
`menubar.latte`'s own real body (past its `{varType}` prelude) is 9
lines: `{include $block->template, block: $block, id: $id}` per block
in a `foreach` — Latte's own native dynamic include, resolved by that
literal string field on `DisplayBlock`, with **no** `Renderer`/
`#[Template]`-attribute lookup involved (that reflection path only
fires for `Renderer::render(View)` calls; a bare `{include $variable}`
never touches it). Each of the 7 sub-block templates
(`menubar_links.latte`, `menubar_categories.latte`,
`menubar_related_categories.latte`, `menubar_tags.latte`,
`menubar_specials.latte`, `menubar_menu.latte`,
`menubar_identification.latte`) receives the same explicit 2-arg
`block: DisplayBlock, id: string` include call — but **not actually
isolated scope**, a correction to this section's own earlier claim
(see the follow-up resolution below). `DisplayBlock` is already a
real typed class, not a raw array, so each sub-block's own tiny View
is a 2-property wrapper around it. `menubar.latte` itself becomes
`{templateType MenubarView}` with one property, `blocks: list<DisplayBlock>`
(what `MenubarBlocksPageContext` already carries); `BlockManager::apply()`
renders it via `Renderer::render()` and assigns the resulting `Html`
into the same ambient `$var` (`'MENUBAR'`) it does today, so every
already-converted page that prints `$MENUBAR` needs no change at all.

**`MenubarIdentificationPageContext` follow-up (resolved, 2026-08-20):**
this section originally flagged two open questions — whether
`{include $block->template, block: ..., id: ...}` is truly isolated
scope, and what shape `DisplayBlock::$data` holds for the
identification block. Both traced directly against the real code:

- **Latte's `{include}` is never actually isolated.**
  `IncludeFileNode::print()` (`vendor/latte/latte/src/Latte/Essential/
  Nodes/IncludeFileNode.php:57`) compiles every `{include file, args...}`
  to `$this->createTemplate($file, $args + $this->params, $mode)` —
  the explicit `$args` are PHP-array-unioned (`+`) with `$this->params`,
  the *current* template's own full variable set, with `$args` only
  winning on key collision. So `{include $block->template, block: ...,
  id: ...}` hands the sub-template `block`/`id` **plus every other
  ambient var already in `Template::$vars` at that point** — not a
  fresh isolated scope. This corrects both this section's own earlier
  "isolated scope" claim and the near-identical claim in the
  include-only-partials section below; those conclusions happened to
  be harmless (the templates in question only ever read the explicitly
  -passed names), but the "isolated vs. full parent-scope" framing
  itself was wrong throughout.
- **`DisplayBlock::$data` is simply never set for the identification
  block.** `MenubarRenderer::render()` only sets
  `$block->template = 'menubar_identification.latte'`
  (`src/Piwigo/Menu/MenubarRenderer.php:392`) — no `$block->data =
  ...` line anywhere for this block. Every real field the template
  needs (`$USERNAME`, `$U_LOGIN`, `$U_LOST_PASSWORD`,
  `$AUTHORIZE_REMEMBERING`, `$U_REGISTER`, `$U_PROFILE`, `$U_LOGOUT`,
  `$U_ADMIN`, plus `menubar_categories.latte`'s own `$U_START_FILTER`/
  `$U_STOP_FILTER`) reaches the template purely through the ambient
  ↔`+ $this->params` merge above, sourced entirely from
  `MenubarIdentificationPageContext`'s own `assignContext()` call
  (`MenubarRenderer.php:395`).

Net finding: `MenubarIdentificationPageContext` is **not a render
-conversion candidate at all** — nothing ever calls
`assignVarFromTemplate()`/`parse()` for it, the same permanent
-ambient shape `CanonicalUrlPageContext`/`SectionFavoritePageContext`/
`CategoryCatsNavbarPageContext`/`CalendarChronologyPageContext`
already establish. No code change needed; this closes the open
follow-up.

**Batch 8 (landed) — Tabsheet, same shape as menubar.** `Tabsheet::assign()`
is the single choke point (constructing `new Tabsheet(...)` happens at
29 call sites, but they all just call `->assign($currentTemplate)` —
none of them touch template rendering directly). `tabsheet.latte`'s
real body needs exactly 2 variables, `$tabsheet` (list of
`{url, caption}`) and `$tabsheet_selected` — both already lowercase,
no further renaming needed. One wrinkle: `Tabsheet`'s own constructor
takes a `$name` that defaults to `'TABSHEET'` but is caller
-overridable, and `assign()` uses `$this->name` as the ambient
`$var` it assigns into — confirm at implementation time whether any of
the 29 call sites actually override it away from the default before
assuming every call site's output lands in the same well-known var.

**Batch 9 (landed) — Calendar.** `month_calendar.latte` is never rendered via
`Template::parse()`/`assignVarFromTemplate()` at all —
`CalendarRenderer` only ever passes its filename as a **string value**
(`CalendarChronologyPageContext::$fileChronologyView`), which whatever
consumes that ambient var (`index.latte`'s own body) turns into its
own `{include $FILE_CHRONOLOGY_VIEW}` — the same dynamic-include-by
-ambient-var shape as Batch 7's menubar blocks, just one level up.
Convert the same way: `{templateType MonthCalendarView}` on the
template, no `Renderer::render()` call needed from `CalendarRenderer`
itself.

**Resolved open question (landed): `{templateType}` on include-only partials.**
Last turn's scoping flagged this as unresolved for
`navigation_bar.latte`/`picture_nav_buttons.latte`/`infos_errors.latte`.
Checked directly: `navigation_bar.latte`'s real body only references
`$navbar` (one array), and its 7 real call sites
(`{include 'navigation_bar.latte', navbar: $cats_navbar}` in
`index.latte`, `picture.latte`, `comments.latte`, and 3 admin
templates) each pass exactly that one variable — so `{templateType
NavigationBarView}`'s single `navbar` property is a complete, accurate
contract for this template's real reads. (Correction, 2026-08-20: this
was originally described as Latte's `{include}` using "isolated
scope" here — it doesn't; `{include}` always merges the explicit args
with the current template's own full variable set, per
`IncludeFileNode::print()`, see the Batch 7 follow-up resolution
above. The conclusion still holds because `navigation_bar.latte`
simply never reads anything beyond `$navbar`, not because the scope
was ever actually isolated.) `{templateType}` is still meaningful and worth
doing here: the round-trip check only requires the declared class to
implement `View` and carry a matching `#[Template]` attribute pointing
back at the same file — nothing requires `Renderer::render()` to ever
actually be called for it. So these become **contract-only**
conversions: a tiny `View` class + `{templateType}` on the template,
with zero `Renderer`/`Html`-wrapping PHP change, purely to let
`VariableMapBuilder`'s reflection branch replace the corpus-wide
fallback-union noise these templates currently carry (`navigation_bar.latte`'s
own `{varType}` prelude, for instance, declares `$watermark`/
`$watermark_files`/`$warning_tags` and dozens more names it never
actually references). Same treatment applies to `picture_nav_buttons.latte`
and `infos_errors.latte` once their own real call-site variables are
confirmed the same way at implementation time.

**Mail domain (landed, 2026-08-20) — folded into P40 as its own
batch.** `MailService::getMailTemplate()` constructs a wholly separate
`Template` instance per send, rooted at `template/mail/{format}` — 13
real files under `themes/default/template/mail/text/{plain,html}/`
(`header.latte`/`footer.latte`/`cat_group_info.latte`/
`notification_by_mail.latte`/`notification_admin.latte`, plus
`mail-css-{clear,dark}.latte`/`global-mail-css.latte` for the HTML
format only — 14 was this section's own original count, off by one
against the real tree), no shared header/footer-as-web-chrome concept
and no `AdminShell` dispatch to piggyback on.

Scope decision: convert only the 3 real render-triggering CONTENT
templates (`notification_by_mail.latte`, `notification_admin.latte`,
`cat_group_info.latte`) to `View`/`Renderer` — `header.latte`/
`footer.latte`/the CSS fragments stay on ambient `assignContext()`,
the same P40/P41 shell boundary already established for the web
header/footer. New `Piwigo\Mail\Projection\NotificationByMailView`/
`NotificationAdminView`/`CatGroupInfoView` (merging
`NbmMailContentPageContext`+`NbmSubscribeActionMailContext`+
`NbmNewsMailContext` into the first, `MailRuntimeTemplatePageContext`
into the other two — all 4 deleted).

Mechanism wrinkle: mail's own `Template` instance is per-call, not the
ambient `CurrentTemplate` `Renderer::render()` binds to, so these 3
Views render via `Template::renderView()` directly with an explicit
bare filename — `Renderer::render()`'s own `#[Template]`-attribute
resolution never runs for them. Each `#[Template]` instead points at
the file's path relative to `themes/default/template/` (e.g.
`'mail/text/html/cat_group_info.latte'`), which satisfies
`ViewTemplateTypeRoundTripTest`'s own prefix-based resolution and
disambiguates from an unrelated same-basename file elsewhere in the
tree (`notification_by_mail.latte` also names the admin UI page, a
different, already-converted class). One class serves both a
template's html and plain variant (identical property shape) since
the attribute is read only by the round-trip test/`VariableMapBuilder`
here, never at runtime by `Renderer`.

`MailService::mail()`'s own generic `$tpl['filename']`/`'assign'`
runtime-template mechanism (used by `mailNotificationAdmins()`,
`Admin\Extensions\CoreUpdateService`, `Admin\AlbumNotificationPageRenderer`)
now resolves through a new `buildRuntimeTemplateView()`, a `match()`
on the exactly 2 real in-tree filenames (confirmed via exhaustive
`grep -rn "'filename' =>"`) instead of a file-existence lookup +
untyped `assignContext()` — the public `mail()`/`mailAdmins()`/
`mailGroup()` `$tpl` contract itself is otherwise unchanged, so
`AlbumNotificationPageRenderer`/`CoreUpdateService` needed zero
changes. Also dropped the fully-unused `'dirname'` key from that same
`$tpl` contract (zero real callers ever set it) across
`MailerInterface`/`MailService`/`SendNotificationEmailJob`/
`ExtensionContext`.

`NotificationByMailSender`'s own 2 direct `parse()` call sites
(subscribe/unsubscribe and news) now construct `NotificationByMailView`
directly; `assignVarsNbmMailContent()` (void, ambient-writing) became
`nbmMailContentFields()` (private, pure, Reflection-tested the same
way `MailService::resolveMailTheme()` already is).

Full-suite validation: `composer analyse:phpstan`/`deptrac analyse`/
`lint:latte`/`lint:php` all clean; full `composer test` (5524 passed);
`composer test:integration -- --filter=Mail` (128 passed, including
`MailGoldenHtmlSnapshotTest`'s byte-exact comparison against all 13
real mail template files' committed baselines — confirms
byte-identical output pre/post conversion); `composer test:browser --
--filter="NotificationByMailSubControllerTest|AlbumNotificationPageRendererTest"`
(20 passed). Two now-stale test fixtures found and fixed via the full
suite: `TemplateCallSiteScannerTest`'s Mail-scoping test (`Notification
ByMailSender`'s own `parse()` call sites went extinct converting to
`renderView()`; `MailService::mail()`'s own header/footer `parse()`
calls are the real remaining fixture) and `ContextVariableExtractorTest`'s
loose "real context class pool" floor (30 exactly now, was `>30`).

**Confirmed P41 (shell) scope, not new P40 work**: `header.latte`/
`footer.latte`/`admin.latte` and their context classes
(`PageHeaderPageContext`, `PageTailPageContext`,
`AdminShellFramePageContext`, `AdminShellPostDispatchPageContext`,
`AdminContentPageContext`, `AlbumSubControllerPageContext`,
`CanonicalUrlPageContext`, `HeaderMessagesPageContext` — assigned by
`Bootstrap\RequestBootstrap` itself, before any controller runs —
and `PageMessagesContext`, assigned by `HtmlService` for the same
header message banner). `install.latte` is already named in P41's own
text below ("`InstallWizard` stop[s] echoing"), so `InstallRenderPageContext`
is not new scope either. The 4 `BatchManager*` ambient contributors
(`FilterPanelPageContext`, `BatchManagerFilterOptionsPageContext`,
`BatchManagerNoSearchResultsPageContext`, `BatchManagerSearchDebugPageContext`)
are a deliberate design choice already established in Batch 3 (ambient
merge into the already-converted `batch_manager_{unit,global}.latte`),
not a gap — converting them is optional future polish, not blocking.

**P41 — Shell-last rendering, `PageState` split, and asset-pipeline
cutover.** Two corrections to the original scope text above, both
verified directly against the real code: admin's "48
`assignVarFromTemplate('ADMIN_CONTENT', …)` call sites" is stale —
already zero, since P40's Batch 3 converted every real admin
sub-controller to `Renderer::render()` wrapping the result in
`AdminContentPageContext`; what's left is just `admin.latte` itself
(P41-C), not 48 call sites. The asset-pipeline swap
(`CssLoader`/`ScriptLoader` → P36's dormant `PageAssets`) is folded into
this same plan as Part 2 (P41-G/H below), not a separately-numbered
future track — `AssetContribution::script()`/`::css()`'s factory params
map 1:1 onto `Template::combineScript()`/`combineCss()`'s existing
params, and the swap happens entirely inside those two methods, with
zero `.latte` file changes.

**The mechanism**: `{layout '…'}` (Latte's own `{extends}` alias) shares
the *same* variable scope between a child template and the layout it
extends (traced through `Latte\Runtime\Template::render()` directly,
not just the compile-time node) — so the existing ambient-merge design
(`Template::renderView()`'s `[...$this->vars, ...get_object_vars($view)]`)
already generalizes to it with no new classes: `PageHeaderPageContext`/
`PageTailPageContext`/`AdminShellFramePageContext`/etc. keep being built
and `assignContext()`-ed exactly as before, and `Renderer::render($pageView)`
renders the whole page in one shot because `$pageView`'s own template
now declares `{layout '…layout.latte'}` and wraps its body in
`{block content}…{/block}`. Transition is incremental via dual methods:
`PageHeaderRenderer`/`PageTailRenderer` each split into a
`prepareContext()` half (kept) and a `@deprecated`-tagged old
`parse()`-calling `render()`/`renderToString()` half, removed only once
every real caller has switched (P41-E).

Splits `PageState` (27 public mutable properties, confirmed by direct
count) by concern, not all of it — only the two self-contained clusters
whose own real readers/writers are exactly the classes this phase
already rewrites: `Piwigo\Core\LayoutState` (`bodyClasses`/`bodyId`/
`pageBanner`/`metaRobots`/`headerNotes`/`headerMessages`, read by
`PageHeaderRenderer`) and `Piwigo\Core\RequestMetrics`
(`countQueries`/`queriesTime`/`requestStart`/`debugOutput`/
`executionUuid`, read by `PageTailRenderer`/`TimingHelper`/`Logger`).
Both live in `Piwigo\Core`, not `Piwigo\Page` as first drafted — deptrac
caught the real violation: `Filter\FilterService`/`Section\SectionPopulator`
(L2bExtendedDomain) write to `LayoutState` directly, and L2b may not
depend on L3Presentation, so it has to sit at `PageState`'s own layer.
Everything else on `PageState` (`errors`/`warnings`/`messages`/`infos`,
`nbPendingComments`/`noMd5sumNumber`/`nbOrphans`/`nbPhotosTotal`/
`updatedVersion`/`notifyApiKeyExpiration`, `commentRejectionReasons`,
`exposedData`/`exposedStringKeys`/`bodyData`/`authKeyId`/`authKeyInvalid`)
is explicitly **not** touched — traced each field's real readers/writers
directly and confirmed none are reached by this phase's own rewrites;
splitting those out is a real, separate refactor wearing this one's
badge only because it happens to share a class.

**Batch P41-A (landed)** — the spike + mechanism + the `PageState` split
above. `themes/default/template/redirect.latte` is the spike (smallest
real body, single real caller in `RedirectService::redirectHtml()`):
proved the `{layout}` runtime trace holds on a real render (no
`{block}`-lookup-across-two-`Runtime\Template`-instances issue, no
`LatteTemplateCompiler::injectVarDocblocks()` anchor-notice regression).
`Template::finalizeHtml(string $html): string` extracted from the former
private `finalizeOutput()` so both the old accumulate-then-flush path
and new `{layout}`-based renders share the same combined-CSS/JS/
JSON-island/`<head>`-element substitution logic. `LayoutState`/
`RequestMetrics` (above) swept across every real reader/writer: the 11
front-end controllers, both popuphelp controllers, `AdminShell`,
`CheckIntegrity`, `MaintenanceActionDispatcher`, `SectionPopulator`,
`CalendarRenderer`, `Category{Default,Cats}Renderer`, `TimingHelper`,
`Logger`, `ConfigBootstrapMiddleware`, `RequestBootstrap`, `PageTail`,
`RedirectService`, `PageHeaderRenderer`, `PageTailRenderer`,
`FilterService`, `UserResolutionMiddleware` — plus a new `layout.latte`
per theme (`themes/default/`, `themes/admin/default/`,
`themes/standard_pages/`), each merging that theme's own real
`header.latte`+`footer.latte` chrome, not yet consumed by any real page
template (P41-B/C and a later standard_pages batch do that). A full
(not scoped) verification pass this batch's own end found and fixed two
real gaps a scoped check had missed: `public/admin.php`/`public/random.php`'s
own manual `RedirectService` construction, and a stale
`vendor/composer/autoload_classmap.php` entry for a class deleted in the
same batch.

**Batch P41-B (landed)** — the 12 remaining front-end
`PageHeaderRenderer`/`PageTail` call sites: `GalleryController`,
`PictureController`, `CommentsController`, `TagsController`,
`AboutController`, `IdentificationController`, `RegisterController`,
`PasswordController`, `ProfileController`, `NotificationController`,
`NbmController`, `PopuphelpController` (front-end). Each already
rendered its own body through a P40-converted `View`; only the final
render sequence changed — `PageHeaderRenderer::render()` →
`prepareContext()`, and the old `$template->appendOutput($this->renderer
->render($view)); $body = PageTail::renderToString();` tail →
`PageTail::prepareContext(); $html = $this->renderer->render($view);
$body = $template->finalizeHtml((string) $html);` — with every ambient
side-effect call (`eventDispatcher->dispatch()`, `flushPageMessages()`,
`flushKeyedErrors()`, `historyService->logVisit()`) kept in its original
relative order, now running before `PageTail::prepareContext()` instead
of before the old `PageTail::renderToString()`. Nested fragment renders
that feed into an outer page View as a property (`ProfileFormView` →
`ProfileView::$profileContent`, `CommentListView` →
`CommentsView::$commentList`) stayed plain, non-`{layout}` renders —
only the outermost page-level render per controller converts.
`identification`/`password`/`register`/`profile` each have a real
`themes/standard_pages/` template variant (that theme's own real 200
alternative, not a fallback) — both variants converted independently.
Every corresponding `.latte` file got `{layout 'layout.latte'}` added
right after its `{templateType}` line and its whole body wrapped in
`{block content}…{/block}`. `PictureController` additionally renders
`SlideshowView`/`slideshow.latte` (the `lightSlideshow` config branch),
also converted. Added `popuphelp` to
`tests/Browser/Helpers/VisualRegressionRoutes.php` (closes that gap for
this batch, per the plan's own Verification note). `picture` was
considered and deliberately **not** added there: `picture.php`'s route
already has real golden-html/VR coverage via each suite's own dedicated
`picture-1`/`slideshow` test, kept outside the shared route array
specifically because viewing a photo increments `images.hit` and the
shared loop has no per-route hit-freeze — adding a second `picture`
entry to that array would have double-counted the same state-mutating
route non-deterministically.
23 existing golden-html baselines changed shape (pure `{layout}`-driven
whitespace/indentation differences — verified line-by-line: no content,
URL, or attribute text differs anywhere) and were regenerated with
`GOLDEN_HTML_UPDATE=1`, then reverified stable on a clean rerun; a new
`popuphelp` baseline was captured. Full verification green: `lint:latte`
(131 templates), `phpstan-latte:compile` + full `analyse:phpstan`,
`deptrac analyse` (0 violations), `lint:php`, `composer test`
(Unit+Arch, 5533 passing), `test:golden-html` (74 passing, reverified
stable), `test:visual` (66 passing), and a scoped `test:browser` run
across all 12 controllers' own test files (151 passing).

**Batch P41-C (landed)** — `admin.latte` converted to a real
`Piwigo\Admin\Projection\AdminShellView` (`{templateType}`/`{layout}`,
replacing its 665-line auto-generated `{varType}` header entirely):
holds the `<div id="menubar">` sidebar-nav fields the shell's own body
actually reads (29 properties — `activeMenu` plus a subset of
`AdminShellFramePageContext`'s own fields: `enableSynchronization`,
`uHistoryStat`, `uMaintenance`, `uNotificationByMail`, 4×
`uConfig{General,Menubar,Languages,Themes}`, `uAlbums`, `uCatOptions`,
`uCatUpdate`, `uRating`, `uRecentSet`, `uBatch`, `uTags`, `uUsers`,
`uGroups`, `uAdmin`, `uPlugins`, `uAddPhotos`, `showRating`, `uUpdates`,
`uComments`, `nbPendingComments`, `nbPhotosInCaddie`, `uCaddie`,
`nbOrphans`, `uOrphans`) — confirmed via a real per-field grep across
every admin template's own body (past its `{varType}` header, which is
theme-wide boilerplate, not per-file usage) rather than assumed from
the two old context classes' own field lists. `AdminShellFramePageContext`
itself keeps being assigned ambiently, unchanged, at the same
pre-dispatch point: 4 other real admin templates (`intro.latte`,
`help.latte`, `photos_add_ftp.latte`,
`include/batch_manager_filter.inc.latte`) read a subset of its same
fields ambiently during `AdminDispatcher::dispatch()`, before this View
is ever constructed — confirmed the same way. `adminPageTitle`/
`adminPageObjectId` stay ambient too (via `AdminContentPageContext`,
unchanged): `admin.latte`'s own `<h1>` needs whichever a sub-controller
most recently overrode, so neither can pin to a fixed View property.
`AdminShellPostDispatchPageContext` (`activeMenu`+`pwgmenu`) is deleted
outright rather than kept alongside the new View: `activeMenu` moved
onto `AdminShellView`, and `pwgmenu` — confirmed dead via the same
exhaustive per-field grep, assigned but never read by any real
template — dropped rather than carried forward as an unused property.

`AdminPopuphelpController` converted the same way, sharing
`Piwigo\Controller\Projection\PopuphelpView` with the front-end
`PopuphelpController` (P41-B) — its `themes/admin/default/template/popuphelp.latte`
also lost its own 665-line `{varType}` header. This conversion also
fixed a real, pre-existing bug found by the batch's own golden-html
verification: the old admin `popuphelp.latte` read `{$HELP_CONTENT}`
(uppercase, ambient), but `AdminPopuphelpController` already rendered
through `PopuphelpView`/`Renderer::render()` (real `get_object_vars()`-based
camelCase properties, no `toArray()`) *before this batch started* —
nothing had ever written an uppercase `HELP_CONTENT` key into
`Template::$vars`, so every real admin help-popup page had been
silently rendering with empty content. Fixed by reading `{$helpContent}`
(the real property) instead — confirmed via the regenerated
`admin-popuphelp` golden-html baseline, which now shows the real help
article body instead of an empty `<div id="content" class="content"></div>`.

Also deleted `Piwigo\Bootstrap\PageTail::render()` and
`Piwigo\Page\PageTailRenderer::render()` (the void/echoing variants) in
this same batch, ahead of P41-E's own formal schedule: `AdminShell.php`
was their last real caller (confirmed via full-repo grep, including
test files — `renderToString()` stays, since dedicated Unit/Integration
tests still call it directly), and PHPStan's own dead-method detector
flags a zero-caller method as an unsuppressable error per this
project's own PHPStan instructions ("do not add baseline entries to
suppress"). `renderToString()`/`prepareContext()`/`Template::$output`/
`pparse()`/`flush()`/`finalizeOutput()`/`fetchOutput()` all stay for
P41-E, since `InstallWizard.php`'s own `pparse('install.latte')` (P41-D,
not yet converted) is still a real caller.

Full verification green: `lint:latte` (131 templates),
`phpstan-latte:compile` + full `analyse:phpstan` (0 errors, including
the dead-method check above), `deptrac analyse` (0 violations),
`lint:php`, `composer test` (Unit+Arch, 5532 passing — one fewer than
P41-B's count, `AdminShellPostDispatchPageContextTest.php` deleted
alongside its subject), `test:golden-html` (74 passing, reverified
stable — 48 admin baselines regenerated for the same `{layout}`-driven
whitespace/indentation reshape as P41-B, individually verified
whitespace-only via full-file comparison against git HEAD, not just the
diff hunks — a hunk-only comparison silently drops shared context lines
from both sides and can't be trusted for this; the one genuine content
change, `admin-popuphelp.html`, is the bug fix above), `test:visual` (65
passing + 1 known pre-existing flaky test — `admin-themes-new`, confirmed
by an isolated rerun, unrelated to this batch — plus a regenerated
`admin-popuphelp` screenshot baseline), scoped `test:browser`
(`AdminShellTest.php`, 12 passing), and scoped `test:integration`
(`PageTailRendererTest.php`/`PageTailTest.php`, 6 passing).

**Batch P41-D (landed)** — `InstallWizard`/`install.latte` converted to
a real `Piwigo\Admin\Install\Projection\InstallView`. No `{layout}`
needed, confirmed: `install.latte` is a genuinely self-contained
`<!DOCTYPE html>` document (its own `<head>`/inline
`{=getCombinedCss()}`/`{=getCombinedScripts('header')}` calls, both of
which already return the same `COMBINED_CSS_TAG`/`COMBINED_SCRIPTS_TAG`
placeholders every other page's `{do combineCss}`/`{do combineScript}`
resolves to, so `Template::finalizeHtml()` needed zero changes to
handle it), not something that parses against a shared admin
header/footer at all. All 18 of `InstallRenderPageContext`'s own fields
mapped 1:1 onto the new View's properties (confirmed via a real
per-field grep of `install.latte`'s own body, past its 665-line
`{varType}` header) — genuinely self-contained, unlike `admin.latte`'s
own P41-C conversion; `$lang_info`/`$ROOT_URL`/`$themeconf`/`$themes`
stay ambient, same `IndexView`-doesn't-declare-`$ROOT_URL` pattern as
every other page. `InstallRenderPageContext` deleted outright (0
remaining callers). `InstallWizard::render()` stays `void`/echoing
(matches `AdminShell::runDispatch()`'s own shape, not a PSR-7
controller) — `echo $template->finalizeHtml((string) $html);` in place
of the old `assignContext()`+`pparse()` pair.

Also deleted `Template::pparse()` itself in this same batch, ahead of
P41-E's own formal schedule: `InstallWizard.php` was its last real
caller (confirmed via full-repo grep — `parse()`/`flush()` both still
have other real callers and stay), and PHPStan's dead-method detector
flags it the same unsuppressable way it flagged `PageTail::render()`
in P41-C. Fixed two stale docblocks this deletion left behind
(`Http\ResponseFactory::html()`'s own "not pparse()'s echo" contrast,
now "not flush()'s echo"; a P41-C-authored `Template::finalizeHtml()`
comment that named install.latte's own pparse() call as the "one
remaining multi-flush example" — no longer true now that every real
page renders through a single `finalizeHtml()` call).

Found and fixed one real pre-existing test/mechanism mismatch during
verification: `tests/Integration/InstallWizardTest.php`'s own
`testRenderAssignsTheCollectedValidationErrorsToTheTemplate()`
asserted `Template::getTemplateVars('errors')` matched the wizard's
own `$errors` array — a real assertion against the *old* ambient
`assignContext()` mechanism, which a real typed View (never written
into `Template::$vars`) can no longer satisfy. Fixed by dropping that
assertion and keeping the test's own already-present, still-real
behavioral check (`install.latte`'s rendered output actually contains
the error text) — the same "verify against real call sites, not
internal mechanism" call this session's own established discipline
already applies elsewhere.

Full verification green: `lint:latte` (131 templates),
`phpstan-latte:compile` + full `analyse:phpstan` (0 errors, including
the `pparse()` dead-method check), `deptrac analyse` (0 violations),
`lint:php`, `composer test` (Unit+Arch, 5530 passing — 2 fewer than
P41-C's count, `InstallRenderPageContextTest.php` deleted alongside its
subject), scoped `test:integration` (`InstallWizardTest.php`, 16
passing), and the real end-to-end `test:install` browser flow (1
passing) — a genuine HTTP-level confirmation, not just Integration-test
internals. No `test:golden-html`/`test:visual` coverage exists for
install (per the plan's own Verification section), so `test:install`'s
real browser walk-through is this batch's actual regression net.

**Batch P41-E, cutover completion (landed)** — deleted
`PageHeaderRenderer::render()`, `PageTailRenderer::renderToString()`,
and `PageTail::renderToString()` (their `render()`/void siblings were
already gone, P41-C) — every real caller had already switched to
`prepareContext()` (confirmed via full-repo grep). Deleted
`Template::$output`, `appendOutput()`, `flush()`, `fetchOutput()`
alongside them, which forced `parse()` itself to simplify: its old
`bool $return = false` accumulate-into-`$output` mode had zero
remaining real callers once the two methods above were gone (every
survivor already passed `true`), so `parse(string $file): string`
now always returns the rendered string — `MailService`'s own shell
render and `assignVarFromTemplate()` (both already `true`-mode) are
unaffected.

Found and fixed one real pre-existing gap during investigation, before
touching any of the above: `Page\NoPhotoYetRenderer` was never covered
by any P41-A–D batch, but was still the last real production caller of
`appendOutput()`/`flush()` — converted to the same one-shot
`Renderer::render()`/`finalizeHtml()`/echo pattern as every other P41
page (`no_photo_yet.latte` is self-contained like `install.latte`, no
`{layout}` needed).

Also fixed a real, structural test consequence of deleting
`PageHeaderRenderer`'s only `parse('header.latte')` call site:
`TemplateCallSiteScannerTest.php`'s own "frontend polymorphic call
site" test relied on that real call site as its fixture (resolving
`header.latte` to both `themes/default/` and `themes/standard_pages/`)
— rebuilt as a synthetic fixture, the same pattern the file's own
Admin-scoping test already established for the identical reason
(P40's admin sweep retired ITS real fixture first). `MailService`'s
own `parse('header.latte')`/`parse('footer.latte')` calls remain real
but Mail-scoped (`themes/default/template/mail/` only), so they don't
cover the theme-polymorphic case.

Removed the now-permanently-unmatched `phpstan.neon` ignore rules for
the `@deprecated P41` methods just deleted (the ignore comment's own
text: "only P41-E deletes both the methods and this ignore together").

Full verification green: full `analyse:phpstan` (0 errors), `deptrac
analyse` (0 violations), `lint:php`, `lint:latte`, `composer test`
(Unit+Arch, 5529 passing), `test:golden-html` (74 passing, **zero**
baseline changes — confirms the refactor is purely internal),
`test:visual` (66 passing), scoped Integration tests for every
directly-touched rendering class (`MailService`, `NoPhotoYetRenderer`,
`PageHeaderRenderer`, `PageTailRenderer`, `PageTail`, `InstallWizard`
— 68 passing), and `MailGoldenHtmlSnapshotTest` (Mail's own real
`parse()` pipeline, 1 passing).

**P41-E's other half (landed)** — physically extracted
`TemplateLocator`/`ThemeChain` out of `Template.php` into their own
classes (`src/Piwigo/Template/TemplateLocator.php`,
`src/Piwigo/Template/ThemeChain.php`,
`src/Piwigo/Template/ThemeChainResolution.php`), matching the same
"constructed internally in `Template`'s own constructor" shape already
used for `$this->scriptLoader = new ScriptLoader()`/`$this->cssLoader
= new CssLoader()` — not a shared/injected collaborator, so none of the
7 real `new Template(...)` construction sites or `TemplateTestFactory::build()`
needed to change.

`TemplateLocator` owns the per-instance theme directory chain
(`addDir()`/`firstDir()`/`resolve()`/`exists()`) —
`resolveLatteTemplatePath()` now delegates to `resolve()`, returning
`null` on a genuine miss instead of calling `fatalError()` directly (the
one real caller converts that back into its own fatal-error path).
`ThemeChain` owns the recursive parent/child theme walk
(`resolve(): ThemeChainResolution`) and `theme.json` loading/caching
(`loadThemeconf()`, kept public on both `ThemeChain` and as a thin
`Template` delegate specifically to preserve `TemplateInstanceTest.php`'s
own ~8 existing direct unit tests unmodified).

The one real design tension: `setTheme()`'s original recursive
algorithm mutated `Template::$vars` directly via a private `append()`
helper (plain list-append for `themes`, key-merge-child-wins for
`themeconf`) at every recursion level. Replaced with a single
`ThemeChainResolution` value object (`dirs`/`themes`/`themeconf`)
`ThemeChain::resolve()` returns in one shot, which `setTheme()` applies
via 3 direct `assign()` calls — safe as a substitute for the old
accumulate-via-recursion approach only because `setTheme()`'s OUTER
call is confirmed (full-repo grep, real + test callers) to fire exactly
once per `Template` instance, always from the constructor, so there's
never pre-existing `$vars['themes']`/`$vars['themeconf']` content an
`assign()` overwrite could clobber. `Template::append()` deleted
outright (zero remaining callers after this rewrite).

The one genuine remaining side effect `ThemeChain` can't compute
purely — `loadThemeJson()` assigning `STD_PGS_SELECTED_SKIN`/
`STD_PGS_SELECTED_LOGO`/`GALLERY_TITLE` the moment a `theme.json`
literally named `standard_pages` loads (real, test-covered in
`TemplateInstanceTest.php`) — threaded through as a constructor-injected
`Closure $onStandardPagesThemeLoaded`, invoked from the exact same
`loadThemeJson()` call site, since `ThemeChain` has no access to
`Template`'s own private `assign()`.

Full verification green: full `analyse:phpstan` (0 errors), `deptrac
analyse` (0 violations), `ecs check` (clean), `composer test`
(Unit+Arch, 5528 passing — 1 unrelated `CategoryRepositoryTest`
DeadlockException, confirmed flaky via isolated rerun, all 105 passing
alone), `test:golden-html` (74/74, zero baseline changes),
`test:visual` (65/66 — the 1 failure, `admin-themes-new`, is the
already-documented pre-existing hover-zoom race on the theme-preview
grid described in `VisualRegressionTest.php`'s own comments, confirmed
via direct screenshot inspection and a clean pass on a 3rd isolated
rerun), and a scoped `TemplateInstanceTest.php`/`TemplateTest.php`/
`LatteEngineWiringTest.php` run (100 passing, 150 assertions) covering
every pre-existing `setTheme()`/`loadThemeconf()`/`parse()`/
`finalizeHtml()` edge case (parent-theme `load_parent_css`/
`load_parent_local_head` inheritance, non-string parent themeconf,
`standard_pages` side-effect timing, `loadThemeconf()`'s own cache-key
collision avoidance) against the new extracted classes unchanged. No
test file needed modification for this piece — every existing test
passed as-is against the new delegate-based `Template.php`.

This completes P41-E and all of P41 Part 1.

**Part 2 (P41-G/H) — asset-pipeline cutover (landed).** Redirected
`Template::combineCss()`/`combineScript()`/`footerScript()` to
`PageAssets::add(AssetContribution)` instead of `CssLoader`/
`ScriptLoader`, with `finalizeHtml()`'s CSS/JS half reading from
`PageAssets::resolveCss()`/`resolveScripts()` through a new
`Template::makeAssetSrc()`/`renderFooterScripts()` tag-rendering pair.
`combineScript()`'s dead `$template` param dropped outright (zero real
call sites, confirmed via grep); `footerScript()`'s 6 real
inline-script call sites route through a new `AssetContribution::inlineScript()`/
`AssetKind::InlineScript` variant, since `PageAssets` is now the *sole*
resolver; header and footer scripts unified onto the same
placeholder-deferred path (`COMBINED_FOOTER_SCRIPTS_TAG`, new).
`GetPageAssets` dispatches once per instance, lazily, on
`finalizeHtml()`'s first call.

One real gap the plan's own "Four real gaps" text hadn't accounted
for, found during implementation, not planning: `CssLoader`/
`ScriptLoader`'s real resolution routed through `FileCombiner`, which
does more than resolve paths — it actually bundles multiple CSS/JS
files into one cache-busted file on disk
(`CurrentConfig::$templateCombineFiles`, default `true`, genuinely
live in production). Per explicit user decision, this file-combining
behavior is **intentionally dropped**, not ported into `PageAssets` —
a real bundler (Vite) is coming once JS migrates to TS in a later
phase, so preserving `FileCombiner`'s ad-hoc mechanism now would be
throwaway work. Each registered CSS/JS file now renders its own
`<link>`/`<script>` tag instead of being merged — more requests per
page than before, accepted tradeoff. `CssLoader`/`ScriptLoader`/
`FileCombiner`/`Combinable`/`Css`/`Script`/`Projection\FooterScripts`/
`Event\CombinablePreparse`/`Event\CombinedCssPostfilter` all deleted
in the same pass (P41-H folded into P41-G, matching P41-E's own
precedent — PHPStan's dead-code detector forces it once nothing real
calls the old classes), along with `MaintenanceActionDispatcher`'s own
now-pointless `FileCombiner::clearCombinedFiles()` call and the 6
already-dead `themes/{default,admin/default,standard_pages}/template/{header,footer}.latte`
files (found via a separate adversarial check — their real call sites
were deleted back in P41-E, but the files themselves were never
removed; not `themes/default/template/mail/` ones, which
`MailService`'s own separately-rooted `Template` instance still
genuinely renders). `test:golden-html` shows real (not whitespace-only)
diffs from the combining removal, reviewed and accepted — reduced
`<link>`/`<script>` bundling, not a rendering bug.

**Part 2 follow-up (P41-I, proposed, then superseded before starting).**
The placeholder-tag + `substr_replace()` mechanism P41-G/H built works
and is fully tested, but isn't idiomatic Latte — Latte's own native
`{capture $var}...{/capture}` was the first proposed fix. Adversarial
review found `{capture}` is itself just a better-engineered workaround
for an ordering problem that shouldn't exist at all: the problem only
exists because `combineCss`/`combineScript`/`footerScript`/
`exposeData`/`exposeString`/`htmlHead` are imperative Latte calls
scattered through template bodies. **Superseded entirely, not just
deferred, by P42 below** — this page metadata belongs on the View,
declared before rendering starts, the same pattern P40 already
established for ordinary template variables.

**P42 — Declarative page assets & exposed data (View-level, in
progress).** Three new small interfaces a View implements when it
needs them: `Piwigo\Asset\HasPageAssets` (`pageAssets():
list<AssetContribution>`, replacing `combineCss`/`combineScript`/
`footerScript`), `Piwigo\Core\ExposesPageData` (`exposedPageData()`/
`exposedStrings()`, replacing `exposeData`/`exposeString`), and
`Piwigo\Core\HasHeadLinks` (`headLinks(): list<HeadLink>`, a new
readonly value object replacing `htmlHead`). `Renderer::render()`
gained a pre-population step applying a View's declared data to
`Template` *before* that View's own `.latte` file runs, and now also
owns `Template::dispatchPageAssetsOnce()`'s one-shot `GetPageAssets`
plugin-dispatch (relocated from `finalizeHtml()`'s former first line,
since `finalizeHtml()` itself is fully deletable once every real call
site migrates). Declarative and not-yet-migrated-imperative
registrations coexist safely on the same page throughout the whole
migration — `PageAssets::add()`/`Template::exposeData()` are dedup-safe
regardless of call order — so this converts incrementally, template by
template, no flag-day cutover.

Scale: 945 real call sites (`combineCss` 143, `combineScript` 226,
`footerScript` 6, `exposeData` 155, `exposeString` 415/329 distinct),
comparable to or larger than the entire P40 campaign. 11 of the 125
real templates were never given a `{templateType}` + View by P40 (no
dedicated class exists yet to attach the new interfaces to) — converting
those is this campaign's own opening batch, a real prerequisite, not
optional cleanup. Batches afterward are ordered bottom-up through the
`{include}` graph (a template that includes a not-yet-migrated partial
must wait), not reused from P40's own rendering-mechanism-based
grouping. Full design — the theme-base content split into 4 focused
collaborators, the CSS/script insertion-order risk `PageAssets::
resolveCss()`'s/`topologicalSort()`'s same-priority tie-breaking
creates, the deptrac layering check (every real View-hosting namespace
can reach `Asset`/`Core` downward), and per-batch verification — is
written up in
`/home/torres/.claude/plans/validated-hopping-hamster.md`.

**Shipped so far**: the 3 interfaces, `HeadLink`, and the
`Renderer::render()` pre-population/dispatch-relocation hook, with
`Template::registerPageAssets()`/`registerHeadLink()` as the small
public wrappers `Renderer` needs (`$pageAssets` itself stays private).

**P42-A (landed) — the 11 leftover pre-P40 partials.** 9 real
**contract-only** `{templateType}` conversions, same shape as the
pre-existing `Piwigo\Controller\Projection\NavigationBarView`
precedent (P40's own "include-only partials" resolution): a small
`View` class + `{templateType}` on the template, zero controller/
call-site changes, since none of these 9 are ever reached via
`Renderer::render()` — only plain `{include}`, which already merges
loose ambient vars with the current scope. `local_head.latte` is the
one real exception (full `View`, not contract-only) — the theme-base
"local-head resolver" piece (below) renders it directly.

Also found and deleted 2 files the plan's own list carried as real
conversions but that turned out to be genuinely dead:
`themes/default/template/include/{colorbox,autosize}.inc.latte` had
zero real callers anywhere in the app — both real usages are
admin-only, resolving against the admin theme's own same-path copies,
confirmed via exhaustive grep rather than assumed from the file list.
Converting them would have been pointless.

`batch_manager_filter.inc.latte` (the largest, 607 real lines) turned
up a real pattern the plan's own Design section hadn't anticipated:
its real property set is sourced entirely from several *ambient*
`TemplatePageContext` classes assigned upstream by other rendering
steps (`FilterPanelPageContext` and 3 others), not from this
template's own `{include}` args — `{templateType}` doesn't care about
provenance, only what the body actually reads, confirmed by reading
the full 607-line body rather than assuming from the 2 real call
sites. Those 2 call sites also both pass `title`/`searchPlaceholder`
args the body never reads at all — a real, pre-existing dead-parameter
waste, left alone as out of scope for this batch.

Verification: `test:golden-html` byte-identical across every real page
reaching one of these files (including all 7 of
`album_selector.inc.latte`'s own real parents), full `composer
analyse:phpstan`/`deptrac analyse`/`ecs check`/`lint:latte` clean, full
`composer test --testsuite=Unit,Arch` green (5386 tests).

**P42-A (fully landed) — the 4 theme-base pieces.** `ThemeBaseAssets`
(3 named factory methods, not `forTheme(ThemeId)` as originally
sketched — the 3 real layout families genuinely differ in their own
unconditional assets, confirmed by reading all 3 real `layout.latte`
files in full: `admin` loads 2 extra stylesheets with a hardcoded
`admin/default/` path regardless of active sub-theme, registers
`jquery` with an explicit path and no `load:`, and never calls
`localCssRules()`), the local-head resolver
(`Template::resolveLocalHeadOnce()`, fired from `Renderer::render()`'s
hook alongside `dispatchPageAssetsOnce()`, narrowly scoped to the one
real `local_head.latte` instance by comparing resolved paths, not
theme id alone — `themes/admin/default/` is also a real theme
literally named "default"), `localCssRules()`'s relocated call site,
and the confirm-dialog registration all wire into
`Template::setTheme()`, replacing the 3 real `layout.latte` files' own
imperative equivalents.

A new `Template::__construct()`/`setTheme()` `applyThemeBase` flag
(default `true`) was needed — `install.latte` is the one real
top-level page in the whole app that doesn't extend `layout.latte`
(confirmed via grep: no other `.latte` file besides the 3 real
`layout.latte`'s own has its own `<!DOCTYPE html>`), so
`InstallWizard` opts out explicitly rather than gaining admin chrome
it never wanted — found via a real golden-html regression during
verification, not assumed. Every CSS/script-tag and JSON-island-key
reordering in the resulting golden-html diffs (49 pages) was confirmed
pure reordering (same tag sets, same JSON content) via a scripted
line/JSON-aware diff check before accepting new baselines. Two
pre-existing stale visual-regression baselines (`admin-config-search`,
`admin-themes-new`) were also found and fixed in the same pass, both
confirmed unrelated to this work.

Also found and fixed, while investigating an Integration-suite run for
this commit: `PageHeaderRendererTest`/`PageTailRendererTest`/
`PageTailTest` each called `Template::parse('header.latte'/
'footer.latte')` directly — both files were deleted in this session's
earlier P41-G/H commit (`00fd301ac5`), a real pre-existing gap
(confirmed via `git stash` against the tree without this commit, not a
P42-A regression) that had gone uncaught because Integration tests
hadn't been run since. Fixed by rendering a tiny fixture file
extending `layout.latte` instead of the deleted standalone files.

This closes P42-A in full (11-partial conversion + theme-base pieces).
**P42-B (the 945-call-site page-by-page migration) — in progress.**
14 real pages/Views landed so far, ~41 of 945 call sites (all in the
admin `configuration_*`/`languages_*`/`updates_*`/`maintenance_*`/
`site_*`/`themes_*`/`check_integrity`/`help`/`permalinks` family —
every one a standalone page or sub-content fragment with zero
`{include}` of a not-yet-migrated partial): `HelpView` (1,
sub-content-fragment shape confirmed working through the P42 mechanism
unchanged), `MaintenanceSysView` (2, `$isWebmaster`-gated),
`PermalinksView` (3), `LanguagesInstalledView` (4, one duplicate
confirm-dialog pair dropped), `ConfigurationDisplayView` (2),
`CheckIntegrityView` (2, one genuinely `null`-vs-absent-key-sensitive),
`LanguagesNewView` (3), `UpdatesPwgView` (2, one duplicate
confirm-dialog string dropped), `MaintenanceEnvView` (5, no
golden-html route reaches this tab -- verified instead via its own
real Browser suite), `ConfigurationCommentsView` (2), `SiteUpdateView`
(2), `ThemesStandardPagesView` (2), `ConfigurationWatermarkView` (4,
one ambient `$ROOT_URL` resolved via the controller's already-injected
`UrlServiceInterface`), `ConfigurationSearchView` (4, one loosely-typed
array property needing `is_array()` narrowing to satisfy
`exposedPageData()`'s own return type).

Every migrated View's new interface methods carry `#[Override]`
(`StructuralTest`'s own project-wide requirement, applies to every
future migration in this campaign too). `test:golden-html`
byte-identical throughout (mostly pure whitespace from the deleted
imperative lines). Two pre-existing, unrelated issues found and
confirmed via `git stash` (not caused by this campaign): an
`admin-dashboard` activity-count drift (left alone, out of scope), and
an `admin-config-search` `filters_names`-ordering golden-html baseline
staleness (regenerated, since this migration's own page directly
exercises it).

Also migrated 8 more pages/Views since the last count, ~65 of 945:
`CatListView` (4), `ConfigurationSizesView` (7, 2 duplicate
confirm-dialog strings dropped), `PictureFormatsView` (8, one
`order: 10` "issue 1080" CSS), `SiteManagerView` (5, 2 duplicates
dropped).

**Real, deferred sub-task found**: `themes_installed.latte` (and 13
other real templates, including `admin.latte` the shell itself)
`{include 'include/colorbox.inc.latte'}` -- one of P42-A's own
contract-only partials, whose real `combineScript`/`combineCss`
content hasn't migrated yet. Per the plan's own bottom-up dependency
rule, none of these 14 pages can migrate their own remaining calls
until `ColorboxView` (and its sibling contract-only partials,
`AlbumSelectorView`/`AddAlbumView`/`AutosizeView`/`DatepickerView`/
`BatchManagerFilterView`/`QuickSearchView`) gets a real `pageAssets()`
**and** every one of ITS OWN real parents is updated to construct that
partial's View and merge its `pageAssets()` in -- there is no other
way for a contract-only, `{include}`-only partial's own declarative
data to actually apply, since `Renderer::render()` never runs for it.
`ColorboxView`'s own `pageAssets()` design was worked out (`$load_mode`
resolves the same 3-way `header`/`footer`/`async` mapping
`Template::combineScript()` itself uses) but reverted rather than
committed half-wired to zero real callers -- this is real, substantial
work (14 files for colorbox alone) deserving its own dedicated
batch(es), not something to fold into an unrelated single-page commit.
Every other page migrated so far was deliberately chosen to have zero
`{include}` of a not-yet-migrated partial, so this gap doesn't block
continued progress on the remaining independent pages.

Also migrated 4 more pages/Views, ~79 of 945: `ElementSetRanksView`
(3), `DoubleSelectView` (1, shared by 4 real callers), `CommentsView`
(8, csrf_token + 7 strings), `StatsView` (6, month_labels/lang_code +
2 strings) -- 22 pages/Views landed so far. All 4 had zero
`{include}` of any not-yet-migrated partial. `test:golden-html`
byte-identical (2 pure-whitespace baseline regenerations,
`admin-album-sort`/`admin-stats`, same deleted-blank-line shape as
every prior batch).

Also migrated 4 more pages/Views, ~190 of 945: `UpdatesExtView` (17,
shared by 4 real callers -- updates/ext + languages/themes/plugins
update tabs), `PluginsNewView` (23, new `$colorscheme` property
resolving the ambient `$themeconf['colorscheme']` reference via
`$template->themeConf('colorscheme')` in `PluginsNewPageRenderer` --
the first real instance of the plan's own documented ambient-value
case), `PluginsInstalledView` (30), `TagsView` (41, one `order: 10`
"issue 1080" CSS preserved) -- 26 pages/Views landed so far.
`test:golden-html` byte-identical throughout (6 pure-whitespace
baseline regenerations; `admin-plugins-installed` stayed byte-
identical with no leftover blank line at all).

Also migrated 6 more pages/Views, ~214 of 945:
`AlbumNotificationView` (5, new `$colorscheme`), `ConfigurationDefaultView`
(1), `MenubarView` (3, no golden-html fixture for either of the last 2 --
verified via `AdminConfigurationTest`'s "renders the default tab" test
and the `Menubar*Test.php` suite instead), `CatPermView` (10, new
`$colorscheme`/`$rootUrl`), `PictureCoiView` (5 -- its `{do htmlHead(...)}`
call is a plain static CSS `<link>`, migrated as an ordinary
`AssetContribution::css()` entry per the plan's own "htmlHead() fully
migrated" design, not a `HasHeadLinks` case; its conditional `{if
isset($coi)}{do exposeData(...)}{/if}` got a direct unit test per the
plan's own branching-logic testing discipline, since golden-html's one
fixture only exercises the non-null branch) -- 31 pages/Views landed so
far. `test:golden-html` byte-identical throughout (3 pure-whitespace
regenerations; `admin-picture-coi`'s regeneration also picked up a real,
expected content change -- the migrated stylesheet link now goes
through the same combining/versioning pipeline as every other asset
instead of being spliced in raw, gaining a `?v17.0.0` query string and
moving into sorted position).

Also migrated 3 more pages/Views, ~263 of 945: `MaintenanceActionsView`
(22, one `order: 10` "issue 1080" CSS preserved), `RatingUserView` (15,
new `$rootUrl`; preserves the bare `combineScript(id:
'jquery.ui.tooltip', load: 'footer')` call with no `path:` --
`PageAssets::fillKnownScript()` resolves it by naming convention),
`RatingView` (12, new `$colorscheme`/`$rootUrl`) -- 34 pages/Views
landed so far. `test:golden-html` byte-identical throughout (3
pure-whitespace regenerations).

**Ran an 8-agent parallel survey** (Workflow) of every remaining
template with imperative calls across all 3 theme trees, to map real
dependency order and gotchas before continuing. Findings:

- **A real infrastructure bug, not yet fixed**: `themes/default/
  local_head.latte`'s `LocalHeadView` is dispatched via `Template::
  resolveLocalHeadOnce()` calling `Template::renderView()` directly,
  bypassing `Renderer::render()` entirely -- so even a correct
  `pageAssets()` on `LocalHeadView` would never actually fire. Needs
  `resolveLocalHeadOnce()` itself updated (call
  `registerPageAssets()`/etc. before its `renderView()` call, mirroring
  what `Renderer::render()` does) before that one template migrates.
- **A real, confirmed regression caught and fixed**: `PopuphelpView` is
  shared by two callers resolving DIFFERENT physical `.latte` files
  (front-end vs. admin-context, same bare `#[Template('popuphelp.
  latte')]` name) -- the admin variant has zero calls of its own. Since
  `pageAssets()` lives on the shared class, a naive migration
  registered `popuphelp.js` on both renders; a new `$isAdminContext`
  flag (set by each real caller) restores the original per-physical-
  file behavior, confirmed via golden-html regeneration (a spurious
  `<script>` tag appeared, then disappeared once fixed). The exact same
  shared-class-two-physical-files pattern recurred for `Identification
  View`/`RegisterView`/`PasswordView` (default theme vs. `standard_
  pages` theme, same `Template::setTheme()` substitution) -- resolved
  identically each time via a new `$isStandardPagesTheme` flag
  (`$template->themeConf('id') === 'standard_pages'`).
- **`MenubarBlockView` (shared by all 7 `menubar_*.latte` sub-block
  templates via one generic `{include $block->template, ...}` call) and
  `MonthCalendarView`/`index.latte`'s own `{include
  $FILE_CHRONOLOGY_VIEW}` are genuinely unresolved design gaps**,
  distinct from the already-solved `PictureNavButtonsView` case below:
  neither class is ever a real `Renderer::render()` target NOR does any
  real caller hold enough of the right data in the right place to do a
  clean construct-and-merge the way `PictureView`/`SlideshowView` do --
  `month_calendar.latte`'s own data flows through a parallel `Template::
  assignContext()` path (`SectionPopulator`→`CalendarRenderer`) that
  never intersects `GalleryController`'s own `IndexView` construction
  at all. Deferred, same as the colorbox-family sub-task above --
  `index.latte`/`month_calendar.latte` and the 3 `menubar_*.latte`
  templates need their own dedicated design batch.
- The admin `layout.latte` shell has 2 `exposeData()` calls
  (`WHATS_NEW_MAJOR_VERSION`/`SHOW_WHATS_NEW`, genuinely per-request
  data with no page-level View to attach to) that need a narrow,
  explicitly-documented exception -- a direct `Template::exposeData()`
  call from `AdminShell::runDispatch()`, not a fake View. All 3 layout
  files' static `page-data` script registration folds cleanly into
  `ThemeBaseAssets` with no question. Not yet done.
- 4 templates (`comment_list.latte`/`mainpage_categories.latte`/
  `thumbnails.latte`/`picture_content.latte`) gate a couple of script
  registrations on `$derivative->isCached()`, a per-item service call
  no DTO View can replicate -- decided to register those scripts
  unconditionally (`PageAssets::add()` is dedup-safe; an unused
  `<script>` tag isn't a functional regression). Not yet done.

Migrated 10 more pages/Views since the survey: `RedirectView` (1),
`PopuphelpView` (1, `$isAdminContext` fix above), `SelectedTagsView`
(1), `IdentificationView` (2+3 across 2 physical files,
`$isStandardPagesTheme` fix above), `RegisterView` (2+3 across 2
physical files), `PasswordView` (4+4 across 2 physical files, plus a
3-way `$action`-gated `footerScript` `match()`), `NotificationView`
(the campaign's first real `HasHeadLinks` migration -- 2 RSS-discovery
`<link>` tags), `PictureNavButtonsView` (8 calls, contract-only, shared
by `picture.latte` AND `slideshow.latte` -- both real parents construct
an instance and merge in, landed together in one commit to avoid
silently regressing `slideshow.latte`), `PictureView` (13 calls, new
`$rootUrl`), `SlideshowView` (0 own calls, merge-only) -- **44 pages/
Views landed so far, ~366 of 945 call sites**. `test:golden-html`
byte-identical throughout (mix of pure-whitespace and fully-clean
regenerations); `picture-1`'s regeneration confirmed every merged
JSON-island key matched byte-for-byte. New unit tests added for every
real branch introduced (`IdentificationView`/`RegisterView`/
`PasswordView`'s theme branch, `PasswordView`'s 3-way action match,
`PictureNavButtonsView`'s 7 independently-nullable exposedPageData
branches, `PictureView`'s `uOriginal`/`rating`-gated branches,
`PopuphelpView`'s context branch).

Migrated 7 more pages/Views: `ToasterView` (2 calls, contract-only,
one real parent confirmed via repo-wide grep) + `ProfileView` (26
calls across the `standard_pages` physical file, 0 on the default
physical file -- same shared-class-two-physical-files pattern as
`PopuphelpView`, merges `ToasterView`'s `pageAssets()` in), then the
**derivative-`isCached()` batch**: `CommentListView` (5),
`CategoryCatsView` (4), `ThumbnailsView` (5), `PictureContentView` (3)
-- **51 pages/Views landed so far, ~412 of 945 call sites**. The first
3 register their `jquery.ajaxmanager`/`thumbnails.loader` pair
unconditionally (a per-item `$pwg->derivative(...)` service call no
DTO View can replicate; `PageAssets::add()` is dedup-safe, so this is
a deliberate, accepted widening); `PictureContentView` is the
exception -- its `$current['selected_derivative']` is already a real
`DerivativeImage` sitting on the View's own constructor data, so its
condition stayed exact, no widening. Confirmed via `git stash`: 2
`quick_search.latte`/`search_filters.inc.latte` migration attempts
were reverted after discovering `quick_search.latte` has a SECOND real
parent (`batch_manager_filter.inc.latte`) still in the deferred
colorbox-family batch -- both stay deferred together.

**First full-suite `test:golden-html` sweep run this campaign**
(previously verified only per-page) surfaced: 2 real, safe-to-close
verification gaps from earlier commits (`slideshow`/`infos-errors`,
closed in their own commit); 1 already-known pre-existing
`admin-config-search` `filters_names`-ordering drift (left alone); and
a full-suite-only "cumulative hit-count across ~78 sequential real
page views" artifact on `random`/`calendar-posted`/`favorites`
(individually clean, only drifts when the whole suite runs in one
process back-to-back -- left alone, not a real baseline defect).

Migrated `UserListView` (`themes/admin/default/template/user_list.latte`,
81 call sites -- 12 `pageAssets()` entries, 13 `exposedPageData()`
passthroughs, 53 `exposedStrings()` literals verified byte-for-byte
against the original via a programmatic extract-and-diff, catching and
fixing one curly-quote transcription error) -- **52 pages/Views landed
so far, ~493 of 945 call sites**. The captured `{capture
$tmpFooterScript}...{do footerScript(...)}` block (100% static JS, zero
interpolation) moved verbatim into the tail of
`themes/admin/default/js/user_list.js`, the file the page's own
`combineScript(id: 'user_list', ...)` call already registers, rather
than becoming page data.

**Layout-shells batch**: the 3 real `layout.latte` files' own
remaining unconditional tail calls -- `page-data` (all 3, 1 call
site each) plus admin's own `jquery.tipTip`/2 `exposeData()`
(`whats_new_major_version`/`show_whats_new`)/`footer` (4 more call
sites, admin only) -- **7 call sites, ~500 of 945 total**. None of
these have a page-level View to attach to (`layout.latte` is never a
`Renderer::render()` target), so none add to the pages/Views count.
Resolved as 2 pieces: `ThemeBaseAssets::lateAdminScripts()` (new
method, admin-only `jquery.tipTip`/`footer`) plus `page-data` itself,
both registered from `Template::finalizeHtml()` -- deliberately *not*
folded into `ThemeBaseAssets`'s own eager theme-init dispatch, despite
being static and theme-wide like everything else there. Every one of
these 4 originally sat at `layout.latte`'s own tail, executing only
after every page-specific and nested-partial script had already
registered; eager theme-init registration inserted them *first*
instead, reordering `PageAssets::resolveScripts()`'s same-priority
tie-break (this section's own "real ordering risk" concern, hit for
real, not just theoretically) -- caught via a first-ever full-suite
`test:golden-html` run showing 62 failures, root-caused to this
insertion-order swap rather than accepted-and-regenerated blindly.
`AdminShell::runDispatch()`'s 2 `exposeData()` calls moved the same
way, from before `Renderer::render()` to right after it (before
`finalizeHtml()`), to preserve the JSON island's original key order.
A second real bug caught during the same investigation:
`InstallWizard`'s separately-rooted `Template` never calls
`setTheme()`'s theme-base path at all (`$path !== 'template'`) and
never wanted `page-data.js` loaded -- an unconditional
`finalizeHtml()` registration would have added it anyway. Fixed via a
new `$themeBaseApplied` guard flag, set only when
`applyThemeBaseAssets()` actually runs past its own early return.
Full-suite `test:golden-html` re-run confirmed clean afterward (74/74
pass); 3 further real, pre-existing baseline gaps unrelated to this
batch (`random`/`calendar-posted`/`favorites` missing the
`CommentListView`/`ThumbnailsView` widening from an earlier commit,
confirmed via `git stash` to reproduce with this batch's changes
removed too) were closed in the same regeneration pass;
`admin-dashboard`'s non-deterministic activity/login counts and the
already-documented `admin-config-search` `filters_names` drift were
deliberately left untouched (`git checkout --` on just those 2
baseline files after the batch regeneration).

Migrated 8 more non-colorbox-blocked pages one at a time
(`NotificationByMailView`, `GroupListView`, `HistoryView`,
`UserActivityView`, `ConfigurationMainView`, `IntroView`,
`ThemesInstalledView`, `ThemesNewView`) -- each still includes
`colorbox.inc.latte`/`datepicker.inc.latte`/`autosize.inc.latte`
(still imperative at the time), so each got the same known
same-priority-tie reorder against that still-live include,
golden-html-regenerated per this section's own accepted-risk
guidance. `IntroView`/`ConfigurationMainView` both have real derived
`exposedPageData()`/`exposedStrings()` logic (an `array_keys()`
loop plus a `subscribeBaseUrl`-gated conditional; a `count()` guarded
by `is_array()`), each with its own new unit test.

**Colorbox-family batch, part 1**: `AutosizeView`/`DatepickerView`
(2 of the 6 real P42-A partials still needing `pageAssets()`) --
confirmed both are contract-only, never reached via `Renderer::
render()`'s own hook (only ever `{include}`d), so every one of their
real parents (`AutosizeView`: `NotificationByMailView`,
`PictureModifyView`, `BatchManagerUnitView`; `DatepickerView`:
`HistoryView`, `BatchManagerGlobalView`, `BatchManagerUnitView`,
`PictureModifyView`) constructs an instance purely to merge
`->pageAssets()` in, the same construct-and-merge pattern
`PictureNavButtonsView`/`ToasterView` already established.
`BatchManagerGlobalView`/`BatchManagerUnitView`/`PictureModifyView`
are 3 large, still-mostly-imperative pages -- only the merged
colorbox-family contribution is declarative on each so far, deliberately;
their own remaining `combineCss`/`combineScript`/`exposeData` call
sites stay imperative until a dedicated future batch, coexisting
correctly per `PageAssets::add()`'s own dedup contract.
`DatepickerView`'s `file_exists()`-gated per-language script
registration is a real derived value (new `$rootPath`/`$jqueryCode`
ambient properties replace its own `$ROOT_PATH`/
`$lang_info['jquery_code']` reads), covered by a new unit test.
`HistorySubController`/`HistoryPageRenderer` needed a new `Paths`
dependency threaded through -- caught via a real `ArgumentCountError`
on the first golden-html run after this change, not assumed --
**~63 pages/Views landed so far (2 of them contract-only colorbox-
family partials), ~674 of 945 call sites**. 4 colorbox-family
partials remain (`ColorboxView`/`AlbumSelectorView`/`AddAlbumView`/
`BatchManagerFilterView`), each with more, and more tightly-coupled,
real parents than `Autosize`/`Datepicker` had.

**Colorbox-family batch, part 2**: `ColorboxView`'s own `pageAssets()`
merged into all 12 real direct parents, plus a fresh full migration of
`AdminShellView` (`admin.latte` itself -- the shell every admin page
renders inside; a new `$hasHelp` property gates the same conditional
`admin_help`/colorbox pair the original template's own
`{if isset($U_HELP)}` block did, resolved from `AdminContentPageContext::
$helpUrl` read back via `$template->getTemplateVars('U_HELP')` after
`AdminDispatcher::dispatch()` runs) and `PhotosAddApplicationsView`
(fully migrated, 3 call sites) -- **~65 pages/Views, ~690 of 945 call
sites**. `colorbox.inc.latte`'s own imperative calls stay in place,
deliberately not deleted this batch: emptying it broke every page
reaching colorbox only *transitively*, through `album_selector.inc.latte`/
`add_album.inc.latte`'s own internal `{include 'colorbox.inc.latte',
...}` (missed by the initial real-parent grep since both reference it
by a bare relative path, not `include/colorbox.inc.latte` -- a real,
caught-not-assumed gap, confirmed via a real golden-html regression on
`search`/`admin-album`/`admin-photo-editor` and fixed by reverting).
Every direct parent's new PHP-side merge is a harmless, dedup-safe
redundant registration alongside `colorbox.inc.latte`'s own still-live
call, until `AlbumSelectorView`/`AddAlbumView` get their own turn in a
future batch and can absorb it properly. `batch_manager_unit.latte`
also keeps its own `{include 'include/colorbox.inc.latte'}` line for
an unrelated, confirmed-reproducible reason: removing it triggers a
phpstan-latte "implicit array creation" false positive on an unrelated
`$all_selected_album` loop later in the same file.

**Colorbox-family batch, parts 3-4**: `AddAlbumView` (real markup, the
"add album" popin -- only its own `combineCss`/`combineScript` calls
move declaratively, its `{include}` stays at every real parent for the
markup) and `AlbumSelectorView` (same shape, the linked-album popin,
guarded by the template's own `{if once('inc_album_selector')}` --
`once()`'s per-render dedup concern doesn't apply to the PHP-side
merge, since `PageAssets::add()`/`Template::exposeString()` are
already dedup-safe regardless of call count) closed out all 7+2 real
parents, plus a fresh full migration of `CatModifyView` (admin-album's
own page) and `SearchFiltersView`/`QuickSearchView` (search.php's
sidebar, genuinely `Renderer::render()`'d for real -- caught a real
gap here: `ExposesPageData` was initially missed, confirmed via a
golden-html diff showing album_selector's own strings vanish from the
JSON island entirely, not just reorder). `BatchManagerFilterView` is
deliberately NOT touched -- its 2 real parents already merge
`AlbumSelectorView` directly, which is enough to compensate for its
own nested `{include 'album_selector.inc.latte'}` too (same asset
ids, dedup-safe regardless of source); its own ~15 remaining call
sites are deferred to a dedicated batch, since its own constructor
properties are genuinely ambient (assigned by a *different* renderer,
`FilterPanelRenderer`, earlier in the same request -- not by its own
`{include}` call), a materially different shape than every other
partial handled so far. A fresh full migration of `PictureModifyView`
(9+6+7 call sites, the last of the 4 large colorbox-family pages to
close its own remaining, non-colorbox-family calls) -- **~68
pages/Views landed so far, ~790 of 945 call sites**. `colorbox.inc.latte`
and `help/quick_search.latte` both deliberately keep their own live
`{do}` calls (each has a second real parent -- `add_album.inc.latte`/
`album_selector.inc.latte` for colorbox, `batch_manager_filter.inc.latte`
for quick_search -- not migrated yet); every already-migrated real
parent's own merge is a harmless dedup-safe redundant registration
alongside them.

**Colorbox-family batch, part 5 -- the last 3 large pages.**
`BatchManagerUnitView` (10+6+8 call sites; new `$colorscheme`/
`$rootUrl`/`$associatedCategories` -- the last read back via
`$template->getTemplateVars('associated_categories')` right after
`FilterPanelRenderer::render()` returns, the same ambient-read-back
shape as `AdminShellView::$hasHelp` above; `all_related_categories_ids`
is a real derived value, a `json_decode()` loop over `$elements`,
covered by a new unit test; its now-fully-redundant
`{include 'include/colorbox.inc.latte'}` line was finally removed once
the `$all_selected_album` pattern that caused part 2's phpstan-latte
false positive was itself migrated away) and `BatchManagerGlobalView`
(10+8+11 call sites; new `$colorscheme`/`$rootUrl`/
`$associatedCategories`/`$allElements`, both ambient-read-back after
`FilterPanelRenderer::render()`) closed out their own remaining
non-colorbox-family calls. `PhotosAddDirectView` (14+11+14 call sites;
new `$colorscheme`/`$rootPath`/`$pluploadCode`; `plupload_i18n-{code}`'s
own `file_exists()` gate and `original_image_id_str`'s derivation from
`$formatsOriginalInfo['id'] ?? -1` are both real derived values,
covered by a new unit test) closes the last of the 4 large
colorbox-family admin pages -- **~71 pages/Views landed so far, ~882 of
945 call sites**. Dropped the last real trigger for phpstan.neon's
`encapsedStringPart.nonString` ignore rule on the generated Latte
analysis path (an unmatched-ignore PHPStan error, fixed by removing the
now-stale rule, not by suppressing or reintroducing it).

**`batch_manager_filter.inc.latte`'s own remaining 17 call sites.**
Its own 13 constructor properties are all real template variables, but
only 4 (`$dimensions`/`$filesize`/`$filter_category_selected` plus the
ambient `$themeconf['colorscheme']`) feed its own asset/data/string
registrations -- constructing a full `BatchManagerFilterView` instance
just to call 3 methods that never touch the other 9 wasn't worth it,
so its 2 real parents (`BatchManagerUnitView`/`BatchManagerGlobalView`)
declare its registrations directly instead, reading the 3 genuinely
ambient values back the same way as `$associatedCategories`/
`$allElements` (`$dimensions`/`$filesize` assigned by
`BatchManagerSubController::handle()`, `$filter_category_selected` by
`FilterPanelRenderer::render()`, both before either View is
constructed). A real ordering subtlety surfaced by a golden-html diff
on `admin-batch`: `batch_manager_filter.inc.latte` itself `{include}`s
`album_selector.inc.latte` internally (a bare relative path, the same
nested-include shape already known from `colorbox.inc.latte`/
`add_album.inc.latte`) -- since `AlbumSelectorView`'s own merge into
each parent is already fully declarative, it resolves entirely before
any template body runs at all, regardless of where its own spread sits
textually in the parent's PHP array; the filter block's own
registrations have to be placed *after* it in that array to match,
not wherever its own old `{include}` line sat relative to
`album_selector.inc.latte`'s *other*, still-imperative-at-the-time
call site. `include/batch_manager_filter.inc.latte`'s own `{include}`
line stays at both parents for its real markup (the filter form
itself) -- **~71 pages/Views landed so far, ~899 of 945 call sites**.

**`index.latte`'s own remaining 3 calls.** Re-checked against current
code rather than trusted from an earlier progress note (which had
wrongly lumped this file in with the menubar-sub-block design gap
below): `IndexView` is genuinely `Renderer::render()`'d
(`GalleryController::__invoke()`'s own
`$this->renderer->render($indexView)` call), so its own 3 static
`combineScript`/`combineCss` calls were fully portable on their own,
no design gap at all -- **~72 pages/Views landed so far, ~902 of 945
call sites**.

**The `MenubarBlockView`/`MonthCalendarView` design gap -- closed.**
`menubar_identification.latte`/`menubar_links.latte`/`menubar_menu.latte`
(5 calls) are reached only via `menubar.latte`'s own native Latte
`{include $block->template, block: $block, id: $id}` (a dynamic
filename include, never a constructed View passed through
`Renderer::render()`) -- but `MenubarView` itself (`menubar.latte`'s
own real View, rendered by `BlockManager::apply()`) already holds every
block's fully-resolved `$template`/`$data` before its own render call
starts. `MenubarView::pageAssets()`/`exposedStrings()` now iterate
`$this->blocks` and pattern-match the 3 known in-tree sub-block
filenames, replicating each one's own registrations directly --
`menubar_menu.latte`'s own `$block->data['qsearch'] === true` branch
included, with its own unit test. An unrecognized (plugin) `$block->
template` falls through untouched. `MenubarBlockView` itself stays
deliberately contract-only -- its real parent didn't need to be.
`month_calendar.latte` (1 call) turned out to have zero genuine
dynamism despite its own `{include $FILE_CHRONOLOGY_VIEW}` shape:
`CalendarChronologyPageContext::$fileChronologyView` has exactly one
real construction site (`CalendarRenderer::render()`) and it's always
the literal `'month_calendar.latte'`. `GalleryController` now reads
`FILE_CHRONOLOGY_VIEW` back ambiently (assigned earlier in the same
request by `SectionPopulator`'s own `CalendarRenderer::render()` call)
before constructing `IndexView`, matching the template's own
`{if isset($FILE_CHRONOLOGY_VIEW)}` guard -- verified end-to-end via a
new browser test, since `chronology_view=calendar` had zero existing
test coverage at any level before this (golden-html's own
`calendar-posted` fixture only exercises the `list` view).

**A real gap in the prior "local_head.latte is fully closed" note,
found while re-checking.** `local_head.latte` itself still had 1 live
call (`print.css`) -- `Template::resolveLocalHeadOnce()` calls
`renderView()` directly, not `Renderer::render()` (would be circular:
`Renderer` itself depends on `Template` via `CurrentTemplate`), so
giving `LocalHeadView` a `pageAssets()` alone would never have been
applied automatically. Fixed by having `resolveLocalHeadOnce()` apply
`HasPageAssets` inline, the same way `Renderer::render()`'s own hook
would.

**P42-B is fully closed.** The last 3 call sites (`colorbox.inc.latte`
x2, `help/quick_search.latte` x1) were each blocked on one real parent
not yet merging their contribution directly -- `SearchFiltersView`/
`CatModifyView` now merge `ColorboxView`, `BatchManagerUnitView`/
`BatchManagerGlobalView` now merge `QuickSearchView` (its own
`is_dark_mode` derived from `$this->colorscheme === 'dark'`, matching
`SearchFiltersView`'s own established pattern). Every real parent of
both files now covers them declaratively, so `colorbox.inc.latte` is
now fully empty (matching `autosize.inc.latte`/`datepicker.inc.latte`'s
own zero-markup precedent) and its now-redundant `{include}` lines
were removed from `add_album.inc.latte`/`album_selector.inc.latte`;
`quick_search.latte` keeps its own `{include}` at both real parents
for its real markup, only its `{do combineCss}` call moved. Two more
real ordering fixes surfaced via golden-html diffs on `search`/
`admin-album`/`admin-batch`, not assumed: `ColorboxView`'s own
resolution position relative to `AlbumSelectorView`/`QuickSearchView`
differs by page (search.php wants it last; cat_modify.php wants it
right after `AlbumSelectorView`) -- once two things are BOTH fully
declarative, their relative order is governed purely by array
position, not by either one's former textual `{include}` position, so
this has to be checked per page, not assumed transitively from one
already-fixed page. Also restored a blank line lost from
`album_selector.inc.latte`'s own body when its nested colorbox
`{include}` was removed. **Re-grepped the full corpus and confirmed
zero remaining `{do combineCss}`/`{do combineScript}`/`{do exposeData}`/
`{do exposeString}`/`{do footerScript}`/`{do htmlHead}` calls anywhere
-- 945 of 945 call sites migrated.**

**Array-to-object campaign reimplementation** (labeled "final step of
P42" in this entry's own earlier draft — a naming collision with the
*actual* mechanism-cleanup final step below, which is a separate,
unrelated piece of work that happens to share the same label; both are
now done, see each one's own completion note): reimplement the
`17.x-rewrite-3` worktree's own independent array-to-object campaign
(124 commits, 614 files, `$array['field']` access converted to typed
`$object->field` access, plus a loose-union signature-narrowing sweep)
on top of `17.x-rewrite`. That branch diverged from this one at
`0c71fa6c55` and the two have drifted too far apart for a clean
merge/rebase/cherry-pick of the whole range -- reimplement the
*pattern* file-by-file instead, using
`/home/torres/piwigo17-rewrite-3/HANDOFF-array-to-object-campaign.md`
as the theme-by-theme index (every cited commit hash is directly
`git show`-able from this worktree too, since both share the same
`.git` object database -- no fetch needed).

**Effectively complete.** Sections 1-2 (standalone conversions,
AuthService), Section 4 (small utility VOs, clusters 14/17/18/20),
Section 12 (the `SearchRules` VO family mini-campaign — foundation
commit plus all 5 adoption sites: `HistorySearchController`,
`ImageFilteredSearchCreateController`, `SearchController`,
`SearchService`, `SearchFilterRenderer`) were already fully landed.
This session additionally landed **Sections 3, 5, 6, 7 in full** (one
commit per themed item, each PHPStan/ECS/Unit+Integration+Browser-
verified), correcting an earlier gap in this very entry — the previous
text never mentioned these 4 sections in either its "landed" or
"remaining" list, an oversight discovered and resolved by direct code
verification rather than trusting the stale claim:

- **Section 3 (Users domain, 9 items)**: computed-category rollup row,
  `getRelatedCategoriesMenu()` row typing, `UserRepository::
  insertUserInfos()` → `UserInfoInsertRow`, `ProfileFormHandler`'s
  `$userdata` → `User`, the `CategoryCatsRenderer` bug fix (folded into
  item 1's own commit), `RegisterUserCheck`/`RegisterUser` event
  payloads, `UserRowFetcher` → `UserListRow`, `SrcImage`/
  `DerivativeImage`'s `$infos` → `SrcImageInfo`/`DerivativePathInfo`
  (2 commits — the main conversion plus a follow-up eliminating 6
  remaining `toArray()`-then-`fromRow()` round trips).
- **Sections 5, 6, 7**: cluster-8 write-map typing, the
  `image_category` id-narrowing chain, and the Category/calendar/rank
  helpers cluster — all landed exactly as scoped by the handoff doc.

**Sections 8-11 and 13-14 turned out to already be done**, verified by
checking every named class/method from the handoff doc against this
tree directly (not assumed from the doc's own framing, which is what
missed Sections 3/5/6/7 above) — completed independently by this
branch's own earlier P40 View/Renderer campaign and prior signature-
narrowing work, just under a different naming convention (e.g. the
handoff's `Configuration*PageContext` family is this branch's own
split `Configuration*Data`/`Configuration*View` pairs;
`*InstalledPageContext` is `*InstalledView`). Confirmed present:
`NotificationByMailUserRow`, `SiteRow`, `MenubarBlockConfigRow`,
`CategoryListRow`, `PictureFormatRow`, `CategoryChildRow`,
`MetadataPanel`, `GroupListRow`, `ImageThumbUrl`, `RatingReportRow`/
`RatingReportRateRow`, `DebugInfo`, `AnomalyRow`,
`ConfigurationCommentsData`/`ConfigurationMainData`/
`ConfigurationDisplayData`/`ConfigurationSizesTabData`/
`ConfigurationSearchTabData`/`ConfigurationWatermarkResult`,
`LanguagesInstalledView`/`ThemesInstalledView`/`PluginsInstalledView`/
`PictureModifyView`/`ProfileView`, `BatchManagerFilterOptionsPageContext`
(already VO-typed), `findDistinctFilesizes(): list<int>`,
`CommentApiListRow`, and ~17 of Section 13's ~19 signature-narrowing
items (already narrowed to their target type — `int`/`?int`/`string`/
`list<int>`/`list<string>` as specified). The 2 Section-13 items that
don't exist under their cited names (`UpdatesPwg` step chain,
`ExtendForTemplatesPageContext`) and Section 14's `Session\Session`
scaffold (never existed on this branch) are moot — nothing to convert
or remove.

**Section 15 (Psalm-suppression cleanup) is now closed, superseded.**
Rather than clean up suppressions, Psalm was removed from the project
entirely on 2026-08-24, after a full errorLevel-1 sweep found the
overwhelming majority of remaining findings were Psalm-only false
positives against a codebase PHPStan already covers with more precision
(see `docs/REFERENCE.md`'s "Key design decisions" for the full
reasoning). Every inline `@psalm-suppress` and `psalm.xml` itself were
removed in the same pass — there is no longer a suppression surface to
clean up.

**Full-repo validation re-run and confirmed clean** after every item
above landed (including the Dimensions VO fix, the last of this
stretch's commits): full `vendor/bin/phpstan analyse` (0 errors),
`vendor/bin/ecs check` (0 errors), `vendor/bin/deptrac analyse` (0
violations), full `composer test -- --testsuite=Unit,Arch` (5516
passed), and full `composer test:integration` (2161 passed) all green.
`composer test:browser`/`test:golden-html`/`test:visual` weren't
separately re-run in this pass (no template-visible behavior change in
this stretch — the array-to-object work is internal typing only).

**The real final step of P42 (Status table's own label): delete the 6
now-dead imperative Latte functions once every real call site had
migrated — done.** With the 945-call-site migration (P42-B, above)
complete, a project-wide grep confirmed zero remaining `{do
combineCss}`/`{do combineScript}`/`{do exposeData}`/`{do exposeString}`/
`{do footerScript}`/`{do htmlHead}` calls in any `.latte` file. Deleted
`Template::combineScript()`/`combineCss()`/`htmlHead()`/`footerScript()`
outright (each had zero remaining internal PHP callers too, once their
own last 4 internal call sites — `Template::localCssRules()` x2,
`registerHeadLink()`, `registerActionSwitchBox()` — were inlined to
build the `PageAssets`/`$htmlHeadElements` registration directly instead
of routing through the now-dead method) and removed all 6 from
`PiwigoExtension::getFunctions()`'s registration list.
`combineScript()`'s own `$errorCollector`-backed invalid-`load`-value
validation had no successor (the declarative replacement,
`AssetContribution::script()`, takes a real `LoadMode` enum, making an
invalid load value a compile-time impossibility rather than a runtime
check) — its own now-orphaned `ErrorCollector` constructor param/DI
wiring was removed from `Template` and cleaned up through 7 real
`new Template(...)`/`new NoPhotoYetRenderer(...)`/`new
InstallWizard(...)` construction sites (`NoPhotoYetRenderer`,
`InstallWizard`, `public/install.php`, `RequestBootstrap` x3,
`RedirectService` x2, `MailService`) plus their own now-dead
`errorCollector()` factory helpers.

**`exposeData()`/`exposeString()` survive as real PHP-only methods** —
`Renderer::render()`'s own pre-population step and `AdminShell.php`
still call them directly; only their Latte-function registration (no
longer reachable from any template) was removed.

**`finalizeHtml()` itself was *not* deletable — corrects this entry's
own prior claim.** It's real, permanent, load-bearing architecture with
~20 real controller callers (every page-rendering controller calls
`$template->finalizeHtml((string) $html)` after `Renderer::render()`),
and `getCombinedScripts()`/`getCombinedCss()`/`getPageDataScript()`
remain real, live Latte functions every real `layout.latte` (`default`/
`admin/default`/`standard_pages`, plus `install.latte`) calls directly
via `{=getCombinedScripts('header')}`/`{=getCombinedCss()}`/
`{=getPageDataScript()}` — these print the placeholder tags
`finalizeHtml()` substitutes, a permanent pattern, not a migration
artifact.

Regenerated the checked-in `tools/phpstan/Latte/Generated/
LatteAnalysisShims.php` via `composer generate:latte-shims` (its own
dedicated test, `ShimClassGeneratorTest.php`, catches drift between
this file and `PiwigoExtension`'s real registrations). Updated 7 test
files that directly exercised the deleted methods
(`TemplateInstanceTest.php`, `PiwigoExtensionTest.php`,
`LatteEngineWiringTest.php`, `LatteTemplateCompilerTest.php`,
`ShimClassGeneratorTest.php`) to exercise the surviving declarative/
PHP-only equivalents instead (`registerPageAssets()`/
`AssetContribution::script()`/`inlineScript()`/`css()`,
`registerHeadLink()`, `once()`); deleted 4 tests whose own behavior has
no successor (`combineScript`'s invalid-`load` validation, its
empty-`require`-string handling, and `footerScript()`/`htmlHead()`'s
empty-content no-op guards — all genuinely retired, not relocated).
Full `vendor/bin/phpstan analyse` (0 errors), `vendor/bin/ecs check` (0
errors), full `composer test -- --testsuite=Unit,Arch` (5513 passed —
net -4, matching the 4 deleted tests exactly), and full `composer
test:integration` (2161 passed) all confirmed clean.

**P43 — Typed contributions + plugin-owned routes.**

*The problem.* Core ships **two** mechanisms for one need, on the same
page: `Template::addIndexButton()`/`parseIndexButtons()` (a ranked
collector flushed into `PLUGIN_INDEX_BUTTONS` by an explicit controller
call right before render) and `Template::concat()` writing
`PLUGIN_INDEX_ACTIONS`. Two names, two shapes, one need — what happens
when each need is solved locally. The `addX()`/`parseX()` split is itself
a Smarty vestige: it exists because Smarty could only read what was
assigned before render, which Latte does not require.

*Why the obvious fix is not enough.* A string-keyed slot registry
unifies those two, but a field survey of the sibling repos shows the real
demand is an order of magnitude larger: **122 of 433 plugins (28%) use
`set_prefilter`, across 211 distinct callbacks** — and that demand
resolves into a *finite* list of kinds: admin form field ~32, picture
info row ~21, profile/register field ~15, auth buttons ~13, thumbnail
overlay ~9, picture action ~8, menu item ~6, and a short tail.

*The design.* Because the kinds are finite, contributions become **typed
value objects**, not string-keyed slots carrying raw HTML, so **the type
determines the destination** and there is no point name to pass. That
structurally removes the one risk a string-keyed registry carries — a
mistyped point name silently creating a disconnected point that never
renders. A wrong kind is a type error; a wrong target is an invalid enum
case. Multi-destination kinds take a typed enum target
(`AdminForm::PictureModify`); per-row cases take a typed field
(`themeId`), never a composed key. Ordering is a `Priority` enum.
Core and themes render every contribution, so themes can restyle them.
This absorbs `addIndexButton`, `addPictureButton`, `parseIndexButtons`,
`parsePictureButtons` and `concat('PLUGIN_INDEX_ACTIONS')`, and adds
`FieldOverride` and `FormProvider` kinds.

*Also in scope.* Prune the Latte API: 18 zero-use registrations, and
`math()` with its `eval()` — exactly 1 call site, becoming `{=abs(...)}`,
removing ~75 lines and the last `eval()` in the codebase. Migrate Smarty
duplicates onto Latte built-ins (`count` → `|length`, `date_format` →
`|date`, `nl2br` → `|breakLines`, `strip_tags` → `|striptags`, `join` →
`|implode`, `cat` → `~`), checking semantics per swap: Smarty's
`strip_tags` replaces a tag with a *space* and Latte's `|striptags` does
not. Rewrite the 48 `htmlOptions` and 6 `htmlRadios` call sites as
`{foreach}` loops. Emit **stable DOM hooks** (`data-image-id`,
`data-category-id`, stable form-control ids) — this alone retires ~12% of
historical `set_prefilter` demand, which exists only because core emits
nothing stable to hook onto.

*The rendering API.* `render(View $view): Html` becomes the single
rendering API application-wide, with `ExtensionContext::render(View): Html`
for plugins (the `myplugin:` prefix stays an internal loader detail), and
`SettingsPageInterface::handleSettingsRequest()` returning a `View`
instead of `void` plus `ADMIN_CONTENT`.

*Deliberately no escape hatch*: no loader-chain template override, no
block override, no rendered-output filter. Consistency and predictability
are worth more than flexibility here, and needing to extend core later is
acceptable. Plugin-owned routes are consequently **required, not
optional** — making `Bootstrap\RouteDefinitions` extensible is the only
remaining answer for page ownership (`tag_groups`,
`piwigo_masonry_grid`, `PWG_Stuffs`).

**P43-A, part 1 (landed) — typed index/picture buttons and actions.**
`Piwigo\Contribution\{ButtonContribution,ActionContribution,PanelLink}`
replace `addIndexButton()`/`addPictureButton()`'s raw-HTML-string
contract and `concat('PLUGIN_INDEX_ACTIONS'/'PLUGIN_PICTURE_ACTIONS', ...)`.
A button navigates directly (label/url/icon/id/order); an action
toggles an expandable panel of `PanelLink` entries, matching both
core's own native `switchBox` pattern (Related tags/Sort order/Photo
sizes) and the real `language_switch_17.0.0` plugin's own flag-picker
— the one real, already-rewritten 17.0.0-generation caller found for
`PLUGIN_INDEX_ACTIONS` in the wider plugin ecosystem, whose own
docblock confirmed the panel shape a plain button-only design would
have missed. Registering an action with a panel wires its `switchBox`
toggle automatically (`Template::registerActionSwitchBox()`), so a
plugin author writes no JS of their own. `SlideshowView`'s own dead
`$pluginPictureButtons` property (declared, never read by
`slideshow.latte`) was dropped while this exact area was already being
touched, not carried forward into the new typed system.
`parseIndexButtons()`/`parsePictureButtons()` (zero real callers) were
deleted outright.

**P43-A, part 2 (landed) — typed picture info row.**
`Piwigo\Contribution\PictureInfoRow` replaces a hand-written
`set_prefilter('picture', ...)` regex/`str_replace()` patch against
`picture.latte`'s `<dl id="standard" class="imageInfoTable">` list —
real-plugin field-shape research first (`~/piwigo16-plugins`: 42 real
`set_prefilter('picture', ...)` sites; `Copyrights`, `download_counter`,
`Extended_author`, `piwigo-openstreetmap`, `piwigo-forecast` read in
full). `$value` is a plain, always-escaped `string` — no `Html` escape
hatch: an initial design let `$value` accept raw `Html`, justified by
`piwigo-openstreetmap`'s `<div id="map">` widget and
`piwigo-forecast`'s multi-line formatted weather data, but neither
plugin is ported yet — P43's contributions are typed value objects
specifically so there's no raw-HTML surface to reason about, so the
escape hatch was dropped; a plugin whose real content needs richer
markup gets a typed answer for that when it's actually ported, not a
passthrough kept around on spec. Same collector shape as
`ButtonContribution`/`ActionContribution` (`Template::$pictureInfoRows`/
`addPictureInfoRow()`/`pictureInfoRows()`, reusing the existing
`flattenByOrder()` helper) — no new abstraction. Wired through
`PictureView::$pluginPictureInfoRows`; not added to `SlideshowView`,
which never renders the `imageInfoTable` block.

**P43-A, part 3 (landed) — typed register/profile field.**
`Piwigo\Contribution\ProfileField` (+ a 2-case `FieldType` enum: `Text`,
`Checkbox`) replaces a hand-written
`set_prefilter('register', ...)`/`set_prefilter('profile_content', ...)`
patch — real-plugin research (`AddInfousers`, `CustomUsersFields`, both
read in full) confirmed the real insertion point is always right before
the form's own `<p class="bottomButtons">`, and the real field shape is
almost entirely plain text inputs (one real `textarea` seen, no other
type). `$value` is a plain, always-escaped `string` from the start this
time — the `PictureInfoRow` raw-`Html` detour above wasn't repeated.
One field per contribution (matching `PictureInfoRow`'s
one-row-per-contribution shape), not a whole arbitrary fieldset. Two
independent collections (`addRegisterField()`/`registerFields()`,
`addProfileField()`/`profileFields()`), matching real plugin behavior:
a plugin can target `register.latte`, `profile_content.latte`, or
both, independently ordered. Wired through `RegisterView` (shared by
both real `register.latte` files) and both `ProfileFormView` (default
theme) and `ProfileView` (`standard_pages`, which renders its own form
inline rather than embedding `profile_content.latte`).

Also deleted a genuinely dead mechanism found while reading these
templates: `$PLUGINS_PROFILE`/`$plugin_block`, present in both
`default/profile_content.latte` and `standard_pages/profile.latte`,
had zero real assignments anywhere in `src/` — and its own
`{include $plugin_block['template']}` was a dynamic, plugin-supplied
template path, exactly the kind of escape hatch P43 is meant to close.
Not carried forward into the typed replacement.

**P43-A, part 4 (landed) — auth button, thumbnail overlay, menu item,
`FieldOverride`, `FormProvider`.** The remaining 5 kinds, landed
together in one pass (implementation batched, full verification run
once at the end, not per-kind — a deliberate departure from parts 1-3's
own per-kind commit-and-verify cadence, at the user's explicit
direction for this batch).

- `Piwigo\Contribution\AuthButton` -- a third-party sign-in button on
  the identification/register pages (real plugins: `oAuth`,
  `SocialConnect`). `$providerId` (not a URL) is what the page's own JS
  dispatches on -- a real sign-in flow is JS-driven, not a plain link.
  One shared list for both pages, unlike `ProfileField`'s per-page
  split: every real plugin read registers the identical provider list
  on both with the same callback.
- `Piwigo\Contribution\ThumbnailOverlay` -- an icon overlay on every
  gallery-index thumbnail (real plugins: `quick_fav`, `quick_star`).
  Per-photo state (is this one a favorite) stays out of core's typed
  contract -- a plugin's own JS reads the rendered `data-image-id` and
  does its own state lookup/toggle client-side, the same mount-point
  pattern the rest of P43-A already established.
- `Piwigo\Contribution\MenuItem` -- a nav link appended to the
  menubar's own "Menu" block (`mbMenu`/`menubar_menu.latte`). No
  template change needed -- that template already iterates its row
  list generically, so `MenubarRenderer` just appends converted rows to
  `$block->data`.
- `Piwigo\Contribution\FieldOverride` (a 1-case enum) -- hides the
  profile-edit form's native password fields, the typed replacement for
  `oAuth`'s own `set_prefilter('profile_content', ...)` patch
  (client-side jQuery `.hide()` there; server-side non-render here).
  Deliberately narrow: one case, matching the one real concrete need
  found, not a generic "hide any named field" mechanism invented on
  spec.
- `Piwigo\Contribution\FormProvider` -- a titled group of
  `ProfileField`s rendered as its own labeled section on the
  profile-edit form, the typed replacement for the dead
  `$PLUGINS_PROFILE`/`$plugin_block` mechanism already deleted in part
  3 above. Reuses `ProfileField`'s existing typed, no-raw-HTML field
  shape rather than inventing a second rendering primitive.

All 5 follow the same collector shape parts 1-3 already established
(`Template::addXxx()`/`xxx()`, ksort+flatten by `$order` via the
existing `flattenByOrder()` helper where ordering applies). Golden-HTML
gated: all 7 baseline changes from this batch are pure whitespace (the
new templates' empty `n:if`/`n:foreach` blocks, no plugin registered in
the fixture), reviewed by hand, no content or semantic change. P43-A is
now fully landed.

**P43-B (landed) — Latte API cleanup (`PiwigoExtension.php`).** Three
mechanical sub-passes, gated by golden-HTML on every commit. (1)
`math()`/`eval()` removal: the one real call site
(`menubar.latte`'s `{=math('abs(pos)', pos: $block['pos'])}`) becomes
`{=abs($block['pos'])}`; deleted the ~85-line whitelisted-function-name
`eval()` dispatcher, the last `eval()` in the codebase. (2) 22
zero-use registrations pruned (re-grepped fresh against the plan's own
24-name list rather than trusting it — 2 were already live or handled
elsewhere), including the `SessionService`-only-used-by-`getDevice()`
cascade this surfaced: `Template`, `NoPhotoYetRenderer`, `MailService`,
and `RedirectService`'s `sessionService()` resolver all dropped the
now-dead dependency across 7 real construction sites. (3)
`cat`/`count`/`join`/`strip_tags` migrated onto Latte's own
`.`/`|length`/`|implode`/`|stripTags` builtins (5 real
`nl2br`→`|breakLines` sites deliberately **not** migrated — 3 rely on
`|htmlspecialchars`'s `ENT_QUOTES` for double-quoted-attribute safety
that `|breakLines`'s internal `ENT_NOQUOTES` can't provide); caught and
fixed 2 real plan errors along the way (the plan's cited `cat` → `~`
target is Latte's *unary bitwise-not* operator, not concatenation —
confirmed via `UnaryOpNode.php` and an isolated compile test, fixed to
`.`; Smarty's `strip_tags` replaces a removed tag with a space,
`|striptags` doesn't). (4) `htmlOptions()`/`htmlRadios()` (Smarty
`{html_options}`/`{html_radios}` ports) replaced by plain `n:foreach`
loops directly in templates, 48+6 real sites across 21 files — full
detail (the two real bugs found and fixed: entity double-encoding, a
live HTTP 500 from a bare `(string)` cast on an `int` option value) is
in that sub-pass's own commit message. Every sub-pass individually
golden-HTML-gated and independently commit-reviewed, not batched into
one pass at the end.

**P43-C (landed) — stable DOM hooks.** `data-image-id` added to
`picture.latte`/`slideshow.latte`'s own `#theImage` wrapper and to
each `thumbnails.latte` `<li>` -- mirrors `rating.latte`'s own
pre-existing `data-image-id` convention, the one real precedent a
corpus-wide audit found (`data-category-id` had zero prior uses
anywhere). `data-category-id` added to `mainpage_categories.latte`'s
own per-album `<li>`. The picture page's own 6 rating buttons (0-5)
also gained stable, indexed ids (`id="rate-{$mark}"`) -- a real gap
found auditing `picture.latte`'s own form controls, part of this
batch's own "stable form-control ids" scope. Retires real historical
`set_prefilter` demand outright: a plugin that only needed a selector
to hook its own `<script>` onto, no markup insertion, no longer needs
`set_prefilter` at all for that.

Also deleted 6 confirmed-dead raw-HTML plugin hooks found while
touching these same 2 files -- `$PLUGIN_PICTURE_BEFORE`/`AFTER`
(`picture.latte`) and `$PLUGIN_INDEX_CONTENT_BEFORE`/`BEGIN`/`END`/
`AFTER` (`index.latte`), all zero real assignments anywhere in `src/`,
same pattern as `$PLUGINS_PROFILE` (P43-A part 3). Golden-HTML +
visual-regression gated (this batch's own stated requirement, beyond
P43-B's golden-HTML-only gate): 9 real routes affected, every baseline
change reviewed by hand -- either the new attribute/id with a correct
real value, or whitespace removed by the dead-hook cleanup. No visual
regression (attributes and ids are invisible).

**P43-D (landed) — `render(View): Html` as `ExtensionContext`'s single
rendering API.** `ExtensionContext::render(View): Html` delegates to
an injected `Template\Renderer`, matching `Renderer::render()`'s own
contract exactly (page-asset/exposed-page-data pre-population,
`#[Template]`-attribute resolution) — same `boot()`-time
`isInitialized()` guard as the existing `template()` accessor, for the
identical reason. `Renderer` threaded through as a new required
constructor param on `ExtensionContext`/`ExtensionContextFactory`/
`PluginBootstrapMiddleware` — the real manual-construction sites
resolve it via `RequestBootstrap::templateRenderer()`/container
autowiring, no new infrastructure.

`SettingsPageInterface::handleSettingsRequest()` changes from `void`
(side-effecting `ADMIN_CONTENT` via `assignVarFromTemplate()`) to
returning a `View`. Its 2 real callers (`PluginSubController`/
`ThemeSubController`) now render the returned `View` via
`ExtensionContext::render()`'s own `Renderer` and assign it into
`AdminContentPageContext` themselves — the same shape every other
admin sub-controller already uses (P40's own Batch 3 pattern).

A plugin author's own `#[Template(...)]` attribute can't embed a
dynamically-computed install-path (PHP attribute arguments must be
constant expressions) — the real resolution mechanism for that
(informally sketched as a `myplugin:` prefix) is explicitly out of
scope here per the plan's own design note, deferred until a real
plugin actually needs it. Every fixture touched by this batch
sidesteps this by embedding a real, already-known absolute path as a
literal string at fixture-generation time (dynamically-generated test
code can do this freely; a real, hand-authored plugin's source is
fixed long before any install path is known).

Full verification (5441 Unit/Arch, 2121 Integration, 774 Browser, 74
golden-HTML, 66 visual-regression) surfaced two real, unrelated
regressions from the earlier P43-B batch — neither covered by
golden-HTML/visual-regression's own fixture data — fixed in the same
pass: a `|stripTags|replace:...` filter-chain order bug (Latte's
`stripTags` marks its own output `Html`-typed, which broke the *next*
piped filter; a real HTTP 500 on any picture page with a non-empty
author/comment field) and a stale `NotificationByMailSubControllerTest`
assertion still expecting P43-B's pre-migration
`selected="selected"` XHTML attribute pair instead of the bare
HTML5-style `selected` its own `n:attr` conversion now renders.

**P43-F (landed) — migrate the admin rendering backbone onto
`render(View): Html`.** Closes the gap P43-D deliberately left open: a
new `Controller\Admin\Projection\AdminPageResult` (`content: Html,
pageTitle: ?string = null, helpUrl: ?string = null`) replaces
`AdminSubControllerInterface::handle()`'s own side-effecting
`void` contract, and every one of the 40 real `Piwigo\Admin\*PageRenderer`
classes' `render()` methods, with `AdminDispatcher::dispatch()` now the
one seam that turns a returned `AdminPageResult` into the ambient
`AdminContentPageContext` every admin page's shell reads — closing the
76-file scope (36 `AdminSubControllerInterface` implementers + 40
`*PageRenderer` classes) this batch's own plan text named.

Landed in two commits rather than one atomic 76-file change: a first
pass introduced the DTO with `AdminSubControllerInterface::handle()`
temporarily returning `?AdminPageResult` (a page not yet converted kept
assigning `AdminContentPageContext` itself and returned `null`, which
`AdminDispatcher::dispatch()` treated as "nothing further to do"),
letting each page convert independently; a second pass finished the
remaining 8 `SubController`s still on that shim and dropped the `?`
once every real implementer returned a genuine `AdminPageResult`.

The 4 multi-tab dispatchers (`ThemesSubController`/`PluginsSubController`/
`LanguagesSubController`/`UpdatesSubController`, plus `MaintenanceSubController`
and `AlbumSubController`/`PhotoSubController`, which turned out to share
the identical shape) merge their own title/help-url override with
whichever per-tab renderer's `AdminPageResult` the selected tab produced,
rather than assigning a second, separate `AdminContentPageContext` after
the fact.

Found and fixed two real regressions the migration itself introduced
along the way, both caught via golden-HTML diffs (a missing
`autoupdate_bar` block): `ThemesSubController` and `PhotoSubController`
were each silently discarding an already-converted renderer's real
output because their own `handle()` hadn't been converted yet in the
same pass — a direct consequence of converting `PageRenderer`s and their
parent `SubController`s in different commits, confirmed safe everywhere
else via a systematic sweep of every converted renderer's real callers.

Verified: PHPStan clean, ECS clean, deptrac 0 violations, full Unit/Arch
(5441 passed), golden-HTML (74 passed), visual-regression (66 passed).

**P43-E (landed) — plugin-owned routes (admin pages + public pages).**
The piece that makes P43's own "deliberately no escape hatch" decision
viable: since nothing else lets a plugin own a whole page, page
ownership goes through real routing/dispatch instead.

Public pages: new `PluginConfig\PageRouteProviderInterface` (manifest
`hasPageRoutes: true`) mirrors `ApiRouteProviderInterface` exactly --
`registerPageRoutes(RouteCollection $routes): void`, called once per
request from the same `Http\Middleware\RoutingMiddleware::process()`
call site as the API-routes registrar, via a new narrow `Routing\
PageRouteRegistrarInterface` (bound to `PluginConfig\CurrentPluginRegistry`,
same shape as `ApiRouteRegistrarInterface`). Deliberately no reserved
URL-prefix/route-name namespace here (unlike API routes' mandatory
`/api/v1/plugin-routes/{id}/`): a real clean-URL page route (the actual
cited need -- `tag_groups`/`piwigo_masonry_grid`/`PWG_Stuffs`-style
plugins wanting their own root-level entry point, e.g. `/tag_groups.php`)
needs to look like an ordinary path, not a namespaced sub-path. Stays
safe without one because `RoutingMiddleware::process()` always appends
plugin routes after `Bootstrap\RouteDefinitions::all()`'s own core
routes, and `UrlMatcher` tries routes in registration order -- a plugin
can add a route but can never shadow an existing core path.

Admin pages: new `PluginConfig\AdminPageProviderInterface` (manifest
`hasAdminPages: true`) -- `registerAdminPages(): array<string, class-string>`,
called once per request. `PluginRegistry::adminPages()` aggregates every
booted plugin's own map, throwing `PluginValidationException` on an
inter-plugin slug collision (silently letting the later plugin win would
make the earlier one permanently unreachable with no visible error).
`Bootstrap\AdminDispatcher::pageMap()` (a new public method, replacing
the old private `map()` internally) merges that onto the static
`config/admin_pages.php` map, throwing `LogicException` on a
plugin-vs-core collision -- gracefully skipping the plugin half entirely
when `CurrentPluginRegistry` isn't initialised yet (a new
`isInitialized()` method, mirroring `Template\CurrentTemplate`'s own),
covering both a Unit test dispatching directly with no real request
pipeline and (in principle) any future caller reached before
`PluginBootstrapMiddleware::process()` has run.

A real gap the plan's own text hadn't named, found only by reading
`Admin\AdminShell::runDispatch()` directly rather than trusting the
plan: that method independently re-`require`d the static
`config/admin_pages.php` file for its own `?page=` slug validation
*before* ever calling `AdminDispatcher::dispatch()` -- patching only
`AdminDispatcher::map()` (the plan's own stated scope) would have left a
plugin-contributed slug passing `dispatch()`'s own check yet still
404ing (silently falling back to `'intro'`) at this earlier gate.
Fixed by having `AdminShell` call the same new `AdminDispatcher::pageMap()`
instead of reading the file itself, making it the one real merged source
of truth both call sites share. Removed `AdminShell`'s own `Paths`
constructor param as a result -- its one remaining use was that same
direct file read.

`AdminPageProviderInterface` deliberately types its own
`registerAdminPages()` return as a bare `class-string`, not
`class-string<Controller\Admin\AdminSubControllerInterface>`:
`Piwigo\Controller` sits above `Piwigo\PluginConfig` in this project's
layered architecture (`deptrac.yaml`'s `L4Integration`/`L3Presentation`
split), so `PluginConfig` may not reference it -- `AdminDispatcher::
dispatch()`'s own existing `instanceof` check (same layer as
`AdminSubControllerInterface`, already run for every resolved page
regardless of origin) enforces that contract for free instead, with no
new validation needed.

Both new manifest flags get the identical install()/activate()-time
contract-conformance validation (`PluginValidationException`) the
existing `hasSettings`/`hasApiRoutes` flags already have.

Also fixed a real P43-F regression found along the way, unrelated to
this batch's own design: `PluginSettingsPageDispatchTest.php` still read
`ADMIN_CONTENT` back via `Template::getTemplateVars()` after
`PluginSubController::handle()` -- which no longer assigns it directly
since P43-F converted that class to `return AdminPageResult` instead --
undetected until now since this file lives in the Integration suite,
deferred for the rest of that same session.

Verified: PHPStan clean, ECS clean, deptrac 0 violations, full Unit/Arch
(5442 passed), golden-HTML (74 passed), visual-regression (66 passed),
plus the 3 directly-touched Integration test files (24 passed,
end-to-end against a real DB + real filesystem scan + real runtime class
autoloading, matching this project's own established fixture-plugin
testing convention).

**P43-G (landed) — constructor-inject `Template`'s hidden dependencies.**
Found during a deep review of the Template layer, not part of this
phase's original design above. `Template.php` had 6
private/private-static methods reaching `Kernel::container()` directly
instead of taking a constructor collaborator like the class's other 9.
`currentConfig()`/`lang()` stay static for real, unrelated reasons (a
raw PHP `include` needing a PHPStan-visible access path; external
static-only callers with no constructor to inject into). The other 4
(`urlService()`, `pageState()`, `htmlRenderer()`, `imageStdParams()`)
each resolved a real container-wide singleton with no
re-resolve-to-observe-current-state requirement, so all 4 are now real
constructor properties, threaded through all 7 real construction sites
(`RequestBootstrap` x2, `RedirectService` x2, `MailService`,
`NoPhotoYetRenderer`, `InstallWizard`). `imageStdParams()`'s own
container factory (`config/container.php`) needed hardening first — its
`tablesExist()` guard already tolerated a missing table but not an
unavailable connection (a real `InstallWizard::boot()` first-GET
credential-timing quirk); wrapped in the same `try/catch (Exception) {}`
pattern `Template`'s own constructor already used for the identical
failure class.

**P44 — Escaping, Input Validation & Security Hardening Campaign.**
Real, measured corpus (not the ~988 this entry originally guessed at,
pre-P40): 494 `|noescape` occurrences across 95 templates, 333 distinct
expressions. Broadened one verified finding at a time into 7
dimensions beyond output escaping — input validation, file-upload
serving, SSRF, deserialization, rate-limiting, cookie/transport
security — since each complements the others (a value escaped
correctly at output with no input-side character restriction; an
upload sanitizer with no serving-side backstop; an SSRF guard
connecting via a second, independent DNS resolution of what it just
validated). Full investigation, findings, and batch definitions:
`.claude/plans/validated-hopping-hamster.md` (session-local plan file,
not committed).

Landed: **P44-B** (12 confirmed bucket-1 `|noescape` removals —
`MENUBAR`/`TABSHEET`, both `Html`-typed — plus 2 dead-code block
deletions, `EXTRA_BODY_CONTENT`/`about.latte`'s `$about_msgs`,
byte-identical golden-HTML modulo one incidental blank-line removal).
**P44-C/D** (the Critical/High/Medium output-escaping findings: admin
shell theme-switch link, `HtmlService::getCatDisplayName()`'s
category-name XSS reaching front-end search, `page_refresh['U_REFRESH']`'s
two independent producers, gallery thumbnail name, `SearchFilterRenderer`'s
unescaped tag-name `sprintf()`, `MailService`'s unescaped username in
raw-HTML mail bodies). **P44-F** (13 confirmed double-escape sites
across 9 files — delete the redundant PHP-side `htmlspecialchars()`
call, trust Latte's own auto-escape; one flagged, non-mechanical site,
`NotificationByMailSubController`'s `CUSTOMIZE_MAIL_CONTENT`,
investigated and documented rather than fixed — the apparent
plain-text-template double-escape site is unreachable dead code for
the real caller, and the actual gap it hints at (`MailService::mail()`'s
own `strip_tags()`-without-`html_entity_decode()` text/plain
derivation) is broader than one field and out of scope here). **P44-H**
(`Username` VO now rejects `<>&"'` at construction; `CatPermSubmitRequest`
allowlists `status`). **P44-I** (uploaded-SVG/HTML serving: `ActionController`
forces `Content-Disposition: attachment` for `image/svg+xml`/`text/html`
regardless of the `download` param; shared DOCTYPE-stripping regex now
consumes a bracketed internal subset correctly;
`sanitizeSvgIfNeeded()` fails closed instead of storing an unparseable
SVG untouched; strips `javascript:`/`data:`-scheme `href`/`xlink:href`
and SMIL animation elements; same MIME-vs-extension cross-check
extended to `text/html`/`text/plain`). **P44-J** (`HttpClientService`
pins the connection to the exact IP `assertUrlIsSafe()` validated, via
Symfony's own `resolve` request option, closing a DNS-rebinding TOCTOU
gap). **P44-K** (`unserialize($result, ['allowed_classes' => false])`
at both remote-response call sites; deleted the now-fully-dead
`ArrayHelper::safeUnserialize()`). **P44-L** (dual-scope — account and
IP — rate limit on password-reset-code *requests*, not just per-code
guesses; `PasswordResetRequestRepository`, new
`password_reset_requests` table). **P44-M** (session cookie
`secure`/`samesite` forced in `SessionBootstrap`/`InstallWizard`;
`BaselineSecurityHeaders` sends `Strict-Transport-Security`
unconditionally).

**P44-A** (the full reclassification sweep of the remaining
`|noescape` corpus) — **complete**, three rounds. Extracted all 469
remaining `|noescape`-bearing print expressions (323 distinct) across
95 templates via a throwaway AST-ish script and individually traced
every one — non-URL-shaped candidates, then all ~94 distinct
`u*`/`U_*`/`*Url`-suffixed URL-shaped names, then the remaining
miscellaneous array-access stragglers — to a real, project-wide-traced
producer.

Round 1 found and fixed one genuine, previously-unknown **Critical**
finding: `InstallWizard`'s own newsletter-subscribe label assembled a
raw HTML fragment from the just-submitted, entirely unescaped
`admin_mail` install-form field — a reflected-XSS gap reachable on the
very first `install.php` submission, before any authentication exists.
Also fixed a translate()-interpolation gap in `standard_pages/password.latte`
(the default theme's own equivalent site was already correct — a
per-theme inconsistency, not a systemic one) and two minor
bucket-2/plugin-metadata cleanups (`updates_pwg.latte`,
`plugins_installed.latte`/`themes_installed.latte`). Confirmed
already-safe: `permalink['name']`, `cat['DESCRIPTION']`,
`comment['CONTENT']`, `tag_path`, `sheet['caption']`,
`representant['picture']['src']`, `thumbnail['TN_ALT']`,
`selectedCategoryName`/`addToAlbum`, `QUERY_SEARCH`,
`debug['QUERIES_LIST']`.

Round 2 traced all 74 `u*`/`U_*`-prefixed nav-link names plus 20
`*Url`-suffixed names — every one a hardcoded-literal concatenation
onto a trusted root, or a genuine trusted-builder call, no anomalies.
Found and fixed one more **admin-self-XSS** finding:
`NoPhotoYetRenderer`'s admin-configurable `noPhotoYetUrl` echoed with
no escaping in `no_photo_yet.latte` (reachable only if an admin
configures a malicious value into their own `no_photo_yet_url`
setting) — fixed by removing `|noescape` from all 3 URL prints on that
page uniformly. Also found and deleted confirmed-dead code:
`batch_manager_global.latte`'s two `element_set_global_plugins_actions`
blocks (zero real producer anywhere in `src/`, same class as P44-B's
`EXTRA_BODY_CONTENT` finding).

Round 3 closed out every remaining straggler: `U_CATEGORIES`
(`MenubarRenderer`'s `makeIndexUrl()`), `cat_path['name']`
(`getCatDisplayNameCache()`, the same already-fixed Critical-2
builder), `formatsOriginalInfo['u_edit']` (root-URL-prefixed DB image
id), all three `F_ACTION` producers (`PictureCommentRenderer`'s
`$url_self` → `duplicatePictureUrl()`, `PictureRateRenderer`'s
`addUrlParams()`, `FilterPanelPageContext`'s `$this->fAction`), and
`imageUrls[...]['tn']` (`DerivativeImage::url()`) — all confirmed
already-safe, no further fixes needed. Every batch gated by `composer
lint:latte`, PHPStan, deptrac, ECS, and the full Unit/Arch + Integration
suites; escaping batches additionally gated by golden-HTML and VR.

**All 7 P44 dimensions now fully landed. Campaign complete.**

**P45 — Latte lint/format enforcement.** P32 built the tooling and gated
almost none of it: `composer lint:latte`, `composer precompile:templates`
and the `tools/latte-prettier/` formatter are invoked by neither
`.github/workflows/ci.yml` nor `lefthook.yml` — only
`composer analyse:phpstan` runs today, via the CI `phpstan` job and a
`lefthook` pre-push hook. Wire the survivors into CI and pre-commit.

Deliberately last in the refactor track: P43 changes `PiwigoExtension`'s
filter set and `lint:latte` registers that extension, so gating earlier
only churns the config. `lint:vartype` is **never** wired — P40 deletes
it along with the `{varType}` blocks it generates.

**P46 — JS → TS mechanical conversion (landed).** `.js` → `.ts` renames,
minimal types to satisfy the existing strict `tsconfig.json`, real Vite
entries replacing the `noop` placeholder. Same code, same behavior.
Vendored third-party files (`jquery.js`/`.min.js`/`.cookie.js`,
`themes/default/js/ui/**`, `themes/default/js/plugins/**`,
`jquery.geoip.js`) stayed out of scope — already ESLint-ignore-listed,
decided in P49. Depended on P38.

Real count: **79 files converted, not the 68 `vite.config.ts` originally
guessed at** — `mcs.js` (`themes/default/js/`) was missed by the initial
survey entirely and only found by an end-of-campaign directory sweep
after every other file had already converted; every file converted
in place (stayed under its own `themes/*/js/` directory, not relocated
into `build/`). A P46-0 sub-phase (landed first, independently) migrated
20 vendored libraries this campaign's own files depend on
(jQuery/jQuery UI/selectize/jqtree/etc.) off self-hosted copies onto
CDN URLs, deleting the vendored files; 3 libraries with no findable
real source stayed vendored with a source-attribution comment. Also
fixed several genuine pre-existing bugs strict TypeScript/ESLint
surfaced along the way (never caught before since neither was
CI-gated) — a `.contents()`/`.children()` mixup that silently deleted a
reusable DOM template, an inverted string/number comparison that broke
tag multi-select, a missing localized string, a stray `const`
reassignment, and (found only via live-browser verification after the
conversion itself was done) a missing `dependsOn: ['jquery']` on a
CDN-loaded script and a client/server empty-string-vs-null mismatch
that 422'd every plain-text photo search. Full validation green:
`bun run typecheck`/`lint:js`/`format`, `bun knip`, a real `vite build`
with compiled-output inspection, PHPStan, ECS, and the full test
suite (Unit, Arch, Integration, golden-html, Browser, Visual
Regression).

**P47 — `getPageData<T>()` typing + `any` reduction (TS half). Done.**
`pwg_getPageData<T = unknown>(key)` replaced the old untyped
`pwg_getPageData(key): any`; all 168 real call sites across 57 files
(the 46-file `any`-count list and the 36-file `pwg_getPageData`-caller
list overlap in 24, so the real scope is their 57-file union) now
declare their real type, sourced from each key's real PHP
`ExposesPageData::exposedPageData()` writer. First-party ajax callback
params reuse `openapi/client/schema.d.ts`'s existing generated types
(`operations[...]`, `components["schemas"]`) instead of hand-written
interfaces. 6 real npm `@types/*` packages (jqueryui, selectize,
jquery.colorbox, jquery.cookie, chart.js, plupload) replaced
hand-rolled ambient stubs in `build/jquery-plugins.d.ts`; DataTables and
a handful of un-typed vendored plugins (`jquery-confirm`,
`jquery.cluetip`, `jquery.Jcrop`, `jquery.jgrowl`, `jquery.ajaxmanager`,
`jquery.progressbar`, `jquery.sort`, `jquery.autogrow-textarea`) stay
loosely typed — no real type source exists for the vendored/pinned
versions actually in use. `eslint.config.ts`'s P46-era blanket
`themes/**/*.ts` any-tolerance override is gone; it now names only
those 6 files plus `jquery-plugins.d.ts`'s own irreducible vendor
entries, so `no-explicit-any`/`no-unsafe-*` are real enforced gates
across the theme tree going forward. Along the way: several real
pre-P47 bugs surfaced and fixed (a `node.visble` typo breaking
lock-icon inheritance in the album tree, a `.attr("checked", false)`
that never worked, an `open_nodes.includes(node)` object/id comparison
that always failed, a guest-filter reading a nonexistent `.username`
field, a `duplicateTag()` call missing a required field, an unexposed
`plugin_add_tab_in_user_modal()` public plugin API, and
`picture_nav_buttons.ts`, which P46's own "done" list had claimed but
was never actually converted). Full validation green end to end:
`typecheck`/`lint:js`/`format`/`knip`/`vite build`, and the full test
suite (Unit+Arch, Integration, golden-html, Visual Regression, Browser)
— see the table entry above for exact counts.

**P48 — Refactor TS into modules.** Breaks up monolithic per-page scripts
into proper ES modules (shared utils, per-feature entry points), one Vite
entry per real page bundle.

**P49 — Remove jQuery.** An explicit per-surface decision, not a blanket
removal: first-party call sites (native DOM/fetch), the vendored bundle
itself (delete once nothing references it), `themes/default/js/ui/**` and
`themes/default/js/plugins/**` (selectize, jqtree — replace or keep
vendored per widget), `jquery.geoip.js`, and the installer's own separate
`jquery.packed.js` load, which is a third easy-to-miss surface with
thinner coverage (`composer test:install` only). `pngfix.js` is not in
scope — it is an IE shim, not a jQuery plugin, already removed in P35.

Split into two sub-phases per the user's own explicit direction: P49-A
(non-visual removals — plain DOM/ajax/event plumbing with an obvious
native equivalent) first, P49-B (every vendored-widget replacement —
a real design decision, not a mechanical swap) scoped but deferred.
Two scope corrections found by direct investigation, not assumed:
`themes/default/js/ui/**` no longer exists (removed by P46-0's CDN
migration); the installer's own "`jquery.packed.js`" doesn't exist
either — `InstallView.php` loads the same CDN jQuery as every other
page, and its one real distinct surface (`jquery.cluetip`) isn't
installer-exclusive.

An earlier attempt at this phase was dropped wholesale rather than
finished: P49-B was executed batch-by-batch without a plan, and kept
producing regressions found only afterwards — three native widget ports
silently dropped the marker classes their CSS targets (`hasDatepicker`,
`dataTable`, `ui-state-hover`), a `JSON.parse(null)` crash shipped, and
a load-order change broke every batch-manager page. Those 67 commits are
preserved at the `p49-archive-20260826` tag, readable as reference
implementations, and the ES-modules work they carried was redone from a
clean base under P48. When this phase restarts it starts from a real
plan file, with the marker-class/CSS-coupling failure mode stated up
front and verification designed before conversion rather than after.

**P50 — Lit component catalog. Skipped.** Conditional on P49's findings, and
still parity-only. Just for widgets P49 found no reasonable vanilla
replacement for — tag autocomplete and tree picker were the likely
candidates, per this phase's own original wording. Skipped per this phase's
own stated exit condition ("skipped entirely if P49 turns up nothing that
needs it"): P49-B (see above) ported every vendored widget natively to
vanilla TypeScript, including both named candidates —
`themes/default/js/vendor/selectize.ts` (tag autocomplete) and
`themes/default/js/vendor/jqtree.ts` (tree picker) — and no other widget
(jcrop, cluetip, colorbox, dataTable, tooltip, datepicker+timepicker, slider,
sortable, jconfirm, jgrowl, uploadQueue/plupload, the chart.js+moment.js
replacement) was left half-ported, vendored-as-is, or needing a
component-framework wrapper either. No `lit`/`lit-element`/`lit-html`
dependency was ever added to `package.json`.

**P51 — TS modernization.** An idiomatic pass over the now-modular,
jQuery-free, fully-typed codebase from P46–P50. Same behavior.

**P51-A done** — adopted the `14.x` branch's hardened ESLint ruleset for
every `.ts` file (`eqeqeq`, `no-console`, `no-implicit-coercion`,
`no-param-reassign`, `no-deprecated`, `no-unnecessary-condition`,
`restrict-plus-operands`, `restrict-template-expressions`,
`prefer-nullish-coalescing`, the `consistent-type-imports`/`-exports`
family, plus `no-non-null-assertion` tracked as `"warn"` rather than
silenced) and fixed every resulting violation (`bun run lint:js` exits 0
tree-wide; only the tracked `no-non-null-assertion` warnings remain).
Real fixes along the way, not just mechanical suppressions: a genuine
pre-existing bug in `vendor/jqtree.ts`'s `mouseStop()` (it read
`this.hoveredArea` for its `onDragStop`-vs-successful-move branch after
already resetting it to `null` two lines above, so `onDragStop` fired
unconditionally regardless of whether a real move happened — fixed by
capturing the value before the reset); a dead `const in_container = true`
in `user_list.ts`'s `user_container_click()` that made every conditional
depending on it tautological, inlined away; every `Node`/`Element`.`textContent`
`?? ""` fallback removed as genuine dead code (this TS version's DOM lib
types the getter non-nullable); real mixed-type id comparisons
(`tag.id == id` where one side can arrive as a string) given an explicit
`Number()`/`String()` normalization instead of a blind `===` swap.
Real, load-bearing runtime guards the type system can't see (closure
mutation across a `forEach`, `NodeListOf.item()`'s real out-of-range
`null`, `navigator.clipboard`'s real absence off a secure origin) kept,
each with a targeted `eslint-disable-next-line` and a comment.

**P51-A2 done** — a new sub-item folded in mid-phase after a direct
question ("is the tsc config hardened too?") surfaced that `tsconfig.json`
had never been brought to `14.x`'s own `tsconfig.app.json` level, even
though the ESLint ruleset had. Turned on every flag 14.x has that this
repo didn't (`noImplicitOverride`, `noFallthroughCasesInSwitch`,
`forceConsistentCasingInFileNames`, `verbatimModuleSyntax` — all measured
at zero real violations, so free; `noImplicitReturns` 3,
`noUnusedLocals` 4, `exactOptionalPropertyTypes` 17, `noUnusedParameters`
43, `noPropertyAccessFromIndexSignature` 195 — each fixed for real, not
suppressed). `verbatimModuleSyntax` supersedes the older `isolatedModules`
(a strict superset per TS's own 5.0 notes), so that flag was retired in
the same commit. Two real findings along the way: `noUnusedLocals`
doesn't honor this codebase's `^_`-prefix-means-intentionally-unused
convention the way ESLint and `noUnusedParameters` both do (verified
directly), so 3 genuinely-dead `_`-prefixed locals were deleted outright
instead of kept under a naming convention the compiler doesn't
recognize (one of them, `_progress_bar_end()`, had been deliberately
preserved undeleted by an earlier phase for documentation purposes — that
call no longer holds once the compiler itself is what's flagging it with
no escape hatch); and a first attempt at `vendor/ajax.ts`'s
`exactOptionalPropertyTypes` fix (widening `fetch()`'s `body` to
`body ?? null`) was a real behavior change, caught by the existing
`ajax.test.ts` suite expecting `undefined`, not `null`, for a bodyless
GET request — fixed with a conditional-spread omission instead, which is
the reason every sub-phase in this campaign runs the full test suite
before its own commit, not just `typecheck`.

**P51-A3 done** — a further round of "how can we harden further" measured
6 candidate rules beyond 14.x's own ruleset before deciding which were
worth adding, in two batches. First batch: `@typescript-eslint/
no-floating-promises`/`no-misused-promises` measured at **zero** real
violations (every `ajax()` call site already disciplines its promise
with `void`/`return`/`Promise.all()`/`await`) — turned on immediately,
free. Second batch, measured together: `switch-exhaustiveness-check` (8),
`no-shadow` (57 in `themes/`, plus 3 more in `tests/Unit/Vendor/*.test.ts`
the first scratch scan's file-glob missed — corrected before finishing),
`strict-boolean-expressions` (117) — all fixed per-site, 0 blanket
suppressions:
- `switch-exhaustiveness-check` (`considerDefaultExhaustiveForUnions:
  true`): 6 of 8 sites already had a `default` clause that genuinely
  covers the rest of the union; the other 2 (`jcrop.ts`'s
  `oppositeLockCorner()`/`getCorner()`) had a real over-broad parameter
  type neither real call site could ever hit — narrowed to a new
  `Corner` type instead of relying on the option.
- `no-shadow`: overwhelmingly one recurring pattern, an ajax
  success-callback param named `data` shadowing the imported `data()`
  DOM helper — renamed to `response` throughout (~40 sites, 12 files).
  Real per-site judgment elsewhere: `configuration_main.ts`'s own
  "preserved closure bug" comment turned out to describe a bug that was
  never real, once cross-referenced against `configuration_comments.ts`'s
  own already-corrected analysis of the identical pattern — the
  now-pointless IIFE was removed outright, not just its shadowing param
  renamed; `batchManagerUnit.ts` had two functions taking an
  `activePlugins` parameter both real call sites always passed the same
  module-level constant for — the redundant parameter was dropped.
- `strict-boolean-expressions`: each site read against its own real
  declared type (openapi schema field, DOM helper return type, a
  third-party widget's own option type). A `string | null` field became
  an explicit `!== null && !== ""` (preserves the old "empty means
  absent" truthy behavior exactly); an `x || fallback` on a nullable
  string with nullish-only real intent became `x ?? fallback`;
  `Boolean(x)` marked the handful of genuinely `any`/heterogeneous
  values (`mcs.ts`'s own `PS_params`, a few `data()` reads), extracted
  to a named local first where used as a bare `if` condition since
  `Boolean(x)` there collides with `no-extra-boolean-cast`. One real bug
  found this way: `window._pwgRatingAutoQueue.length` is optional (only
  present during the "queue array" phase, gone once the real `{push}`
  handler replaces it) — the old `&&`-chained truthy check masked a real
  `TS18048` that only surfaced once the boolean expression was made
  explicit.

**P51-A4 done** — two more built-in `@typescript-eslint` hardening
rounds, each measured before fixing, per-file commits, then a real
`feat(ts): enable` commit once the whole population was closed --
`no-unsafe-type-assertion` (548 sites/75 files) and `no-use-before-define`
(`{ functions: false }`, 287 real sites/11 files fixed by
reordering/hoisting; separately verified the 578 sites the
`functions: false` option itself exempts are all genuine top-level
function declarations, none block-scoped, so the exemption is real and
not masking an Annex-B hazard).

**P51-A5 done** — an "add all of them" pass over every remaining
unconfigured `@typescript-eslint/*` rule with a real violation count
worth having: 5 zero-violation rules enabled as pure forward-guards
(`no-unused-private-class-members`, `consistent-type-assertions`,
`array-type`, `class-literal-property-style`, `no-restricted-imports`
guarding against jQuery ever coming back post-P49), plus
`consistent-generic-constructors`/`consistent-indexed-object-style` (1
site each), `no-inferrable-types` (10/7 files), `dot-notation` (11/5
files), `promise-function-async` (33/9 files -- found and documented 8
real false positives where a function must keep returning `ajax()`'s own
decorated `AjaxThenable` object by reference, since wrapping it in
`async` re-resolves it through `Promise.resolve()` and strips its
`.done()`/`.fail()`/`.always()` methods that jconfirm.ts's own
`isThenable()` check and 10 real call sites depend on), and
`prefer-destructuring` (64/30 files -- autofixed the bulk, hand-fixed 29
array-index/reassignment sites the autofixer wouldn't touch, and verified
empirically via this project's own happy-dom test runtime, plus
TypeScript's own `DOM.Iterable` lib typings, that `HTMLCollection`
(`.children`, `.tBodies`) is actually destructurable in this project's
real target environment before converting those 3 sites instead of
assuming the classic "HTMLCollection isn't iterable" gotcha still held).

**P51-B done** — landed `build/collectScriptEntries.ts` before any other
sub-phase moves a file: scans `src/**/*.php` for real
`AssetContribution::script()` calls (83 real call sites, not 233/84 as
originally estimated -- those earlier counts didn't exclude 2 real
doc-comment/code-comment mentions of the method name; re-verified 3
independent ways), extracting the 2nd positional literal string
(mirroring the method's own real signature,
`script(string $id, string $path, ...)`) to get **69 unique `.ts`
paths**, plus 2 hardcoded exceptions genuinely unreachable via
`AssetContribution` scanning (`build/vitals.ts`, resolved through a
separate hardcoded `ViteManifest::resolve()` call in
`PageTailRenderer.php`; `build/noop.ts`, a pure `ViteManifestTest.php`
fixture) -- **71 total, an exact match with `knip.json`'s prior
hand-maintained list, zero gaps either direction**. One real correction
along the way: `themes/standard_pages/js/profile.ts`/`standard_pages.ts`
were originally assumed to need hand-listing as non-page-asset
exceptions "since nothing in `src/` registers those" -- false, both
have real `AssetContribution::script()` registrations
(`IdentificationView.php`/`ProfileView.php`/`PasswordView.php`/
`RegisterView.php`) and are correctly scanner-derived; the only 2 real
exceptions are the `build/*` pair above (`openapi/client/index.ts`
stays knip-only, as originally scoped -- it was never a Vite entry).

`vite.config.ts`'s `rollupOptions.input` now reads
`collectScriptEntries().map(r)` -- **array form, not the prior
hand-picked-camelCase-key Record** (`photosAddDirect`/`catModify`/etc.),
since `ViteManifest::resolve()` keys its `dist/.vite/manifest.json`
lookup by real source *path*, confirmed from its own doc comment, never
by whatever alias Rollup used internally for the chunk name -- a
hand-picked key bought nothing functionally. Still routed through the
existing `r()` helper (kept -- still needed for the unrelated
`resolve.alias["tus-js-client"]` entry) to preserve the absolute-path
resolution every entry already relied on, avoiding Rollup's own
cwd-relative-path footgun. Real, live-verified cost of the array form:
the 4 basename collisions among the 69 paths (`rating.ts` admin+default
theme; `notification_by_mail.ts`/`photos_add_direct.ts`/
`picture_modify.ts`, each a bare file + its deliberate `pages/`-nested
per-page-bundle counterpart, P48's own established pattern, not a
smell) get less-descriptive auto-disambiguated chunk names
(content-hash-only, no numeric suffix needed in practice) -- confirmed
via a real `bun run build` that PHP's own resolution is unaffected
either way (`dist/.vite/manifest.json`'s real entry keys matched the
71-path set exactly) and that `vitals`'s own special-cased fixed
filename (`entryFileNames`) still resolves correctly. `rating.ts`'s own
collision will resolve for real once P51-I item 3 lands (rename to
`rating_photo.ts`) -- deliberately left there, not pulled forward into
this build-config-only sub-phase.

`knip.json` converted to `knip.config.ts` (`import type { KnipConfig }
from "knip"`, confirmed as the real public type export from knip's own
installed package), importing `collectScriptEntries()` for its own
`entry` field alongside the still-hand-listed
`openapi/client/index.ts`; `project`/`ignoreDependencies` carried over
unchanged. Needed adding `knip.config.ts` to `tsconfig.json`'s own
`include` array (a real, necessary fix, not scope creep -- every sibling
`*.config.ts` file is listed there too). A real regression net added
alongside the scanner itself: `tests/Unit/Build/collectScriptEntries.test.ts`
asserts *invariants* (non-empty, every entry a real existing `.ts` file,
no duplicates, a handful of known-stable entries present) rather than an
exact snapshot -- an exact-match test would itself have become a second
hand-maintained list needing an update on every real new page,
undermining this sub-phase's own point.

Full validation green: `typecheck`/`lint:js`/`format`/`knip`/`vite build`,
Unit+Arch (5576, 1 unrelated flaky rerun-clean), Integration (2180+),
golden-html (91, all 89 diffs were either the expected chunk-filename
change above or 2 already-existing stale baselines this change
incidentally surfaced -- `popuphelp`'s own leftover `?v17.0.0` query
string from a P49-A fix that landed without a rebaseline, and
`install`'s own leftover jQuery/jquery-cluetip CDN script tags from
cluetip's native port, same cause -- both confirmed pre-existing and
unrelated by reading every one of the 90 diffs' own changed lines, none
of which fell outside those 2 categories or the expected rename).

**P51-C done** — converted `ajax()` call sites from jQuery-shaped
callbacks to async/await. `vendor/ajax.ts` already returned a real
`AjaxThenable` (`await ajax(...)` already worked everywhere); this was
purely converting callers. The plan's own pre-execution count (118 sites)
turned out stale in two independent ways, both caught and corrected
during execution rather than assumed: a first recount (before any file
was touched) found the 118 figure was itself inflated by comment-text
false matches, landing on 101 real sites across the files this plan
already named; a second, much larger correction came only after
converting all of those — a plain `ajax(` grep never matches the generic
call form `ajax<Foo>({...})`, so an entire second wave of real sites in
files the original survey never even listed (`album_selector.ts`,
`batchManagerGlobal.ts`, `comments.ts`, `history.ts`, `intro.ts`,
`rating_user.ts`, `profile.ts`, and 12 more 1-2-site files, `~31` more
real sites in `19` more files) was found only via a corrected,
generic-aware resweep of the whole tree. Every real site converted to
`try { const data = (await ajax(...)) as T; ... } catch (e) { ... }`,
narrowing the caught error to `AjaxError` where the handler reads
`e.responseText`/`e.status`/`e.statusText`/`e.message` (the last for a
site that read the old `error` callback's 3rd `errorThrown` param, which
`AjaxError`'s own `.message` is built from, not `.responseText`). Added
the `json?: unknown` option to `AjaxOptions` (stringifies and sets
`contentType` once inside `ajax()` itself) in the same pass, adopted at
every site that hand-paired `contentType: "application/json"` with
`data: JSON.stringify({...})`. **`AjaxOptions`'s `success`/`error`/
`complete` fields could NOT be dropped** (corrects this plan's own
original text) — 7 real, structural, permanently-uncoverted exceptions
still use them: 5 jconfirm `content: function () { return ajax({...}) }`
callbacks (`cat_modify.ts`'s delete-album confirm, `group_list.ts`'s
single-group-delete confirm, `plugins_installated.ts`'s `deletePlugin`,
`tags.ts`'s `removeTag`/`mergeGroups` confirms) that must keep returning
`ajax()`'s own raw `AjaxThenable` object so jconfirm.ts's own
`isThenable()` check can drive its loading-spinner-then-`setContent()`
UX; `install.ts`'s `runDbCheck()`, which keeps a closure-level
`AjaxThenable` handle specifically to call `.abort()` on it; and
`vendor/ajaxQueue.ts`'s own single internal `ajax()` call, whose
`complete:` callback IS the queue's real concurrency-control mechanism
(dequeuing the next request), not app-level response handling — every
`AjaxQueue.add({success, error})` consumer (`batchManagerGlobal.ts` x3,
`updates_ext.ts` x2, `thumbnails.loader.ts` x1) is a consumer of that
same real callback-based API and stays unconverted for the same reason.
Two real, previously-live bugs found and fixed as a byproduct, not
separately scoped work: `user_list.ts`'s `add_user()` had a `beforeSend`
callback with `return false;` branches meant to cancel the request on
validation failure, but `ajax()`'s own implementation never checked
`beforeSend`'s return value, so invalid submissions posted anyway; and
`album_selector.ts`'s `#prefill_search_subcats()` was already `async`
but never actually `await`ed its own `ajax()` call, so its caller's
`.then()` (removing a loading spinner) ran before the real response ever
arrived, not after.

**P51-D — frontend id-typing boundary** — **Done**, closed with a
narrower final scope than originally estimated, found only by
re-verifying against real source rather than trusting the inherited
~85-site/~9-file count (itself stale: re-grepped to 244 raw sites
across 19 files before any file-specific exclusion). Landed: two new
`vendor/dom.ts` accessors, `dataId(el, key): number` (wraps `data()`'s
already-coercing read; asserts rather than silently minting a fake id
0 for a missing/`"null"`-valued attribute) and `valId(target): number
| null` (wraps `val()`'s genuinely-string DOM read; `null` is the
"nothing selected" signal, never `NaN`/`0`). Converted:
`group_list.ts` (80 sites), `tags.ts` (46, `TagId` alias removed
outright), `batchManagerUnit.ts` (31, picture-id side only),
`albums.ts` (24, DOM-boundary album-id reads only), `history.ts` (9,
closing the `user_id` "-1" sentinel bug found and quick-fixed earlier
the same session), `comments.ts` (5), `rating.ts`/admin (2, aligned to
the same accessor for consistency), `cat_search.ts` (1, its own local
`AlbumTreeNode.id` narrowed to plain `string`, matching what
`albums.ts` really exports), `user_activity.ts` (2) — each its own
commit, each verified via typecheck/lint/format/knip plus that file's
own Browser test (`tags.ts` gained a new mutation-verified merge-flow
test, a real, previously-uncovered gap). Every `"#prefix-" + id`
selector build in a touched file folded onto the existing `escapeId()`
helper for consistency (P49-C).

Two real, previously-silent bugs surfaced by `dataId()`'s own new
assertion and fixed at the root, not worked around: `group_list.ts`'s
`#addGroupForm`/`#group-template` rows shared the `.GroupContainer`
class used to find real group rows, reaching `setupGroupBox()` with a
`data-id` that was never really there; and 2 `albums.ts` call sites
cached a raw attribute string via `setData()`, bypassing `data()`'s
own numeric coercion. A third, unrelated stale comment (claiming a
real, reachable function was "dead code, zero callers") was corrected
in `batchManagerUnit.ts` along the way.

**Narrower than planned, verified rather than assumed**: `jqtree.ts`'s
own id generic stays `string | number` — `albums.ts`'s own
`AlbumTreeNode.id` is genuinely `string` (the raw JSON tree's real
ids), used and compared as a string throughout `getId()`/`getRank()`/
`applyMove()`/`moveNode()`/`changeParent()`/`changeRank()` — not type
debt. `user_list.ts`, `addAlbum.ts`, `rating_user.ts`,
`default/js/rating.ts`, `batch_manager_global.ts`/`batchManagerGlobal.ts`,
and `standard_pages/js/profile.ts` needed no changes: their apparent
`string | number` sites are either already plain `number`, a
non-`multi` selectize instance (whose own `getValue()` can return the
literal string `""` regardless of its declared `T` — confirmed via
`vendor/selectize.ts`'s own source), a cross-file PHP-string page-data
contract this JS-only phase doesn't touch, or (profile.ts's `pkid`) a
genuine API-key token string mistaken for a numeric id by the
original grep. **`album_selector.ts` and its whole dependent cluster
are excluded outright, not deferred** — `cat_modify.ts`'s
`parent_album`, the rest of `batchManagerUnit.ts`'s category/tag side,
`picture_modify.ts`, `photos_add_direct.ts`'s `uploadCategory`/`add_cat`,
`batch_manager_global.ts`'s categories selectize, `batchManagerFilter.ts`,
`mcs.ts` all feed the same shared `AlbumSelector` class, and its own
category-id storage is confirmed genuinely polymorphic: `cat_modify.ts`,
`photos_add_direct.ts`, and `picture_modify.ts` pass 3 different real
representations (an explicit `String()`-mapped array, a real
`pwg_getPageData<number[]>`, and a real `pwg_getPageData<string[]>`)
into the identical constructor parameter of the identical shared
class. Unifying that is a separate, larger PHP+JS cross-cutting effort
(normalizing several controllers' own page-data contracts first), not
a JS-only typing-boundary fix. Full JS gate green (typecheck/lint:js/
format/knip/vitest, 236 tests); golden-html (91) and visual-regression
(82) both zero-diff, as expected for a phase that changes no rendered
HTML; full Browser suite run as the closing regression net.

**P51-E (Done)** — real hard-private class fields. Recounted scope
confirmed exact on re-grep: **97 TS-keyword `private`/`protected`
member declarations across 6 files** -- `vendor/ajax.ts` (1),
`vendor/dom.ts` (9), `vendor/uploadQueue.ts` (17), `vendor/ajaxQueue.ts`
(4), `vendor/lineChart.ts` (29), `vendor/jqtree.ts` (37) -- all
`private`, zero `protected`; zero real subclasses exist for any of the
7 classes involved (`AjaxError`, `Tween`, `UploadQueue`, `AjaxQueue`,
`LineChart`, `TreeNode`, `JqTreeController`), so every site converted
to real `#field`/`#method` syntax with no exceptions.

Three distinct conversion shapes, not one uniform rename, found by
reading every site's real declaration before converting:
- **Ordinary fields/methods** (~84 sites): `this.foo` → `this.#foo`.
- **`private static` methods** (6: `jqtree.ts` x4, `uploadQueue.ts`
  x2): `static #method`, with internal `ClassName.method()` call
  sites rewritten to `ClassName.#method()`.
- **Constructor-parameter-property shorthand** (7: `dom.ts`'s `Tween`
  x5, `ajax.ts`'s `AjaxError` x1, `ajaxQueue.ts`'s `AjaxQueue` x1) --
  `#field` has no parameter-property shorthand, so each needed real
  restructuring: drop the modifier from the parameter, add an
  explicit `#field: T;` declaration, add an explicit
  `this.#field = field;` assignment in the constructor body (after
  `super(...)` for `AjaxError`). `album_selector.ts`'s own
  `AlbumSelector` class (P46-C) was live, already-shipped precedent
  for this exact restructured shape.

**Execution-time finding, not caught by planning**: 14 sites (10 in
`lineChart.ts`, 4 in `jqtree.ts`) destructured fields directly off
`this` (`const { ctx, canvas } = this;`) -- a pattern that silently
stops working once the destructured names become real private fields
(TS catches it as a compile error, not a silent bug, but it required
rewriting to explicit `const ctx = this.#ctx;` reads before the bulk
rename). `jqtree.ts`'s own 4 sites turned out to destructure fields
that were never private in the first place (`currentItem`/
`scrollParent`/`positionInfo` are public -- all 37 of `jqtree.ts`'s
private sites are methods, no private data fields at all), so those 4
were reverted rather than changed.

Build tooling was pre-verified safe, not assumed: `vite.config.ts`'s
own `build.target: "es2022"` exists precisely because an earlier
phase (P46-C) hit a real dead-on-arrival `ReferenceError` from esbuild
downleveling `#field` syntax for this project's actual browser floor,
fixed by pinning that target explicitly -- confirmed still live before
converting anything here, and confirmed again via a real `bun run
build` after each file.

`lineChart.ts`'s hover/tooltip path and `jqtree.ts`'s touch-event/
scroll-during-drag path have no automated test coverage (checked
directly against each file's real Browser tests, not assumed) --
manually smoke-tested each via Playwright after conversion:
hovering the real stats chart draws/clears a tooltip correctly and
different hover positions produce different tooltip content;
dispatching real `TouchEvent`s on the real album tree produces the
same drag-element behavior as the existing mouse-based drag tests.

Verification per file: `bun run typecheck`/`lint:js`/`format`/`knip`
clean, `bun run build` (confirms real `#field` output, not
downleveled), plus that file's own real test(s) -- `ajax.test.ts`,
the full `dom-*.test.ts` set, `PhotosAddDirectInteractionTest`,
`ThumbnailsLoaderTest`/`BatchManagerGlobalInteractionTest`/
`UpdatesExtInteractionTest`, `StatsPageRendererTest`/
`AdminStatsChartTest`, `dom-events.test.ts`/`AlbumTreeTest` -- every
one passed. Full JS gate green afterward (236 unit tests); golden-html
(91) and visual-regression (82) both zero-diff, as expected for a
phase that changes no rendered HTML or runtime behavior. No full
`test:browser` closing sweep run -- the per-file scoped Browser tests
above, run during each file's own commit, are the real regression net
for this kind of change.

**P51-F (scoped, not started)** — convert `LocalStorageCache.ts` to real
ES6 classes. The one file in the entire `.ts` tree still using pre-ES6
prototype-based "class" emulation (`const Foo = function (this:
FooInstance, ...) {...} as unknown as FooCtor`, `Foo.prototype.method =
function (...) {...}`, `Foo.prototype = new Bar()` for inheritance) --
`LocalStorageCache` → `AbstractSelectizer` → `CategoriesCache`/
`TagsCache`/`GroupsCache`/`UsersCache`. Convert to a real `class ...
extends ...` hierarchy, replacing every prototype method assignment with
a real method body and every manual `_init()` call with a real
`constructor()`/`super()` chain. The existing
`LocalStorageCacheInstance`/`AbstractSelectizerInstance`/
`EntityCacheInstance<T>` interfaces describe the real public shape each
class needs to keep, not scaffolding to delete.
`AbstractSelectizer.getRender()` being static already maps directly to a
real `static` method. Drive at least one of each of the 4 real
Selectize-driven caches through the browser once, not just the test
suite, since a subtly wrong `this`-binding could break invisibly to a
golden-html/VR diff.

**P51-G (scoped, not started)** — rename snake_case identifiers to
camelCase. The largest single sub-phase by site count: **628 `const`/
`let` declarations across 41 files** (a fresh rough recount today found
603 via a simple single-line regex, consistent with the original,
more careful count once multi-line declarations are included). Heavily
concentrated: `user_list.ts` 112, `mcs.ts` 51, `albums.ts` 43, `tags.ts`
42, `history.ts`/`group_list.ts`/`album_selector.ts` ~30 each,
`profile.ts` 29, `search_filters.ts` 27, 29 more files smaller. Batch by
file, largest first. Where a variable's value is later sent as a
request-body/query field whose real name is still snake_case in the
OpenAPI contract (`image_id`, `category_id`, `author_id`, `download_url`,
`element_url`, `page_url`), keep that specific field key as-is at the
point it's built into the request object (`{ image_id: imageId }`) while
the local binding itself goes camelCase -- watch for read sites in other
files that already destructure one of these variables' old snake_case
name from a shared object shape, and rename the binding there too, not
the source key. While in `check_integrity.ts`: also rename its top-level
`function DeselectAll(...)` to `deselectAll` -- the one PascalCase plain
function in the codebase, which otherwise reserves that casing for
classes/constructors.

**P51-H (scoped, not started)** — dead code, unsafe casts, animation
engine, and global coordination, bundled into one sub-phase (independent,
low-risk items):
- Delete 30 lines of dead, commented-out jQuery-era code across 8 files
  (`profile.ts`, `photos_add_direct.ts`, `cat_search.ts`, `dom.ts`,
  `mcs.ts`, `cat_list.ts`, `user_list.ts`, and `batchManagerUnit.ts`'s
  own 17-line `updateTags()` sketch under a "yet to be implemented"
  comment).
- Convert the 2 remaining DOM0-style `document.onkeydown = function
  (e) {...}` handler assignments (`picture_nav_buttons.ts`,
  `plugins_installated.ts`) to this codebase's dominant
  `on()`/`addEventListener` convention.
- Replace the manual "accumulate a string with a trailing separator, then
  `.slice(0, -N)` it off" pattern with `Array.prototype.join()` at 10
  real sites (`mcs.ts`, `cat_search.ts`, `history.ts`).
- Delete the dead `Array.prototype.indexOf` polyfill in `common.ts` (its
  own guard comment already confirms it's unreachable under this
  project's browserslist floor).
- Read each of the 53 real `as unknown as` double-casts; fix the ones
  masking a real, fixable type mismatch, narrow any genuinely
  irreducible boundary cast to a single documented `as` if the
  intermediate `unknown` step isn't load-bearing, and leave a real,
  permanent boundary cast alone with a comment explaining why.
- Convert `vendor/dom.ts`'s `Tween` class from its `setInterval(tick,
  FX_INTERVAL)` loop to `requestAnimationFrame`, computing elapsed time
  from the RAF callback's own timestamp (same duration, same `swing()`
  easing curve). Once landed, check whether `vendor/colorbox.ts`'s own
  separate hand-rolled RAF tween (`animateBox()`) can retire in favor of
  the now-modern shared `animate()` -- it may need a continuous callback
  shape `animate()` still doesn't expose, in which case document why it
  stays separate. Convert `vendor/jcrop.ts`'s own `animateTo()` the same
  way, from its recursive `window.setTimeout(step, ANIMATION_DELAY)` to
  RAF. Needs the Visual Regression suite re-run watching any
  animated-transition baseline -- same duration and easing should mean
  zero pixel diff.
- Delete the 4 independent local `setHtml`/`setHtmlAll` reimplementations
  of `dom.ts`'s own `html()` helper (`standard_pages.ts`, `cat_search.ts`,
  `intro_tooltips.ts`, `intro.ts`) and import `html`/`htmlOf` from
  `dom.ts` instead.
- Extract `themes_installed.ts`'s and `themes_new.ts`'s near-identical
  ~25-line `window.addEventListener("load", ...)` screenshot-scaling
  block (down to sharing the same preserved `"heigth"` (sic) typo) into
  one shared helper.
- Extend `vendor/cookie.ts`'s `setCookie()` with a real optional `days?:
  number` parameter, then delete `standard_pages.ts`'s own local
  cookie-get/set reimplementation in favor of the shared one -- needs a
  live check that this file's real cookie values (`mode`/`lang`)
  round-trip identically through the shared `cookie()`/`setCookie()`.
- Delete `build/ambient-globals.d.ts`'s confirmed-dead
  `pwg_getPageData`/`pwg_getPageString` (verified `picture.ts` imports
  both directly from `page-data.ts` now, not via the ambient global) and
  the ~23-member `search_filters.ts`-derived block (P48 landed, that
  file is a real typed module now); audit the remaining ~10 members the
  same way (a real `window.X` read exists) before deleting anything else
  -- several (`selectGenerateDerivAll`/`hide_user_whats_new`-family/
  `ignoreAll`-family/`plugin_add_tab_in_user_modal`) are confirmed real,
  reached only via `onclick=`/`javascript:` HTML attributes, not
  import-reachable.
- Convert `window.SwitchBox`'s entire queue mechanism to a plain real
  export (`export function registerSwitchBox(link: string, box: string):
  void` from `switchbox.ts`), called directly via `import` from both real
  call sites (`index.ts`, `picture.ts`) -- provably dead as a queue since
  both pushers already `import "./switchbox"` before their own first
  `push()` call, so real ES module evaluation order means the queue-drain
  branch can never see anything queued (this was originally waved
  through as "already-documented P48 exception", true of why it was
  built but not re-checked against P48 having since removed the
  classic-script IIFE wrapper it was built to survive -- real module
  scope now provides the same isolation natively).
- Move `window._pwgRatingAutoQueue` off the ambient global into a small
  new shared module (e.g. `ratingAutoQueue.ts`) that `picture.ts` and
  `rating.ts` both `import` directly -- a genuinely different case from
  `SwitchBox`: these two files share no `import` edge at all, so their
  relative execution order isn't guaranteed by the module graph, meaning
  the queue itself is real, necessary behavior, not dead code; only
  *where* it lives changes.
- Rename `check_integrity.ts`'s top-level `function DeselectAll(...)` --
  folded into P51-G above since it's the same camelCase-rename mechanic,
  called out here too since it was found during the dead-code read.

**P51-I (scoped, not started)** — file-level reorganization: split,
merge, rename, move. No constraint to preserve the current file layout.
Requires P51-B (the scanner) landed first; several items also require
P51-F and P51-H landed first, noted inline. Each item its own commit:
1. Split `common.ts`'s grab-bag into concern-named modules: `sprintf.ts`
   for `sprintf()`/`str_repeat()`/`getRandomInt()`; `TemporaryState.ts`
   for that class; a module alongside `vendor/jconfirm.ts` (or a new
   `jconfirmPresets.ts`) for the `jConfirm_*_options` presets and
   `pwg_jconfirm_follow_href()`. Leave `common.ts` itself holding only
   `fontCheckbox()` and the search-cancel wiring. Requires P51-H's
   indexOf-polyfill deletion landed first -- once that and `sprintf`
   (with its one real `a: any`) are gone, `common.ts` has zero remaining
   `any`; remove its entry from `eslint.config.ts`'s any-relaxation list
   in this same commit, adding `sprintf.ts`'s own path in its place.
2. Merge `batchManagerGlobal.ts` and `batch_manager_global.ts` into one
   real file for `batch_manager_global.php`, removing the documented
   import-order hazard (`batchManagerGlobal.ts`'s own top-level
   `lang.Cancel` read needing `lang` already set) entirely.
3. Rename `rating.ts` to `rating_photo.ts` (or similarly explicit),
   disambiguating it from `rating_user.ts` -- both do near-mirror-image
   operations (delete ratings of one photo vs. by one user) but only one
   name says which axis it operates on.
4. Move `album_selector.ts`, `doubleSlider.ts`, and the new `sprintf.ts`
   (from step 1) from `themes/admin/default/js/` to `themes/default/js/`
   (or its `vendor/`) -- all three are real cross-theme dependencies
   reached from `mcs.ts`/`search_filters.ts`/`profile.ts` despite living
   under the admin theme, a real layering violation found by checking
   import direction.
5. Investigate the 9 mega-files (`user_list.ts` 4254 lines, `mcs.ts`
   2886, `group_list.ts` 1672, `tags.ts` 1605, `albums.ts` 1413,
   `user_activity.ts` 1343, `history.ts` 1125, `comments.ts` 864, and the
   merged `batchManagerGlobal.ts` from step 2, ~1367 lines once merged)
   for real internal seams worth splitting -- map each file's real
   internal structure first, split only where a genuine cohesive
   sub-concern exists. Best done after P51-G/P51-H have already shrunk
   and clarified these files. A file that turns out to be one cohesive
   flow top to bottom stays one file, a legitimate outcome.
6. Evaluate splitting `LocalStorageCache.ts`'s generic base classes from
   its 4 thin entity subclasses -- requires P51-F landed first (the real
   ES6 classes need to exist to judge the split against). Not a mandate.
7. Reorganize `themes/admin/default/js/`'s ~70 flat files into feature
   subdirectories: `configuration/` (5), `batch_manager/` (3, post-merge),
   `categories/` (4), `plugins/` (3), `updates/` (2), `languages/` (2),
   `site/` (2), `maintenance/` (2), `users/` (`user_list.ts`/
   `user_activity.ts`/`group_list.ts`), `ratings/` (the renamed pair).
   Split `vendor/`'s 24 flat files into `vendor/widgets/` and
   `vendor/utils/` the same way. Do this after steps 1-6 land.
8. Normalize file-name casing to camelCase for every non-`pages/` file in
   `themes/admin/default/js/` and `themes/default/js/` (PascalCase only
   for a class-primary-export file), leaving every real `pages/*.ts`
   bundle-entry file's name exactly as-is -- it deliberately mirrors the
   real PHP page filename it registers for. Do this last.
   For every step: update every real import site touched, and grep
   `src/` for the exact literal old path -- update every real
   `AssetContribution::script()`/`::css()` call and any doc-comment
   naming it (`AlbumsPageRenderer.php`/`SqlDialect.php`/`TagRow.php`/
   `UserListView.php`/`vite.config.ts`'s own prose), all in the same
   commit. This is what actually catches a stale registration -- invisible
   to `tsc`/knip/the Vite build, only a live page load or the grep would
   surface it.

**P51-J (scoped, not started)** — backend: close the real `Comment`
id-typing gaps. Independent of every frontend sub-phase, can run
anytime. Convert 4 real targets: `Projection\Comment::$authorId` (`?int`
→ `?UserId`), `CommentInsertData::$authorId` (`?int` → `?UserId`,
updating `CommentService.php`'s own 2 real assignment sites -- `$comm->
authorId = $guestId` and `$comm->authorId = $user->id->value` → drop the
`->value` unwrap and assign `$user->id` directly), and
`CommentRepository`'s 3 real method parameters still raw (`delete(array
$ids, ?int $authorId)`, `update(CommentId $id, CommentUpdateData $data,
?int $authorId)`, `countRecentComments(int $authorId, ...)`), using the
same `tryFrom()`-at-the-boundary pattern this file already uses at its
own `hydrate()` method. Leave `CommentApiListRow::$authorId` and
`CommentListRow::$authorId` exactly as they are -- deliberately scalar,
each file's own docblock already explaining why (DQL's `IDENTITY()`
extraction never hydrates a VO). Update `CommentApiCriteria.php`'s own
stale docblock cross-reference (names a `CommentEntity::$authorId` that
doesn't exist; means `Projection\Comment::$authorId`) in the same commit.

**P51-K (scoped, not started)** — backend: close `CategoryRepository`'s
remaining id params. Independent of every frontend sub-phase and of
P51-J, any order. Convert 13 methods (`findById`,
`findIdNamePermalinkById`, `findRandomImageId`,
`findRandomImageIdInCategory`, `setRepresentativeImage`,
`findCategoryUppercatsById`, `findCategoryStatus`,
`updateCategoryAfterInsert`, `hasImages`, `findPhotoCountAndDateRange`,
`existsAndNotForbidden`, `existsById`, `findSyncCandidatesForSite`) from
raw `int`/`int|string` to `CategoryId`, following the exact
`tryFrom()`-at-the-boundary pattern already proven in this same file's 7
already-converted methods (`find`, `updateImageOrder`,
`findAccessUserIds`, `findAccessGroupIds`, `updateImagePathsForCategory`,
`updateFields`, `categoryScopeConditionDql`) -- never `from()` at a
caller-supplied boundary, it throws on non-positive. Update every real
caller. Then, as a real investigation rather than an enumerated guess:
check whether `CategoryRepository`'s ~40 remaining bulk `array
$ids`-taking methods are worth narrowing to `array<CategoryId>`, and
whether the 41 `array<string, mixed>`-shaped `$category` docblocks across
11 files (`CategoryService.php`, `CategoryRepository.php`,
`Projection\Category.php`, `Projection\CategoryAvailableListRow.php`,
`Projection\CategoryRelatedMenuRow.php`,
`Projection\CategoryAdminListRow.php`, `Projection\ComputedCategoryRow.php`,
`Projection\CategoryCatsNavbarPageContext.php`,
`Controller\Projection\CategoryCatsHtmlPageContext.php`,
`Event\RenderCategoryName.php`, `Search\Projection\CategoryRule.php`) can
narrow now that `CategoryEntity::$id` is already VO-backed -- scope by
what's actually found reading those 11 files, not a number guessed now.

**P51-L (scoped, not started)** — closing re-read and full-suite gate. A
re-read of every file actually touched by P51-A through P51-K, done after
they all land, against the final state -- specifically to catch anything
a sub-phase's own mechanical conversion introduced (a rename that missed
a reference, an async conversion that changed a real error path, a
`#field` conversion that broke a real subclass, an import path a P51-I
move left stale), not to hunt for new pre-existing categories (that hunt
is already done, see P51-A's own leading description). Track files
re-read vs. remaining explicitly (a simple checklist) so "fully
re-verified" is a checkable claim. Where a re-read surfaces a genuinely
new category the original close read missed, fix it and fold it into
whichever existing sub-phase it belongs to, or add a new one if it's
large enough to warrant its own scope. Ends with one final full-suite run
(`typecheck`/`lint:js`/`format`/`knip`, PHPStan, ECS,
Unit+Arch+Integration+golden-html+VR+Browser) as the phase's own closing
gate.

**P51-M — third-party ESLint plugin exploration (`eslint-plugin-unicorn`,
`eslint-plugin-sonarjs`), deferred until P51-A–L land.** Both installed
temporarily and measured against the real tree (ESLint 10/TS 6.0.3
compatible, confirmed via `npm view ... peerDependencies` before
installing), then uninstalled again once measured -- no trace left in
`package.json`/`bun.lock`.

`eslint-plugin-sonarjs@4.2.0`'s `recommended` config: **119 real sites
across 29 rules**, small and genuinely bug/security-focused
(`super-linear-regex` for ReDoS, `no-hardcoded-passwords`,
`different-types-comparison`, `cognitive-complexity`, `no-nested-conditional`,
`no-identical-functions`, `no-ignored-return`/`no-ignored-exceptions`).
Good candidate for a full campaign matching this session's own
measure-fix-verify-commit discipline, same size class as the smaller
`@typescript-eslint` rounds above.

`eslint-plugin-unicorn@74.0.0`'s `recommended` config: **4,314 real sites
across 122 rules** -- an order of magnitude bigger than anything in this
phase, and its top offenders actively fight conventions this codebase
already deliberately chose, confirmed by reading real sample sites, not
assumed from the rule names:
- `no-incorrect-query-selector` + `prefer-query-selector` (554 + 168 =
  722 sites) both want `.querySelector()` over `.querySelectorAll()` for
  a simple `#id` selector -- but `vendor/dom.ts`'s entire helper API
  (`css`/`find`/`attr`/...) is deliberately built to always take
  `Element | ArrayLike<Element>` and this codebase always calls
  `querySelectorAll()` uniformly for exactly that reason (P49's own
  "genuine collection-batching capability" design, not an oversight).
  False positives against this project's own architecture, not real
  findings.
- `no-top-level-assignment-in-function` (199) flags this codebase's
  dominant page-script pattern (module-level mutable state updated by
  handlers), not a bug.
- `no-null` (172) bans `null` for `undefined`, fighting the native DOM
  APIs (`querySelector` returns `null`) this codebase mirrors everywhere.
- `filename-case` (76) directly conflicts with P51-I's own planned
  filename-casing pass and its deliberate `pages/*.ts` carve-out above.
- `name-replacements` (1,324, the single biggest bucket),
  `consistent-boolean-name` (140), `single-line-block-comment-style`
  (131) are pure renaming/formatting bikeshed, no bug-catching value.
- `no-this-assignment` (7) duplicates the already-enabled
  `@typescript-eslint/no-this-alias`, including re-flagging
  `cat_modify.ts`'s own already-justified disable-comment site.

A real, smaller subset is worth having (`no-unsafe-string-replacement`
156, `prefer-dom-node-append` 83, `explicit-length-check` 33,
`no-global-object-property-assignment` 21, `dom-node-dataset` 19,
`no-break-in-nested-loop` 11, and more in the long tail) but that is a
curated pick from 122 rules, not a preset to flip on. **When retrying**:
adopt sonarjs's `recommended` in full (same discipline as the
`@typescript-eslint` rounds above); for unicorn, hand-pick the real
bug-catchers/safe-modernizations rule by rule rather than the
`recommended` preset, re-measuring counts fresh against whatever P51-A–L
has changed by then (particularly P51-G's snake_case rename and P51-I's
filename-casing pass, both of which will shift or resolve some of the
above numbers).

**P52 — CSS architecture modernization.** `@container` queries, `@layer`
cascade. Same visual output, proven via VR baselines. Depends on P39,
not on the JS track, so parallelizable with all of P46–P51. Includes
confirming that nothing in the vendored plugin RTL rules
(`selectize.dark.css`, `jqtree.css` — the only RTL handling anywhere in
this repo) regresses if P49 touched those files.

**The Tailwind decision, pulled forward and resolved: not adopted.**
Decided before P40 started, per this section's own reasoning (adopting
late would mean rewriting `class=` across all 135 templates a third
time, on top of P40/P41's own restructuring). P39 (Inline CSS
extraction) already built an extensive vanilla per-theme utility-CSS
architecture — `themes/{admin/default,default,standard_pages}/css/
utilities.css`, `css/pages/*.css`, `css/components/*.css` — kept
as-is rather than partially replaced. P52's own scope here is
therefore `@container`/`@layer` modernization of that existing
architecture, not a utility-framework migration.

#### New-feature track — lands last

**P53 — Picture pipeline.** `<picture>` AVIF/WebP variants plus ThumbHash
blur-up placeholders: new image formats and a new loading-placeholder UX.
Independent of the refactor track; kept last per the modernize-first
ordering rather than for a technical dependency. Soft-depends on P36 if
generated variants should be served through the Vite manifest.

**P54 — Dark mode.** A new user-facing capability (theme toggle,
`prefers-color-scheme`). Depends on P52 — it needs the modernized cascade
layers and custom properties to add a theme dimension onto cleanly.

#### Closing gate

**P55 — Real quality gates.** `lighthouserc.json` has no `assert` block
today and is collect-only; `.size-limit.json` has one 1 KB placeholder
budget, whose own name still cites a pre-renumbering phase. Wires real
Lighthouse perf, a11y and best-practices thresholds and real per-entry
`size-limit` budgets, and decides whether the risk register's claimed
"a11y gate" becomes a real automated check. Needs P35–P54's real bundles,
templates and features to measure against.

**P56 — Codebase-wide non-DI audit.** Found while reviewing `Template`
during P43-G, then extended codebase-wide: a full sweep of every
`Kernel::container()` call site outside `config/container.php` (225
across 38 files). Most are already-correct, deliberate design (the
`Bootstrap` service-locator/orchestration layer, `RedirectService`'s
established static-resolver pattern) — real scope is two groups. P56-A:
12 domain-service classes with a lazy container-resolver method
alongside a real constructor; 11 confirmed correctly static (many real
manual construction sites, a genuine DI-container cycle on
`HtmlService`, or serving a sibling static method), 1 real conversion
(`MailService`'s `processCache()`/`currentConfigService()`, undocumented
and — checked directly — carrying no construction-time cost). P56-B: 8
fully static-only utility classes plus 2 reclassified from P56-A; 7
confirmed genuinely convertible once real callers are checked (most
already have a real constructor of their own — `DateHelper`,
`FilesystemHelper`, `PermissionCacheInvalidator`, `ImageBackend`,
`PageHeaderRenderer`, `MenubarRenderer`, `UniqueExecLock`,
`CoverageCollector`), only `DbConnection` stays static for a real
structural reason (it builds the connection the container's own
dependency graph itself depends on) — `ErrorCollector::currentConfig()`
is already optimally scoped, no change. Independent of every other
phase — no ordering constraint, land whenever convenient. Re-verify
every call-site count above at execution time rather than trusting this
snapshot to stay accurate.

**P57 — `default`/`standard_pages` theme-duplication investigation.**
Found while adding live client-side validation to
`install`/`register`/`profile`/`password` (a duplicate `id="login"` on
`standard_pages/register.latte`'s own email field, a bug this exact
duplication let go unnoticed). Scope, investigate-not-prescribe: does the
`default`/`standard_pages` split across these 4 page families still earn
its keep, or is it worth merging away. A documentation-only phase — no
code deleted or merged here.

*Full candidate list.* 4 real per-theme splits confirmed:
`identification.latte`, `register.latte`, `password.latte`,
`profile.latte`/`profile_content.latte`. `toaster.latte` is NOT a 5th —
`standard_pages`-exclusive, no `default`-theme counterpart at all
(confirmed via `ProfileView`'s own `ToasterView` merge-in, gated
entirely on `isStandardPagesTheme`). No other `themes/standard_pages/
template/*.latte` file was checked against a `default`-theme counterpart
beyond these 5 candidates — a future revisit of this phase should
re-confirm the list is still exhaustive rather than trusting this one.

*Real behavior diff, not just markup.* `standard_pages` is a genuine
superset/modernization across all 4 pages, not a lateral reskin:
light/dark mode toggle (`toggle_mode()`, a real per-page cookie-backed
preference), a language switcher menu, a `helpLink`, icon-led inputs,
and a real per-field `.error-message` UI convention (already wired to
show/hide on blur/input by `standard_pages.js`'s own required-field
check, which this session's own live-validation checks intentionally
reused rather than duplicating). `default`'s own templates carry none of
that — plain `<ul><li>` field lists, no live UI feedback beyond what
this session just added. Field ids mostly line up 1:1 across the two
implementations for the same semantic field (`username`/`password` on
identification; `password`/`password_conf`/`mail_address` on register
after this session's own id fix; `use_new_pwd`/`passwordConf` on
password) — `profile` is the one real exception: the `default` theme
embeds `profile_content.latte` (`password`/`use_new_pwd`/`passwordConf`)
while `standard_pages/profile.latte` hand-writes its own inline form
with different ids (`password`/`password_new`/`password_conf`) instead
of embedding it — already flagged once before, in P43-A part 3
(`ProfileView` "renders its own form inline rather than embedding
`profile_content.latte`") and worked around there (two independent
field-contribution collections) rather than resolved. JS-asset wiring is
a real `if ($this->isStandardPagesTheme) { ... } return [...]` branch on
every one of these 4 views' own `pageAssets()` — never a shared file —
so the two implementations' client-side behavior can (and, per the
`profile` case above, already does) drift independently of each other,
not just their markup.

*Usage signal: none exists.* `Piwigo\Telemetry\TelemetryPayload`
(`EnvironmentInfo`/`DatabaseInfo`/`GalleryStats`/`ExtensionStats`) has no
`use_standard_pages` field, and — more fundamentally — that whole module
is still assembly-only; its own docblock says sending the payload
anywhere "is out of scope here". There is no live signal, anonymous or
otherwise, for how many real deployments actually run with
`use_standard_pages` off. `InstallDefaultConfig::rows()` sets it `true`
for every fresh install (Part 5, this session), so `default`'s own
4 templates are being kept alive today purely for an unmeasured
opt-out path, not a known-significant one.

*Test coverage gap, partially closed since it was first documented.*
`docs/PLAN.md`'s own P38-era note recorded that `test:golden-html`'s
`golden_html_test` fixture theme never triggered the
`use_standard_pages` swap at all. That's no longer fully true:
`GoldenHtmlSnapshotTest.php`'s `goldenHtmlCapturesStandardPages()` now
captures real `standard-pages-identification`/`-register`/`-password`
output (confirmed live this session, reviewing the real diffs Part 6's
own id fix produced against these 3 snapshots). `profile` is the one
still-open exception: `Template::setTheme()`'s own `standard_pages`
fallback only fires for `identification`/`register`/`password`, never
`profile` — confirmed live this session (the "`standard-pages-profile`"
snapshot's own diff, from this session's `ProfileView`/
`profile_content.latte` changes, showed `default`-theme markers
throughout — `<script src="themes/default/js/scripts.js">`, no
`gallery-icon-*` classes — despite its name). `standard_pages/
profile.latte`'s own real rendered output, including its own
`password_new`/`password_conf` fields, has still never been captured by
`test:golden-html` at all.

*Conclusion.* Not a drive-by merge candidate — `profile`'s already-live
field-shape divergence (`password_new` vs. `use_new_pwd`) means "delete
`default`'s copies, keep `standard_pages`'s" isn't a no-op rename, and
`standard_pages/profile.latte`'s own real output has zero golden-html
coverage to catch a mistake made while doing it. Recommended direction,
not decided here: `standard_pages` is the one worth keeping long-term
(real UX superset, defaults on for every fresh install) — but a real
merge should wait on two prerequisites this phase surfaced rather than
resolved: (1) closing the `profile` golden-html gap above, so a removal
step has real regression coverage; (2) some real signal on how many live
deployments actually run `use_standard_pages` off, which today would
mean building out `TelemetryService`'s own unimplemented transmission
half first, not just adding one more field to the payload it already
assembles. Until both land, keep both trees and keep them from drifting
further apart (the `profile` id mismatch above is the cautionary
example) rather than committing to either merge or permanent
duplication.

**P58 — phpstan-latte CAMPAIGN-PENDING.** `phpstan.neon`'s
CAMPAIGN-PENDING block holds 26 identifier-wide `ignoreErrors` entries
scoped to `_analysis/phpstan-latte/*` — P32's two named follow-up
campaigns, described there and never scheduled. No phase owned them and
no status field tracked them until this one.

*Why it stopped being housekeeping.* `foreach.valueOverwrite` sat inside
Campaign B's blanket ignore, filed as Smarty-conversion style needing
per-site care. It had two sites, both in `search_filters.inc.latte`, and
both were live bugs: Latte compiles `{foreach $x as $k => $x}` to
`unset($k, $x); foreach ($x as ...)`, so each loop destroyed its own
collection and the file-type and rating filter option lists rendered
empty on every search page carrying those filters. Neither the
golden-html fixture nor the VR baseline noticed — both were generated
after the bug existed, and a snapshot cannot report an absence. Fixed in
`086b658ca5`, the ignore dropped with it. A blanket identifier ignore
assumes every finding under it is the same kind of finding; for that one
it wasn't.

*Sizes.* `tools/p58/` is how they are measured: `census.php` strips the
CAMPAIGN-PENDING block into a scratch config beside `phpstan.neon`
(relative paths need the repo root) and re-runs PHPStan; `trace.php`
walks each finding back from its compiled line to the View property that
carries the wrong type; `assign.php` maps those pairs onto fix
techniques. Re-run `phpstan-latte:compile` first — a stale compile
changes the count.

Opened at **P58-A 843** across 74 templates and 63 View classes and
**P58-B 376** across 72 (P32 recorded ~1,400 and ~450). **A is closed at
0**; B stands at **314** -- the 62 that have gone were not B work, but
`empty()`/`==` guards A had to restate on its way past, since `empty()` on
an object is always false and a comparison against a newly-typed value can
be written strictly.

All twenty of A's identifier-wide entries have come out of `phpstan.neon`,
each forced by `reportUnmatchedIgnoredErrors` rather than noticed, and
nothing of A is suppressed in their place: its two residual findings are
Latte's own `{foreach}` chaining and epilogue, filed by message with the
rest of the codegen. Those two patterns had to be widened at the end --
they named `CachingIterator<mixed, mixed>`, and typing the collections
those loops run over made Latte report concrete generics instead. §5 ended by deleting what
made it an archetype rather than by typing around it: `menubar.latte`'s
`{include $block->template, ...}` was a dynamic-filename include, so none
of its seven sub-templates could carry a `{templateType}` and the compile
step does not model include arguments. All seven render through
`Renderer::render()` into the block's `raw_content` now, and the include,
`MenubarBlockView`, `DisplayBlock::$template`/`$data` and `MenubarView`'s
asset dispatch went with it.

*P58-A0/A0b (done, `3e6255a4d9`, `fc763eaa57`).* 81 of A's raw 924 were
`booleanNot.exprNotBoolean` reading `Latte\Runtime\Template|null` —
Latte's own compiled `{block}` guard, not template source. A0b refiled 22
more, the `$ʟ_it ?? null` chaining Latte emits for `{foreach}` in any
template that uses `$iterator`. Both are in the permanent codegen group,
scoped by message so the same identifiers keep reporting on real template
source — which matters: a third, near-identical `CachingIterator` message
is *not* codegen and has to keep firing.

*The erasure that was hiding a fifth of the campaign (`a796cf74cb`).*
Latte compiles every `{foreach}` in an `$iterator`-using template to
`foreach ($iterator = $ʟ_it = new CachingIterator($rows, …) as $row)`
and declares that class `@extends \CachingIterator<mixed, mixed, …>`, so
`$row` was `mixed` however well `$rows` was typed — in 13 templates
holding 219 of the then-638 findings. It was invisible until a retype
landed inside one of them and cleared 9 of 24, the other 15 having merely
changed identifier on the same expressions. A generic stub
(`phpstan-stubs/LatteCachingIterator.stub.php`) took A from 638 to 505 on
its own. A stub *replaces* the real docblock, so Latte's own
`@property-read` list has to be carried over verbatim, and the parameters
must stay invariant — PHP's own `\CachingIterator` declares invariance
and `@template-covariant` is rejected outright.

*P58-A — type the producer → View → template chain.* The dominant cause
is not a missing type: it is a typed VO flattened to an array at the View
constructor, one line before the template that needed it. `assign.php`
sorts every traced pair into eight fix techniques — these are techniques,
not a partition, since one chain can need two:

| # | technique | opened | now |
| --- | --- | --- | --- |
| 1 | delete a `->toArray()` flatten | 118 | **0** |
| 2 | tighten a leaf `*Result`/`*Data` property | 87 | **0** |
| 3 | compose a row VO (incl. `array_merge` sites) | 160 | **0** |
| 4 | retire a flattening `TemplatePageContext` | 86 | **0** |
| 5 | polymorphic block data (`mixed` by design) | 52 | **0** |
| 6 | picture family: untyped event payloads | 35 | **0** |
| 9 | template locals / fallback-union globals | 119 | **0** |
| 11 | nullable/union used as if definite | 23 | **0** |

§1 and §11 are finished and §4 all but; what is still filed under them is
residue of `assign.php`'s single-assignment rule, verified expression by
expression against the compiled output. `trace.php` attributes a finding to
the first root variable in the compiled condition, which for
`{if isset($pdfNbPages) and $navCurrent['path_ext'] == "pdf"}` is the wrong
one of the two.

Delete each `toArray()` whose last caller this removes — but check the
*other* consumer first. A View's `exposedPageData()` reads the same bags,
and `.ts` files read those by name through `pwg_getPageData()`, so a
flatten that also feeds page data is renamed `toPageData()` and kept, not
deleted. Where a flatten really is dead, grep the class name alone and
read the hits: a `grep Class | grep toArray` needs both on one line, and
a call spanning five lines is how five gallery pages were briefly left
fatal.

Two structural constraints, both learned by getting them wrong. Only a
template rendered through `Renderer::render()` can carry a
`{templateType}`, so a `{varType}` layout extended by 23 children and an
`{include}`d partial both take their variables from elsewhere — type the
ambient producers instead of inventing a View. And
`phpstan-latte:compile` must re-run after every template edit, or
shipmonk reports every property of the newly-typed VO as never read.

§3's row VOs each found something the loose shape was hiding: a
manifest read with no `??` warned once per missing field per row for
every pre-17.x catalog entry (`CatalogPluginRow`); an
`explode(' ', $occuredOn)` destructured into a pair for a value that
is
`''` whenever the date could not be read (`ActivityLogRow`); a
`?? null`
bridged two blocks under the identical guard, feeding a template that
calls `count()` on it with no null check (`SelectedTagRow`). Four
producer keys were computed and read by nothing. And typing one
property can close far more than its own count — `$activityChartData`
carried six of `$activityLastWeeks`'s findings, because the keys it
yields are what index into it.

Not every shape should become a VO. `IntroView::$storageChartData`
reaches `intro.ts` via `exposedPageData()`, which reads
`total.filesize`/`nb_files` by name, so a readonly object would
serialize as `nbFiles` and break that contract; it takes a precise
array shape instead. The shape then has to be *inferable*: accreting a
nested array in place infers `array<string, array<string, mixed>>`
whatever the docblock claims, and PHPStan's own output says not to
override its inference with an inline `@var`. Accumulate into flat
typed arrays and assemble each entry in one expression instead.

`RatingUserView::$ratings` was the campaign's one restructure rather
than retype, and its comparators' own docblock had argued against a
real shape ("would tie every comparator to render()'s own internal
accumulation order, for no safety gain"). A `UserRatingAccumulator`
whose `freeze()` computes the statistics settles it: the defensive
narrowing in all five comparators goes away with the shape it was
guarding against. Keep their expression shape, though — `<=>` is not
faithful where the operands are `int` and the zero test is `=== 0.0`,
nor where `sqrt()` of a float-error-negative variance can yield `NAN`.

§9's tail turned out to hold two distinct failure modes, and telling them
apart is what the technique's own name obscures. Four of its roots were
template variables **no producer assigns at all** — a rename that missed a
file (`$COMBINABLE_TAGS`, so the related-tags panel rendered its heading
and nothing else since P40 Batch 2), a pair the View supplies in
camelCase while the template still reads snake_case
(`$cache_sizes`/`$time_elapsed_since_last_calc`, so the maintenance env
tab printed "N/A" and "never calculated" whatever the real state), a meta
tag whose value moved onto `PictureView` while `layout.latte` renders
before that View exists (`$INFO_AUTHOR`), and markup superseded five years
ago upstream (`$search_summary`). A `mixed` root with no View property
behind it is the signature: the census finds these because nothing types
them, and no snapshot can, because a fixture generated after the fact
records the absence as correct.

The other mode is the compile step under-describing code that was already
right, and three gaps closed there moved the census without touching
application source. Latte discards the types a `{define}` declares —
`TemplateGenerator::buildParams()` emits `$x = $ʟ_args[0] ?? … ?? null`
and never reads `ParameterNode::$type` — so declared block parameters
reached PHPStan as `mixed` and `{define}`'s type syntax was decorative;
transform #4 injects the missing `@var`, cross-checking the names it parses
out of Latte's own emitted tag comment against the assignments underneath.
And `ContextVariableExtractor` typed an array literal in `toArray()` by
"the first property it mentions", so `'chronology' => ['TITLE' => $title]`
arrived as `string` and `'footer_elements' => [$debug]` likewise —
*worse* than `mixed`, since the resulting findings accused the template of
an offset access on a string. Keyed literals now yield `array{…}`, keyless
ones `list<…>`, and three shapes still refuse to guess (an int key, a
spread, a literal mixing both forms). The compile step emits no
"computed expression" notices now.

Not everything volatile is unfreezable, and the difference is worth
measuring rather than asserting. The maintenance env tab was recorded as
uncapturable because it "prints wall-clock timestamps"; two renders three
seconds apart differ on exactly two lines, and only one had to. The PHP
one was `date('Y-m-d H:i:s')` — the single clock read in that file not
going through `Env::now()`, against 157 sites in `src/` that do — so
`PIWIGO_TEST_NOW` freezes it. The MySQL one is `SELECT NOW()` off the
database server and the pair exists to reveal PHP/DB skew, which is real
here. Hence a third element in the shared route table meaning "golden HTML
only": a byte snapshot normalizes an external value the way it already
normalizes the checkout path, a pixel baseline cannot. That table's shape
lives in three places — the file's own docblock and a `@var` over each
consumer's `require` — and all three must move together.

*P58-B — modernize the template source.* Ordered strictly after A: 154
of B's 268 loose comparisons and 28 of its 103 `empty()` calls have
`mixed` on one side and are undecidable until A lands, and tightening
types *creates* new always-true/false findings, which are the
bug-bearing kind.

*B1 (done).* B led with those 5, and the bet paid: one of the two files
held a live bug (#20 below). `notification_by_mail.latte`'s three
`=== "false"` comparisons were the other file, and they really were dead
-- the legacy half of a check written when those config values were the
JSON strings `"true"`/`"false"`, which is bug #1's data corruption seen
from the reading side. The migration repaired the data and the properties
are plain `bool` now, so the string half cannot match; the `!$param[X]`
half it was `||`-ed with already carried the branch alone. Removed rather
than corrected, and the "No" radio's `checked` -- the branch those three
lines control, which nothing asserted -- is now covered. Three identifier
ignores (`if.alwaysTrue`, `booleanOr.rightAlwaysFalse`,
`identical.alwaysFalse`) retired; **314 → 309**, 3 entries left in the
CAMPAIGN-PENDING block.

*B2 (done).* All 78 `empty()` calls, in five commits grouped by area.
**No `empty()` survives anywhere in the template tree**, and that retired
two `phpstan.neon` entries rather than one: `empty.notAllowed` from
CAMPAIGN-PENDING, and `empty.variable` from the **permanent** group, since
every presence check left is spelled `isset()`.

The plan's rule -- array to `!== []`, string to `!== ''`, nullable to
`!== null` -- turned out to be the *second* question. The first is whether
the variable exists at all, because several of these calls are presence
tests wearing an emptiness test's clothes. `PageMessagesContext` and
`HeaderMessagesPageContext` emit a key only when they have a value, so
`$infos`/`$header_msgs` are genuinely *undefined* on most pages and only
`isset()` is correct. The same shape needed opposite answers twice:
`$tags`/`$authors` keep their nullability, because `{if isset($tags)}`
wraps them and `null` ("no filter section") differs from `[]` ("section,
empty"); `$items`/`$calendarBars` lose theirs, because no outer `isset`
guards them and both producers can return `[]`, so neither `!== null` nor
`!== []` was correct alone.

Three flattening contexts stopped omitting their null keys
(`PageHeaderPageContext`'s `header_notes`/`meta_ref`/`page_refresh`),
which is what let those read `!== null` at all: an omitted key leaves the
variable undefined, and that is the only reason the templates reached for
`empty()` there. A missing key was Smarty's way of spelling "no value" and
nothing else.

*B3 (done).* The comparisons -- 231 of them, not the 268 this plan first
recorded, since Campaign A's typing resolved the rest. `===` was not a
mechanical substitution anywhere:

- `int`/`int` (48) is the one always-safe pair and went in as a sweep,
  after confirming the narrowed ranges (`int<min, 2>|int<4, max>`) were
  PHPStan tracking an `{elseif}` chain's excluded values rather than
  always-false findings hiding in the pile.
- `string`/`string` (97) needed every literal read first: all ~40 are
  non-numeric identifiers, so `'10' == '1e1'` was never in play. The
  thirteen with no literal on either side were read individually, and one
  family mattered -- `$PAGE_TITLE != $GALLERY_TITLE` compares
  user-supplied text, so a gallery named `1e3` and a page named `1000`
  were EQUAL under `==` and the page title vanished from `<title>`.
- `mixed`/`string` (53) could not be decided until the operand had a type,
  exactly as this plan predicted, and 42 had one cause:
  `SearchFilterRenderer` flattened `FilterViewDefinition` to an array so a
  loop could overwrite its `access` string with a computed bool. One slot
  holding two meanings; two names replaced it.
- `string|null`/`string` (20) were safe once checked: the `'' == null`
  trap needs an empty-string literal, and every one of these compares
  against a non-empty action name.

Four retypes came out of B3 rather than an operator change: the
batch-manager filter panel takes `BulkManagerFilter` instead of the raw
session bag, the three search-filter counter maps became
`array<array-key, int>`, the rating buckets `array<int, int>`, and
`Navbar::$currentPage` became an `int` (bug #23).

*Gate.* Each identifier's ignore comes out of `phpstan.neon` in the
commit that takes its count to zero; `reportUnmatchedIgnoredErrors`
forces it. A ends with 20 entries removed, B with 6 (3 gone with B1, 1 with B2),
and the CAMPAIGN-PENDING block gone. B2 also retired `empty.variable`
from the permanent group and added `nullCoalesce.property` to it, which
is the same trade one level down: `month_calendar.latte`'s
`$chronology_calendar` is only assigned for a calendar view, and the
template renders for the list view too. Output is invariant across A by
construction, so golden-html (83) and VR (75) staying green with no
regeneration is the proof, not a chore; in B a changed byte means the
loose comparison was load-bearing.

*What the invariance argument does not cover, and what it cost.*
Un-flattening inverts two guards in opposite directions. `isset($x['k'])`
becomes `isset($obj->prop)`, always *true* on a non-nullable property —
PHPStan reports that as `isset.property`. `empty($x)` becomes
`empty($obj)`, always *false* on any object — and PHPStan is **silent**,
because calling `empty()` on an object is perfectly legal. In
`month_calendar.latte` that silently flipped all seven of a month's
padding cells to the wrong branch, and the only thing that caught it was a
golden fixture built one commit earlier. Every `{if !empty($x)}` whose
`$x` becomes an object has to be read by hand.

Twenty-three live bugs have surfaced this way, none of them type work:

1. Saving the Main, Comments or Display config tab turned off every
   checkbox on it. The tabs normalized to `'true'`/`'false'` strings and
   saved through `confUpdateParam()`, which `json_encode()`s, so a `json`
   column got the JSON *string* `"true"` and a `bool` property resolved
   through `is_bool($decoded) ? $decoded : false`. 49 params, repaired by
   `Version20260828120000` on MySQL, Postgres and SQLite.
2. `configuration_search.latte:87` read a key that has only ever been a
   sibling of the array it was read from, so the box never rendered
   ticked.
3. The integrity panel's two action buttons did nothing — the form named
   them one thing and the handler read another. Inherited from the
   pre-conversion `.tpl`, and it survived because the Integration tests
   set `$_POST` directly, the one coverage shape that cannot see a
   form/handler mismatch.
4. The batch manager's Square ratio preset drove its slider to a
   nonexistent index: its `data-max` was written across two source lines,
   Latte emitted the newline verbatim inside the attribute, and the value
   no longer matched any entry of the list `indexOf()` searches.
5. `rating.php?users[]=…` passed an array to `strval()`, producing the
   literal string `Array` and an "Array to string conversion" warning.
6. `admin.php?page=updates&step=3` on an up-to-date install rendered an
   empty release badge inside an empty href and passed `null` to
   `htmlspecialchars()`. The step came straight from `$_GET` with no check
   that the release its mode presents exists, and steps 2 and 3 are the two
   that reach `CoreUpdateService::upgradeTo()`.
7. The sizes tab dereferenced null on every derivative-only validation
   failure: `$sizes` was left null whenever the POST carried no `original_*`
   field, while the flag said the tab was populated, and
   `configuration_sizes.latte` reads `$sizes->originalResize*` unguarded six
   times. Found by replacing a no-op `assert()` with a real check.
8. `Image\ImagePathHelper` built paths out of `strrpos()`'s `false` --
   `false + 1` is `1`, so a path with no directory or no extension was
   rebuilt from its own offset 1. Found by enabling `zend.assertions`.
9. Four watermark form fields (`minw`, `minh`, `xrepeat`, `yrepeat`) went
   through no validation at all -- every value reached `WatermarkParams`
   through a bare `intval()`, so `'abc'` became 0 and a negative was stored
   as-is. The template had always carried error markup for all four, and
   because nothing wrote those error keys the branches could not render,
   which is how the gap surfaced. The dead markup was removed first and the
   gap recorded; the validation landed afterwards, with the markup restored
   and the bounds taken from what the renderer can use -- a negative repeat
   makes `ImageDerivativeController`'s `for ($i = -$r; $i <= $r; $i++)` skip
   its body while the enclosing `if` still says repeats are on, an oversized
   one costs `(2x+1)(2y+1)` iterations per derivative, and a negative
   minimum makes `willWatermark()` true for everything. `xpos`/`ypos` gained
   the same numeric check on the way past: they were range-checked but
   `intval('abc')` is 0, which passed.
10. The related-tags panel rendered its heading above zero tags on every
    picture page: P40 Batch 2 renamed the ambient `$COMBINABLE_TAGS` its
    `{foreach}` reads, and nothing read the new name.
11. `<meta name="author">` was missing from every picture page --
    `$INFO_AUTHOR` lost its producer in `14bf8701c7` and the template kept
    asking for it.
12. The maintenance environment tab reported "N/A" and "never calculated"
    unconditionally: `maintenance_env.latte` read snake_case keys off a
    view whose properties are camelCase.
13. Plugin and theme author and version links rendered as escaped literal
    markup on the extensions pages -- a missing `|noescape`, with
    `themes_installed.latte`'s correct `{$version|noescape}` one line below
    as the proof of intent.
14. The photo-sizes switcher never rendered at all after P40 split the
    ambient `current` into `navCurrent`/`current`; the template kept
    reading the name that no longer carried the derivatives.
15. The menubar tag cloud had no size variation, because
    `addLevelToTags()` was never called on the sliced tag list -- every
    tag rendered at the same weight.
16. The related-albums scaffold rendered its rows as `<a href="">`.
17. The menubar emitted an empty `<dl id="...">` for any registered block
    nothing filled in -- `BlockManager::apply()`'s hide check compared
    `raw_content` strictly against `''` while it defaults to `null`. Live
    on every gallery page for `mbLinks` and `mbRelatedCategories`, and
    baked into 25 and 20 golden fixtures because both snapshot
    instruments record what is present rather than reporting what should
    be absent.
18. The standard_pages theme never showed a page-level error on its
    login, password-reset or registration pages: all three rendered
    `$errors['..._page_error']` -- a single translated string -- through a
    `{foreach}`, so the container rendered and the message inside it did
    not. "You are not authorized to access the requested page", "Invalid
    key" and "Invalid/expired form key" were all silently dropped, while
    the `*_form_error` sibling in each same file rendered correctly.
19. One extension's author byline leaked onto the next on the
    installed-themes and installed-plugins pages: `{var $author = ...}` is
    assigned only inside `{if !empty($x->author)}`, and a Latte `{var}` is
    a plain PHP variable that survives the `{foreach}` iteration that set
    it. Neither page could show it -- every theme on disk is excluded from
    the list by the renderer's own `continue`, and the plugins fixture has
    no rows -- so both loops render at most one entry here.
20. An album with a thumbnail but no direct photos could not have that
    thumbnail removed. `cat_modify.latte` wrapped the two representative
    actions in `isset($representant) and ($representant->allowSetRandom ||
    $representant->allowSetRandom)` -- a duplicated operand, inherited
    verbatim from `62fdf2ab65`, the commit that first introduced the block
    in Smarty. The second half was meant to be `allowDelete`, the only
    other action the box holds. `allowSetRandom` is `$has_images` and
    `allowDelete` is true whenever `!$has_images`, so the one combination
    the typo swallows is exactly the one where `allowDelete` is the reason
    the box exists, and "Remove thumbnail" never rendered there. Reachable
    through the public API alone: `PUT /categories/{id}/representative`
    checks that both ids exist, not that the image is in the category.

21. **The core-upgrade page never listed incompatible extensions.**
    `updates_pwg.latte` step 3 read `$missing['plugins']`/`$missing['themes']`
    -- plural, which is what the pre-conversion `updates.class.php` used
    (`$this->types = ['plugins', 'themes', 'languages']`) -- while the P23
    port re-keyed the bag by `ExtensionType::value`, singular. Five reads
    matching nothing, so no incompatibility warning, no "I decide to update
    anyway" checkbox, and an update button that was never disabled.
    `empty()` made it silent: `$missing[$type][]` exists only when
    something IS missing, so a wrong key and "nothing missing" are the same
    answer, and off step 3 the same expression is a null offset that
    `empty()` also swallows. Both sides had tests; nothing tested the join.
22. **A zero consensus deviation rendered as an empty cell.**
    `rating_user.latte` guarded its "consensus deviation (top)" column with
    `!empty($rating->cdTop)` on a `?float`, and `empty()` swallows a real
    `0.0` alongside the null. `0.0` is not exotic: `abs($rate - $average)`
    is exactly zero whenever the user is the sole rater of an element, so
    "agreed exactly with the consensus" and "no top-rated element to
    average" showed as the same blank cell. The committed golden fixture
    had it baked in -- `power_user`'s cell goes from blank to `0.000`.

23. **The paginated navigation bar highlighted no page at all for any
    off-boundary offset.** `CURRENT_PAGE` was a float, so `?start=30` with
    20 per page exported `2.5` and `{if $page == $navbar->currentPage}`
    matched no key in the pages list. History says it was collateral: the
    2009 original computed `ceil($start / $per) + 1`, a whole number, and
    856b5a2519 (2012) replaced it while doing something else -- its subject
    was pinning the generated URLs to page boundaries, it needed a
    fractional position for its new `floor()`/`ceil()` window bounds, and
    it reused one variable for both jobs. The page is now the one the first
    displayed element falls on, clamped to the last real page, and the
    links and window derive from it. Two more defects fell out: a "next"
    link pointing at the page already shown (at `start=85` of 100), and an
    out-of-range offset naming a page that does not exist. Verified by
    diffing old against new over 15,306 inputs -- **zero differences on any
    boundary-aligned offset**, which is every URL Piwigo itself emits.

A twenty-fourth is the compile step's own: `VariableMapBuilder` joined a
variable's declarations by imploding type strings, so a name one context
declares `?string` and another declares `string` became `?string|string` --
not valid PHPDoc, since the `?` shorthand cannot take part in a union.
PHPStan discarded the annotation and every read of that variable analysed
as `mixed`. `$ADMIN_PAGE_TITLE` is the case that surfaced it; the
annotation was present and looked right in the compiled output, which is
why it read as a template problem rather than a tool one.

## Greenfield tracks (T3, cuttable — outside the P0–P58 backbone)

All entirely cuttable, never gating a backbone commit, dropped first on
overrun. None have started; each depends on backbone phases that have not
landed.

- **T3·WEB** — PWA, View Transitions, Speculation Rules, JSON-LD, SRI,
  resource hints. Depends on P36 (asset pipeline), P31/P33 (Latte
  templates) and P52 (CSS architecture).
- **T3·AI** — depends on P19 and P27.
- **T3·RIDERS** — CQRS, libvips/HEIC, vector/CLIP search, tus uploads,
  webhooks, Fibers, Mercure, passkeys, OIDC, soft delete. Each is hosted
  on its own backbone phase.

The **legacy import tool** (`bin/piwigo import:legacy`) is the one
non-cuttable exception in this group — T2 adoption tooling, not a rider.

## Execution approach for remaining phases

1. **Write tests first**, or in the same commit group.
2. Read the target state of the equivalent code on `16.x-rewrite`
   (`../piwigo16-rewrite`) — for reference only.
3. **Re-implement manually.** Nothing is git-pulled or cherry-picked from
   either branch. Self-contained files are re-created by hand; greenfield
   items are authored new.
4. `config/container.php` and `config/routes.php` grow incrementally with
   each phase, never reproduced from the reference in bulk.
5. Full gate suite after each commit group; fix before proceeding.
6. **Extend this file and `docs/REFERENCE.md`; do not create a new
   per-phase doc.** The original plan had each remaining phase spinning
   up its own file (`docs/FRONTEND.md`, `docs/API.md`, `docs/SECURITY.md`,
   `docs/PLUGINS.md`, `docs/EVENTS.md`, `docs/STRUCTURE.md`,
   `docs/AI.md`). That is superseded by this consolidation's whole
   premise: 18 drifting files reduced to 2. Add a section, not a file.

**Rollback rules.** Every commit must be green — fix before the next
commit, never accumulate broken state. Stuck mid-phase: revert to the
last green commit and re-approach, do not push through. A phase
materially exceeding its estimate: drop its T3 items first, and split the
phase only if T1/T2 alone is still oversized.

## Risk register

- **P40 is the largest single diff remaining.** Mitigated by the thin
  slice and by converting one page-family at a time; two rendering models
  coexist during the transition.
- **P43's no-escape-hatch decision means core must be extended for novel
  needs.** Accepted explicitly; the consequence already absorbed is
  plugin-owned routes.
- **P43's built-in filter swaps have real semantic differences.** Check
  each; golden-HTML catches the rest.
- **P36's fork is decided (view-declared) — shell-last composition is
  retired by P41's own single, one-time cutover, run only after P40's
  page-family campaign fully completes, not interleaved with it.**
- **P52's Tailwind decision, resolved: not adopted.** Kept the vanilla
  per-theme utility-CSS architecture P39 already built; P52 modernizes
  it via `@container`/`@layer` rather than migrating to a utility
  framework.
- **P29 breaks external extensions by design** — an accepted product
  decision, not an oversight. In-tree callers migrate in the same phase.
- **Skipping workstream C3 Phase 0 breaks Phase 1 silently, not
  loudly.** A bootstrap-phase middleware that short-circuits without
  Phase 0's `ResponseReadyException`-at-every-nesting-level fix would
  still "work" (the request completes) while quietly losing security
  headers and Server-Timing and spamming Sentry with false errors —
  exactly the kind of regression a happy-path test suite would not
  catch. Both landed together for this reason.
- **MySQL 9.x is a non-LTS line.** Pin the exact server version; hedge via
  the MariaDB/PostgreSQL provider matrix.

**On the "a11y gate."** There isn't one. No automated accessibility
tooling — `axe-core`, `pa11y`, or a Lighthouse `assert` block scoped to
the a11y category — exists anywhere in this repo. What actually ran
during P31's 139-template conversion was the VR baseline plus manual
per-template review, and both stay in place for whatever templates P38
and later still touch. Making it real is P55's call.

## MySQL infrastructure notes

**Open question — collation.** `utf8mb4_0900_ai_ci` was the originally
planned MySQL collation, for more accurate multilingual sort than
`utf8mb4_unicode_ci`. It appears nowhere in the live repo: all 39
`CREATE TABLE` statements in `install/piwigo_structure-mysql.sql`
explicitly declare `utf8mb4_unicode_ci`. No decision record explains the
reversal, and MariaDB's `utf8mb4_uca1400_ai_ci` equivalent was similarly
never adopted. Whether this was a deliberate undocumented simplification
— fewer moving parts across the provider matrix, since MariaDB has no
`_0900_` collation either way — or an oversight is not established. It
matters for any phase still touching schema: a new table following the
*original* instruction would be inconsistent with all 39 existing ones.

Other notes: MySQL 8.0+ has no `.frm` or query cache, and the
`symfony/cache` layer is the intentional replacement, not a gap.
`SET PERSIST` is available for a future admin maintenance page's tuning.
Replication terminology is `SOURCE`/`REPLICA` in any future documentation
or admin page that touches it.

## Migration path

Clean fork, no in-place upgrade from an existing Piwigo install. Doctrine
Migrations (`bin/piwigo migrations:migrate`) are the real, live mechanism
today, for both a fresh install and a version-to-version upgrade of an
existing v17 install.

`install/piwigo_structure-{mysql,mariadb,pgsql}.sql` are **generated,
human-reviewable snapshots** regenerated *from* migrations by
`bin/piwigo schema:dump` — not the install-time source of truth. They
look like the hand-maintained static schema that briefly replaced
migrations between 2026-07-24 and the pgsql-support pass, which is the
one thing to know before assuming they are authoritative.

Adopting from an existing pre-v17 install is meant to go through
`bin/piwigo import:legacy`, which is not built.

## Verification

The full gate list once every phase is done. Most already run in CI
per-commit; a few are aspirational until later phases land. See
`docs/REFERENCE.md`'s CI section for the current status of each.

```bash
vendor/bin/pest                             # unit, integration, browser, arch
vendor/bin/pest --mutate --min=60           # mutation score — not run in CI yet
vendor/bin/pest --type-coverage --min=95    # type coverage
vendor/bin/ecs --no-progress-bar            # style — 0 violations, blocking
composer analyse:phpstan                    # level 10, 0 errors — blocking
vendor/bin/rector --dry-run                 # non-blocking
vendor/bin/deptrac --no-progress            # 0 violations — blocking
vendor/bin/composer-require-checker check
vendor/bin/composer-unused
vendor/bin/phpbench run --report=aggregate
just typecheck && just lint-js && just lint-css
just build
bun run test:unit -- --coverage
bunx size-limit
bunx knip
bunx lhci autorun
actionlint .github/workflows/*.yml
bunx commitlint --from origin/16.x --to HEAD
k6 run tests/Load/*.js                      # non-blocking, tests/Load/ doesn't exist yet
```

`composer analyse:phpstan` chains `bin/piwigo phpstan-latte:compile`
ahead of PHPStan; a bare `vendor/bin/phpstan analyse` skips template
checking entirely.

**Deliberately not in this list**: `composer lint:latte`/
`precompile:templates`, which exist and work but are gated nowhere until
P45. (`vendor/bin/psalm` was removed from the project entirely on
2026-08-24 — see `docs/REFERENCE.md`'s "Key design decisions".)

**SEC traceability has no automated cross-check.** The original design
had every `SEC-NN` reachable from threat model → phase checklist →
manifest → `verified_by` test, enforced by a `tools/plan-lint` script
against `docs/plan/manifest.yaml`. Both were deleted in this
consolidation. The threat model and phase mapping survive below, but
nothing re-verifies that a `SEC-NN` still appears everywhere it should.
If that enforcement matters going forward it needs a new mechanism that
does not depend on the deleted YAML.

## Security master checklist

65 items, `SEC-01`–`SEC-65`, each globally unique. Status is derived from
the phase table above unless marked `(confirmed)`, which means directly
verified in code. Treat "phase done ⇒ item done" as a reasonable default,
not a guarantee.

| ID | Phase | Item | Status |
| --- | --- | --- | --- |
| SEC-01 | P4 | `.htaccess`/Caddy deny rules for sensitive directories | Done (confirmed) |
| SEC-02 | P0 | CLI guards on all `tools/*.php` scripts | Partial — see below |
| SEC-03 | P2 | No fixture SQL with secrets in web root | Done |
| SEC-04 | P4 | Ship `robots.txt` | Done |
| SEC-05 | P4 | Brotli compression | Done |
| SEC-06 | P4 | `Cache-Control: immutable` for hashed assets | Done |
| SEC-07 | P5 | Replace `mt_rand()` with `random_int()` | Done for security-sensitive uses — see below |
| SEC-08 | P5/P17–P23 | Replace loose `==` with `===` | Done |
| SEC-09 | P5 | `#[\SensitiveParameter]` on secret-carrying params | Done (confirmed) — see below |
| SEC-10 | P9→P17–P23 | Remove `addslashes()` superglobal sanitization | Done (confirmed) — `addslashes()` on every superglobal in `RequestBootstrap::bootEntryPoint()` was corrupting data (`O'Brien` stored as `O\'Brien`), masked by 71 compensating `stripslashes()` calls; fixed `aba74c9129` |
| SEC-11 | P9 | CSRF token md5→sha256 HMAC | Done (confirmed) |
| SEC-12 | P9 | CSRF verification via `hash_equals()` | Done (confirmed) — holds for `CsrfService`; the WS layer's own copy, `Ws\WsHelper`/`WsCsrfGuard::checkSecurityToken()`, used `!==` not `hash_equals()` across all 41 real call sites; fixed `b38c5f0877` |
| SEC-13 | P9 | `CookieService` HttpOnly + Secure flags | Done |
| SEC-14 | P9 | Cookie deletion calls include all flags | Done |
| SEC-15 | P20 | Eliminate 2 of 3 `eval()` calls (3rd = SEC-49) | Done |
| SEC-16 | P19 | Wrap `exec()` calls with `escapeshellarg()` | Done (confirmed) — 4 of 16 real `exec()` sites escaped nothing (`Admin/Image/ImageBackend.php`, `Admin/MaintenanceActionsPageRenderer.php`, `Ws/Core/GetCacheSizeHandler.php` ×2), admin-to-shell via DB-settable config; fixed `c6a63c8143` |
| SEC-17 | P17 | URL validation in redirect responder | Done |
| SEC-18 | P19 | Replace `addslashes()` in `SearchService` with prepared statements | Done |
| SEC-19 | P21–P22 | Controllers use PSR-7 request, not superglobals | Done |
| SEC-20 | P19 | XXE protection on SVG/XML parsing | Done (confirmed) |
| SEC-21 | P19 | SVG stored XSS sanitization on upload | Done |
| SEC-22 | P21 | Replace `phpinfo()` with curated server info | Done |
| SEC-23 | P17 | SSRF hardening for the HTTP client | Done |
| SEC-24 | P17 | Remove local-file read fallback in the HTTP client | Done |
| SEC-25 | P18 | Session fixation: regenerate on privilege escalation | Done — P28 was meant to verify further |
| SEC-26 | P16 | Validate locale before `include` in `LangService` | Done (confirmed) |
| SEC-27 | P18 | Auto-login key HMAC sha1→sha256 + `hash_equals()` | Done |
| SEC-28 | P18 | `EphemeralKeyService` HMAC md5→sha256 + `hash_equals()` | Done |
| SEC-29 | P17 | Host header poisoning defense | Done |
| SEC-30 | P17–P22 | Exception messages don't expose internals | Done |
| SEC-31 | P18 | Account enumeration via registration | Done |
| SEC-32 | P20 | ZIP bomb protection | Done (confirmed) |
| SEC-33 | P19 | Derivative serving leaks file existence | Partial — permission check is real, but runs through the full pipeline, not the designed fast path, so the item's scope shifted |
| SEC-34 | P22 | Install sentinel DB-flag secondary check | Done |
| SEC-35 | P19 | Remove non-standard headers from derivative pipeline | Done |
| SEC-36 | P27 | REST error responses never leak internals | Done (confirmed) — `Http\Middleware\ExceptionHandlerMiddleware` catches every uncaught `Throwable` app-wide (including `/api/v1`), logs it + reports to Sentry, and returns a bare `Internal Server Error` 500 with no message/trace |
| SEC-37 | P27 | No object dumps in the REST error path | Done (confirmed) — same middleware; nothing beyond the class name and message is logged, never returned to the client |
| SEC-38 | P27 | REST route authorization middleware | Done (confirmed) — `Http\AdminGuard` (401 vs 403, RFC 9457 problem+json), explicitly injected into 69 of the 134 `Controller\Api\*` classes |
| SEC-39 | P27 | Validate `Content-Type: application/json` on REST bodies | Done — `Http\JsonBody::decode()` (the single choke point every JSON-body-consuming controller already goes through) rejects a non-empty body whose media type isn't `application/json` with a 415, mirroring `TusUploadPatchController`'s own tus-protocol check |
| SEC-40 | P24 | Request DTOs as a hard input-validation gate | Real progress — arch test live; no "0 remaining" verified |
| SEC-41 | P28 | Password hashing → Argon2id | Not started |
| SEC-42 | P28 | CSRF middleware: remove `/admin*` exemption | Not started |
| SEC-43 | P28 | No `Access-Control-Allow-Origin: *` on the OpenAPI spec endpoint | Not started — still moot: the OpenAPI 3.2 spec now exists (`openapi/openapi.yaml`, P27) but only as a lint-gated repo artifact, never served over HTTP — no route exposes it, so there's no endpoint for a CORS header to apply to |
| SEC-44 | P28 | API rate limiting + rate-limit headers | Not started — `rate_limiter` pool deliberately unbuilt pending this |
| SEC-45 | P28 | CSP violation reporting | Not started |
| SEC-46 | P28 | Cross-Origin Isolation (COOP/COEP) | Not started |
| SEC-47 | P28 | `Vary: Cookie` on permission-dependent responses | Not started |
| SEC-48 | P28 | Default `allow_html_descriptions` to `false` | Not started — still `true` (confirmed); remapped from a pre-renumbering phase |
| SEC-49 | P29 | Remove `eval_visible` (plugin-facing half of SEC-15) | Done |
| SEC-50 | P3 | CycloneDX SBOM generated as a CI artifact | Done (confirmed) |
| SEC-51 | P3 | Pin GitHub Actions to commit SHAs | Done |
| SEC-52 | P3 | OSV-Scanner over lockfiles in CI | Done |
| SEC-53 | P3 | SLSA build provenance + attestations | Done |
| SEC-54 | P4 | Sign container images + release artifacts | Done (confirmed) |
| SEC-55 | P28 | OIDC SSO: PKCE + state/nonce + ID-token validation | Not started |
| SEC-56 | P18 | GDPR data-subject endpoints behind re-auth + rate limit | Not started — `PrivacyService` doesn't exist; the backend was P18 scope, its REST exposure P27 |
| SEC-57 | P15 | Append-only / tamper-evident audit log | Done — `Piwigo\Audit\*` is real |
| SEC-58 | P11 | Feature-flag changes authz-gated + audited | Partial — `FeatureFlag` is read-only by design, no mutation path exists yet to protect |
| SEC-59 | T3·AI | MCP server: scoped read-only tokens | Not started (cuttable) |
| SEC-60 | P7 | Worker-mode request isolation | Not started — the FrankenPHP worker-mode gap; workstream C3 Phases 0–1 (unified PSR-15 bootstrap pipeline) are a real prerequisite now landed, Phase 2/3 still open |
| SEC-61 | P11 | Mercure topic authorization | Not started (T3 rider) |
| SEC-62 | P28 | Trusted Types | Not started |
| SEC-63 | P28 | Fetch Metadata isolation | Not started |
| SEC-64 | P3 | OpenSSF Scorecard | Done |
| SEC-65 | P27 | API `Idempotency-Key` replay store | Done — `Http\Middleware\ApiIdempotencyMiddleware`, opt-in via the `Idempotency-Key` header, scoped to `/api/v1` mutating methods excluding tus; true concurrent-duplicate-request locking is a deliberate non-goal (a replay cache, not cross-process locking) |

### Notes on the partial items

**SEC-02.** Most real entry-point scripts have a `PHP_SAPI !== 'cli'`
guard, but `tools/i18n/verify-parity.php` and `tools/i18n/convert-all.php`
— both directly invokable per their own "Usage:" docblocks — have none.
Not currently reachable (`tools/` is not among `public/`'s symlinks), but
a literal gap against this item's stated scope regardless.

**SEC-07.** Seven `mt_rand()` calls remain, each non-security-sensitive:
temp-filename uniqueness, cache-busting query params, probabilistic
log-sampling gates, or picking a *length* parameter for a value that
itself comes from `random_bytes()`/`generateKey()` — e.g.
`Ws/Users/AddHandler.php`'s auto-generated password uses
`generateKey(mt_rand(15, 20))`, where the entropy is `generateKey`'s, not
`mt_rand`'s. None is the entropy source for a security-relevant token.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "mt_rand(" src --include="*.php" | wc -l' expect="7" -->

**SEC-09 — closed since last audit.** Every gap previously listed here is
now covered: `AuthService::tryLogUser()` marks its `?string $password`,
`Db\DbCredentials` marks the DB password, and all four Request DTOs
(`IdentificationSubmitRequest`, `RegisterSubmitRequest`, `PasswordRequest`,
`UserBootstrapRequest`) mark their promoted password properties.
`pwgLogin()` no longer takes a raw password at all — it takes a
`TryLogUser` event, whose own docblock records the residual limitation:
`#[\SensitiveParameter]` redacts scalar and array *parameters*, never
object properties, so an event carrying a password is not redacted by the
attribute and must not be dumped.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rln "SensitiveParameter" src --include="*.php" | wc -l' expect="9" -->

### Threat model

A different cross-section of the same 65 items. Every attacker goal maps
to at least one `SEC-NN` above, so its status is derivable from theirs;
it is not reproduced row by row here. Two items (SEC-05 Brotli, SEC-06
`Cache-Control: immutable`) are performance items, not mitigations, and
intentionally appear in no threat row. Mitigations that are not numbered
items at all — nonce-based CSP, the PSR-18 SSRF guard, DB-level account
locking, dual passwords — belong to P28 the same as their numbered
siblings.

### Secrets & key management

DB credentials and the application `secret_key` live in `.env`, never
web-served. A single `secret_key` derives the HMACs for CSRF tokens
(SEC-11/12), the auto-login cookie (SEC-27) and ephemeral keys (SEC-28) —
rotating it invalidates all three at once, forcing re-login repo-wide.
See `docs/REFERENCE.md`'s Secret rotation section.

DB password rotation via MySQL dual passwords
(`ALTER USER … RETAIN CURRENT PASSWORD`) is P28 scope, not built. Today's
path is the simpler "update env, roll deployment" sequence
`docs/REFERENCE.md` documents.
