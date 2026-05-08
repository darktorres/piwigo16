# Piwigo 16.x — Active Roadmap

> Open work only. Completed phases live in git history. The repository
> restructure plan is held out separately while its design decisions
> settle.

---

## At a glance (2026-05-08)

| § | Section | Status | Effort | TL;DR |
|---|---|---|---|---|
| 1.1 | Concrete bugs | 🟡 **Not started** | S | 8 individual fixes (6 deferred markers + 2 perf notes) |
| 1.2 | Templates pipeline | 🟡 **Not started** | XL | wave 1 hygiene → wave 2 Latte → wave 3 precompile |
| 1.3 | Plugin / theme + WS | 🟡 **Not started** | XL | `PluginInterface`, `ThemeInterface`, OpenAPI follow-ups |
| 1.4 | Security hardening | 🟢 **Active** ▸ 1 / 6 | M | CSP, rate limit, lockout, sessions, `SECURITY.md` |
| 1.5 | Type correctness | 🟡 **Not started** | M | mixed-types · globals · schema metadata |
| 1.6 | Test infrastructure | 🟡 **Not started** | M + L + S | Pest → coverage → Infection (chained) |
| 1.7 | Deferred / on-demand | 🟠 **On-demand** | — | Monolog · S3/SFTP · supervisor · Renovate |
| 2.1 | TS `any` reduction | 🟡 **Not started** | M | 478 → ≤250 patterns |
| 2.2 | Vitest unit tests | 🟡 **Not started** | M | TS unit-test runner + first wave |
| 2.3 | Bundle size budgets | 🟡 **Not started** | S | per-entrypoint gzip limits in CI |
| 2.4 | Vendored library migration | 🟢 **Active** ▸ 1 / 5 | L | Tiers 1, 3, 4, 5 (Tier 2 shipped) |
| 3.1 | CSS design tokens + Stylelint | 🟢 **Active** ▸ 3 / 13 | M | 10 live steps remaining |
| 3.2 | A11y audit (axe-core) | 🟡 **Not started** | M | WCAG 2.1 AA gating |

## Legend

| Tag | Meaning |
|---|---|
| 🟡 **Not started** | Scheduled, no commits yet. |
| 🟢 **Active** | Work in progress; one or more sub-tasks already shipped. The `▸ N / M` count shows shipped vs total sub-tasks. |
| 🔵 **Continuous** | Opportunistic; no single checkpoint date. Applied as files are touched. |
| 🟠 **On-demand** | Passive backlog; trigger when a deployment, audit, or external need calls for it. |

Effort tags: **S** ≤ 1 day · **M** 2–7 days · **L** 1–3 weeks · **XL** > 3 weeks.

---

## Cross-cutting dependencies

Most sections are independent. The chains that aren't:

- **1.2 templates pipeline.** Wave 1 hygiene → Wave 2 Latte → Wave 3
  precompile. Strict order; can't reorder. If hygiene is skipped, its bugs
  propagate verbatim into `.latte`.
- **1.5b globals cleanup.** Gated by direct `$GLOBALS[...]` reads in `src/`
  being eliminated first. Both halves (bridge cleanup + renderer residuals)
  land together.
- **1.6 test infrastructure.** Pest (1.6.1) lands first because it changes
  the runner that 1.6.2 and 1.6.3 measure. Coverage (1.6.2) feeds Infection
  (1.6.3) — mutation testing's MSI is meaningful only once enough tests
  exist to mutate.
- **3.1 → 3.2.** CSS design tokens before a11y audit — color-contrast
  violations dissolve when tokens land, so most of the violation list
  resolves on its own.
- **1.2 wave 1 javascript-URL fix ↔ §2 TS event binding.** Replacing
  `javascript:` URLs and inline `onclick` touches both `.tpl` and the
  receiving TypeScript handlers; coordinate the commit across tracks.
- **1.3 phase 2 themes ↔ 3.1 step 8.** The skin refactor in 3.1 presumes
  the `theme.json` layout that lands in 1.3. Soft dependency, not blocking.

---

## 1. PHP backend

> Working principle (continuous, no checkpoint): when touching a service
> that still resolves dependencies via `ServiceLocator::get(...)`, migrate
> to constructor injection in the same commit. Not a discrete item.

### 1.1 Concrete bugs

**Status:** 🟡 Not started · **Effort:** S · 8 items

8 individual bugs and perf notes from the in-code audit. Each is small
enough to land as its own commit; ordering is opportunistic.

**6 deferred markers** (real bugs with no current owner):

- `Updates.php` plugin-era redirect target.
- Search cat-id access gap.
- Admin URL leaking into the gallery filter.
- Category position persistence.
- Stub `cache_size` returning a placeholder.
- Image-id / filename precedence ambiguity.

**2 perf notes:**

- Redundant `get_available_tags()` call.
- All-rows-before-PHP-count in WS history pagination.

### 1.2 Templates: hygiene → Latte → precompile

**Status:** 🟡 Not started · **Effort:** XL · 3 sequential waves

One pipeline, three sequential waves. Each wave runs after the previous
lands — can't reorder.

#### Wave 1 — Smarty hygiene on existing `.tpl`

**Status:** 🟡 Not started · **Effort:** M · 8 actions

Eight ordered hygiene actions on the existing `.tpl` files, low-risk first.
These run **before** the Latte conversion so the converter sees correct
source — if skipped, the bugs propagate verbatim into `.latte`.

| Order | Item |
|---|---|
| 1 | Personal email leak in `install.tpl` (this fork) — replace `torres.dark@gmail.com` fallback with empty string or `$DEFAULT_ADMIN_EMAIL`. |
| 2 | Translation order bug — `'Level %d'\|@sprintf:$lvl\|@translate` becomes `'Level %d'\|translate\|sprintf:$lvl` (current order calls `sprintf` first, then translates a non-existent key). |
| 3 | Plural via `translate_dec` — 6 entries in `intro.tpl` and similar use the singular string for both 1 and N (`'%s editions'\|translate:$n`) → `$n\|translate_dec:'%s edition':'%s editions'`. |
| 4 | Invalid HTML — 4 sites: `<input>...</input>`, `<div>` inside `<p>`, unquoted `id={$key}` attribute. `picture_modify.tpl`, `batch_manager_global.tpl`, `user_list.tpl`. |
| 5 | `http://` → `https://` — 4 links in `photos_add_applications.tpl` to `piwigo.org/ext/extension_view.php`. |
| 6 | Delete dead browser code — IE5/6/7 conditional comments in `header.tpl`, `local_head.tpl`, `install.tpl`, `upgrade.tpl`; stale `mail-css.tpl` overlays under `themes/admin/{dark,light}/` (broken paths since 2008). |
| 7 | `\|@translate` → `\|translate` mechanical sweep across `themes/` (1203 occurrences). Same for `\|@sprintf`, `\|@escape`, `\|@count`, `\|@json_encode`, `\|@urlencode`, `\|@in_array`, `\|@nl2br`, `\|@htmlspecialchars`. |
| 8 | `javascript:` URLs and inline `onclick` → `data-*` attributes + delegated handlers. 5 sites in `picture.tpl`, `batch_manager_global.tpl` (4 places), `queue.tpl`. **Touches TS event binding — coordinate with section 2.** |

#### Wave 2 — Smarty → Latte conversion

**Status:** 🟡 Not started · **Effort:** XL · depends on Wave 1

Latte engine (`latte/latte`) wired alongside Smarty; templates converted
in waves: admin (~55 files) → public (`_base`, ~50) → `standard_pages` (~7) →
plugins (~31). Smarty kept during the transition; engine selection per
file extension.

Three large pieces fold into this wave:

- Wire the `|translate` filter at the engine level (Latte filter backed
  by `Piwigo\Lang\Translator`).
- Convert pure rendering includes — `PageHeaderRenderer`,
  `PageTailRenderer`, `PictureCommentRenderer`, `PictureMetadataRenderer`,
  `PictureRateRenderer`, `NoPhotoYetRenderer`, `SearchFilterRenderer`,
  `SelectedTagsRenderer`, `CategoryCatsRenderer`, `CategoryDefaultRenderer`
  (already typed PHP services) — to Latte partials.
- Populate the five page-context DTOs (`AlbumPageContext`,
  `PicturePageContext`, `SearchPageContext`, `TagsPageContext`,
  `AdminPageContext`) from controllers as each `.latte` partial is written.

Several findings from earlier hygiene auditing are folded in here as design
notes — Latte's escape-by-default and sandbox solve them systemically, so
no Smarty-side fix is needed:

| Concern | Approach |
|---|---|
| XSS surface from inconsistent manual escaping (the dominant security risk in the current `.tpl` set) | Latte's context-aware escape-by-default; sandbox + `PiwigoPolicy` for plugin-supplied templates |
| Markup-in-translation strings (e.g. `'Return to <a href="…">Sign in</a>'`) | Handled at conversion — split markup out of `.po` keys via `{capture}` patterns |
| `{section name=…}` legacy loop in `search.tpl` | Mechanical converter rewrite to `{foreach}` |
| Dynamic `{include file=$var}` (plugin extension hook) | `TemplateExtensionRegistry` whitelist + Latte sandbox compile-time check |
| Inline mail-CSS rendered through Smarty-in-Smarty (`mail/text/html/header.tpl`) | Keep email CSS as a *static* asset, not a runtime template |

Out of scope (informational — leave alone): HTML4 mail attributes
(`cellspacing`, `cellpadding` — still acceptable for Outlook), mixed
tab/space indentation in template files (`.editorconfig` covers new edits).

Drop `smarty/smarty` from `composer.json` once all bundled `.tpl` are
converted and the top-3 plugins ship Latte versions.

#### Wave 3 — Precompile at deploy

**Status:** 🟡 Not started · **Effort:** S · depends on Wave 2

Once Latte is the primary engine, ship `tools/precompile_templates.php` —
boots Piwigo in CLI, walks every active theme + admin context, calls
Latte's compile-only API per `.latte`, and Smarty's
`compileAllTemplates(.tpl, force: true)` for the transition window.

Outcome:

- First-request compile latency disappears; `_data/templates_c/` is warm
  at deploy time.
- Enables flipping `template_compile_check = 0` in production (the
  per-render `stat()` is wasted work once compile-on-first-hit is gone).
- CI hook catches Latte syntax regressions in plugin templates that lack
  unit-test coverage.
- Iterate per-theme (push/pop the dir stack) and per-plugin-set (boot-time
  prefilters/extensions alter compiled output).

### 1.3 Plugin / theme system + WS plugin surface

**Status:** 🟡 Not started · **Effort:** XL · 4 sub-items

One section because the same plugin contract drives all four sub-items.

#### Phase 1 — Plugins

**Status:** 🟡 Not started

- `Piwigo\Plugin\PluginInterface` with `getId/getVersion/getName/boot/shutdown/install/activate/deactivate/uninstall/subscribedEvents`.
- PSR-14 events (`composer require psr/event-dispatcher symfony/event-dispatcher`)
  with typed event objects under `src/Piwigo/Event/` (`PictureRendered`,
  `UserAuthenticated`, `CommentSubmitted`, …).
- Legacy compatibility layer in `src/Piwigo/Compat/LegacyEvents.php` —
  `add_event_handler('user_login', $cb)` keeps working; bridge maps string
  events to typed events with `E_USER_DEPRECATED` on first call.
- Declarative `plugin.json` per plugin (id, version, minPiwigo, main FQCN,
  PSR-4 autoload).
- DI in `boot()` — plugins receive the container; auto-resolved listener
  dependencies.
- Migrate the 5 bundled plugins (`LocalFilesEditor`, `nbc_ThemeChanger`,
  `piwigo-openstreetmap`, `piwigo-videojs`, `user_tags`) — one commit each;
  source moves to `plugins/<id>/src/`, templates convert to Latte under 1.2.
- Plugin admin UI reads `plugin.json` instead of parsing `main.inc.php`
  headers.

#### Phase 2 — Themes

**Status:** 🟡 Not started · depends on Phase 1

- `Piwigo\Theme\ThemeInterface` mirroring `PluginInterface` plus
  `getParentId/loadParentCss/getAssetDir/getLocalHeadTemplate`.
- Declarative `theme.json` (parent chain, asset directories, localHead,
  main FQCN).
- Side-effect code from `themeconf.inc.php` (`$this->assign(...)`,
  `ConfigService::confGetParam(...)`) moves into `Theme::boot()` where it
  has DI access.
- Inheritance via composition — `?ThemeInterface $parent`; `getAssetDir()`
  walks up the chain. Mirrors how `themeconf.inc.php` array merge already
  works.
- Legacy `themeconf.inc.php` shim — registry detects missing `theme.json`,
  synthesizes a `LegacyTheme` from the `$themeconf` array.
- `Piwigo\Theme\ThemeChanged` event so plugins (notably `nbc_ThemeChanger`)
  react via PSR-14 instead of procedural hooks.
- Migrate the 5 bundled themes (`themes/_base`, `themes/standard_pages`,
  `themes/admin/_base`, `themes/admin/light`, `themes/admin/dark`) — one
  commit each.
- **Soft dependency:** the skin refactor in 3.1 presumes the `theme.json`
  layout. Whichever lands first sets the layout the other adopts.

#### Migrate plugins off `PwgServer::addMethod()`

**Status:** 🟡 Not started · folded into Phase 1 work

`PwgServer::addMethod()` was retired during the front-controller migration;
`register(MethodDefinition)` is the only registration path. Plugins still
calling `addMethod` need to migrate. Same work as Phase 1 — the
`register()` migration happens inside each plugin's PluginInterface
conversion.

#### OpenAPI follow-ups

**Status:** 🟡 Not started · depends on Phase 1

Once plugin handlers are reflection-accessible controller classes:

- Teach `SpecBuilder` to read the `#[ApiMethod]` attribute for per-method
  enrichment (`summary`, `responseClass`, `tags`).
- CI gate validating the generated OpenAPI spec on every push. Three
  options, pick one when the work lands:
  - PHPUnit structural test (no new dep) — assert required OpenAPI 3.1 keys.
  - `cebe/php-openapi` as `require-dev` — full PHP validator with `$ref`
    resolution; callable from a test.
  - External: `openapi-spec-validator` (Python) or `redocly lint` in CI.

### 1.4 Security hardening

**Status:** 🟢 Active ▸ 1 of 6 sub-tasks done · **Effort:** M

CSRF middleware is already in place (centralized in `CsrfMiddleware`
during the front-controller work). The remaining hardening:

- **`SecurityHeadersMiddleware`** — adds CSP (with per-request nonce wired
  into `Template`/Latte), `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, HSTS,
  `Permissions-Policy`. CSP `style-src-attr 'unsafe-inline'` covers the 13
  surviving CSS-custom-property attrs; if a stricter policy is needed
  later, resurrect `{html_style}` to emit a single nonce'd `<style>` per
  request (implementation in `Template.php` is intact, callers were
  removed).
- **Login rate limiting** — `composer require symfony/rate-limiter`; token
  bucket: 5 failed attempts / minute / IP → 429; 10 failed / 10 min /
  IP+username → 15-min account lockout + email.
- **Brute-force lockout** — `phpwg_user_failed_logins` table,
  `AuthService::login()` rejects with `AuthException::accountLocked()`
  even for correct password while locked. Admin "Unlock account" action
  clears the counter.
- **Session hardening** — `samesite: 'Strict'`, `secure: true`,
  `httponly: true` cookie params; `session_regenerate_id(true)` on
  successful login.
- **Document threat model** in `docs/SECURITY.md` — CSP override procedure,
  account-lockout admin actions, vulnerability reporting.

### 1.5 Type correctness — three converging streams

**Status:** 🟡 Not started · **Effort:** M · 3 streams (7 + 2 + 5 items)

After PHPStan level 10 landed, three threads describe the remaining
type-tightening surface. Same gating constraint, same review effort —
tackle as one section, work the streams in parallel where possible.

#### 1.5a Mixed-type fixes

**Status:** 🟡 Not started · 7 items

Seven high-ROI items from the codebase mixed-type audit, ordered by
effort:

| Item | Files | Effort |
|---|---:|---|
| `ImageInterface::compose(mixed $overlay)` → `ImageInterface $overlay` | 4 | trivial |
| `CookieService::getCookieVar()` → `string\|null` (cookies are always strings) | 1 | low |
| ID parameters `mixed $id` / `$userId` → `int\|string` | ~25 methods | low |
| `Config::raw()` typed return — `string\|int\|bool\|array<mixed>\|null` | 1 | low (annotation) |
| `EventDispatcher::dispatch()` → `@template T` generic — eliminates many downstream `mixed`s | 1 | medium |
| Typed DB query helpers (`DbConnection::fetchIntColumn`, `fetchStringColumn`) — removes ~100 `fn (mixed $v)` lambdas | several | medium |
| `RequestCache` / `PersistentCache` → `@template T` generic — typed cache reads | 2 | medium |

Estimated reduction: ~880 / ~1272 mixed occurrences become typed; the
remaining ~880 are legitimate (DB row results, event payloads, generic
cache get/put).

#### 1.5b Globals cleanup

**Status:** 🟡 Not started · 2 items · gated by `$GLOBALS[...]` reads in `src/` being eliminated first

Both items below are gated by the same precondition — direct
`$GLOBALS[...]` reads in `src/` being eliminated first — so tackle them
together.

- **Drop `$GLOBALS` reference bridges in `phpstan-bootstrap.php`.** The
  bridges for `$page`, `$user`, `$lang`, `$template`, etc. exist only
  because `src/` still does direct `$GLOBALS[...]` reads.
- **Retire `$GLOBALS` reads in renderers** (residuals from earlier
  modernization passes):
  - `PageHeaderRenderer.php:25,59` reads/writes `$GLOBALS['page']`.
  - `PageTailRenderer.php:56,62` reads `$GLOBALS['debug']` and
    `$GLOBALS['t2']`.
  - `NoPhotoYetRenderer.php:26` reads `$GLOBALS['user']`.

These are cheap to retire if the value-object design is later adopted, but
they're harmless given the reference-bridge model in `Kernel::boot()`. Low
priority.

#### 1.5c Config schema metadata

**Status:** 🟡 Not started · 5 items

Five `Config::SCHEMA` enhancements that are still deferred design surface:

- `'required' => true` field + `MissingRequiredConfigException`, validated
  in `ConfigLoader::applyDefaults()`. Throws if any required key is unset.
- `'description'` field → populate the generated config-reference doc with
  per-key descriptions (currently the description column is empty for all
  287 keys).
- `'sensitive'` field + `Config::dumpForLog(): array` returning a
  sensitive-masked snapshot for log output.
- Namespace-prefix support in `ConfigStorage` for plugin keys — lets a
  per-plugin Config class store `<plugin>.key_name` cleanly.
- `--target=<path>` flag on `tools/build-config-accessors.php` so per-plugin
  `Config` classes (`LocalFilesEditor`, `NbcThemeChanger`,
  `PiwigoOpenstreetmap`, `PiwigoVideojs`) regenerate from the same
  generator instead of staying hand-written.

### 1.6 Test infrastructure

**Status:** 🟡 Not started · **Effort:** M + L + S · 3 chained items

Three coupled items, sequenced because each enables the next.

#### 1.6.1 Pest — first

**Status:** 🟡 Not started · **Effort:** M

`composer require pestphp/pest pestphp/pest-plugin-browser --dev`. Pest
wraps PHPUnit so the existing 378 unit tests run unchanged. The browser
plugin drives Playwright under the hood — port the 16 `tests/e2e/*.spec.ts`
to `tests/Browser/*.php` using the `browser()` helper. End state: single
`vendor/bin/pest` command, no separate `npx playwright test`.

Lands first because it changes the runner that 1.6.2 and 1.6.3 measure.

#### 1.6.2 Unit-test coverage 13% → ≥40%

**Status:** 🔵 Continuous · **Effort:** L · depends on Pest landing

Coverage baseline; priority order:

1. Core typed services (`Config`, `PageState`, `Lang`, `CurrentUser`,
   `Kernel`, `ServiceLocator`) → 90%+ each.
2. WS encoders (`PwgJsonEncoder`, `PwgRestEncoder`, `PwgXmlWriter`,
   `PwgServer::register/verifyParams`) → 85%+.
3. Search Q-classes + Calendar (needs `AbstractDbStub` in
   `tests/Unit/stubs/`).
4. `ScriptLoader` with/without `dist/manifest.json` (temp-dir fixture).
5. `Admin/Image/ImageGd` against `dev/fixtures/sample.jpg` (Imagick is
   integration-only).

Continuous — does not gate later items.

#### 1.6.3 Mutation testing — Infection — last

**Status:** 🟡 Not started · **Effort:** S · depends on coverage from 1.6.2

`composer require infection/infection --dev`. `infection.json5` at repo
root: `minMsi: 60`, `minCoveredMsi: 75`. CI job runs on `main` push only
(slow); raise thresholds 60 → 70 → 80 over time as test quality improves.

Lands last because the MSI signal is meaningful only once 1.6.2 has
produced enough coverage to mutate.

### 1.7 Deferred / on-demand

**Status:** 🟠 On-demand · 4 items · no scheduled effort

Real backlog — passive, executed only when a deployment or audit demands.

- **Logger backend swap to Monolog.** `LoggerInterface` is the contract
  today; the file/DB backend keeps working. Swap when demand surfaces.
- **File storage S3/SFTP adapters.**
  `composer require league/flysystem-aws-s3-v3` (or `-sftp-v3`); edit the
  closure in `config/storage.php` for the disk that needs offloading
  (`derivatives`, `uploads`, etc.). Plugin code does not change.
- **Worker daemon ops config.** Package supervisor / systemd unit files
  alongside the documented
  `bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M`
  flow. Currently ops-by-doc only.
- **Renovate dev-dep auto-merge workflow.** Dependabot ships no built-in
  auto-merge; add a small workflow only if dependency churn warrants it.
  Manual review is fine for current cadence.

---

## 2. TypeScript / frontend glue

### 2.1 `any` reduction 478 → ≤250

**Status:** 🟡 Not started · **Effort:** M · 3 tiers

ESLint `@typescript-eslint/no-explicit-any: error` is configured but
undermined by the existing 478 `any` patterns. Three tiers, largest files
first (`tags.ts` 80, `user_list.ts` 58, `albums.ts` 52, `group_list.ts` 45):

- **Tier 1 (~130 instances) — window globals.** Functions assigned to
  `window` for plugin interop (`applyFontCheckbox`, `array_delete`,
  `sprintf`, `TemporaryState`). Add `themes/_base/js/types/admin-globals.d.ts`
  with `interface Window` declarations, replace `(window as any).x` with
  `window.x`.
- **Tier 2 (~80 instances) — plugin callbacks.** Type
  `(window as Record<string, unknown>)[pluginId + '_save']` as
  `PluginSaveCallback | undefined` instead of `(window as any)[…]`.
- **Tier 3 (~100 instances) — fetch response shapes.** Add
  `themes/_base/js/types/ws-responses.d.ts` with explicit interfaces for
  the most-used WS responses (`pwg.images.search`, `pwg.categories.getList`,
  `pwg.tags.getList`); replace `any` in `.then((data: any) => …)` chains.

Closing this item is what unlocks a clean `npm run lint` exit — currently
the rule is enforced for new code via review only.

### 2.2 Vitest unit tests

**Status:** 🟡 Not started · **Effort:** M

Today the only JS test infrastructure is Playwright E2E — useful for
end-to-end flows, slow and high-friction for testing pure functions.

`npm i -D vitest @vitest/coverage-v8 happy-dom`. `vitest.config.ts`
includes `themes/{_base,admin/_base}/js/**/*.test.ts`; environment matches
`*.dom.test.ts` to happy-dom. Initial waves:

1. Pure-utility tests: `common.test.ts` (format/parse), `urls.test.ts`
   (URL builders), `getPageData.test.ts` (uses happy-dom).
2. State reducers: `batchManagerGlobal.test.ts` for selection/filter logic.

Threshold: lines 50% / functions 50% / branches 40%, raised to 70% after
the first wave. CI: `npm run test:unit -- --coverage` appended to
`.github/workflows/ci.yml`.

Boundary note: 1.6.1 Pest absorbs the *browser* tests (Playwright →
`pest-plugin-browser`); Vitest stays for TS unit tests. Non-overlapping.

### 2.3 Bundle size budgets

**Status:** 🟡 Not started · **Effort:** S

`npm i -D size-limit @size-limit/file vite-bundle-visualizer`.
`.size-limit.json` sets per-entrypoint gzip budgets ~5–10% above today's
measured baseline:

```json
[
  { "name": "admin/admin",       "path": "dist/assets/admin-*.js",       "limit": "85 kB" },
  { "name": "admin/batchManager*", "path": "dist/assets/batchManager*-*.js", "limit": "60 kB" },
  { "name": "admin/picture_modify", "path": "dist/assets/picture_modify-*.js", "limit": "55 kB" },
  { "name": "themes/_base/script",  "path": "dist/assets/script-*.js",       "limit": "45 kB" }
]
```

CI: `npm run build && npx size-limit` after every PR. Failure = budget
must be raised in `.size-limit.json` with a one-line rationale, or the
change reworked. Optional `vite-bundle-visualizer` HTML uploaded as a
workflow artifact on `main` push.

### 2.4 Vendored library migration

**Status:** 🟢 Active ▸ 1 of 5 tiers done · **Effort:** L · 4 tiers remain

Stylelint/ESLint scope cleanup (Tier 2) is already in place. Remaining
four tiers:

- **Tier 1 — Quick wins (S each).** `@fontsource/open-sans` replaces
  `themes/admin/_base/fonts/open-sans/`; `@fontsource-variable/open-sans`
  replaces `themes/standard_pages/fonts/OpenSans-VariableFont_wdth,wght.ttf`;
  `tablesorter` npm replaces
  `plugins/nbc_ThemeChanger/include/jquery.tablesorter.js`; `tom-select`
  (already a Piwigo dep) replaces `plugins/user_tags/js/jquery.addtags.js`
  — also drops the jQuery dependency for that plugin.
- **Tier 3 — video.js consolidation (M).** Drop `video-js-4` and
  `video-js-5` outright (vintage admins unlikely on 16.x). Pin `video.js@7`
  or `@8` via npm. Port `videojs.thumbnails` / `videojs.watermark` /
  `videojs.zoomrotate` / `videojs-resolution-switcher` to npm equivalents.
  Net: ~12 MB removed from the repo.
- **Tier 4 — Leaflet 0.7 → 1.9 (L, highest blast radius).** Audit which
  plugins `osmmap.php`/`osmmap2.php`/`osmmap3.php`/`osmmap4.php` actually
  call; some bundled plugins may be dead weight. Stand up `leaflet@1.9` +
  `leaflet.markercluster` + `leaflet-search` on a feature branch. Replace
  `Leaflet.Elevation-0.0.2` → `@raruto/leaflet-elevation`,
  `Control.MiniMap.js` → `leaflet-control-minimap`,
  `leaflet-omnivore.min.js` → `leaflet-omnivore`. Drop or rewrite
  `qleaflet.jquery.js` (jQuery wrapper).
- **Tier 5 — CodeMirror (M).** Two paths: `codemirror@5` (low risk,
  drop-in close to v2 — `LocalFilesEditor` wiring needs minor edits), or
  `codemirror@6` (long term — different module shape, `@codemirror/lang-php`
  etc., full editor re-init). Default to v5 unless there's appetite to
  invest.

Stays as static asset (cannot move to npm): Fontello custom-glyph subsets,
bundled themes (out of 16.x core scope), `themes/_base/js/plugins/piecon.ts`
(authored TS, ~100 LOC).

Each tier produces two commits: add the npm dep + glue, then delete the
vendor dir. Two commits make the actual replacement reviewable; the
deletion commit is otherwise a 12 MB diff that hides the real change.

---

## 3. CSS / themes

### 3.1 Design tokens + Stylelint

**Status:** 🟢 Active ▸ 3 of 13 steps done · **Effort:** M · 10 live steps remain

`themes/admin/_base/theme.css` is 9,561 lines monolithic;
`themes/_base/theme.css` is 1,241 lines. ~689 `!important` declarations
across first-party CSS. Zero CSS custom properties; breakpoints
inconsistent (`576/640/800/1100`).

Stylelint rule set, mechanical auto-fix, and inline-`<style>` extraction
are already in place. The 10 live steps:

| Step | What |
|---|---|
| 3 | Delete orphans: `themes/_base/fix-{khtml,ie5-ie6,ie7}.css`; broken `admin/_base/fix-ie7.css` `<link>` in `install.tpl` / `upgrade.tpl`. IE conditional comments are inert in modern browsers. |
| 4 | Split `themes/_base/theme.css` (1,305 lines) along section markers into per-concern files (`menubar.css`, `content.css`, `picture.css`, `layout.css`, `colors.css`, `forms.css`, `calendar.css`, `comments.css`, `popup.css`). `theme.css` becomes an `@import` list. |
| 5 | Collapse search CSS variants. Replace `search.css` + `clear-search.css` + `dark-search.css` with a single variable-driven `search.css` using `--search-*` tokens. Net savings: ~500 lines. |
| 6 | Non-color design tokens at theme root — `:root {}` block with `--space-*`, `--font-size-*`, `--line-height-*`, `--radius-*`, `--z-*`, `--bp-*` (canonical breakpoints `sm=576 md=800 lg=1100`). Replace hardcoded values throughout. |
| 7 | Color tokens for `themes/standard_pages/`. Emit `:root {}` color block in parent; replace direct color literals with `var(--color-*)`. |
| 8 | Refactor `themes/standard_pages/skins/*.css` (11 skins × ~337 lines × 20 `!important` ≈ 220 instances). With tokens in place, each skin reduces to a single `:root {}` override block (~30 lines, 0 `!important`). **Soft dep on 1.3 phase 2** — `theme.json` layout. |
| 10 | Admin-parent CSS design tokens via `base.css.tpl` — Smarty-templated `:root {}` block emitting `--admin-{bg,fg,accent,border}` from `$admin_skin` in each child theme's `themeconf.inc.php`. Removes the `{combine_css path="…/$theme.id/css/components/general.css" order=-9}` `{* Temporary solution *}` workaround. |
| 11 | Split `themes/admin/_base/theme.css` (9,635 lines) along its 60+ `/* name.css */` section markers into base/components/pages/features. `theme.css` becomes an `@import` list. Utility classes `.u-*` (added by inline-style extraction) land in `base/utilities.css`. |
| 12 | Slim admin child themes — `themes/admin/{light,dark}/theme.css` reduce to `:root {}` variable override blocks. Structural rules currently duplicated (borders, padding, grid, `@keyframes`) move up into the parent's split CSS. |
| 15 | `!important` final elimination pass. Tier 2 (tom-select redundant `!important` — our specificity already wins): `batch_manager_unit.css`, `picture_modify.css`, `albums.css`, `user-list.css`. Then Tier 3 file-by-file from largest to smallest. Reinstate `declaration-no-important` Stylelint warning once count is low. |

### 3.2 A11y audit — axe-core in Playwright

**Status:** 🟡 Not started · **Effort:** M · soft dep on 3.1

`npm i -D @axe-core/playwright axe-core`. Helper at
`tests/e2e/utils/a11y.ts`:

```typescript
import AxeBuilder from '@axe-core/playwright';
import { expect, Page } from '@playwright/test';

export async function runA11y(page: Page, opts: { disable?: string[] } = {}) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag21aa', 'wcag2aa'])
    .disableRules(opts.disable ?? [])
    .analyze();
  const blocking = results.violations.filter(v =>
    ['critical', 'serious', 'moderate'].includes(v.impact ?? '')
  );
  expect(blocking, JSON.stringify(blocking, null, 2)).toEqual([]);
}
```

Wrap critical pages: gallery index, picture, search, login, register,
admin dashboard, batch manager, picture edit, user management. Initial
sweep: snapshot every violation, triage into Fixable / Accepted-with-rationale
(per-page `disable: [rule-id]` + comment) / Out-of-scope (vendor libs).
WCAG 2.1 AA at severity moderate-and-above fails CI.

**Soft dep on 3.1.** Most color-contrast violations dissolve once colors
come from `--color-*` variables — so 3.1 first means a smaller triage.

Document in `CONTRIBUTING.md`: rationale-on-disable rule, workflow for
adding new pages to the audit.
