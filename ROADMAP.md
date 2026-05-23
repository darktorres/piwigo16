# Piwigo 16.x — Active Roadmap

> Open work only. Completed phases live in git history. The repository
> restructure plan is held out separately while its design decisions
> settle.

---

## At a glance (2026-05-10)

| §    | Section                       | Status                 | Effort    | TL;DR                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---- | ----------------------------- | ---------------------- | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1.1  | Concrete bugs                 | ✅ **Done** ▸ 9 / 9    | —         | history pagination refactor shipped 2026-05-10 (6-query split + snapshot tests); cat-id gap closed without code change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| 1.2  | Templates pipeline            | ✅ **Done**            | XL        | waves 1+2+3 done — Smarty hygiene → 133/133 Latte conversion → deploy-time precompile (`composer precompile:templates`) + CI gate                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| 1.3  | Kill ServiceLocator + DI      | ✅ **Done**            | L         | constructor injection everywhere; `ServiceLocator.php` deleted; `DbConnection::get()` callers eliminated                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| 1.4  | Plugin / theme + WS           | ✅ **Done**            | L         | shipped 2026-05-16 in 19 batches (B0–B18) on `16.x-rewrite`: `PluginInterface` + `PluginRegistry`, ~160 typed PSR-14 events under `src/Piwigo/Event/`, `ThemeInterface` + `ThemeRegistry`, 95 WS endpoints registered via typed `MethodDefinition` and exposed as OpenAPI 3.1 (via `SpecBuilder`) with cebe/redocly CI gates, legacy runtime deleted. `#[ApiMethod]` attribute + SpecBuilder reflection wired but no endpoint yet decorates with it — per-domain decomposition deferred (see §1.4 Phase 3 follow-up)                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| 1.5  | Security hardening            | ✅ **Done**            | M         | shipped 2026-05-22 in 4 waves on `16.x-rewrite` (A: `SameSite=Lax`/`HttpOnly`/scheme-conditional `Secure` session cookie, B: `piwigo_user_failed_logins`+`LoginThrottle` lockout & `symfony/rate-limiter` sliding-window per-IP/per-account on form+WS-API, C: `SecurityHeadersMiddleware` CSP/XFO/XCTO/Referrer-Policy/Permissions-Policy/HSTS + `composer lint:no-inline-scripts` CI guard, D: `docs/SECURITY.md`) + 3 polish commits (`Http\RequestScheme` for `PIWIGO_TRUSTED_PROXIES` X-Forwarded-Proto/-For trust, `SecurityHeaders::emitDirect()` on install/upgrade/i fast paths, doc tightening). Deferred follow-ups inventoried under §1.5                                                                                                                              |
| 1.6  | Type correctness              | ✅ **Closed** ▸ 13 / 13 | M        | 1.6b closed; 1.6a ✅ closed (2026-05-23 — `RequestCache @template T` + imperative-cache refactor); 1.6c ✅ closed (2026-05-23): `sensitive`+`dumpForLog`, `required`+`MissingRequiredConfigException`, plugin-Config template, and `'description'` field on all 277 SCHEMA entries                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| 1.7  | Typed boundaries              | 🟢 **Active** ▸ partly shipped under different names | L         | Phase 1: ~94/99 WS endpoints already use `WsAction`+`WsParams` (delivered as part of §1.4) — admin/web-side DTOs still open. Phase 2: 56 narrow `Projection/*` classes already in tree — 249/646 bare-`array` repo returns + 291 `fn(mixed)` lambdas still to tighten.                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| 1.8  | Test infrastructure           | 🟡 **Not started**     | M + L + S | Pest → coverage → Infection (chained)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| 1.9  | Deferred / on-demand          | 🟠 **On-demand**       | —         | Monolog · S3/SFTP · supervisor · Renovate · ScriptLoader dep graph                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| 1.10 | `PHPWG_ROOT_PATH` elimination | ✅ **Done**            | L         | shipped 2026-05-17 in 13 commits + 1 fix on `16.x-rewrite`: `Piwigo\Core\Paths` value object replaces the legacy global string, threaded through DI from `Paths::fromIndex(__FILE__)` in `index.php` → `Container::build($paths)` → service constructors; 195 reads across 72 files migrated; URL-context callers cleaned up (see [#33](docs/ROADMAP-PHP.md#33--eliminate-phpwg_root_path-global-replace-with-typed-paths))                                                                                                                                                                                                                                                                                      |
| 1.11 | Runtime `define()` retirement | ✅ **Done**            | M         | shipped 2026-05-18 in 7 commits + 1 follow-up on `16.x-rewrite`: all 12 surviving runtime `define()` constants retired — `PHPWG_DOMAIN` + `PWG_HELP` deleted as dead code, `PWG_LOCAL_DIR` → `Paths::$local`, `PREFIX_TABLE` + `UPGRADES_PATH` → `Tables::upgrade()` + `RequestContextRegistry`, `PWG_API_KEY_REQUEST` → `ApiKeyAuthRegistry`, `PEM_URL` → typed `PemUrlResolver` service, `PHPWG_URL` → `AppInfo::PROJECT_URL` placeholder, plus trivial moves for `BUTTONS_RANK_NEUTRAL` / `DEFAULT_PREFIX_TABLE` / `PHOTOS_ADD_BASE_URL`. Final invariant: `grep -rn 'define(' src/ index.php config/ tools/` returns only doc-comment hits explaining what each legacy `define()` was replaced with — zero live calls (see [#34](docs/ROADMAP-PHP.md#34--retire-the-remaining-define-constants)) |
| 2.1  | TS `any` reduction            | ✅ **Done**            | M         | 478 → 0; `no-explicit-any: error` enforced; `npm run typecheck` + `lint:js` clean                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| 2.2  | Vitest unit tests             | 🟡 **Not started**     | M         | TS unit-test runner + first wave                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| 2.3  | Bundle size budgets           | 🟡 **Not started**     | S         | per-entrypoint gzip limits in CI                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| 2.4  | Vendored library migration    | 🟡 **Not started**     | S         | Open Sans webfonts → `@fontsource` (scope shrunk after bundled-plugin removal)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| 3.1  | CSS design tokens + Stylelint | ✅ **Done**             | M        | all 13 steps shipped — `declaration-no-important: warning` reinstated; 689 → 101 `!important` (−85%); admin theme.css 9,521 → 9 lines                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| 3.2  | A11y audit (axe-core)         | 🟡 **Not started**     | M         | WCAG 2.1 AA gating                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

## Legend

| Tag                | Meaning                                                                                                        |
| ------------------ | -------------------------------------------------------------------------------------------------------------- |
| 🟡 **Not started** | Scheduled, no commits yet.                                                                                     |
| 🟢 **Active**      | Work in progress; one or more sub-tasks already shipped. The `▸ N / M` count shows shipped vs total sub-tasks. |
| ✅ **Done**        | Shipped; no further work expected on this scope.                                                               |
| 🔵 **Continuous**  | Opportunistic; no single checkpoint date. Applied as files are touched.                                        |
| 🟠 **On-demand**   | Passive backlog; trigger when a deployment, audit, or external need calls for it.                              |

Effort tags: **S** ≤ 1 day · **M** 2–7 days · **L** 1–3 weeks · **XL** > 3 weeks.

---

## Cross-cutting dependencies

Most sections are independent. The chains that aren't:

- **1.6b globals cleanup.** Gated by direct `$GLOBALS[...]` reads in `src/`
  being eliminated first. Both halves (bridge cleanup + renderer residuals)
  land together.
- **1.7 typed boundaries.** §1.4 shipped first, so Phase 1 (HTTP request
  DTOs) can now ride on the 95 typed `MethodDefinition`-registered WS
  endpoints rather than coordinating against an in-flight migration.
  (`#[ApiMethod]` decoration of per-domain endpoint classes is wired
  but deferred to a later §1.4 batch — Phase 1 doesn't need it.)
  Phase 2 (repository entity layer) closes out the globals work in §1.6b —
  `$user`, `$page` etc. become typed entity reads.
- **1.8 test infrastructure.** Pest (1.8.1) lands first because it changes
  the runner that 1.8.2 and 1.8.3 measure. Coverage (1.8.2) feeds Infection
  (1.8.3) — mutation testing's MSI is meaningful only once enough tests
  exist to mutate.
- **3.1 → 3.2.** CSS design tokens before a11y audit — color-contrast
  violations dissolve when tokens land, so most of the violation list
  resolves on its own.
- **1.4 phase 2 themes ↔ 3.1 step 8.** The skin refactor in 3.1 presumes
  the `theme.json` layout that lands in 1.4. Soft dependency, not blocking.

---

## 1. PHP backend

> **Working principle (continuous, no checkpoint):** when touching a service
> that still resolves dependencies via `ServiceLocator::get(...)`, migrate
> to constructor injection in the same commit. Not a discrete item — applied
> opportunistically as files are edited for any other reason.

### 1.1 Concrete bugs

**Status:** ✅ Done ▸ 9 of 9 · **Effort:** —

All 9 audit items closed. The cat-id access gap was closed without code
change on 2026-05-10 after an audit confirmed the psalm-level2 sweep
had already resolved it (see _Closed without code change_ below); the
history pagination refactor shipped on 2026-05-10 as Commits 2 + 3 on
`16.x-rewrite` (snapshot tests + 6-query split).

Verified by `vendor/bin/phpunit` (499 tests, 2419 assertions green) and
`vendor/bin/phpstan analyse` (clean).

#### Shipped

- ✅ **`Updates.php` redirect target.** Used `PHPWG_ROOT_PATH` (filesystem
  path) where a URL was needed, producing 404s when the path was
  non-empty. Switched to `UrlService::getRootUrl() . 'index.php?/upgrade'`
  to match `CommonBootstrap.php:162`. Note: roadmap originally suggested
  `UrlGenerator::admin('plugins')`, but the redirect lives in the _core_
  upgrade flow (post-zip-extraction, needs to run DB migrations) — going
  to plugins admin would have skipped the migration step.
- ✅ **Admin URL leaking into the gallery filter.** Public-gallery
  `SearchFilterRenderer` passed `UrlGenerator::admin() . '&page=album-'`
  to `getCatDisplayNameCache`, which 403'd guests. Passed `''` instead
  so the helper falls through to `makeIndexUrl(['category' => $cat])` —
  the public gallery URL.
- ✅ **Category position persistence.** Root cause: `Dml::massUpdates`
  with ≥10 rows builds a temp table with `UNIQUE KEY (id)`. If the
  caller passed the same id twice (e.g. UI double-fire), the
  temp-INSERT failed on the unique key and _no_ ranks persisted. Fixed
  by deduping `$datas` while building it (later writes overwrite earlier
  ones — user's final intended position wins).
- ✅ **Stub `cache_size` returning 4242.** `getInfos` now walks
  `_data/cache/` and memoizes via `PersistentCacheRegistry` for 5 min.
- ✅ **Image-id / filename precedence.** `HistoryAdminService::getHistory`
  ANDed both clauses when both were set, producing empty results when
  the IDs didn't intersect. Now `image_id` wins (filename is unset when
  image_id is present), matching the precedence already used in
  `PictureController:91-100`.
- ✅ **PERF — redundant `getAvailableTags()`.** Wrapped the no-args case
  in `RequestCache::remember` so all callers within one request share
  one result (eliminates redundant deserialize + render_tag_name event
  dispatch on hot pages like Tags, Search, Menubar).
- ✅ **PERF — history sort to SQL.** Pushed `ORDER BY date, time` into
  the SQL query and removed the now-unused `historyCompare` PHP
  comparator. (Originally one item with the LIMIT/OFFSET refactor — the
  deeper part is now tracked separately below.)
- ✅ **PERF — history pagination refactor (LIMIT/OFFSET path)** ·
  _shipped 2026-05-10_. Split `historySearch` into 6 dedicated SQL
  queries: `getHistoryCount`, `getHistoryTotalFilesizeForHigh` (INNER
  JOIN images), `getHistoryGuestIpHistogram`, `getHistoryUserHitCounts`,
  `getHistoryDistinctSearchIds`, `getHistoryPage` (`ORDER BY date DESC,
time DESC LIMIT/OFFSET`). The full filtered set used to be loaded
  into PHP per request (>~50 K rows on busy installs) — now only the
  current 300-row page is materialized. Page size is read from
  `Config::nbLogsPage()` (300 default) instead of the previous hardcoded
  `300` literal. Dead code removed: the `firstLine`/`lastLine` filter at
  `historySearch` line 678 (impossible AND, never fired) plus the
  post-loop `array_reverse + array_slice` pagination. Summary numbers
  are byte-identical to pre-refactor (locked down by 3 integration
  tests in `tests/Integration/HistorySearchTest.php`, 100 assertions).

#### Closed without code change

- 📕 **Search cat-id access gap** · _closed 2026-05-10_. Original
  description: "A search code path reads category IDs from a request
  shape that may be absent on first-load, returning empty results
  without an error. Fix: explicit `?? []` and guard the empty case." A
  full audit of every `cat_id` / `cat_words` / `categories` /
  `category_ids` read across search-related code on 2026-05-10
  confirmed every access path is already guarded with
  `is_array(...) ? : []` plus an emptiness check before SQL — so no
  silent empty-result path remains:
  - `src/Piwigo/Search/SearchController.php:94` — `inputInt('cat_id',
null, $_GET)` then explicit `if ($cat_id !== null)`; line 110
    `if (count($cat_ids) > 0 || in_array('cat', $fields))`.
  - `src/Piwigo/Search/SearchService.php:261-263` — `is_array(...) ?
... : []` on `searchFields['cat']` and `['cat']['words']`; line
    263 gates the SQL with `if (isset(...) and !empty($catWords)
and ...)`.
  - `src/Piwigo/Search/SearchFilterRenderer.php:449-452` — same guards
    plus `if (count($cat_words) > 0)` before any rendering.

  The psalm-level2 sweep landed the guards. Reopen only if a user
  reports first-load search returning empty silently against
  `16.x-rewrite`.

#### Verification

```bash
vendor/bin/phpunit            # 499 tests, 2419 assertions green ✅
vendor/bin/phpstan analyse    # clean ✅
```

---

### 1.2 Templates: hygiene → Latte → precompile

**Status:** ✅ Done ▸ Waves 1 + 2 + 3 all closed · **Effort:** XL (delivered)

**Why this is one section, not three.** The three waves operate on the
same artefacts (the ~135 `.tpl` files under `themes/`) and can't be
reordered: Wave 1's clean `.tpl` is what Wave 2 converts; Wave 2's
`.latte` output is what Wave 3 precompiles. If hygiene is skipped, its
bugs propagate verbatim into `.latte` (the converter is intentionally
faithful). If precompile lands without Latte, the warm-up tool only
covers Smarty during a transition window that's about to end.

**Why migrate at all.** Latte buys five things over Smarty:

1. **Context-aware escape-by-default.** The single biggest pain point in
   `Template.php` today is `escape_html = false` plus inconsistent manual
   escaping. Latte escapes per attribute/element/JS-string context
   automatically — the XSS surface collapses.
2. **Compile-time syntax checking.** Smarty fails at first render; Latte
   fails at compile, before any user request hits the page.
3. **Type-safe templates.** Templates can be declared against a typed
   context object (the page-context DTOs already exist).
4. **Sandbox mode.** Plugin-supplied templates can run under a
   `PiwigoPolicy` that whitelists allowed filters/functions/tags.
5. **Faster compilation, better IDE support, native PHP expressions.**
   Quality-of-life improvements throughout.

#### Wave 1 — Smarty hygiene on existing `.tpl`

**Status:** ✅ Done · **Effort:** M · 8/8

Eight ordered hygiene actions on the existing `.tpl` files, low-risk
first. These run **before** the Latte conversion so the converter sees
correct source — if skipped, the bugs propagate verbatim into `.latte`.

Suggested execution order (each row a separate commit):

| #   | Action                                                            | Files                                                             | Risk    |
| --- | ----------------------------------------------------------------- | ----------------------------------------------------------------- | ------- |
| 1   | Personal email leak in installer (this fork)                      | `themes/admin/_base/template/install.tpl:151`                     | trivial |
| 2   | Translation order bug                                             | `themes/admin/_base/template/batch_manager_global.tpl:55`         | trivial |
| 3   | Plural via `translate_dec`                                        | `themes/admin/_base/template/intro.tpl:112-117` and similar       | low     |
| 4   | Invalid HTML                                                      | `picture_modify.tpl`, `batch_manager_global.tpl`, `user_list.tpl` | low     |
| 5   | `http://` → `https://`                                            | `photos_add_applications.tpl` (4 links)                           | trivial |
| 6   | Delete dead browser code                                          | IE conditional `<link>` blocks; stale mail-css overlays           | low     |
| 7   | `\|@translate` → `\|translate` mechanical sweep                   | ~1203 occurrences across `themes/`                                | low     |
| 8   | `javascript:` URLs and inline `onclick` → data-attribute handlers | 5 sites; touches TS event binding                                 | medium  |

Concrete patterns for the trickier rows:

#### Row 1 — installer fork-private leak

```smarty
{* before *}
value="{if $F_ADMIN_EMAIL}{$F_ADMIN_EMAIL}{else}torres.dark@gmail.com{/if}"
{* after *}
value="{if $F_ADMIN_EMAIL}{$F_ADMIN_EMAIL}{else}{$DEFAULT_ADMIN_EMAIL|default:''}{/if}"
```

#### Row 2 — translation order

```smarty
{* before — calls sprintf first, then translates "Level 5" which doesn't exist *}
{'Level %d'|@sprintf:$thumbnail.level|@translate}

{* after — translates the format string, then sprintf-substitutes *}
{'Level %d'|translate|sprintf:$thumbnail.level}
```

The `@` prefix is legacy "do not auto-escape" — redundant when
`escape_html = false` already. Row 7 strips it everywhere.

#### Row 3 — plurals

```smarty
{* before — same string for "1 edition" and "5 editions" *}
title="{'%s editions'|translate:$number}"

{* after *}
title="{$number|translate_dec:'%s edition':'%s editions'}"
```

#### Row 8 — javascript: URLs

```smarty
{* before *}
<a href="javascript:phpWGOpenWindow('{$U_ORIGINAL}','xxx','scrollbars=yes,...')" rel="nofollow">
  {'Original'|translate}
</a>

{* after *}
<a href="{$U_ORIGINAL|escape:'html'}" data-popup="original" rel="nofollow noopener">
  {'Original'|translate}
</a>
```

```js
// in themes/_base/js/scripts.ts
document.addEventListener('click', e => {
  const a = (e.target as HTMLElement).closest<HTMLAnchorElement>('a[data-popup="original"]');
  if (!a) return;
  e.preventDefault();
  window.open(a.href, 'original', 'scrollbars=yes,toolbar=no,status=no,resizable=yes');
});
```

#### Wave 2 — Smarty → Latte conversion

**Status:** ✅ Done ▸ Phases A + B + C + D.\* + E + F.0 + F + F.1 all closed · 133 / 133 .latte · `smarty/smarty` removed; `Template`'s handle-based API replaced with direct `.latte`-path calls · **Effort:** XL (delivered) · depends on Wave 1

##### Phase progress

| Phase            | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Status                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A                | latte/latte 3.1, `TemplateEngine` interface, `LatteEngine`, `PiwigoExtension` (translate, translate_dec) + 7 unit tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| A.tooling        | `composer lint:latte` wrapper around `Latte\Tools\Linter` + parallel CI job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| A.tooling+       | `efabrica/phpstan-latte` vendored fork (PHP 8.5 + Latte 3.1 patches), engine bootstrap, custom `PiwigoLatteEngineResolver`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| B.1+B.2          | 18 PHP-passthrough filters + 8 stateless custom modifiers (l10n, explode, ternary, url_is_remote, is_admin, is_classic_user, get_device, get_gallery_home_url, get_extent)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| B.3              | 8 stateful asset functions (combineScript, getCombinedScripts, combineCss, getCombinedCss, defineDerivative, htmlHead, htmlStyle, footerScript) sharing `TemplateRegistry::current()` state                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| B.4              | prefilter_white_space + postfilterLanguage documented as intentionally not ported                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| B.5              | `LatteEngine::default()` factory using Piwigo's data location                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| B.6              | First template conversion: `themes/admin/_base/template/help.latte` (parallel with the .tpl) + 2 integration tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| C                | `tools/smarty-to-latte/convert.php` mechanical rewrite tool — 67 unit tests pin behavior, 40 private rewrite passes. Covers: foreach (with optional `name=`), dot-access (incl. PHP-property chains and `$arr.$varname` variable-index), if-not (any expression), Smarty operator keywords (`eq`/`neq`/`ne`/`gt`/`lt`/`gte`/`lte`/`is odd`/`is even`), `{else if}`→`{elseif}`, escape filter (any arg form), combine*script/css/get_combined*_, define_derivative, include path, printed-literal filter prefix, assign (named + positional, parenthesizes pipes), section→foreach, capture, literal, strip→spaceless, html_head/style/footer_script blocks, regex_replace→replaceRe, multi-arg pipe `:`→`,`, function definition (named + positional, dedupes Smarty 5 dual-form declarations), `$smarty.foreach.X.{index,iteration,first,last,total}`→`$iterator->_`, `$smarty.{now,server,cookies,capture}` residue rewrites, Smarty 5 `$item@index/iteration/first/last/total/key`iterator attributes,`{html_options}`/`{html_radios}`/`{math}`Smarty plugins →`htmlOptions`/`htmlRadios`/`math`PiwigoExtension function calls,`{counter}`strip,`{break}`idiom →`{breakIf}`, pipe-in-`{if}`, embedded `{$X}`print sub-tags inside tag args, backtick interp →`.`concat. **Audit-driven passes** (added during the 133-pair walkthrough):`addNoescapeToHtmlLiteralRepeats`(HTML literal`\|str_repeat:N`), `addNoescapeToHtmlBearingTranslations`(translate args containing markup),`addNoescapeToJsonScriptBlocks` (`<script type="application/json">`payloads). CLI driver`--force`flag (default skips existing`.latte`so manual`\|noescape` annotations aren't lost). | ✅ Done · audit walkthrough closed; remaining hand-fixes documented as non-generalisable (plugin HTML payloads, attribute-fragment vars, sprintf-built HTML, dynamic include scope-pass, `quick_search` arg-rename)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| D.admin          | Convert ~55 admin templates (`themes/admin/_base/template/`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | ✅ Done · **70 / 70 lint-clean**. Iteration 3 lifted the remaining 32 templates by registering `htmlOptions` / `htmlRadios` / `math` as PiwigoExtension functions (Smarty plugin ports), adding converter rules for `{counter}` strip, user-defined function call → `{include NAME, k: v, …}` rewrite, embedded `{$X}` print sub-tags inside `{if}`/`{elseif}`/`{var}`, Smarty backtick string interpolation → `.` concat (Latte's `~` is rejected inside function-call args), Smarty 5 `$item@index/iteration/first/last/total/key` iterator-attribute syntax, `{if X}{break}{/if}` → `{breakIf X}` idiom, pipe-in-`{if}` (`$x\|count`→`count($x)`), nested-`:{round(...)}` filter-arg unwrap, `$arr.$varname`(variable-index dot-access),`{capture assign=NAME}`keyword variant, and an extended args parser that accepts`key = value`(with whitespace) plus expressions containing`,` `:` `\|` `()`. Two templates needed hand-fixes that don't generalise: `header.latte`(JSON config script tag rebuilt with`\|json_encode\|noescape`since Latte rejects`{$X}`print-statements inside JS string literals) and`queue.tpl`source (HTML attribute quoting flipped to single-quoted to escape inner`"` chars in the Smarty translate-string literal).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| D.public         | Convert ~55 public theme templates (`themes/_base/template/` incl. `mail/`, `include/`, `help/` subtrees)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done · **55 / 55 lint-clean**. Iteration 4 added two converter rules (dot-access leading-expr now walks `->prop` PHP-property chains so `$block->data.qsearch` → `$block->data['qsearch']`; `combine_css` / `combine_script` regexes now allow nested `{...}` in path values for `path="…/{$themeconf.colorscheme}.css"`) and four PiwigoExtension filters (`count`, `strip_tags`, `str_repeat`, `default`, `date_format`) plus two functions (`url_is_remote` is now also exposed as a function, not just a filter; `l10n` likewise). Source-level hygiene fixes: `header.tpl` × 2 (`pwg-config` JSON now built via `[…]\|json_encode` array literal, working in both Smarty and Latte); `related_tags.inc.tpl` + `menubar_tags.tpl` (href-split-across-`{if}` rebalanced so each branch produces matching HTML — the original "split `<a … href=\\n{if}…\\n{/if}>`" idiom isn't representable in Latte's tag-aware parser). One hand-fix that doesn't generalise: `search.latte` blocks `{section name=day start=1 loop=32}` (one-off; .tpl source keeps Smarty form, .latte uses `{foreach range(1, 32) as $day}` and `--force` regen requires reapplying).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| D.standard_pages | Convert 7 templates in `themes/standard_pages/template/` (footer, header, identification, password, profile, register, toaster) + the orphan `themes/_base/local_head.tpl`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done · **8 / 8 lint-clean** on first conversion. The converter rules accumulated through D.admin + D.public covered every construct in this corpus — no rule additions, no source-level hygiene fixes, no hand-fixes required.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| E                | Implement `Piwigo\Template\Latte\PiwigoPolicy` sandbox for plugin-supplied templates                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | ✅ Done. `PiwigoPolicy` extends Latte's `Sandbox\SecurityPolicy` with two factory methods: `createPluginPolicy()` is the default-deny allowlist for plugin templates (permits structural tags, escape filters, the translation pair, read-only Piwigo helpers; denies `php`/`include`/`extends`/`do`, the asset-pipeline functions, filesystem-touching filters, `math()`, and opaque payload decoders); `createCorePolicy()` is the trusted-core superset that allows the asset-pipeline functions and `do`/`include`/`extends` while still keeping `{php}` denied. `LatteEngine` gained a `?Policy $policy` constructor arg + `LatteEngine::sandboxed()` factory that segregates the plugin compile cache (`templates_c/latte_plugin/`) from the trusted-engine cache so a malicious plugin can't poison core. 10 unit tests pin the allow/deny matrix and round-trip representative `SecurityViolationException` cases (`{php}`, `\|file_exists`, `combineScript()`) through a sandboxed engine. Plugin-loader integration shipped in §1.4 — `PluginRegistry::bootActive()` instantiates each `PluginInterface`, calls `boot($container)`, and registers `subscribedEvents()` against the Symfony dispatcher; plugin templates compile through `LatteEngine::sandboxed()` with `PiwigoPolicy`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| F.0              | Runtime engine routing facade in `Template::parse()`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | ✅ Done. `Template::parse()` dispatches by file extension: `.latte` paths route to a private `renderLatte()` that threads Smarty's accumulated template-vars through `LatteEngine::default()` and resolves the bare filename against Smarty's `template_dir`. Smarty plugin pre/post filters and `compile_id` language-cache keys are deliberately not applied to Latte — Latte caches by content hash and plugin extension landed separately in §1.4 (`AssetService` + per-plugin Vite manifests). All in-tree controller and service call-sites now register `.latte` filenames (last two flips landed in `MailService.php`: the `mail-css-{theme}` theme-overlay CSS check around line 541 and the dynamic `{tplFilename}` mail-content selector around line 575 — both line numbers drift as the file is edited; `grep -n 'mail-css-\\|tplFilename' src/Piwigo/Mail/MailService.php` finds the current anchor). The Smarty branch of the dispatcher is now unreachable from in-tree code; Phase F removes it along with the `smarty/smarty` dependency. **Hard-won lesson from an early reverted bulk flip:** lint-clean ≠ runtime-safe. 13 templates contained `$smarty.foreach.X.Y`, `$smarty.now`, `$smarty.server.X`, `$smarty.cookies.X`, `$smarty.capture.NAME` references — Smarty's implicit globals that lint-pass under Latte (the bracket form `$smarty['foreach']['X']` looks like a normal array access) but fail at render with "Undefined variable $smarty". Converter now rewrites all five residue families (matches both the dotted form and the bracketed form left by `rewriteSmartyDotAccess`); `rewritePrintedLiteralFilter` widened to accept function-call and parenthesized leading exprs so `{time()\|...}`and`{($\_SERVER['X'] ?? '') \|...}`get the`{=...}`print marker.`LatteEngine::default()`and`::sandboxed()`chmod their cache dirs 0o775 after creation so`\_data/templates_c/latte\*`is shareable between Apache (`www-data`) and CLI (developer); without the chmod, mode bits clamp the parent ACL mask to r-x and whoever creates the dir first locks the other out. Each controller flip got e2e validation, not just lint;`\|noescape`annotations on raw-HTML prints survive across`--force`regen at the .latte level only (the converter is intentionally faithful and never auto-adds`\|noescape`). |
| F                | Drop `smarty/smarty`; strip Smarty internals from `Template`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | ✅ Done. `Template` (1322 → 456 lines, –866) lost all Smarty machinery: 50+ `registerPlugin` / `registerFilter` calls, plugin handlers (`blockHtml{Head,Style}`, `blockFooterScript`, `funcDefineDerivative`, `funcCombine{Script,Css}`, `funcGetCombined{Scripts,Css}`), filter callbacks (`modcompiler{Translate,TranslateDec}`, `modExplode`, `modTernary`, `prefilterWhiteSpace`, `postfilterLanguage`, `prefilterLocalCss`), and the plugin-extension surface (`setExtent(s)`, `setPrefilter`/`setPostfilter`/`setOutputfilter`, `loadExternalFilters`/`unloadExternalFilters`). The `$this->vars` array replaces Smarty's variable bag; `$this->template_dirs` replaces `Smarty::setTemplateDir`; `parse()` always renders via `LatteEngine::default()`. Public API unchanged so callers don't move (Phase F.1). Companion deletes: `PwgTemplateAdapter` (Smarty's `$pwg.X` accessor — `derivative()` ported to `PiwigoExtension` as a Latte function so 4 templates that used `$pwg->derivative(...)` continue to work); 137 `.tpl` source files (133 in core themes + 4 sample plugin templates under `template-extension/distributed/samples/`); `smarty/smarty: ^5.0` from `composer.json`/`composer.lock`. New methods on `Template`: `templateExists($file)` replaces the public `$tpl->smarty->templateExists()` accessor used by mail rendering. Stale doc comments in `LatteEngine` and `TemplateEngine` updated to reflect the single-engine post-Phase-F state.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| F.1              | Migrate callers off `Template`'s handle-based API to direct `.latte`-path calls                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | ✅ Done. `Template::parse($handle, $return)` / `pparse($handle)` / `assignVarFromHandle($var, $handle)` were a Smarty-era inheritance — controllers always called `setFilename($handle, $file.latte)` immediately followed by `parse($handle, …)` in the same method, so the handle indirection added no value over passing the path directly. Public API now: `parse($file, $return = false)`, `pparse($file)`, `assignVarFromTemplate($var, $file)` — each takes the bare `.latte` filename (resolved against the registered template directories) or an absolute path. Companion deletes: `setFilename`, `setFilenames`, `assignVarFromHandle`, the `$files` map. ~30 consumer files migrated (controllers under `Controller/` + `Controller/Admin/`, plus `Page/*Renderer`, `Mail/MailService`, `Admin/Tabsheet`, `Admin/Integrity/CheckIntegrity`, `Admin/Notification/NotificationAdminService`, `Category/CategoryCatsRenderer` + `CategoryDefaultRenderer`, `Picture/PictureCommentRenderer` + `PictureContentRenderer`, `Tag/SelectedTagsRenderer`, `Menu/BlockManager`, `Core/Util`). Output buffer + `scriptLoader` / `cssLoader` + button accumulators + theme-search-path infrastructure stay where they are (page-coordination state, not engine-specific).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

##### Engine architecture

`composer require latte/latte` ✅ (3.1.4). Define a
`Piwigo\Template\TemplateEngine` interface both engines satisfy:

```php
interface TemplateEngine
{
    public function assign(string $name, mixed $value): void;
    /** @param array<string, mixed> $params */
    public function render(string $template, array $params = []): string;
}
```

**Caveat from delivery:** the original spec included
`parse(string $template): string` in the interface. It was dropped because
Smarty's `Template::parse(string $handle)` operates on registered
filename handles, not paths — wedging it into a path-shaped interface
would have forced a no-op stub on `LatteEngine` that adds no value over
`render()`. Revisit if a use case for "compile but don't execute" lands
(e.g., precompile tooling in Wave 3).

**Caveat:** `Template` (the Smarty wrapper) does NOT implement
`TemplateEngine`. `LatteEngine` is the sole implementer. The
interface is the forward-compatible contract for controllers that
move to engine-agnostic rendering; with Phase F set to delete the
Smarty wrapper entirely, the originally-planned `Template` →
`SmartyEngine` rename is moot.

`Piwigo\Template\LatteEngine` is the new engine. Two entry shapes are
in active use: `LatteEngine::default()->render($path, $params)` for a
small number of direct callers (the call-shape that doesn't need
Smarty's handle indirection), and the dispatcher inside
`Template::parse()` (delivered in Phase F.0) — when a `.latte` file
is registered via `setFilename()`, `parse()` routes to a private
`renderLatte()` that threads Smarty's accumulated template-vars
through `LatteEngine::default()` and resolves the bare filename
against Smarty's `template_dir`.

Latte configuration as actually shipped (Latte 3.1 deprecated
`setStrictTypes()` and `setTempDirectory()` — the spec's calls were
updated):

```php
$engine = new Latte\Engine();
$engine->setFeature(Feature::StrictTypes);     // was: setStrictTypes(true)
$engine->setCacheDirectory(PHPWG_ROOT_PATH . Config::dataLocation() . 'templates_c/latte/');  // was: setTempDirectory(...)
$engine->addExtension(new Piwigo\Template\Latte\PiwigoExtension());

// Sandbox not yet wired — see Phase E. The roadmap's setPolicy()
// call lands once we draft the PiwigoPolicy whitelist.
// $engine->setPolicy(new Piwigo\Template\Latte\PiwigoPolicy());
```

##### Smarty-plugin → Latte-extension port

Status of each Smarty registration in `Template.php` against
`Piwigo\Template\Latte\PiwigoExtension`. All ports landed in
`PiwigoExtension` (a single class) rather than the originally-planned
`AssetExtension` / `DerivativeExtension` split — it kept import surface
small and matched Latte's preferred extension-as-namespace pattern.

| Smarty                                                                                                                                                                                                                                                          | Latte equivalent                                                                                                                                                                                                                                                                                                                                                                   | Status                                                                      |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `\|translate`, `\|translate_dec`                                                                                                                                                                                                                                | filters in `PiwigoExtension` backed by `Piwigo\Core\Lang::t` / `Piwigo\Lang\Translator::plural`                                                                                                                                                                                                                                                                                    | ✅ Phase A                                                                  |
| `\|l10n`                                                                                                                                                                                                                                                        | filter alias for `\|translate` (same `Lang::t` callback)                                                                                                                                                                                                                                                                                                                           | ✅ Phase B.2                                                                |
| `\|sprintf`, `\|urlencode`, `\|intval`, `\|file_exists`, `\|constant`, `\|json_encode`, `\|json_decode`, `\|htmlspecialchars`, `\|stripslashes`, `\|in_array`, `\|ucfirst`, `\|trim`, `\|md5`, `\|strtolower`, `\|is_null`, `\|is_file`, `\|strpos`, `\|sizeOf` | filters dispatched directly to PHP first-class-callable functions                                                                                                                                                                                                                                                                                                                  | ✅ Phase B.1                                                                |
| `\|implode`, `\|str_replace`, `\|str_ireplace`, `\|preg_match`, `\|strstr`, `\|stristr`, `\|array_key_exists`                                                                                                                                                   | **deliberately omitted** — Smarty pipes the value first, but PHP wants `$glue`/`$search`/`$pattern` first; PHP 8's deprecation of swapped args makes the legacy form a TypeError. Verified zero pipe usage in `themes/` + `plugins/`; templates using these in Latte call them as expressions: `{=implode(',', $arr)}`.                                                            | ✅ Phase B.1 (intentional non-port)                                         |
| `\|explode`, `\|ternary`, `\|url_is_remote`, `\|is_admin`, `\|is_classic_user`, `\|get_device`, `\|get_gallery_home_url`, `\|get_extent`                                                                                                                        | one-line wrappers in `PiwigoExtension` delegating to existing services (`UrlService`, `PermissionService`, `Util`, `UrlGenerator`)                                                                                                                                                                                                                                                 | ✅ Phase B.2 + B.3 (get_extent)                                             |
| `{combine_script}`, `{get_combined_scripts}`, `{combine_css}`, `{get_combined_css}`, `{define_derivative}`                                                                                                                                                      | functions in `PiwigoExtension::getFunctions()`, called via `{do combineScript(...)}` (void) or `{var $x = defineDerivative(...)}` (returns); delegate to `TemplateRegistry::current()`'s `scriptLoader` / `cssLoader` so a `.latte` template's combine_script accumulates into the same bundle a `.tpl` template's would                                                           | ✅ Phase B.3                                                                |
| `{html_head}`, `{html_style}`, `{footer_script}` blocks                                                                                                                                                                                                         | functions in `PiwigoExtension::getFunctions()`, called as `{capture $x}…{/capture}{do htmlHead($x)}`; `htmlStyle` writes through `Template::appendHtmlStyle()` (added in Phase B.3 — the buffer is private and shared between the two engines)                                                                                                                                     | ✅ Phase B.3                                                                |
| `prefilter_white_space` filter                                                                                                                                                                                                                                  | **deliberately omitted** — Smarty-specific source rewrite; Latte handles whitespace differently and provides `{spaceless}` for explicit zones. Revisit only if profiling shows need.                                                                                                                                                                                               | ✅ Phase B.4 (intentional non-port, documented in PiwigoExtension docblock) |
| `postfilterLanguage` filter                                                                                                                                                                                                                                     | **deliberately omitted** — Smarty constant-folds `<?php echo 'literal'?>` after `Lang::t('key')` resolution in `compiledTemplateCacheLanguage` mode. Latte equivalent would be a NodeVisitor compiler pass that rewrites `{=$x\|translate}`to a literal when`$x` is a string-literal expression and language caching is enabled. Defer until profiling justifies the optimization. | ✅ Phase B.4 (intentional non-port, documented)                             |

**Filter coverage shipped:** 24 filters as of 2026-05-22 (verified by
counting `'name' =>` entries in `PiwigoExtension::getFilters()` — the
original 27 dropped a few that turned out to be unused by any
template after the conversion pass).

**Functions shipped:** 10 (`combineScript`, `combineCss`, `derivative`,
`footerScript`, `getCombinedCss`, `getCombinedScripts`, `htmlHead`,
`htmlOptions`, `htmlRadios`, `math`, `url_is_remote`, `l10n` — the
original 8 grew by `htmlOptions` / `htmlRadios` / `math` during the
D.admin iteration to absorb three Smarty plugins). Count from
`PiwigoExtension::getFunctions()`.

##### Latte partials and page-context DTOs

The pure rendering includes already exist as typed PHP services:

- `PageHeaderRenderer`, `PageTailRenderer`
- `PictureCommentRenderer`, `PictureMetadataRenderer`, `PictureRateRenderer`
- `NoPhotoYetRenderer`
- `SearchFilterRenderer`, `SelectedTagsRenderer`
- `CategoryCatsRenderer`, `CategoryDefaultRenderer`

Each becomes a `.latte` partial declared against its page-context DTO.
The DTOs (under `src/Piwigo/Page/Context/`) already exist as `readonly`
value objects — no controllers populate them yet, but the type contracts
are ready:

| DTO                                    | Properties                                                                      |
| -------------------------------------- | ------------------------------------------------------------------------------- |
| `AlbumPageContext`                     | `category`, `subAlbums`, `photos`, `pagination`, `baseUrl`, `section`           |
| `PicturePageContext`                   | `picture`, `relatedCategories`, `items`, `category`, `commentAction`, `urlSelf` |
| `SearchPageContext`                    | `query`, `filters`, `results`, `pagination`                                     |
| `TagsPageContext`                      | `tags`, `selectedTags`, `photos`, `displayMode`                                 |
| `AdminPageContext` _(base, non-final)_ | `pageTitle`, `pageMeta`, `themeAssets`, `flashMessages`                         |

Controllers populate the relevant DTO and pass it to the partial:

```php
return $this->engine->render('picture/index.latte', [
    'ctx' => new PicturePageContext(
        picture: $picture,
        relatedCategories: $relatedCategories,
        // …
    ),
]);
```

```latte
{templateType Piwigo\Page\Context\PicturePageContext $ctx}
<h1>{$ctx->picture->title}</h1>
{include 'partials/related-categories.latte', ctx => $ctx}
```

##### Conversion waves

Templates convert in waves, low-risk first:

1. **Admin templates** — ~55 files in `themes/admin/_base/template/`.
   Lowest user-visible blast radius; failures surface in admin sessions
   only.
2. **Public theme `_base`** — ~50 files in `themes/_base/template/`.
3. **`standard_pages`** — 7 files (login, register, password, profile)
   plus mail templates.

##### Mechanical conversion helpers

**Status:** ✅ Done (Phase C). The converter shipped at
`tools/smarty-to-latte/convert.php` (CLI driver) +
`tools/smarty-to-latte/Converter.php` (40 private rewrite passes,
67 unit tests). All 133 `.tpl` files converted. The rewrite table
below documents the foundational rules; the converter has accumulated
substantially more (audit-driven `|noescape` passes, Smarty 5 iterator
attributes, `{html_options}`/`{html_radios}`/`{math}` plugin ports,
`{counter}` strip, `{break}` idiom, `$smarty.*` residue families) —
see Phase C row above for the full inventory.

Foundational rewrites in `tools/smarty-to-latte/convert.php`:

| Smarty                                                                 | Latte                                                                                                                                                                                                                                                                                                                                                                                             |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `{if $foo}`                                                            | `{if $foo}` (compatible)                                                                                                                                                                                                                                                                                                                                                                          |
| `{if not $foo}` / `{if !$foo}`                                         | `{if !$foo}` — Latte rejects `not`; Smarty accepts both                                                                                                                                                                                                                                                                                                                                           |
| `{foreach from=$arr item=x}`                                           | `{foreach $arr as $x}`                                                                                                                                                                                                                                                                                                                                                                            |
| `{$x\|escape}`                                                         | `{$x}` (Latte escapes by default)                                                                                                                                                                                                                                                                                                                                                                 |
| `{$x\|escape:'none'}` or `{$x}` under Smarty's `escape_html=false`     | `{$x\|noescape}` — **important**: Smarty ran with `escape_html=false`, so every `{$x}` was raw. Latte auto-escapes by default; preserving the existing render contract requires inserting `\|noescape` on every print that holds pre-rendered HTML. The converter must distinguish "raw HTML var" from "user data var" — for now, conservative is "treat as raw" and let security review tighten. |
| `{'literal'\|translate}` (Smarty: bare prints with filter)             | `{='literal'\|translate}` — Latte requires the `=` prefix for printing string-literal expressions; bare `{'literal'\|translate}` is ambiguous with tag syntax                                                                                                                                                                                                                                     |
| `{include file=foo.tpl}`                                               | `{include 'foo.latte'}`                                                                                                                                                                                                                                                                                                                                                                           |
| `{$x\|@count}` (legacy `@` already stripped in Wave 1 Row 7)           | `{count($x)}`                                                                                                                                                                                                                                                                                                                                                                                     |
| `{section name=i loop=$arr}…{/section}`                                | `{foreach $arr as $i => $val}…{/foreach}`                                                                                                                                                                                                                                                                                                                                                         |
| `{capture name=foo}…{/capture}…{$smarty.capture.foo}`                  | `{capture $foo}…{/capture}…{$foo}`                                                                                                                                                                                                                                                                                                                                                                |
| `{literal}…{/literal}`                                                 | `{syntax off}…{syntax on}`                                                                                                                                                                                                                                                                                                                                                                        |
| `{combine_script id='x' load='footer' path='y.js'}`                    | `{do combineScript(id: 'x', load: 'footer', path: 'y.js')}` — uses Latte's PHP 8 named-args syntax inside `{do}`                                                                                                                                                                                                                                                                                  |
| `{combine_css path='x.css' order=-10}`                                 | `{do combineCss(path: 'x.css', order: -10)}`                                                                                                                                                                                                                                                                                                                                                      |
| `{define_derivative name='thumb' type='thumb'}` (Smarty mutates scope) | `{var $thumb = defineDerivative(type: 'thumb')}` — Latte function returns the value; caller assigns                                                                                                                                                                                                                                                                                               |
| `{html_head}…{/html_head}` (block)                                     | `{capture $head}…{/capture}{do htmlHead($head)}` — Latte has no equivalent block-tag in `PiwigoExtension`; the converter rewrites to capture + function call                                                                                                                                                                                                                                      |
| `{html_style}…{/html_style}` (block)                                   | `{capture $style}…{/capture}{do htmlStyle($style)}`                                                                                                                                                                                                                                                                                                                                               |
| `{footer_script require='common'}…{/footer_script}` (block)            | `{capture $script}…{/capture}{do footerScript($script, require: 'common')}`                                                                                                                                                                                                                                                                                                                       |
| `\|implode:','` (and other arg-reversed PHP fns)                       | `=implode(',', $arr)` — these aren't registered as filters (see plugin port table); the converter rewrites pipe form to function form                                                                                                                                                                                                                                                             |

The converter applies the rewrites file-by-file; hand-fix the residue
(custom modifiers, complex assignments, broken-on-purpose constructs).
Run the existing PHPUnit + browser smoke tests after each wave commit.

##### Findings folded in as design notes

Several issues from the earlier `.tpl` audit are folded into Latte rather
than fixed in Smarty — Latte's escape-by-default and sandbox solve them
systemically:

| Concern                                                                                                                                                                                                                                                             | Latte approach                                                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **XSS surface from inconsistent manual escaping** (the dominant security risk in the current `.tpl` set — every `{$var}` outside a `<script>`/`<style>` block missing an explicit `escape`/`translate`/`json_encode`/`urlencode` modifier is a potential injection) | Context-aware escape-by-default; sandbox + `PiwigoPolicy` for plugin-supplied templates. The audit reduces to "remove redundant escapes" rather than "find missed escapes." |
| **Markup in translation strings** (e.g. `'Return to <a href="…">Sign in</a>'\|translate\|replace:…` — translators must preserve HTML and a magic placeholder filename)                                                                                              | Handled at conversion: split markup out of `.po` keys via `{capture}` patterns; translation strings carry only `%s` placeholders that controllers fill with HTML.           |
| **`{section name=…}` legacy loop** in `search.tpl` (Smarty 5 still supports it but flags for removal)                                                                                                                                                               | Mechanical converter rewrite to `{foreach}`                                                                                                                                 |
| **Dynamic `{include file=$var}`** (plugin extension hook backed by `get_extent()`)                                                                                                                                                                                  | `TemplateExtensionRegistry` whitelist + Latte sandbox compile-time check; a path outside the project root or containing `..` rejects at compile time.                       |
| **Inline mail-CSS rendered through Smarty-in-Smarty** (`mail/text/html/header.tpl` includes `mail-css.tpl` whose body is itself templated)                                                                                                                          | Pre-render the mail CSS at deploy time; load as a static asset in mail templates. There's no plugin extension point that justifies the runtime path.                        |

Out of scope (informational — leave alone): HTML4 mail attributes
(`cellspacing`, `cellpadding` — still acceptable for Outlook), mixed
tab/space indentation in template files (`.editorconfig` covers new
edits).

##### Second-pass audit walkthrough (Wave 2 closeout)

After the bulk conversion (Phase D.\*), all 133 `.tpl` ↔ `.latte` pairs
were re-reviewed without skimming — full Smarty source against full
Latte source — to validate iteration / control flow, variable shape,
filter chains, tag-language args, `$smarty.*` residue rewrites, and
hand-fix preservation. The walkthrough produced four classes of
artefact, each landed in the codebase:

1. **Producer-side `Html` wraps.** Many vars whose values are
   pre-rendered HTML (built by controllers/services with `<a>`,
   `<em>`, `<br>`, etc.) now travel as `Latte\Runtime\Html` so Latte's
   auto-escape passes them through unmolested at every `{$VAR}` print
   site. Examples: `REDIRECT_MSG` (`Util.php:152`),
   `MAIL_CSS`/`GLOBAL_MAIL_CSS` (`MailService.php:563,569` via
   `assignVarFromHandle`), `getCombinedCss/Scripts`,
   `htmlOptions/htmlRadios`, `INFO_CREATION_DATE`,
   `INFO_POSTED_DATE`, `ELEMENT_CONTENT`, `related_categories[].name`,
   `categories_because_of_groups[]`, `STORAGE_CATEGORY`,
   `correction_msg`, `L_INSTALL_HELP`, `EMAIL` (installer),
   `category_search_results[]`. The trust travels with the value;
   reviewers don't have to guess which prints are safe.
2. **`PageState` collections widened to `list<string|Html>`.**
   `$errors`/`$warnings`/`$messages`/`$infos` accept either shape;
   HTML callers explicitly `new Html(...)` at the push site
   (`PasswordService:243`, `MiscController:453,455,590`,
   `AlbumController:490,660`, `BatchManagerController:798`,
   `ConfigurationController:286`, `ExtensionsController:320`,
   `MaintenanceController:522`, `UsersController:194`).
   `admin.latte` / `infos_errors.latte` foreach print bare `{$x}` —
   `Html` passes through, plain strings auto-escape.
3. **Audit-driven converter passes.**
   `addNoescapeToHtmlBearingTranslations` (translate args containing
   markup); `addNoescapeToHtmlLiteralRepeats` (`'<HTML>'|str_repeat:N`
   patterns); `addNoescapeToJsonScriptBlocks`
   (`<script type="application/json">…</script>` payloads);
   `rewriteSmartyDotAccess` extended to walk `->prop` PHP-property
   chains. Each pass is pinned by unit tests so regen-from-source
   reproduces the hand-fixes.
4. **15 documented hand-fixes that don't generalise.** Narrow cases
   where the converter cannot mechanically detect the right rewrite
   from the `.tpl` source alone: plugin HTML payloads
   (`PLUGIN_INDEX_*`, `PLUGIN_PICTURE_*`, `$footer_elements` debug
   HTML), attribute-fragment vars (`$link['REL']`, `$u['CHECKED']`),
   sprintf/`|cat:`-built HTML in `plugins_installed` /
   `themes_installed`, dynamic `{include $PATH, …}` scope-pass for
   plugin sub-templates, `{$block->raw_content|noescape}` for
   templateless menu blocks, `{$action['CONTENT']|noescape}` /
   `{$sheet['caption']|noescape}` defensive plugin entry points,
   `|replace:' ','<br>'|noescape` for HTML in replace values, and the
   `dark_mode` → `is_dark_mode` arg-rename in
   `batch_manager_filter.inc.latte` (Smarty's auto-propagation hid the
   bug; Latte's strict scoping surfaced it). These survive across
   `--force` regen at the `.latte` level only — converter's `--force`
   default-skips existing files for that reason.

##### Static-analysis tooling (Phase A.tooling, A.tooling+)

Two CI checkpoints catch Latte issues at PR time.

**`composer lint:latte`** — wrapper at `tools/latte-lint.php` around
`Latte\Tools\Linter`. The bundled linter validates every `\|filter`,
`function()`, class reference, and method call against a configured
engine; pointing it at the Piwigo engine (with `PiwigoExtension`
loaded) means it knows about our `\|translate`, `\|combineScript`,
etc. instead of warning "Unknown filter".

Two-process design: the linter is `final`, its `writeError()` writes
warnings to STDERR (not STDOUT), and its `lintLatte()` installs a
private per-file `set_error_handler` that suppresses E_USER_WARNING
propagation. None of those hooks let us cleanly fail the run on an
unknown filter from inside the same process — so `tools/latte-lint.php`
spawns `tools/_latte-lint-inner.php` as a subprocess and inspects its
stderr for `[WARNING]` / `[DEPRECATED]` markers; any hit fails the
build. CI job `latte` in `.github/workflows/ci.yml`.

**`vendor/bin/phpstan analyse`** — gains type-aware Latte coverage via
`efabrica/phpstan-latte`. The published versions cap PHP at `<8.5` and
Latte at `~3.0.25` (we run PHP 8.5.4 + Latte 3.1.4), so the package is
**vendored as a path-repo fork at `tools/phpstan-latte/`**. Two patches:

1. `composer.json`: relax `latte/latte` to `^3.0.25` and pin a
   synthetic `0.99.0` version so the path repo doesn't inherit the
   parent repo's git branch name.
2. `src/Compiler/Compiler/Latte3Compiler.php`: Latte 3.1 split the old
   single-call `TemplateGenerator::generate()` into `buildClass()` +
   `generateCode()`; adapt the call site.

`tools/phpstan/PiwigoLatteEngineResolver.php` is a custom
`NodeLatteTemplateResolverInterface` implementation. Out-of-the-box,
phpstan-latte's resolvers expect Nette presenter conventions and don't
detect Piwigo's bespoke `LatteEngine::render()` calls. Our resolver
catches `$engine->render(string $path, array $params)` on
`Piwigo\Template\TemplateEngine` implementations and registers
string-literal `.latte` paths with the analyser.

`tools/phpstan-latte-engine.php` is the engine bootstrap: it returns
the same configured `Latte\Engine` production uses (PiwigoExtension,
StrictTypes feature) so the analyser recognizes our filter set.

**Caveats baked into `phpstan.neon`:**

- `latte.errorPatternsToIgnore` silences three categorically-expected
  error families:
  1. Latte 3.1 runtime-side `iterable`/`array` shape comments on
     `$__filters__` / `$__functions__` / `$__variables__` (Latte's
     compiled output uses bare types in some signatures; not user
     template code).
  2. "Cannot automatically resolve latte template from expression"
     on `LatteEngine::render()` itself — the wrapper forwards a
     `$template` parameter, which phpstan-latte's built-in
     collector flags as unresolvable; resolution happens upstream
     on call sites that PiwigoLatteEngineResolver picks up.
  3. "Undefined variable: $X" + "Parameter (mixed) of echo cannot
         be converted to string" in `.latte` files —
         PiwigoLatteEngineResolver doesn't yet extract typed params
         from the call-site array, so every `{$varName}` appears
     undefined. Lifts once typed page-context DTOs flow through
     controllers (Phase D contracts).
- `tools/phpstan/StrictTypesRequiredRule.php` skips `.latte` files
  and the analyser's temp dir (`sys_get_temp_dir()/phpstan-latte/`),
  so the strict-types declaration rule doesn't fire on compiled
  template artefacts.

**Bug-injection probe (verify silencing didn't hide signal):**
replacing `\|translate` with `\|nosuchfilter` in `help.latte` still
produces `Undefined latte filter "nosuchfilter"` from phpstan-latte.

##### When to drop Smarty

Drop `smarty/smarty` from `composer.json` once all bundled `.tpl` are
converted (0 `.tpl` under `themes/_base/`, `themes/admin/_base/`,
`themes/standard_pages/`). The fork ships no plugins, so plugin-shim
and deprecation-window gating no longer applies.

Then: delete `Piwigo\Template\SmartyEngine`; remove the Smarty branch
from `Template::parse()` and the `$template->smarty` accessor.

##### Verification

```bash
find . -name '*.tpl' -not -path '*/_data/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' | wc -l
# 0 — .tpl sources deleted alongside smarty/smarty in Phase F.

composer show smarty/smarty 2>&1 | grep 'not installed'   # confirms removal
vendor/bin/phpunit                                         # green (497 tests)
composer lint:latte                                        # green (133 templates)
npx playwright test                                        # green (no visual regression)
```

#### Wave 3 — Precompile at deploy

**Status:** ✅ Done · **Effort:** S

`tools/precompile_templates.php` walks `themes/` recursively, resolves
each `*.latte` to an absolute path, and calls
`LatteEngine::default()->warmupCache($abs)`. Latte's compile cache keys
on the path passed to its loader; that key matches what
`Template::resolveLatteTemplatePath()` produces at runtime
(`Template.php:373-385`), so runtime hits the warmed entry directly.

`LatteEngine` gained a thin `warmupCache(string $name): void` wrapper
delegating to the private `Latte\Engine::warmupCache()` (matches the
existing `assign() / render() / renderFromString()` surface; no public
engine accessor leaks). The script does not call `Kernel::boot()` —
`Config::dataLocation()` falls back to its `'_data/'` default when
`Config::$data` is empty, so the CI job runs without a database.

A single `themes/` root rather than the originally-planned three
(`themes/admin/_base/template/`, `themes/_base/template/`,
`themes/standard_pages/template/`) covers all 133 `.latte` files
including the orphan `themes/_base/local_head.latte` (registered via
`theme.json`'s `localHead` key after §1.4 B14, resolved by
`Template::setTheme()` against the theme root, not its `template/`
subdir). The single-root walk also picks up §1.4 child themes
(`theme.json` + `Theme.php` per `ThemeRegistry` / `TemplateResolver`)
without further changes.

CI: a parallel `precompile` job in `.github/workflows/ci.yml` runs
`composer precompile:templates` after `composer install`, mirroring
the existing `latte` lint job. Kept distinct from `latte` because a
precompile failure is a strictly stronger signal than a lint warning,
and the separate job makes that distinction visible at-a-glance in
the workflow run.

Outcome delivered:

- First-request compile latency disappears for git-deploy / staging /
  CI flows that run the script.
- CI catches Latte syntax regressions at PR time. Bug-injection probe
  (`echo '{nope blah' > themes/_base/template/_probe.latte` →
  `composer precompile:templates`) exits 1 with the failed path on
  stderr.
- Cache is shareable between Apache and CLI: the cache dir already
  chmods 0o775 via `LatteEngine::ensureGroupWritable()` (introduced in
  Phase F.0).

##### Deferred to follow-ups

- **Tarball-install integration.** `tools/` is `export-ignore`d from
  `git archive`, so the script is dev/CI-only. The install/init flow
  is slated for a larger rework — a tarball-friendly install-time
  precompile hook folds in there, not here. Tarball end-users absorb
  a one-time first-request compile until then.
- **Plugin sandbox cache.** `LatteEngine::sandboxed()` warming
  (`templates_c/latte_plugin/`) stays parked: §1.4 shipped the plugin
  contract, but no in-tree plugins ship `.latte` templates yet. Re-warm
  once external plugins start landing under `plugins/`.
- **`template_compile_check = 0` flip.** The precompile is a
  prerequisite for the production toggle, but the toggle itself is
  config-schema work and fits §1.6c.
- **OPcache guidance / preload doc.** `_data/templates_c/latte/` holds
  plain PHP and benefits from OPcache; `opcache.max_accelerated_files`
  needs only ~150 entries for the current tree, and `opcache.preload`
  on the compiled templates yields a further small win. Capture in a
  hosting/deployment doc when one lands.

##### Verification (as shipped)

```bash
rm -rf _data/templates_c/latte/*
composer precompile:templates                                       # "Compiled successfully: 133 templates."
find _data/templates_c/latte -name '*.php' | wc -l                  # 133
vendor/bin/phpunit                                                  # 500 tests, 2422 assertions
composer lint:latte                                                 # 133 files, 0 errors
echo '{nope blah' > themes/_base/template/_probe.latte
composer precompile:templates ; echo $?                             # exit 1, failed path on stderr
rm themes/_base/template/_probe.latte
```

---

### 1.3 Kill ServiceLocator — constructor injection everywhere

**Status:** ✅ Done · **Effort:** L

`Piwigo\Core\ServiceLocator` was a static service-lookup shim over the
PHP-DI 7 container — ~1980 `ServiceLocator::get(Foo::class)` callsites
across `src/`, hiding dependencies and preventing typed plugin wiring.

**What shipped:**

- ✅ **Constructor injection class-by-class.** Every DI-managed service
  received explicit constructor parameters; `config/container.php`
  factories updated to pass them. Cycles (e.g.
  `CategoryService → HtmlService → UrlGenerator → UrlService →
HtmlService`) broken with `Kernel::service(X::class)` inline at the
  lowest-traffic edge rather than removing the dep from the constructor.

- ✅ **Static-context callers.** All-static classes (`Dml`,
  `UpgradeService`, `ImageStdParams`, `PageTailRenderer`, `CommonBootstrap`,
  etc.) converted to `Kernel::service(X::class)` inline. Pre-boot guards
  use `Kernel::isBooted() ? Kernel::service(...) : DbConnection::build()`.

- ✅ **`DbConnection::get()` eliminated.** Zero callers remain outside
  `DbConnection.php` itself. The two surviving `DbConnection::build()`
  calls are both legitimate pre-boot sites (`InstallService`,
  `ConfigService::loadConfFromDb`). Post-boot callers
  (`ImageDerivativeController`) migrated to constructor injection on
  the DI-managed `Connection`.

- ✅ **Tests migrated.** `KernelBootTest` and all other tests that
  called `ServiceLocator::has/get` updated to `Kernel::service()`.

- ✅ **`ServiceLocator.php` deleted.** `Kernel::setContainer()` hook
  removed from `Kernel::boot()` and `Kernel::reset()`.

- ✅ **`themes/` and `tools/` cleaned.** `themeconf.inc.php` SL calls
  replaced with `Config::raw()`; stale phpstan tool references updated;
  7 one-shot migration scripts deleted from `tools/`.

**Verification (as of completion):**

```bash
grep -rn "ServiceLocator" src/ tests/ config/ tools/ themes/ | wc -l   # → 0
grep -rn "DbConnection::get()" src/ tests/                              # → 0
```

---

### 1.4 Plugin / theme system + WS plugin surface

**Status:** ✅ Done 2026-05-16 ▸ all 19 batches (B0–B18) shipped on the
`16.x-rewrite` branch in 47 commits. **Effort:** L · 3 phases (delivered).

**Delivered.** The full contract landed: `PluginInterface` +
`PluginRegistry::bootActive()` + `plugin.json` schema (B7–B9), ~160
typed PSR-14 event DTOs under `src/Piwigo/Event/**` (count rolls up
as new events are added; 153 at B6 closure, 159 as of 2026-05-22) +
selective-mutability subscribers + 17 typed listeners (B1, B3–B6),
`ThemeInterface` + `ThemeRegistry` + `TemplateResolver` with five
bundled themes migrated to `theme.json` (B13–B14), 95 WS endpoints
registered via typed `MethodDefinition` in `WsMethodRegistrar` +
`SpecBuilder` reflecting on those definitions to emit
`/ws/openapi.json`, cebe-validated `SpecValidityTest` + redocly CI
gate (B15–B16). The `#[ApiMethod]` attribute and SpecBuilder's
`extractApiMethodAttribute` reflection are wired as infrastructure
for a later per-domain shard-out of `WsMethodRegistrar` (currently a
1413-LOC inline registration body — see comment at
`src/Piwigo/Ws/WsMethodRegistrar.php:25-27`); no endpoint yet
carries the attribute. Plus the legacy deletion pass (B17): ~2300
LOC of static `EventDispatcher`, `LoadedPluginRegistry`,
`PluginService`,
`PluginMaintain`, `ThemeMaintain`, `tools/triggers_list.php`,
frontend BC queues, and procedural plugin/theme stubs in
`tools/phpstan-bootstrap.php` retired alongside their typed
replacements. Plus the closure-record narrative in B18.

The rest of this section preserves the planning detail for historical
reference; commit history on the `16.x-rewrite` branch (B0 through
B18, plus the Phase 6.3 P3.1/P3.2/P3.3 follow-up) captures what
actually shipped per batch.

**Prerequisite landed.** `AppInfo::VERSION` was bumped from `16.3.0` to
`17.0.0` (commit `26377fe07`) so PEM's branch-16 entries no longer match
`getVersionsToCheck()`. All currently-installed extensions are now
flagged incompatible — the rewrite below replaces them on the fork's
own branch. See the **Fork identity** subsection for the runtime
implications.

**Strategy.** Three phases share one contract:

- **Phase 1 — Plugins.** `PluginInterface`, `plugin.json`, PSR-14 typed
  events, lifecycle methods, DI hookup.
- **Phase 2 — Themes.** Mirrors Phase 1 with `ThemeInterface`,
  `theme.json`, side-effect → `boot()` refactor.
- **Phase 3 — WS API enrichment.** Reflection-driven `#[ApiMethod]`
  reading and OpenAPI CI gate, riding on the registry Phase 1 builds.

Languages are out of scope — they're `.po` + `.lang` + `index.php` with
no behavior to gate; no manifest needed. **`piwigo16-tools` is also
out of scope** — it's a historical archive of external desktop/CLI
tools (Mac `.pkg`, Windows `.exe`, native `.so`, even `.jar`) that
talk to Piwigo over the WS API. None of them run inside the Piwigo
process; §1.4 doesn't touch them.

**No bridges, no deprecation window** — the 17.0 bump is the
deprecation. Plugins or themes without a valid `plugin.json` /
`theme.json` declaring `minPiwigo: "17.0"` are refused at load time
and listed under admin → Plugins → Incompatible.

**Reality check — this is a rewrite, not a port.** The PEM-mirror
audit (see "Migration walkthrough" below) shows current plugins use
**zero namespaces**, **zero readonly/nullsafe**, only 8 with
`declare(strict_types=1)`, only 1 arrow function, and **zero tests**.
Fork-targeted plugins are written from scratch in modern PHP against
the new contract — they're not legacy plugin code lightly translated.
Same for themes (113 themeconf-based, all PHP 5-era).

**Critical-path dependencies inside this section.** §1.4 doesn't
sequence inside itself — Phase 1 plugins, Phase 2 themes, Phase 3 WS
API. It also rides on §1.7 Phase 2 (repository entity layer): the
plugin API surface below exposes typed entity repositories (`Image`,
`User`, `Album`, `Tag`) so plugins don't reach `IMAGES_TABLE` /
`USERS_TABLE` constants directly. §1.7's typed-entity work is on the
critical path for §1.4, not just §1.3.

**Foundation already landed:**

- `src/Piwigo/Plugins/EventDispatcher.php` — static priority-bucketed
  event dispatcher with `addListener` / `dispatch` / `notify`. Maintains
  a `$GLOBALS['pwg_event_handlers']` bridge so legacy plugin code that
  writes the global directly still works.
- `src/Piwigo/Plugins/LoadedPluginRegistry.php` — replaces the legacy
  `$pwg_loaded_plugins` global.
- `src/Piwigo/Plugin/PluginRepository.php` + `PluginService.php` — DB
  persistence and current loader orchestration (loads `main.inc.php`
  directly; no `plugin.json` yet).
- `src/Piwigo/Theme/ThemeRepository.php` — DB persistence; no theme
  domain model yet.
- `src/Piwigo/Ws/OpenApi/ApiMethod.php` — `#[ApiMethod]` attribute is
  declared, no endpoint method uses it yet.
- `PwgServer::register(MethodDefinition)` is the only WS-method
  registration path; `addMethod()` was removed during the front
  controller migration.

**Still missing:**

- `Piwigo\Plugin\PluginInterface` + `Piwigo\Plugin\PluginRegistry`.
- `Piwigo\Theme\ThemeInterface` + `Piwigo\Theme\ThemeRegistry`.
- Typed event DTOs under `src/Piwigo/Event/`.
- A PSR-14 instance dispatcher (the existing one is static and tied to
  the `$GLOBALS` bridge — see Phase 1 "PSR-14 typed events").
- `plugin.json` and `theme.json` schema readers.
- Per-plugin and per-theme migration commits.

#### Phase 1 — Plugins

**Status:** ✅ Done 2026-05-16 ▸ shipped in batches B0–B12 / B14 / B16–B18 on `16.x-rewrite` (see §1.4 parent summary above; per-batch detail preserved below for traceability)

##### `PluginInterface`

One file per plugin (`src/Plugin.php`). Lifecycle methods live on the
same interface — there is no separate `Maintain` class. Empty bodies
are fine for plugins that don't need a given hook.

```php
namespace Piwigo\Plugin;

interface PluginInterface
{
    public function getId(): string;             // e.g. 'my-plugin'
    public function getVersion(): string;        // e.g. '1.4.0'
    public function getName(): string;           // human-readable

    public function boot(ContainerInterface $c): void;

    // Lifecycle — admin-triggered, not every request.
    public function install(): void;
    public function activate(): void;
    public function deactivate(): void;
    public function uninstall(): void;
    public function update(string $oldVersion, string $newVersion): void;

    /**
     * Symfony `EventSubscriberInterface`-compatible shape:
     *   class-string => method-name                            (default priority 0)
     *   class-string => [method-name, priority]                (single listener with priority)
     *   class-string => list<array{0: string, 1?: int}>        (multiple listeners on same event)
     *
     * Higher priority runs first. The handler method runs on `$this`;
     * **both `$this`'s constructor deps and the handler method's own
     * extra parameters are autowired by PHP-DI on every invocation**.
     * The first parameter is always the typed event object; any
     * additional typed parameters are resolved from the container at
     * call time:
     *
     *   public function onPictureRendered(
     *       PictureRendered $event,
     *       LoggerInterface $logger,   // autowired per-call
     *   ): PictureRendered { … }
     *
     * @return array<class-string, string|array{0: string, 1?: int}|list<array{0: string, 1?: int}>>
     */
    public function subscribedEvents(): array;
}
```

Worked example — a plugin that listens to two events with priority on
one and falls back to default on the other:

```php
public function subscribedEvents(): array
{
    return [
        PictureRendered::class => [
            ['onRendered', 100],   // runs before default-priority listeners
            ['logIt', -50],        // runs after them
        ],
        TemplateAssigned::class => 'onAssigned',  // shorthand, priority 0
    ];
}
```

The return value is passed straight to Symfony's `EventDispatcher`
(see "PSR-14 typed events" below); no wrapper logic in our code.

**Lifecycle ordering — explicit contract.** Confirmed from the PEM
mirror's `maintain.*.php` conventions:

- `install()` runs **once** on first install. Idempotent
  re-runs are permitted (and several PEM plugins re-run their
  `install()` body from `update()` to re-apply config defaults).
- `activate()` runs every time the plugin is turned on (idempotent).
  May run multiple times across the plugin's lifetime.
- `deactivate()` runs every time the plugin is turned off. Symmetric
  to `activate()`.
- `update($oldVersion, $newVersion)` runs when the loader detects a
  version mismatch between filesystem and DB. Receives the previous
  installed version so the plugin can run a step-wise migration.
- `uninstall()` runs **once** when the plugin is removed.
  `PluginRegistry` invokes `down()` on every migration before
  calling `uninstall()` (see "Plugin migrations" below).

##### PSR-14 typed events

The existing `Piwigo\Plugins\EventDispatcher` is **static** and routes
through `$GLOBALS['pwg_event_handlers']`. It can't be retrofitted into
PSR-14 cleanly because PSR-14 dispatchers are instance methods, and
the `$GLOBALS` bridge is incompatible with typed event objects. We
adopt **Symfony's `EventDispatcher`** (which implements PSR-14
natively and supports priority, propagation stop, and the
`EventSubscriberInterface` shape `subscribedEvents()` already uses).

1. `composer require symfony/event-dispatcher` — already in
   `composer.lock` transitively via `symfony/doctrine-messenger`,
   make it an explicit direct dep.
2. Bind `Symfony\Component\EventDispatcher\EventDispatcher` in the DI
   container under `Psr\EventDispatcher\EventDispatcherInterface`.
3. Add typed event DTOs under `src/Piwigo/Event/` — **one DTO per
   distinct event** that core or any current PEM plugin uses. The
   audit of `/home/torres/piwigo16-plugins/` (405 plugins) surfaces
   **144 distinct event names**, plus core's own events. The fork
   ships typed DTOs for **all of them** — plugin authors never reach
   for a string event name; if it isn't a typed event class, it isn't
   an event. The catalog is exhaustive on day one; partial coverage
   would push plugin authors back onto stringly-typed dispatch.

   DTOs are grouped by domain for navigability:

   ```text
   src/Piwigo/Event/
     Lifecycle/          # init, user_init, register_user, login_*, delete_*
     Location/           # loc_begin_*, loc_end_* (page-rendering hooks)
     Picture/            # PictureRendered, PictureDeleted, render_element_content
     User/               # UserLoggedIn, UserDeleted, UserCommentValidation
     Admin/              # AdminPagesRegistering, TabsheetBeforeSelect, etc.
     BlockManager/       # BlockManagerApply, BlockManagerRegisterBlocks
     Ws/                 # WsMethodsRegistering, WsInvokeAllowed
     Template/           # TemplateAssigned, RenderPageBanner
     # …
   ```

   The DTO names are derived case-by-case from each legacy event name
   (snake_case → PascalCase + domain prefix where ambiguous). A
   one-shot generator script in `tools/event-dtos/` reads the audit
   output and stubs the 144 classes; per-event work is then filling in
   the constructor parameter list (replacing the legacy positional
   `$arr` with named typed fields).

   Sample DTO:

```php
namespace Piwigo\Event\Picture;

final readonly class PictureRendered
{
    public function __construct(
        public int $pictureId,
        public string $renderedHtml,
    ) {}
}
```

**Modify-return events.** ~62% of legacy event handlers
(84/(84+52) `trigger_change` vs `trigger_event`+`trigger_notify`) use
the modify-return pattern: handler receives data, returns modified
data. PSR-14 doesn't natively express this, so the DTO carries
`with*()` clone-and-modify methods. **This pattern is mandatory** on
every typed event whose legacy form used `trigger_change`. Subscribers
mutate output by returning the new event instance:

```php
namespace Piwigo\Event\Template;

final readonly class TemplateAssigned
{
    public function __construct(
        public string $templateName,
        public array $params,
    ) {}

    public function withParam(string $key, mixed $value): self
    {
        return new self($this->templateName, [...$this->params, $key => $value]);
    }
}
```

1. Migrate the ~217 `Piwigo\Plugins\EventDispatcher::dispatch()` /
   `::notify()` callsites to dispatch typed event objects through the
   new instance dispatcher.
2. Once the last callsite is gone, delete
   `Piwigo\Plugins\EventDispatcher` and the
   `$GLOBALS['pwg_event_handlers']` bridge with it.

**Plugins as event sources, not just subscribers.** The PEM-mirror
audit surfaced ~40 distinct plugin-defined event names — plugins
firing their own events for other plugins to listen to (e.g.
`AdditionalPages` fires `AP_render_content` to 22 listeners,
`BatchDownload` fires three `batchdownload_*` events, `cas_users`
fires `cas_users_user_info`). Each plugin can ship its own typed
event DTOs under `plugins/<id>/src/Event/`. Other plugins subscribe
via the class-string key in `subscribedEvents()` exactly the same
way they subscribe to core events. The `require` graph in
`plugin.json` (see "Plugin dependencies" above) ensures the
event-source plugin loads before its listeners.

##### Fork identity

`AppInfo::VERSION` was bumped to `17.0.0` as preparation for this
section's work. Upstream Piwigo has no 17.x line, so the major version
alone is sufficient to signal "this is the rewrite fork" — no separate
`FORK_VERSION` constant is needed.

```php
// src/Piwigo/Core/AppInfo.php
public const string VERSION = '17.0.0';
```

The bump immediately marks every existing PEM extension as incompatible
(see 1.4 intro): `getVersionsToCheck()` queries PEM for a version whose
branch matches ours, finds nothing for branch 17, and returns empty.
That is the whole point of the bump — it lets us rewrite plugins,
themes, and languages one at a time against the fork's branch instead
of carrying along thousands of stock-16-only extensions.

Plugins and themes that target the fork declare `minPiwigo: "17.0"` in
their manifest (see below). The registry compares the manifest's branch
against `AppInfo::branchFromVersion(VERSION)` and rejects everything
that doesn't match — which is exactly the same gate as the PEM check,
applied locally at load time.

##### Declarative `plugin.json`

```json
{
  "$schema": "https://raw.githubusercontent.com/<fork>/piwigo16/16.x-rewrite/docs/schemas/plugin.schema.json",
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.4.0",
  "description": "What this plugin does, one line.",
  "homepage": "https://example.com/my-plugin",
  "author": "Jane Developer",
  "authorUri": "https://example.com",
  "license": "GPL-3.0-or-later",
  "minPiwigo": "17.0",
  "hasSettings": "webmaster",
  "require": {
    "piwigo": "^17.0",
    "plugin/GrumPluginClasses": "^4.0"
  },
  "main": "Piwigo\\Plugin\\MyPlugin\\Plugin",
  "autoload": { "psr-4": { "Piwigo\\Plugin\\MyPlugin\\": "src/" } }
}
```

The seven metadata fields above the `minPiwigo` line map 1:1 to the
header block PEM plugins already embed in `main.inc.php`:

| `main.inc.php` header | `plugin.json` key | Required |
| --------------------- | ----------------- | -------- |
| (directory basename)  | `id`              | yes      |
| `Plugin Name:`        | `name`            | yes      |
| `Version:`            | `version`         | yes      |
| `Description:`        | `description`     | yes      |
| `Plugin URI:`         | `homepage`        | no       |
| `Author:`             | `author`          | no       |
| `Author URI:`         | `authorUri`       | no       |
| `Has Settings:`       | `hasSettings`     | no       |
| (no legacy header)    | `license`         | yes      |

`hasSettings` is constrained to four values matching the PEM mirror
distribution (96 × `true`, 38 × `"webmaster"`, 9 × `false`, plus
one `"Webmaster"` typo we normalize). `homepage` and `authorUri` are
validated as URLs; `version` is validated as
SemVer-or-PEM-revision-string. `license` is a required SPDX
identifier (e.g. `"GPL-3.0-or-later"`, `"MIT"`, `"Apache-2.0"`) —
the PEM-mirror audit found only 120/405 plugins state a license
explicitly today; 309 ship without any license boilerplate, which
the fork's lint rejects to avoid distributing unlicensed code as if
it were GPL. The fork-specific additions (`id`, `minPiwigo`,
`license`, `require`, `main`, `autoload`, `$schema`) have no legacy
header equivalent — they're new structure that the loader needs.

##### Plugin dependencies

The PEM mirror surfaces a small but real graph of inter-plugin
dependencies — ~30 edges total, with two clear "library plugin" hubs:

- **`GrumPluginClasses`** is required by **11+ plugins** (AMenuManager,
  AMetaData, ASearchEngine, AStat, ColorStat, EStat, GMaps, Histogram,
  lmt, UserStat, FormattedDescription, mypolls, translator).
- **`IndexManager`** is required by 4 (ComOnIndex, nbc_EditoOnIndex,
  nbc_LogonOnIndex, nbc_TagsOnIndex).
- A long tail of bilateral deps (`SocialConnect` → `oAuth`,
  `LocalFilesEditor` → `PersonalPlugin`, `PWG_Stuffs` → `piclens`, etc.)

Today these are expressed as raw `include` from
`PHPWG_PLUGINS_PATH . 'OtherPlugin/...'`. The new mechanism uses
the `require` field in `plugin.json` (composer-style constraint
strings):

```json
"require": {
  "piwigo":                   "^17.0",
  "plugin/GrumPluginClasses": "^4.0",
  "plugin/IndexManager":      "^17.0"
}
```

`PluginRegistry::load()` builds the dependency graph at boot,
topologically sorts plugins, and refuses to load anything whose
declared `require` constraints aren't satisfied (missing plugin,
wrong version). Cycle detection produces a structured error.

The full inter-extension graph (which plugin → which plugin, which
theme → which parent theme) is maintained in `docs/EXTENSIONS.md`
under a dedicated section, regenerated by `tools/audit-extension-deps.php`.

`minPiwigo` is required. `PluginRegistry` compares its branch against
`AppInfo::branchFromVersion(AppInfo::VERSION)` and rejects anything that
doesn't match — so a manifest with `minPiwigo: "16.0"` (the stock line)
is refused by design, while `"17.0"` (or later, once the fork advances
its branch) is accepted. Directories without `plugin.json` at all are
refused for the same reason: stock-16 plugins have no manifest and are
not loaded.

`Piwigo\Plugin\PluginRegistry` reads the manifest, registers PSR-4
autoload, instantiates the main class, and calls `boot()`. The plugin
admin UI reads `plugin.json` instead of parsing `main.inc.php` headers.

##### Manifest schema validation

Ship `docs/schemas/plugin.schema.json` and
`docs/schemas/theme.schema.json` as first-class repository artifacts.
Validate manifests at load time with **`opis/json-schema`** (draft
2020-12 compliant, structured errors with JSON path + violation type).

Plugin and theme authors reference the schema via `$schema` for IDE
autocomplete and pre-commit validation:

```json
{
  "$schema": "https://raw.githubusercontent.com/<fork>/piwigo16/16.x-rewrite/docs/schemas/plugin.schema.json",
  "id": "my-plugin",
  "version": "1.4.0",
  ...
}
```

`PluginRegistry::load()` rejects a manifest with structured errors:

```text
plugins/foo/plugin.json: validation failed
  /minPiwigo  required property missing
  /autoload/psr-4  must be object, got array
```

The schema file IS the contract; ad-hoc validation code can't drift
out of sync with it. `composer require opis/json-schema` adds the
dep; no further infrastructure.

##### WS-method registration

Plugins that expose Web Service methods subscribe to a
`WsMethodsRegistering` typed event and call `register(MethodDefinition)`
on the provided server. (`PwgServer::addMethod()` was removed during
the front controller migration; it no longer exists.)

The event itself:

```php
namespace Piwigo\Event;

final readonly class WsMethodsRegistering
{
    public function __construct(public PwgServer $server) {}
}
```

It fires from `PwgServer::populateMethods()` after core methods are
registered and before they're sorted — the same point in the lifecycle
as the legacy `ws_add_methods` hook.

Plugin handler:

```php
public function onMethodsRegistering(WsMethodsRegistering $event): void
{
    $event->server->register(new MethodDefinition(
        name:         'pwg.my.method',
        callback:     $this->myEndpoints->myMethod(...),
        description:  'Description shown in the API browser',
        params:       [
            ParamDefinition::required(name: 'photo_id', type: WsType::Int->value | WsType::Positive->value),
        ],
        tags:         ['my'],
        access:       AccessLevel::Administrator,   // see "granular auth" below
        hidden:       false,
    ));
}
```

The handler shows up in `subscribedEvents()` keyed by
`WsMethodsRegistering::class`. `$this->myEndpoints` is a typed
constructor dep (no `ServiceLocator` lookup — see §1.3).

**Granular auth.** Legacy plugins set `'admin_only' => true` and
`'hidden' => true` as separate flags in `addMethod()`'s options array
(seen on ~30 plugin-registered methods in the PEM mirror).
`MethodDefinition`'s typed `access` field replaces the coarse
`requiresAuth: bool` with `Piwigo\Core\AccessLevel`:

```php
enum AccessLevel: int {
    case Guest         = 0;   // unauthenticated reads
    case Normal        = 1;   // any logged-in user
    case Administrator = 2;   // requires check_status(ACCESS_ADMINISTRATOR)
    case Webmaster     = 3;   // requires check_status(ACCESS_WEBMASTER)
}
```

`hidden: true` keeps a method out of the public OpenAPI listing
(plugin-private methods only). Both fields default to `Normal` /
`false`.

##### Migration walkthrough — generic plugin

Existing layout (legacy plugin) — counts from the PEM mirror at
`/home/torres/piwigo16-plugins/` (405 plugins total):

```text
plugins/<id>/
  main.inc.php             # entry point — 404/405 have one
                           # contains the header block (Plugin Name:, Version:,
                           # Description:, …) parsed as plugin metadata, then
                           # add_event_handler() registrations.
  maintain.inc.php         # function-style lifecycle (142/405).
  maintain.class.php       # class-style lifecycle extending PluginMaintain
                           # (104/405). Modern of the two; 159 have neither.
  admin.php                # admin-tab handler routed via
                           # admin.php?page=plugin-<id> (224/405).
  language/                # bundled .po/.lang files (341/405).
  template/                # Smarty .tpl files (209/405; 872 .tpl files total
                           # across the mirror).
  include/                 # internal classes / event handlers.
```

Post-migration:

```text
plugins/<id>/
  plugin.json              # declarative manifest
  src/
    Plugin.php             # implements PluginInterface (incl. lifecycle)
  template/                # Latte .latte files
```

Steps to migrate one plugin (one commit each, in order):

1. Move source under `plugins/<id>/src/` with PSR-4 namespace
   `Piwigo\Plugin\<Pascal>\`. Drop the conventional
   `include/functions.inc.php` / `include/events.inc.php` /
   `include/admin_events.inc.php` split — let class names carry
   intent.
2. Convert `main.inc.php` event-handler registrations to a `Plugin`
   class with `subscribedEvents()`. Fold lifecycle methods from
   `maintain.inc.php` or `maintain.class.php` (`install` / `activate`
   / `deactivate` / `uninstall` / `update`) onto the same `Plugin`
   class.
3. Add `plugin.json`.
4. **Convert templates to Latte.** Run
   `tools/smarty-to-latte/convert.php` on the plugin's `template/`
   directory. The PEM mirror has **1,218 plugin `.tpl` files** to
   process across the ecosystem. The §1.2 converter handled core's
   133 templates; the plugin-side surface is wider and the converter
   must support these Piwigo-specific Smarty constructs (counted
   across plugin `.tpl` files):

   | Smarty form                | Plugin uses | Latte target                              |
   | -------------------------- | ----------: | ----------------------------------------- |
   | `{combine_script ...}`     |         391 | `{combineScript(...)}` (Piwigo extension) |
   | `{combine_css ...}`        |         381 | `{combineCss(...)}`                       |
   | `{footer_script}…{/foot…}` |         219 | `{block footer-scripts}…{/block}`         |
   | `{html_head}…{/html_head}` |          76 | `{block html-head}…{/block}`              |
   | `{html_style}…{/html_st…}` |          73 | inline `<style>` block                    |
   | `{html_options ...}`       |         139 | `{foreach}` over an options array         |
   | `{known_script ...}`       |          22 | `{knownScript(...)}` (Piwigo extension)   |
   | `{lang ...}`               |          88 | `{=$x\|translate}` or `{=t($x)}`          |
   | `{ldelim}` / `{rdelim}`    |         338 | literal `{` / `}` (Latte: `{l}` / `{r}`)  |
   | `\|translate` modifier     |       1,318 | `\|translate` filter (Piwigo extension)   |
   | `\|cat` (concat)           |         212 | Latte `~` operator                        |

   Extend `tools/smarty-to-latte/convert.php` with these rules if it
   doesn't already cover them. The converter is intentionally faithful
   — no `\|noescape` auto-injection (same policy as the core
   conversion).

5. **Convert language files to `.po`.** Plugins currently ship
   `.lang.php` (key→string PHP arrays) — **5,916 files** across the
   mirror. Run `tools/lang-php-to-po/convert.php` to produce
   `language/<locale>/plugin.po` per locale. The English-string keys
   plugins use with `l10n(...)` survive verbatim as msgid; the new
   `LangService::t($msgid)` reads the same string.
6. **Convert JS to TypeScript.** The PEM mirror ships **1,424 `.js`
   files** across 143 plugins. The fork policy is **all plugin JS is
   TypeScript**; run `tools/js-to-ts/convert.php` (port of the same
   `tsc --noEmit` + manual-touchup loop core uses) and ship
   `.ts` sources only. Build through Vite (see "Plugin assets" below).
7. **Replace global reads.** 227/404 plugins read `global $page` or
   `global $user` directly. Replace with `Piwigo\Page\PageState` and
   `Piwigo\Users\CurrentUser` injected via constructor; same for
   `$conf` reads (118 plugins use array-shaped config via
   `conf_update_param`).
8. **Migrate DB schema work.** If the legacy `maintain.*.php`
   contains raw `CREATE TABLE` / `ALTER TABLE` (82 / 61 of the 405
   plugins do), port each statement to a versioned migration file
   under `plugins/<id>/migrations/` (see "Plugin migrations" below).

##### DI for plugins

Plugins receive the container in `boot()` and register their own services:

```php
public function boot(ContainerInterface $c): void
{
    $c->set(Piwigo\Plugin\OpenStreetMap\TileService::class, /* … */);
    // listener methods on this class get auto-resolved deps via reflection
}
```

`ContainerInterface` here is `Psr\Container\ContainerInterface`,
backed by the PHP-DI 7 container that §1.3 establishes as the only DI
mechanism. Plugins receive it in `boot()` for late-bound resolution;
their own `Plugin` class also declares typed deps in its constructor
like any core service, autowired by PHP-DI.

##### Modern plugin API surface

`boot(ContainerInterface $c)` is the only entry point plugins use to
reach core. The container exposes typed services that replace every
legacy procedural function plugins currently call directly. The set
below covers ~98% of legacy plugin API usage in the PEM mirror; the
counts are the total callsite count across all 405 plugins (in both
`main.inc.php` and `include/*.php`).

| Service (typed, via `$c->get(…::class)`)                                                            | Replaces legacy function(s) / global                                                                | Plugin calls today             |
| --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ------------------------------ |
| `Piwigo\Db\DbConnection` (Doctrine DBAL)                                                            | `pwg_query`, `query2array`, `mass_inserts`, `mass_updates`                                          | 1315                           |
| `Piwigo\Lang\LangService`                                                                           | `load_language`, `l10n`, `l10n_dec`                                                                 | 2700+                          |
| `Piwigo\Url\UrlService`                                                                             | `get_root_url`                                                                                      | 292                            |
| `Psr\EventDispatcher\EventDispatcherInterface`                                                      | `trigger_change`, `trigger_event`, `trigger_notify`                                                 | 136                            |
| `Piwigo\Core\StringUtil`                                                                            | `safe_unserialize`, `safe_serialize`                                                                | 87                             |
| `Piwigo\Config\ConfigService`                                                                       | `conf_update_param`, `conf_delete_param`, `$conf[…]` reads                                          | 65+ writes, 800+ reads         |
| `Piwigo\Page\PageState`                                                                             | `global $page`, `$page['errors'][]`, `$page['infos'][]`                                             | 227 globals reads              |
| `Piwigo\Users\CurrentUser`                                                                          | `global $user`, `is_admin`, `is_webmaster`                                                          | (same 227)                     |
| `Piwigo\Users\UserCacheService`                                                                     | `invalidate_user_cache`                                                                             | 34                             |
| `Piwigo\Users\UserService`                                                                          | `get_username`, `get_user_language`, etc.                                                           | ~10                            |
| `Piwigo\Session\SessionService`                                                                     | `$_SESSION[…]` direct reads/writes                                                                  | 49 plugins                     |
| `Piwigo\Csrf\CsrfService`                                                                  | `get_pwg_token`, `check_pwg_token`                                                                  | 39 plugins                     |
| `Psr\Log\LoggerInterface`                                                                           | `pwg_log`                                                                                           | (low; coarsely tracked)        |
| `Piwigo\Image\DerivativeService`                                                                    | `DerivativeImage`, `get_derivative_url`, `derivative_path` reads                                    | 77 plugins                     |
| `Symfony\Contracts\HttpClient\HttpClientInterface`                                                  | `curl_init`/`curl_exec` raw, `fetchRemote`                                                          | 33 plugins                     |
| `Piwigo\Mail\MailService`                                                                           | `pwg_mail`                                                                                          | 25 plugins                     |
| `Psr\Cache\CacheItemPoolInterface` (Symfony `cache.app`)                                            | `cache_set`/`cache_get`/`PERSISTENT_CACHE`                                                          | 5 plugins                      |
| `Piwigo\Storage\LocalStorage`                                                                       | `PWG_LOCAL_DIR` reads, `_data/` writes, raw `mkdir`/`file_put_contents` for plugin data             | 82+47+54+11 ≈ 194 hits         |
| `Piwigo\Session\FlashService`                                                                       | one-shot `$page['infos'][]` / `$page['errors'][]` writes survived across a redirect                 | (post-redirect-GET pattern)    |
| Typed entity repositories (`ImageRepository`, `UserRepository`, `AlbumRepository`, `TagRepository`) | direct table reads (`IMAGES_TABLE` 425, `USERS_TABLE` 133, `CATEGORIES_TABLE` 214, `TAGS_TABLE` 45) | 800+ table-constant references |

(Some services already exist in `src/Piwigo/…/`; `UserCacheService`,
`PageState`, `CurrentUser`, `SessionService`, `CsrfService`, and
the entity repositories land as part of Phase 1's first commit
batch — the latter folded with §1.7 Phase 2 work.)

Plus, on the response side — 343 plugins call `redirect()` or set the
`Location` header directly. The new model returns a typed
`Psr\Http\Message\ResponseInterface` from the controller; for the
common case, a `RedirectResponse::to($url)` factory keeps it short.
12 plugins use `$_FILES`; the new model receives typed
`Psr\Http\Message\UploadedFileInterface` from the PSR-7 request.

**Non-service legacy calls** plugins must inline:

- `format_date($ts)` → `(new \DateTimeImmutable("@$ts"))->format(...)`
  with `LangService` for localized format strings (36 callsites).
- `generate_key($len)` → `bin2hex(random_bytes((int) ceil($len / 2)))`
  (16 callsites).

**Explicitly NOT carried into the new API:**

| Legacy surface                                      | New mechanism                                                                              |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `$GLOBALS['…']` reads/writes                        | typed services + PSR-7 request/response                                                    |
| `$conf` array access                                | `ConfigService` (typed reads, validated writes)                                            |
| top-level `pwg_query()`                             | `DbConnection` from the container                                                          |
| `$page['errors'][] = …`                             | typed `PageState` accumulator on the response                                              |
| `pwg_log(…)`                                        | PSR-3 `LoggerInterface` from the container                                                 |
| `set_status_header()`, `redirect()`                 | return `ResponseInterface` from the handler                                                |
| `script_basename()` (113 callsites)                 | PSR-15 controllers know their route via request URI                                        |
| `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` constants | `RequestContextRegistry::current() === RequestContext::Admin\|Ws\|Upgrade` (Phase 4c §Z22) |
| `PHPWG_ROOT_PATH` (1,041 file uses)                 | constructor-inject `Piwigo\Core\PathService` or `string $rootPath`                         |
| `defined('PHPWG_ROOT_PATH') or die(...)` guard      | gone — controllers run only when routed                                                    |

Plugin authors that grep positively for any of those forbidden
patterns during a pre-publish `composer piwigo:lint` step (planned,
not yet built) get rejected. The PEM listing for branch 17 only
surfaces plugins that pass the lint AND ship a passing test suite
(see "Pre-publish gates" below).

##### Pre-publish gates

Zero of the 405 current PEM plugins have any tests. For the fork's
branch-17 PEM listing to be meaningful, plugins targeting it must
clear two CI-style gates on every published revision:

1. **Lint** — `composer piwigo:lint`. Greps source for the forbidden
   patterns above (`$GLOBALS[`, `$conf[`, top-level `pwg_query`, raw
   `IMAGES_TABLE`/`USERS_TABLE` reads, `script_basename`, etc.). Also
   validates `plugin.json` against `docs/schemas/plugin.schema.json`.
2. **Tests** — `vendor/bin/phpunit` (or Pest from §1.8.1) exits zero.
   `tests/` is required. The fork ships a `PluginTestCase` base class
   that boots a sandboxed container with mocked services so plugins
   can test their event handlers, admin controllers, and migrations
   without spinning a real DB. Minimum coverage threshold gets set
   later (probably 50%); the day-one requirement is just "a test
   suite that runs and passes."

The PEM-side endpoint that lists plugins for a fork install filters
out anything that fails either gate, so plugins without tests
literally aren't discoverable on the fork — same enforcement
mechanism as the `minPiwigo: "17.0"` check.

##### Plugin assets — TypeScript, CSS, Vite

Plugins ship 1,424 `.js` files and put 195 plugin entries into the
admin and frontend CSS stack today via `combine_script` (22),
`combine_css` (39), and `set_filenames` (208 combined invocations).
The new asset pipeline:

1. **TypeScript only, and jQuery-free.** Plugin JS is `.ts` source;
   `.js` ships only as Vite build output (under
   `plugins/<id>/dist/`). No raw hand-written JS in `src/`. Existing
   plugin JS migrates via `tools/js-to-ts/convert.php`.

   The mirror's reality: **105 of 143 JS-shipping plugins use
   jQuery** today; 87 use TinyMCE; 19,247 LOC of plugin JS in total.
   The conversion is two-step, not one:
   - **`.js → .ts`** — type the existing logic.
   - **jQuery → vanilla DOM** — `$(selector)` → `document.querySelector`,
     `.on('click', ...)` → `addEventListener`, `$.ajax` → `fetch()`,
     `$.cookie` → `document.cookie` helper, etc. Mechanically retyping
     jQuery as `JQueryStatic` defeats the point — the fork has no
     jQuery dependency.
   - **TinyMCE stays** (rich-text editing is non-trivial to replace),
     but accessed through a typed wrapper around the upstream
     `@tinymce/tinymce-webcomponent` package — no global namespace
     reach.

2. **Vite integration.** Each plugin declares its entry points in
   `plugin.json`:

   ```json
   "assets": {
     "entries": {
       "admin":  "src/admin/index.ts",
       "public": "src/public/index.ts"
     },
     "css": ["src/admin/admin.css", "src/public/public.css"]
   }
   ```

   Core's Vite config picks these up at build time; the plugin's
   `dist/manifest.json` records the hashed asset paths.

3. **Runtime injection.** Plugins inject assets via the typed
   `Piwigo\Asset\AssetService`:

   ```php
   public function onAdminPagesRegistering(AdminPagesRegistering $event): void
   {
       $this->assets->registerEntry('my-plugin/admin');
   }
   ```

   The service reads `dist/manifest.json` and emits the right
   `<script type="module">` / `<link rel="stylesheet">` tags. No
   string-concat of asset URLs; no `combine_script`/`combine_css`.

4. **Build hooks.** A plugin's `composer build:assets` runs Vite
   over its entry points and writes to `dist/`. `PluginRegistry`
   refuses to load a plugin whose `dist/manifest.json` is missing
   or out of date relative to its `src/`.

##### Plugin migrations

**82 plugins ship `CREATE TABLE`, 61 `ALTER TABLE`, 90 `DROP TABLE`**
in `maintain.*.php`. Today this is raw `pwg_query()` SQL inline in
the lifecycle methods — no versioning, no rollback, no replay
visibility. The new system uses **Doctrine migrations**, one file per
schema change, under `plugins/<id>/migrations/`:

```text
plugins/community/
  migrations/
    Version20260601_120000_CreatePermissionsTable.php
    Version20260710_093000_AddStorageColumn.php
```

Each migration extends `Doctrine\Migrations\AbstractMigration` and
implements `up()` + `down()`. `PluginRegistry`:

- Runs un-applied `up()` migrations on `activate()`.
- Runs forward `up()` migrations on `update()`, from the recorded
  current state to head.
- Runs `down()` migrations in reverse on `uninstall()` (or skips them
  with a warning if `keepData: true` was passed by admin UI).

The applied-migration ledger is per-plugin, stored in a typed
`piwigo_plugin_migrations` table (plugin_id, version, applied_at).
Plugins without DDL don't need a `migrations/` directory at all.

##### Plugin translations

**5,916 `.lang.php` files** (key→string PHP arrays) across the PEM
mirror. Core uses `.po`; plugins do not. The fork standardises on
`.po`:

1. **Conversion.** `tools/lang-php-to-po/convert.php` walks each
   plugin's `language/<locale>/` directory and emits
   `language/<locale>/plugin.po`. The English-string keys plugins use
   (`l10n('Closed icon position')`) become `msgid` verbatim; the
   `.lang.php` value becomes `msgstr`. No transformation of the
   actual translation strings.
2. **Runtime.** Plugins look up translations via the typed
   `LangService::t()`:

   ```php
   $label = $this->langService->t('Closed icon position');
   ```

   `LangService` discovers the plugin's `.po` files via
   `PluginRegistry::getPath($pluginId) . '/language/'` — no explicit
   `load_language()` call needed. The 335 legacy `load_language()`
   sites collapse into autoload.

3. **Plurals.** Legacy `l10n_dec($singular, $plural, $n)`
   (13 callsites) maps to `LangService::l10nDec($singularKey,
   $pluralKey, $decimal)` — same gettext `ngettext`-shaped contract,
   PHP name aligned with the existing `l10n()` accessor.

##### Admin pages

224 of 405 PEM plugins have an `admin.php` handler reached via the
URL convention `admin.php?page=plugin-<id>`. The new mechanism
replaces both that URL hack and the 142-instance
`get_admin_plugin_menu_links` event with a typed registry:

```php
public function subscribedEvents(): array
{
    return [
        AdminPagesRegistering::class => 'onAdminPagesRegistering',
    ];
}

public function onAdminPagesRegistering(AdminPagesRegistering $event): void
{
    $event->registry->add(new AdminPage(
        id:         'my-plugin',
        label:      'My Plugin',
        controller: $this->adminController,         // RequestHandlerInterface
        menuGroup:  AdminMenuGroup::Plugins,
        permission: AdminPermission::Webmaster,
    ));
}
```

The admin menu reads its entries directly from the `AdminPage`
registry — plugins don't subscribe to a separate menu event. URLs
move from `admin.php?page=plugin-<id>` to `/admin/plugin/<id>`,
routed through the front controller. The `controller` is any
PSR-15 `RequestHandlerInterface`; the plugin's constructor-typed
deps are autowired.

For plugins that need multiple admin pages (sub-tabs in the legacy
model), call `$event->registry->add(...)` multiple times with
different `id` and `label`.

**Granular permissions.** The legacy admin codebase uses
`check_status(ACCESS_ADMINISTRATOR)` (75 hits across plugin
`admin.php` files) and `ACCESS_WEBMASTER` (8 hits). The new system
uses the same `Piwigo\Core\AccessLevel` enum that `MethodDefinition`
uses (defined above under WS-method registration), exposed here as
`AdminPermission` for narrative parity:

```php
enum AdminPermission: int {
    case Guest         = AccessLevel::Guest->value;
    case Normal        = AccessLevel::Normal->value;
    case Administrator = AccessLevel::Administrator->value;
    case Webmaster     = AccessLevel::Webmaster->value;
}
```

`AdminPage::permission` is the **minimum** access level. The router
issues 403 for under-permissioned requests before the controller
runs. `CsrfMiddleware` (from §1.5) gates all `POST`/`PUT`/`DELETE` to
plugin admin pages; 39 plugins already use `pwg_token` checks today,
and they migrate to the new `CsrfService` (see the API surface
table above).

**GET-render / POST-handle / redirect — the controller convention.**
236 PEM plugins have `<form method="post">` in their admin templates;
43 use `{$F_ACTION}` (post-to-same-URL); 343 plugins call
`redirect()` after a successful save. The PSR-15 controller mirrors
that idiom:

```php
final class MyPluginAdminController implements RequestHandlerInterface
{
    public function __construct(
        private ConfigService     $config,
        private TemplateRegistry  $templates,
        private FlashService      $flash,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $this->config->confUpdateParam('my_plugin', $request->getParsedBody());
            $this->flash->success('Saved.');
            return RedirectResponse::to((string) $request->getUri());
        }
        return $this->templates->current()->render('admin/my-plugin.latte', [
            'config' => $this->config->confGetParam('my_plugin'),
        ]);
    }
}
```

`FlashService` is a typed session-backed one-shot message store —
read once by the next request, then cleared. Replaces the legacy
`$page['infos'][]` / `$page['errors'][]` array writes for the
post-redirect-GET case.

##### Removal note

Old plugins are not deprecated — they're refused. `PluginRegistry::load()`
skips any directory lacking a valid `plugin.json` and lists it under
admin → Plugins → Incompatible. No `E_USER_DEPRECATED`, no carry-over
release: the 17.0 version bump is the removal signal. The rewrite
cookbook for plugin authors goes in `docs/PLUGIN-DEVELOPMENT.md` (to be
added alongside this section's first commit).

#### Phase 2 — Themes

**Status:** ✅ Done 2026-05-16 ▸ shipped in batches B13–B15 on `16.x-rewrite` (`ThemeInterface` + `ThemeRegistry`, declarative `theme.json`, inheritance via composition; see §1.4 parent summary above)

##### `ThemeInterface`

Mirrors `PluginInterface` plus theme-specific methods:

```php
namespace Piwigo\Theme;

interface ThemeInterface
{
    public function getId(): string;             // 'standard_pages'
    public function getVersion(): string;
    public function getName(): string;
    public function getParentId(): ?string;      // null for root themes
    public function loadParentCss(): bool;
    public function getAssetDir(string $kind): string;   // 'img', 'icon', 'mime_icon'
    public function getLocalHeadTemplate(): ?string;

    public function boot(ContainerInterface $c): void;

    public function install(): void;
    public function activate(): void;
    public function deactivate(): void;
    public function uninstall(): void;
    public function update(string $oldVersion, string $newVersion): void;

    /** @return array<class-string, string|array{0: string, 1?: int}|list<array{0: string, 1?: int}>> */
    public function subscribedEvents(): array;
}
```

##### Declarative `theme.json`

```json
{
  "$schema": "https://raw.githubusercontent.com/<fork>/piwigo16/16.x-rewrite/docs/schemas/theme.schema.json",
  "id": "standard_pages",
  "version": "1.0.0",
  "name": "Standard Pages",
  "parent": "_base",
  "loadParentCss": false,
  "assets": {
    "img": "images",
    "icon": "icon",
    "mimeIcon": "icon/mimetypes"
  },
  "localHead": "local_head.latte",
  "main": "Piwigo\\Theme\\StandardPages\\Theme",
  "autoload": { "psr-4": { "Piwigo\\Theme\\StandardPages\\": "src/" } }
}
```

The fork renamed the upstream root theme `default` → `_base` to
mirror the `themes/_base/` directory convention already in use for
the bundled frontend skin. PEM-mirror themes currently declaring
`"parent": "default"` get rewritten to `"parent": "_base"` during
migration; all other parent names (`Pure_default`, `gally-default`,
`stripped`, `PwgCarbon_dft`, etc.) survive verbatim — they're
non-root themes that aren't renamed.

**Multi-level inheritance is real.** The PEM mirror has 47 themes
parenting on `_base` (was `default`), but **30+ more themes parent
on other non-root themes**: 9 on `Pure_default`, 6 on
`gally-default`, 4 on `OS_default`, 3 on `stripped`, 3 on
`PwgCarbon_dft`, plus a long tail. Inheritance chains of depth 3 or
more exist (theme → `Pure_default` → `_base`). `ThemeInterface::getParentId()`
already supports this; `ThemeRegistry` walks the chain at boot to
build the asset-resolution order.

##### Side-effect code → `Theme::boot()`

Today, `themes/standard_pages/themeconf.inc.php` runs `$this->assign(...)`
and `Config::raw(...)` at file-include time (ServiceLocator calls were
removed in §1.3, but side-effects still fire at include scope). That
code moves into `boot()` where it has DI access:

```php
final class Theme implements ThemeInterface
{
    public function boot(ContainerInterface $c): void
    {
        $config = $c->get(ConfigService::class);
        $template = $c->get(TemplateRegistry::class)->current();
        $template->assign('themeColor', $config->confGetParam('standard_pages.color'));
    }
}
```

Event handlers registered from `themeconf.inc.php` move into
`subscribedEvents()`.

##### Inheritance via composition

`Theme` always has a `?ThemeInterface $parent`; methods walk up the chain:

```php
public function getAssetDir(string $kind): string
{
    return $this->assets[$kind] ?? $this->parent?->getAssetDir($kind) ?? '';
}
```

This mirrors how `themeconf.inc.php` array merge already works and avoids
forcing third-party themes to extend a base class. (Alternative
considered: class inheritance via `extends DefaultTheme`. Rejected
because it forces a brittle hierarchy on third-party authors.)

**Template-file resolution.** PEM-mirror themes ship **selective
overrides**, not full template trees — typical overrides are
`header.tpl` (46 themes), `footer.tpl` (49), `picture.tpl` (40),
`mail-css.tpl` (69), `local_head.tpl` (42), `comments.tpl` (41), etc.
`ThemeRegistry` wires a `Piwigo\Theme\TemplateResolver` that walks
the parent chain at lookup time to find each template file:

```php
final readonly class TemplateResolver
{
    public function __construct(private ThemeInterface $current) {}

    public function resolve(string $relativePath): string
    {
        for ($t = $this->current; $t !== null; $t = $t->getParent()) {
            $candidate = $t->getRootPath() . '/template/' . $relativePath;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        throw new TemplateNotFoundException($relativePath);
    }
}
```

Latte's `FileLoader` is configured to call through `TemplateResolver`,
so `{include 'header.latte'}` resolves correctly regardless of which
theme owns the rendered file. The chain depth is bounded by the
parent-graph the registry validates at boot (no cycles).

##### Theme switch event

```php
namespace Piwigo\Theme;

final readonly class ThemeChanged
{
    public function __construct(
        public string $oldThemeId,
        public string $newThemeId,
    ) {}
}
```

Plugins subscribe to this instead of using procedural hooks.
`ThemeRegistry::activate($id)` dispatches it after the swap.

##### Bundled themes to migrate

- `themes/_base` (frontend default)
- `themes/standard_pages`
- `themes/admin/_base`
- `themes/admin/light`
- `themes/admin/dark`

One commit per bundled theme. Scope across the wider PEM-mirror
ecosystem: **1,495 theme `.tpl` files** and **230 theme `.js`
files** need converting if/when third-party themes follow.

1. Add `theme.json` (rename `default` → `_base` in `parent` if
   present).
2. Add `Theme` class under `themes/<id>/src/` (or
   `themes/admin/<id>/src/`).
3. Move `themeconf.inc.php` side-effects into `boot()`.
4. Convert `ThemeMaintain` callers to the new lifecycle methods.
5. **Convert templates to Latte.** Bundled-theme templates landed in
   §1.2 Wave 2 (D.public / D.standard_pages). PEM-mirror themes
   convert through the same `tools/smarty-to-latte/convert.php`
   pipeline as their respective theme migrations land.
6. **Convert theme JS to TypeScript.** 47 of 113 PEM-mirror themes
   ship JS (230 `.js` files total). Same `tools/js-to-ts/convert.php`
   used for plugins.
7. Delete `themeconf.inc.php`. Themes without `theme.json` are
   refused by `ThemeRegistry`; there is no `$themeconf` array to
   reach for.

##### Soft dependency on 3.1

The CSS skin refactor in 3.1 step 8 presumes the `theme.json` layout —
specifically the per-skin `assets:` map. Whichever lands first sets the
layout the other adopts.

#### Phase 3 — WS API enrichment

**Status:** 🟢 Partially done 2026-05-16 ▸ CI-gate half shipped (95 WS endpoints typed via `MethodDefinition` + `SpecBuilder` → OpenAPI 3.1 at `/ws/openapi.json` + cebe-validated `SpecValidityTest` + redocly CI gate). The `#[ApiMethod]` attribute-decoration half is **deferred**: the attribute class at `src/Piwigo/Ws/OpenApi/ApiMethod.php` and SpecBuilder's `extractApiMethodAttribute` reflection are wired, but no endpoint method carries the attribute yet — `WsMethodRegistrar` still registers all 95 endpoints inline (see comment at `src/Piwigo/Ws/WsMethodRegistrar.php:25-27`). The two sub-tasks below split accordingly.

Once plugin handlers are reflection-accessible classes (which they
become as part of Phase 1), two follow-ups land.

##### `#[ApiMethod]` attribute reading

The attribute class at `src/Piwigo/Ws/OpenApi/ApiMethod.php` is already
defined but dormant — no endpoint method uses it yet. Phase 3 work:

1. Decorate every endpoint method with `#[ApiMethod(...)]`:

```php
final class ImagesEndpoints
{
    #[ApiMethod(
        summary: 'Search images by tag, date, or filename.',
        responseClass: ImageSearchResponse::class,
        tags: ['images', 'search'],
    )]
    public function search(array $params): array { /* … */ }
}
```

1. Teach `SpecBuilder` to walk registered endpoint classes via
   reflection, read the attribute, and emit richer OpenAPI metadata
   than what `MethodDefinition` carries today.

##### CI gate validating the generated spec

Two complementary gates:

- **`cebe/php-openapi`** as `require-dev` — runs inside PHPUnit, so
  devs catch malformed specs pre-push without waiting for CI. Validates
  full schema semantics including `$ref` resolution.
- **`@redocly/cli` lint** as a CI step — the industry-standard OpenAPI
  linter. Catches style + spec violations that pure schema-validity
  doesn't surface (unused components, weak descriptions, deprecated
  patterns). Node toolchain already runs in CI for ESLint/stylelint,
  so no new infrastructure.

The two cover different rule depths on the same generated spec, so
both stay green together or both flag a regression.

##### Verification

```bash
# Spec served via the WS endpoint
curl -s 'http://localhost/index.php?/ws?_openapi=json' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"))->info->title;'
# → Piwigo Web Services

# Event dispatcher conforms to PSR-14:
php -r 'echo (new Piwigo\Event\EventDispatcher) instanceof Psr\EventDispatcher\EventDispatcherInterface ? "ok" : "fail";'

# E2E with the empty plugins/ tree:
npx playwright test
```

---

### 1.5 Security hardening

**Status:** ✅ Done 2026-05-22 ▸ all 4 waves shipped on `16.x-rewrite`
in 4 commits. **Effort:** M (delivered).

**Delivered.** CSRF middleware was already in tree from the
front-controller work (`CsrfMiddleware` validating `pwg_token` on POST
with an allow-list for `/ws`, `/admin`, `/install`, `/upgrade`,
`/identification`, `/register`, `/qsearch`); §1.5 added the rest of
the posture:

- **Wave A** (`56b671e1b`) — `SessionBootstrap.php` now sets
  `SameSite=Lax`, `HttpOnly`, and a request-scheme-conditional
  `Secure` flag via the array form of `session_set_cookie_params`.
- **Wave B** (`e64282b79`) — `piwigo_user_failed_logins` table +
  `UserFailedLoginRepository` + `LoginThrottle` (5 failures /
  15 min lockout, default thresholds hardcoded; config keys gated
  on §1.6c) + `LoginRateLimiterFactory` over the existing
  `symfony/cache` pool (sliding-window 5/min per IP, 10/10 min per
  account). Wired into `AuthService::pwgLogin`,
  `IdentificationController`, and the WS-API `LoginHandler`
  (returns `PwgError(429)` on rate-limit or lockout). Bypassed
  under `TestMode` so the integration suite can log in repeatedly
  from loopback. `AuthException::accountLocked()` /
  `rateLimited()` named ctors + `ActivityAction::LoginFailureLocked`
  case. Includes 8 integration tests covering the repo + throttle
  paths.
- **Wave C** (`dc1f59d6f`) — `SecurityHeadersMiddleware` at
  position 0 of the PSR-15 pipeline emits CSP (`default-src 'self'`
  with `img-src 'self' data: blob:`, `style-src-attr 'unsafe-inline'`
  for the inline `style="--var:value"` CSS-variable bridges,
  `script-src 'self'` with no nonce since every `<script>` in tree
  is `type="application/json"`), `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
strict-origin-when-cross-origin`, `Permissions-Policy:
geolocation=(), microphone=(), camera=()`, and HSTS
  (`max-age=31536000; includeSubDomains`) only when
  `$_SERVER['HTTPS']` is on. Plus
  `tools/check-no-executable-inline-scripts.php` + `composer
lint:no-inline-scripts` CI guard scanning `themes/**/*.latte`
  (mail templates excluded — never HTTP-served).
- **Wave D** (`6aff94432`) — new `docs/SECURITY.md` covering
  reporting workflow (GitHub private advisories at
  `darktorres/piwigo16`), three-group threat model mapped to
  defenses in tree, response-header + session-cookie + lockout
  reference tables, plugin/theme-author auth notes, CSP-override
  stub, and a known-gaps list (admin unlock UI, configurable
  thresholds, HSTS preload, `__Host-` / `__Secure-` cookie
  prefixes, CSP reporting, per-plugin CSP relaxation hook).

Verified by `composer lint:php` clean, `vendor/bin/phpstan analyse`
clean, `vendor/bin/phpunit` 1272 tests / 17656 assertions green (up
from 1259 / 16566 at the start of §1.5).

**Polish landed 2026-05-22** (3 follow-up commits after the
retrospective deep review):

- `ceffd2257` — `Http\RequestScheme` helper gated on
  `PIWIGO_TRUSTED_PROXIES` env var. Honours `X-Forwarded-Proto` for
  the `Secure` cookie flag + HSTS emission and walks
  `X-Forwarded-For` right-to-left for the per-IP rate-limit bucket.
  Empty allow-list (the default) = forwarded headers ignored entirely.
  9 unit tests including the spoof-rejection regression guard.
- `644317b66` — extract `Http\SecurityHeaders` (single source of
  truth for header shapes); reduce `SecurityHeadersMiddleware` to a
  `foreach`; call `SecurityHeaders::emitDirect()` from each
  short-circuit branch in `index.php` (`install`, `upgrade`,
  `upgrade_feed`, `i/`) so the install + upgrade wizards now carry
  CSP/XFO/XCTO/Referrer-Policy too. New `FastPathHeadersTest`
  exercises `/index.php?/install` against live Apache.
- `be409d2e3` — `docs/SECURITY.md` aligned with the polish work +
  three new Known-gaps bullets (trusted-proxy handling, COOP/COEP/CORP,
  CSP escape-hatch fragment-merge contract).

Post-polish suite: 1282 tests / 17676 assertions green.

#### Deferred follow-ups (carried forward, not blockers)

**Config / UX gaps:**

- **Configurable lockout / rate-limit thresholds.** Gated on §1.6c
  (config-schema metadata). Currently hardcoded in `LoginThrottle`
  and `LoginRateLimiterFactory`.
- **Admin UI for unlocking users.** Register an `AdminPage` for the
  unlock UI via the §1.4 `AdminPagesRegistering` event.
- **HSTS `preload`** — deployment-policy decision, not a code change.
- **`__Host-` / `__Secure-` cookie prefixes + unconditional `secure`**
  — gated on a future "force HTTPS" config flag.
- **CSP `report-uri` / `report-to`** — no reporting endpoint
  designed yet; revisit if production violations appear.

**§1.7-gated cleanups (typed HTTP DTO refactor):**

- **Typed HTTP response for the WS rate-limit error.** Currently
  `PwgError(429, …)`; typed `Response` body comes with §1.7.
- **Eliminate the `PageState::loginFailureReason` back-channel.**
  `AuthException::accountLocked()` is defined but not yet thrown —
  the locked-account branch in `IdentificationController` /
  `LoginHandler` currently detects via the side-effect flag
  `PageState::current()->loginFailureReason === 'account_locked'`.
  When §1.7 promotes the WS / form login paths to throw-and-catch,
  fold the named ctor in and delete the flag. (Review finding F5.)

**CSP / headers — defer-until-needed:**

- **Per-plugin CSP relaxation hook.** Currently
  `SecurityHeadersMiddleware` uses `withHeader` (replace, not
  append), so a controller or plugin middleware that sets its own
  `Content-Security-Policy` is silently overwritten. Eventual
  contract: inner code appends to a per-response CSP-fragment
  attribute; outer middleware merges fragments into the final
  header value. Surface lives on `PluginInterface::boot()` once the
  first concrete plugin justifies designing it. (Review finding F7.)
- **`Cross-Origin-*` response headers** (`Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy`, `Cross-Origin-Embedder-Policy`).
  Each one breaks a concrete UX flow — popup-window flows for COOP,
  third-party hotlinking for CORP, etc. Defer until a concrete
  deployment justifies the breakage. (Review finding N7.)
- **`Permissions-Policy` long tail.** Currently only blocks
  `geolocation` / `microphone` / `camera`. Could tighten to also
  block `fullscreen`, `payment`, `usb`, `serial`, `bluetooth`,
  `idle-detection`, `browsing-topics`, `interest-cohort`. Each one
  has plausible plugin use cases though — keep permissive on UX APIs
  by default. (Review finding N8.)
- **Shape-enforce `style-src-attr`.** `style-src-attr 'unsafe-inline'`
  was added for inline `style="--var:value"` CSS-variable bridges
  but does NOT restrict inline styles to that shape — the browser
  accepts any inline `style=""` attribute. Latte autoescaping is
  the only line of defense, so `|noescape` near a style attribute
  needs script-tag-level scrutiny. No standard CSP directive enforces
  the `--var:value` shape; would require a custom build step or
  CSP-Hashes per-template. Defer indefinitely unless a real bypass
  surfaces. (Review finding F8.)

**Intentional trade-offs documented for future re-evaluation:**

- **Lockout fires only for known usernames.** `AuthService::pwgLogin`
  gates the lockout check on `$ufId > 0`, so attempts against
  non-existent accounts record with `user_id=NULL` and don't trip
  the per-user lockout. Username enumeration is defended by the
  per-IP rate limit instead. Re-evaluate if username enumeration
  via login-timing or response-shape oracles becomes a concrete
  attack. (Review finding F2.)
- **`password_verify` runs even when the account is already locked.**
  Pro: constant-time response so an attacker can't probe lock
  state via timing. Con: each locked attempt costs ~100ms CPU, so
  5 attempts/15min × N accounts is a real DoS surface. Re-evaluate
  if DoS reports come in. (Review finding F3.)

**Cleanup nits (drive-by when in the area):**

- **`LoginFailureLocked` activity-log spam.** Each locked attempt
  writes a row to `piwigo_activity`. An attacker hammering one
  account floods the audit trail. Consider rate-limiting that log
  write to once per (user, hour). Defer until a real install reports
  log-volume problems. (Review finding N3.)
- **Unused `idx_ip_time` index on `piwigo_user_failed_logins`.**
  No query currently reads by IP. Cheap-but-dead; keep if a DB-backed
  IP fallback is planned (vs. the current `symfony/cache`-backed
  bucket), otherwise drop in a future schema cleanup pass. (Review
  finding N2.)

#### Wave A — Session cookie hardening + `SECURITY.md` skeleton

**Status:** ✅ Done 2026-05-22 (`56b671e1b`) · **Effort:** XS (delivered) · planning text preserved below for traceability; the closure narrative is in the **Delivered** paragraph above

Lock down the session cookie at bootstrap time and seed
`docs/SECURITY.md` so the later waves have a place to append.

`session_regenerate_id(true)` (rotation on successful login) is
**already** in `AuthService::logUser()` line 146 — settled during the
front-controller work, contrary to the original sub-task drafting.
The remaining gap is bootstrap-time cookie params.

In `src/Piwigo/Bootstrap/SessionBootstrap.php`, replace the two-arg
`session_set_cookie_params(0, CookieService::cookiePath())` (line 44)
with the array form:

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => (string) CookieService::cookiePath(),
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
]);
```

`SameSite=Lax` (not `Strict`) preserves the "click a Piwigo link in
email/Slack and stay logged in" UX while still blocking cross-site
POST. `secure` is conditional on the request scheme — hard-coding
`true` would break local plain-HTTP dev.

#### Wave B — Brute-force lockout + login rate limiting (paired)

**Status:** ✅ Done 2026-05-22 (`e64282b79`) · **Effort:** M (delivered). Planning text below preserved as authored; final shape diverged on two points: (a) table prefix is `piwigo_` not `phpwg_` (matches `Config::dbPrefix()` default — the planning text predates the schema audit that confirmed the convention), and (b) there is no `MigrationRunner` in tree — the schema landed via a direct append to `install/piwigo_structure-mysql.sql` (the canonical install SQL), not a migration file. The closure narrative is in the **Delivered** paragraph above.

Two defenses ship together because each is partial in isolation:
lockout without rate limiting still allows a flood that wastes DB
cycles; rate limit without lockout means a slow-and-low attacker
eventually guesses passwords.

**Schema.** First real migration in tree — `migrations.php` and
`MigrationRunner` are wired but `src/Piwigo/Migrations/` is empty, so
this migration also sets the project's migration-file pattern:

```sql
CREATE TABLE phpwg_user_failed_logins (
    user_id INT UNSIGNED NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_user_time (user_id, attempted_at),
    KEY idx_ip_time (ip, attempted_at)
);
```

`user_id` is nullable so failures against unknown usernames still
inform rate-limit decisions without contributing to per-user lockout.

**Service.** New `Piwigo\Users\LoginThrottle`:

```php
final class LoginThrottle
{
    public function __construct(
        private Connection $conn,
        private int $threshold = 5,
        private int $windowSeconds = 900,
    ) {}
    public function isLockedOut(int $userId): bool;
    public function recordFailure(?int $userId, string $ip): void;
    public function clearFailures(int $userId): void;
    public function purgeExpired(): void;  // GC, hooked into sessionGc
}
```

Integrated in `AuthService::pwgLogin()` (line 187): pre-check
`isLockedOut()` after `findUserByUsernameOrEmail`; call
`recordFailure()` on `password_verify` miss; `clearFailures()` on
success.

**Rate limiter.** `composer require symfony/rate-limiter ^8.0`.
Storage uses the existing `symfony/cache` pool (already in
`composer.json`). Two policies:

| Policy          | Limit       | Reset window |
| --------------- | ----------- | ------------ |
| `login_ip`      | 5 attempts  | 1 minute     |
| `login_account` | 10 attempts | 10 minutes   |

Consumed in `IdentificationController` (line 68 POST branch) before
the `AuthService::tryLogUser` call. Hitting either limit surfaces as
a `$page['errors']` message — same UX channel as a bad-password
error. (The roadmap's `return new Response(429)` shape doesn't fit
the existing form-rendering controller; the WS-API path gets a
proper exception via the named constructors below.)

**Lockout-reason surfacing.** `pwgLogin()` keeps its `bool` return.
A new `AuthService::getLastFailureReason()` lets the controller
distinguish lockout from bad-credentials for the user-facing
message. Restructuring to an explicit result-DTO belongs to §1.7
(typed boundaries), not this wave.

**`AuthException` gains** `accountLocked()` and `rateLimited()` named
constructors for the WS-API login path, where exceptions are the
natural channel (the form path uses error strings).

**Configuration deferred.** Thresholds are hard-coded in the service
constructors with the values above. Config keys
(`login_throttle_*`, `login_rate_*`) come **after** §1.6c
config-schema metadata lands — premature now would produce keys that
need re-shaping when the schema work happens.

#### Wave C — `SecurityHeadersMiddleware` + nonce wiring + CI guard

**Status:** ✅ Done 2026-05-22 (`dc1f59d6f`) · **Effort:** S (delivered). The planning text below shows the original nonce-based CSP (`script-src 'self' 'nonce-{$nonce}'`); the as-shipped policy is `script-src 'self'` with **no nonce** since every in-tree `<script>` is `type="application/json"` (data island) and CSP3 §6.1.5 exempts those from `script-src`. The nonce wiring was dropped as YAGNI. The closure narrative is in the **Delivered** paragraph above.

PSR-15 middleware inserted at position 0 of the pipeline (before
`ExceptionHandlerMiddleware`, so error pages also get the headers):

```php
public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
{
    $nonce = bin2hex(random_bytes(16));
    $req = $req->withAttribute('csp_nonce', $nonce);

    $response = $next->handle($req);

    $csp = "default-src 'self'; "
         . "img-src 'self' data: blob:; "
         . "style-src 'self'; "
         . "style-src-elem 'self'; "
         . "style-src-attr 'unsafe-inline'; "
         . "script-src 'self' 'nonce-{$nonce}'; "
         . "frame-ancestors 'self'; "
         . "form-action 'self'";

    return $response
        ->withHeader('Content-Security-Policy', $csp)
        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
}
```

**Why strict `script-src` works without template refactoring.** All
45 `<script>` tags across the 41 `.latte` files using them are
`<script type="application/json">` data islands — PHP-rendered JSON
consumed by external JS via `combineScript()`. Per CSP3 §6.1.5,
`script-src` does **not** apply to non-executable script types, so
these tags need no nonce and don't break the policy. Wave 2's
conversion was thorough: zero executable inline JS remains.

**`style-src-attr 'unsafe-inline'`** covers the 15 files with inline
`style="…"` attributes. All are PHP-driven `--var: value`
custom-property bridges (e.g. `style="--thumb-w:{$thumbW}px"`) —
uniform shape, no event handlers or dangerous content.

**CI guard against regressions.**
`tools/check-no-executable-inline-scripts.php` walks
`themes/**/*.latte` and fails the build if any `<script>` tag is
missing a `type=` attribute or uses a `type` outside the allow-list
of non-executable types (`application/json`, `application/ld+json`,
`importmap`, `speculationrules`). Composer script entry
`lint:no-inline-scripts`; new CI job mirrors the `latte` lint job.

**Nonce wiring** prefers the no-engine-change variant: a
controller-base helper reads `$request->getAttribute('csp_nonce')`
and assigns to the template via `LatteEngine::assign()`. Settled
during implementation; if a wider change makes sense (e.g. a
template-extension-level binding), revisit then.

#### Wave D — `docs/SECURITY.md` finalize

**Status:** ✅ Done 2026-05-22 (`6aff94432`) · **Effort:** XS (delivered) · planning text below preserved for traceability; the closure narrative is in the **Delivered** paragraph above.

Replace the Wave A skeleton with the real content once the posture
is locked:

- **Threat model.** Anonymous attackers (credential stuffing, CSRF,
  XSS, clickjacking), authenticated low-priv attackers (privilege
  escalation, IDOR), supply chain (composer/npm). Map each to the
  defense in tree.
- **CSP override procedure.** Documented as "not yet supported, file
  an issue". §1.4 shipped the plugin contract but didn't land a CSP
  relaxation hook yet — that's a follow-up plugged into
  `PluginInterface::boot($container)` once the first plugin needs it.
- **Account-lockout admin runbook.** Manual unlock via
  `DELETE FROM phpwg_user_failed_logins WHERE user_id = …` until the
  admin UI lands a button (the §1.4 admin-page registry shipped
  in B10; the actual unlock-button page is open follow-up work).
- **Vulnerability reporting.** Private channel + SLA. Channel choice
  confirmed before merge.

##### Deferred to follow-ups

- **WS-API login lockout.** `/ws` bypasses `IdentificationController`.
  Wave B should also wire the limiter + throttle into
  `WsAuthMethods::login()`; confirm scope during Wave B
  implementation.
- **Config keys for thresholds.** Gated on §1.6c (config-schema
  metadata).
- **Admin UI for unlocking users.** §1.4 shipped the admin-page
  registry (B10); the unlock-button page itself is open follow-up
  work — register an `AdminPage` for the unlock UI via the new
  `AdminPagesRegistering` event.
- **CSP `report-uri` / `report-to`.** No reporting endpoint to design
  yet; revisit if violations appear in production.
- **Per-plugin CSP relaxation hook.** §1.4 plugin contract is in
  place; add the CSP hook to `PluginInterface::boot($container)` when
  the first plugin needs to relax CSP.
- **`secure => true` unconditional + cookie prefix
  (`__Host-` / `__Secure-`).** Gated on a future "force HTTPS"
  config flag.
- **Nonce on `<style>` tags.** No inline `<style>` blocks remain in
  tree (Wave 2 cleanup), so `style-src-elem 'self'` is enough.

##### Verification

```bash
# Headers present on every response (Wave C)
curl -sI http://localhost/ | grep -iE 'content-security-policy|x-frame-options|strict-transport-security'

# Per-IP rate limit kicks in at the 6th attempt (Wave B)
for i in $(seq 1 6); do
  curl -sw '%{http_code} ' -o /dev/null \
    -X POST http://localhost/identification \
    -d 'username=test&password=wrong&login=1'
done   # 6th request renders the limit error in $page['errors']

# CSRF without token (already blocking via CsrfMiddleware)
curl -sw '%{http_code}\n' -o /dev/null \
  -X POST http://localhost/admin/category_delete \
  -d 'cat_id=1'   # expect: 403

# Session cookie params (Wave A) — confirm SameSite=Lax + HttpOnly
curl -sI http://localhost/ | grep -i set-cookie

# CI guard against executable inline scripts (Wave C)
echo '<script>alert(1)</script>' > themes/_base/template/_probe.latte
composer lint:no-inline-scripts ; echo $?   # exit 1
rm themes/_base/template/_probe.latte
```

---

### 1.6 Type correctness — three tactical streams

**Status:** ✅ Closed 2026-05-23 ▸ 13 of 13 sub-tasks done · **Effort:** M

> **Audit refresh (2026-05-23).** All three streams closed today.
> Stream **1.6a** closed 2026-05-23: `@template T` on `RequestCache::remember()`,
> widened `set()` to `mixed`, refactored all three imperative
> `has()+set()+get()` sites (HtmlService, MailService) to use `remember()`.
> Stream **1.6b** closed 2026-05-16.
> Stream **1.6c** closed 2026-05-23: `sensitive`+`dumpForLog`,
> `required`+`MissingRequiredConfigException`, plugin-Config reference template,
> and the `'description'` field on all 277 SCHEMA entries. The accessor-generator
> sub-item was moot (the generator was deleted 2026-05-13).

After PHPStan level 10 landed, three threads tightened the remaining
mixed-type surface that didn't require new architectural patterns:

- **1.6a** — six high-ROI mixed-type fixes from the codebase audit.
  **Closed** (all 6 done/moot).
- **1.6b** — `$GLOBALS` cleanup that was deferred since the
  modernization phases that retired the procedural layer.
  **Closed** — only the intentional `Lang::attachGlobals` read remains.
- **1.6c** — five `Config::SCHEMA` enhancements left as deferred design
  surface from the schema work. **Closed** (4 shipped, 1 moot).

Architectural boundary work — typed entities for DB rows and typed DTOs
for HTTP input — was originally drafted under 1.6a (items 8–9) but lives
in §1.7 now. Same gating constraint, same review effort — tackle 1.6
as one section, work the streams in parallel where possible.

#### 1.6a Mixed-type fixes

**Status:** ✅ Closed 2026-05-23 ▸ all 6 items done/moot

Six fixes ordered by effort. None require behavior changes — all are
type narrowings supported by existing runtime invariants.

| Item                                                                                              | Files | Effort           | State                                                                      |
| ------------------------------------------------------------------------------------------------- | ----: | ---------------- | -------------------------------------------------------------------------- |
| `ImageInterface::compose(mixed $overlay)` → typed `$overlay`                                      |     — | —                | ✅ moot — already `PwgImage $overlay` in the interface (`src/Piwigo/Admin/Image/ImageInterface.php:26`) and all three implementations (`ImageGd`, `ImageImagick`, `ImageExtImagick`). Namespace migrated from `Piwigo\Image\` to `Piwigo\Admin\Image\` since the audit was written. |
| `CookieService::getCookieVar()` → typed return                                                    |     — | —                | ✅ moot — already `getCookieVar(string $var, string $default = ''): string` at `CookieService.php:70`. No `mixed` to narrow.                            |
| `CategoryAdminService::deleteSite(mixed $id)` → typed                                             |     1 | trivial          | ✅ shipped — already `int $id`                                             |
| `Config::raw()` typed return — `string\|int\|bool\|float\|array<mixed>\|null`                     |     — | —                | ✅ moot — `Config::raw()` no longer exists; `Config::src()` is now `private` with zero out-of-class callers, and all reads go through typed accessors (`Config::trustedProxies()`, `Config::sessionName()`, …) backed by `Config::SCHEMA` |
| `EventDispatcher::dispatch()` → `@template T` generic                                             |     — | —                | ✅ moot — static class deleted in §1.4 B17d, replaced by typed PSR-14 DTOs |
| `RequestCache::remember()` / `::get()` → `@template T` (note: `PersistentCache` no longer exists) |     7 | medium           | ✅ shipped 2026-05-23 — `@template T` on `remember()`, `set()` widened to `mixed`; imperative `has()+set()+get()` sites refactored to `remember()` in HtmlService (×2) and MailService |

##### Concrete examples

###### ImageInterface::compose (moot — already typed)

The interface and all three implementations already declare
`compose(PwgImage $overlay, int $x, int $y, int $opacity): bool`:

```bash
$ grep -n 'function compose' src/Piwigo/Admin/Image/Image*.php
src/Piwigo/Admin/Image/ImageInterface.php:26:    public function compose(PwgImage $overlay, int $x, int $y, int $opacity): bool;
src/Piwigo/Admin/Image/ImageGd.php:        public function compose(PwgImage $overlay, int $x, int $y, int $opacity): bool
src/Piwigo/Admin/Image/ImageImagick.php:    public function compose(PwgImage $overlay, int $x, int $y, int $opacity): bool
src/Piwigo/Admin/Image/ImageExtImagick.php: public function compose(PwgImage $overlay, int $x, int $y, int $opacity): bool
```

The interface lives under `Piwigo\Admin\Image\` (the audit was
written against the old `Piwigo\Image\` namespace). No work left.

###### Notes on the moot items

- **`CategoryAdminService::deleteSite`** is already
  `public function deleteSite(int $id): void` at line 49. The original
  audit caught a `mixed` signature that's since been tightened. The
  related `array_map(fn (mixed $v) => …)` lambdas at DB call boundaries
  the audit also mentioned are unaffected by this fix — they get
  removed naturally as queries move into typed repository methods
  under §1.7 Phase 2.
- **`Config::raw()`** does not exist on `Piwigo\Config\Config` anymore
  — the entire surface is typed accessors generated against
  `Config::SCHEMA` (~250 methods like `Config::trustedProxies()`,
  `Config::sessionName()`, `Config::guestId()`, …). `Config::src()`
  is `private` with zero out-of-class callers (one PHPDoc reference
  remains in `UserFieldsMap.php`). No annotation work left.
- **`EventDispatcher::dispatch` generic** was scoped against the
  static `Piwigo\Plugins\EventDispatcher` class. That class was
  deleted in §1.4 batch B17d and replaced by PSR-14 dispatch through
  Symfony's `EventDispatcherInterface` with ~160 typed event DTOs (159
  as of 2026-05-22; rolls up as new events land). The
  dispatcher already returns the same instance the caller passed
  (PSR-14 contract), so the `@template T` win the original item
  promised is now structural — no annotation needed.

##### Sequencing

Closed 2026-05-23. `RequestCache::remember` got `@template T`, `set()` was
widened to `mixed`, and the three imperative `has()+set()+get()` sites
(HtmlService×2, MailService) were refactored to use `remember()`. The five
follow-on defensive guards (`is_array`/`is_int` narrowings on cached values)
were dropped in the same commit since T is now inferred. `PersistentCache`
doesn't exist anymore — `RequestCache` is the only request-scoped cache
shipping today.

#### 1.6b Globals cleanup

**Status:** ✅ Closed 2026-05-16 ▸ both items shipped or proved unneeded

**Audit findings on the current tree:**

- **`phpstan-bootstrap.php` reference bridges** — gone. The file's only
  contents today are `define()`s for runtime constants (`PHPWG_DOMAIN`,
  `PHPWG_URL`, `PEM_URL`, `PREFIX_TABLE`, `PHOTOS_ADD_BASE_URL`). No
  `$page`, `$user`, `$lang`, `$template` bridges remain.
- **`PageHeaderRenderer` / `PageTailRenderer` / `NoPhotoYetRenderer`
  globals** — all retired by the Wave A reference-bridge cleanup +
  Phase 3 channel migration (2026-05-15). `grep -n '\$GLOBALS'`
  against those three files now returns zero hits.
- **Remaining `$GLOBALS[…]` reads in `src/`** — exactly one, at
  `Core/Lang.php:43,49`:

  ```php
  $raw = $GLOBALS['lang'] ?? [];
  // […]
  unset($GLOBALS['lang']);
  ```

  `Lang::attachGlobals()` consumes the procedural `.lang.php` files
  (which still use `$lang = [...]` as their on-disk format) into typed
  static properties and immediately clears the global. This read is
  intentional and stays until the translation pipeline itself is
  redesigned (out of scope for §1.6).

**Relationship to §1.7 Phase 2.** The original sub-section noted that
the `$user` global was a raw DB row, and that closure would arrive
together with the typed `UserEntity`. That motivation still applies to
`UserEntity`'s design — but it no longer gates the globals cleanup,
which is now complete on its own.

#### 1.6c Config schema metadata

**Status:** ✅ Closed 2026-05-23 ▸ 4 of 5 shipped · 1 moot

Four `Config::SCHEMA` enhancements were deferred design surface. The
fifth has been overtaken by events (see below). They're independent of
each other; pick whichever delivers value first.

##### `'required' => true` field + validation

**Status:** ✅ shipped 2026-05-23. `db_host`, `db_user`, `db_base`, `secret_key`
marked `'required' => true` in `Config::SCHEMA`. `MissingRequiredConfigException`
added (`src/Piwigo/Config/`). `ConfigLoader::validateRequired()` checks all
required keys for non-empty values and is called from `CommonBootstrap` immediately
after `ConfigService::loadConfFromDb()`.

```php
// Config::SCHEMA additions
'db_host' => ['type' => 'string', 'default' => 'localhost', 'required' => true],
'secret_key' => ['type' => 'string', 'default' => '', 'required' => true],
```

Validation after env overrides:

```php
foreach (Config::SCHEMA as $key => $meta) {
    if (($meta['required'] ?? false) && !Config::has($key)) {
        throw new MissingRequiredConfigException("Required config key '$key' is unset.");
    }
}
```

##### `'description'` field → populated reference doc

**Status:** ✅ shipped 2026-05-23. All 277 SCHEMA entries now carry a one-line
`'description'` string; `@var` shape on `Config::SCHEMA` extended with
`required?: bool, sensitive?: bool, description?: string`. Replaced as a single
line-55 Edit (the constant is one long line by convention). PHPStan clean,
SchemaIntegrityTest green, Pint clean. The reference-doc generator can now
read `Config::SCHEMA[$key]['description']` directly.

```php
'gallery_title' => [
    'type' => 'string',
    'default' => 'Piwigo',
    'method' => 'galleryTitle',
    'description' => 'Title of the gallery shown in the browser tab and page header.',
],
```

##### `'sensitive'` field + `Config::dumpForLog()`

**Status:** ✅ shipped 2026-05-23. `db_password` and `smtp_password` marked
`'sensitive' => true` in `Config::SCHEMA`. `Config::dumpForLog()` added in the
hand-written accessor region — returns all config values with sensitive keys
redacted to `'********'`. `'dumpForLog'` added to `SchemaIntegrityTest::ALLOW_LIST`.

```php
'db_password' => [
    'type' => 'string',
    'default' => '',
    'sensitive' => true,
],
'smtp_password' => [..., 'sensitive' => true],
```

```php
public static function dumpForLog(): array
{
    $out = [];
    foreach (self::$data as $key => $value) {
        $out[$key] = (self::SCHEMA[$key]['sensitive'] ?? false)
            ? str_repeat('*', 8)
            : $value;
    }
    return $out;
}
```

Used in error-handler logging instead of `var_export($GLOBALS['conf'])`.

##### Namespace-prefix support — caller pattern over `ConfigStorage` feature

**Status:** ✅ shipped 2026-05-23 (reference template). Pattern proven in
`tests/Fixtures/Plugin/ExamplePlugin/Config.php`: static accessors call
`Kernel::service(ConfigRepository::class)->findByParamPattern(PREFIX . '%')`
to load prefixed rows — no ConfigRepository changes required. PHPStan-clean.

The persistence facade in tree is `ConfigService` + `ConfigRepository`
(under `src/Piwigo/Config/`) — `ConfigStorage` referenced in earlier
drafts of this plan never landed as a class name. The pair is
deliberately prefix-agnostic: each calling Config class is responsible
for its own typed accessors and validation; `ConfigRepository` is the
storage backend, and Config classes are the typed read/write API. The
plan therefore shifts from "add prefix support to the storage facade"
to "establish the per-plugin Config-class pattern":

```php
namespace Piwigo\Plugin\OpenStreetMap;

final class Config
{
    private const PREFIX = 'openstreetmap.';
    public const SCHEMA = [
        'tile_provider' => ['type' => 'string', 'default' => 'osm'],
        'default_zoom' => ['type' => 'int', 'default' => 13],
    ];

    public static function tileProvider(): string
    {
        // accessor body — calls ConfigStorage with self::PREFIX . 'tile_provider'
    }
}
```

The plugin's Config class prepends `self::PREFIX` before every
`ConfigRepository::persist` / `loadAll(…WHERE param LIKE 'openstreetmap.%')`
call. No `ConfigRepository` change required — what's still missing is
the reference template (no in-tree plugin yet ships a Config class to
prove out the pattern).

##### ~~`--target=<path>` flag on `tools/build-config-accessors.php`~~

**Status:** ✅ Moot. The accessor generator was deleted on 2026-05-13
in commit `7341f5497` ("chore(tools): delete one-shot migration
scripts"). `Config.php`'s SCHEMA and accessors are now hand-edited;
`tests/Unit/Config/SchemaIntegrityTest.php` catches accessor/SCHEMA
drift at CI time, which fulfills the original "no-diff" guarantee
without a generator.

**Doc-rot follow-up ✅** — the Config.php docstring (now around
`src/Piwigo/Config/Config.php:35-43`) has been updated to read "The
typed accessors below are hand-edited to match this SCHEMA — they
used to be generated by tools/build-config-accessors.php, but that
one-shot generator was retired on 2026-05-13 once the SCHEMA reached
its current shape." The `SchemaIntegrityTest` line stays load-bearing.

---

### 1.7 Typed boundaries — HTTP input and DB rows

**Status:** 🟢 Active · **Effort:** L · 2 phases (both partly shipped)

The codebase has two boundaries where untyped data crosses into the
domain: HTTP input (`$_POST`/`$_GET` is `array<string, mixed>`) and DB
rows (`fetchAssociative()` returns `array<string, mixed>`). Both are
solved by the same architectural pattern: a single-cast factory at the
boundary that produces a typed object, and business logic that consumes
typed properties without `is_*` guards.

> **Status reconciliation (2026-05-23).** Both phases have partially
> shipped under different names than the original §1.7 draft proposed:
> the WS layer acquired `*Params` + `*Handler` per-endpoint classes
> during §1.4 (~94/99 endpoints, tracked as the **F5-h** commit series
> — 127 F5-* commits in git log; `WsAction.php` interface docblock
> literally says "per F5-h"), and the repository layer grew **two
> complementary** typed-row patterns: 7 wide `*/Entity/` classes
> (Image=4, Category=1, Tag=1, Comment=1) supported by 21 value-object
> types under `src/Piwigo/Common/ValueObject/`, plus 56 narrow
> `*/Projection/` classes. The original §1.7 draft's `ImageEntity`
> example was prescient — `Image/Entity/Image.php` exists and is
> richer (value-object-typed) than the draft showed. The remaining
> work — admin/web-side DTOs and the bare-`array` repo-return sweep —
> is described below as the next step in patterns the codebase has
> already chosen. This section is comparable in scope to §1.2
> (templates) or §1.4 (plugins).

> **§1.7 is a 2-of-5 slice of the broader F5 master plan**
> (`.claude/plans/what-is-the-proper-magical-taco.md`, 506 lines,
> titled "Ground-up Refactor: Eliminate `mixed` from the Domain").
> The F5 plan identifies **5 boundaries** where `mixed` enters the
> domain and requires a single-cast parser per boundary:
>
> | # | Boundary             | §1.7 coverage | Tracker             |
> | - | -------------------- | ------------- | ------------------- |
> | 1 | HTTP request         | **Phase 1 ✓** | F5-h (per-endpoint) |
> | 2 | Stored JSON          | ✗ — out of §1.7 scope | F5-i — `SearchRules` deep adoption (foundation shipped; #1 Psalm-info hotspot pending) |
> | 3 | DB rows              | **Phase 2 ✓** | F5-d/e (Entity + Projection patterns) + SQL-DTO-AUDIT |
> | 4 | `$_SESSION`          | ✗ — out of §1.7 scope | F5-c — `Session.php` typed wrapper exists; `SessionService` → `SessionStore` rename pending |
> | 5 | PSR-11 container     | ✗ — out of §1.7 scope | F5-b — `Container/*Factory.php` extraction from `config/container.php`'s 50+ inline closures |
>
> The end goal of the F5 plan is `psalm --show-info <50` (F5-k gate),
> currently **1814** as of 2026-05-23. §1.7 contributes to that goal
> via its 2 phases but doesn't deliver the gate alone — the other 3
> boundaries need to close as well.

#### Phase 1 — Request DTO layer (HTTP boundary)

**Status:** 🟢 Active ▸ WS layer ~94/99 done · admin/web side open

##### Done — WS layer per-endpoint DTOs

§1.4 shipped the per-endpoint pattern: each WS method has a `*Handler`
(implementing `WsAction`) plus a `*Params` DTO (implementing `WsParams`)
with a hand-rolled `public static function fromArray(array $raw): self`
factory. As of 2026-05-23: **94 `*Handler.php` + 83 `*Params.php` files**
under `src/Piwigo/Ws/Action/Pwg/<Domain>/`. `grep -rn 'new
MethodDefinition' src/Piwigo` returns **99 hits across 4 files**;
of those, ~1 hit is a doc-comment example in `MethodDefinition.php`
itself and the remaining ~98 are real registrations. Within
`WsMethodRegistrar.php`'s body, **94 use `handlerClass:` and 4 use
the legacy `callback:` path**. Of the 94 handler endpoints, 11 are
zero-param and need no `*Params` companion (83 + 11 = 94).
Representative example:

```php
// src/Piwigo/Ws/Action/Pwg/Images/AddCommentParams.php
final readonly class AddCommentParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public string $author,
        public string $content,
        public string $key,
    ) {}

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $authorIn  = $raw['author']  ?? null;
        $contentIn = $raw['content'] ?? null;
        $keyIn     = $raw['key']     ?? null;
        return new self(
            imageId: is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            author:  trim(is_string($authorIn) ? $authorIn : ''),
            content: trim(is_string($contentIn) ? $contentIn : ''),
            key:     is_string($keyIn) ? $keyIn : '',
        );
    }
}

// AddCommentHandler::__invoke()
$input = AddCommentParams::fromArray($params);
```

This is the same architectural shape the original §1.7 draft called for
("typed object built at the boundary; business logic consumes typed
properties"), with two deliberate differences: (a) no Symfony
Serializer / Validator dependency — `fromArray()` is hand-rolled, and
(b) validation lives inside the factory (defensive casts) rather than
in attribute constraints. The WS-layer architectural choice is settled
and not under review here.

**Also shipped on the async boundary: typed Messenger Job DTOs.** 6
`final readonly` Job classes at `src/Piwigo/Job/{GenerateDerivative,
BatchUpload,ReindexImages,RegenerateAllDerivatives,SendNotificationEmail,
Failed}Job.php` carry typed properties; `Job/Handler/*Handler.php`
classes use `#[AsMessageHandler]` + typed `$job` parameter. Same
"typed object at the boundary" philosophy as `WsParams` but on the
async/queue boundary instead of the sync HTTP boundary. Already
shipping; no §1.7 work needed on this front.

**Also shipped on the WS layer: typed responses via `WsResult`.** The
interface at `src/Piwigo/Ws/WsResult.php` is the output counterpart to
`WsParams` — each handler returning structured data returns a
`*Result` instance whose `toArray()` produces the wire-format dict
that `PwgServer` JSON-encodes. As of 2026-05-23: **7 `*Result`
implementations** in tree (`Categories/MoveResult`,
`Tags/{Add,Delete,Merge,Duplicate}Result`,
`GetMissingDerivativesResult`, `Session/GetStatusResult`). So
**7/94 handler endpoints have typed responses**; the remaining ~87
still return `array<string, mixed>`. Extending `WsResult` to those
handlers (plus to error envelopes, currently inline as `PwgError`) is
listed in "Additional in-scope work" below.

##### Open — admin / web side

Admin controllers and page renderers still read `$_POST` / `$_GET`
directly. As of 2026-05-23: **758 raw `$_POST` / `$_GET` reads across
54 files** (was 626 / 45 at the original audit; surface drifts up as
new admin endpoints land). The WS *handler* layer doesn't contribute —
handlers receive the params map from `PwgServer::invoke()`, not the
superglobals — but the WS *protocol* layer does: `PwgServer.php`,
`Ws/Protocol/PwgRestRequestHandler.php` (the transport that picks
`$_POST` vs `$_GET` based on HTTP method), and one stray mutation in
`Action/Pwg/Permissions/AddHandler.php`. So ~3 files in `Ws/` and ~51
on the web/admin side account for the 758 reads.

Target classes for the sweep (representative, not exhaustive):

- `src/Piwigo/Controller/Admin/MaintenanceController.php`
- `src/Piwigo/Controller/Admin/BatchManagerController.php`
- `src/Piwigo/Controller/PictureController.php`
- `src/Piwigo/Picture/PictureCommentRenderer.php` — multi-field comment
  submission; the original §1.7 draft's `CommentSubmitRequest`
  proof-of-pattern target still applies here (the WS equivalent
  `AddCommentParams` already proves the shape).
- The upload / maintenance / admin form controllers under
  `src/Piwigo/Controller/Admin/` more generally.

Keep `StringUtil::inputInt/inputString/inputBool` for one-shot reads
(`?image_id=42`) where a DTO is ceremony. Realistic estimate (carried
from original draft, still applicable): 30–50 web-side DTOs cover most
multi-field admin / submission endpoints; the long tail stays on
`StringUtil::input*`.

##### Open architectural choice

For the web-side sweep, two viable patterns:

- **(a) Extend `WsParams::fromArray()`** to the web layer (with a
  sibling marker interface or just freestanding `*Request` classes).
  No new dependencies. Matches what already ships in the WS layer.
- **(b) Adopt `symfony/serializer + symfony/validator`** for the web
  layer only. Brings attribute-based validation (`#[Assert\Email]`,
  `#[Assert\Length(max: 5000)]`, etc.), which is genuinely nicer for
  admin forms with many constraints. Adds two dependencies that the
  WS layer doesn't need.

The original ROADMAP picked (b) without knowing (a) was already
shipping in the WS layer. **Recommend defaulting to (a)** for
consistency unless validator attributes prove worth the deps for
admin forms specifically. Make this decision before the
proof-of-pattern PR — it determines whether
`PictureCommentRenderer` migration looks like `AddCommentParams` or
like `CommentSubmitRequest` (the original draft snippet).

##### Deferred — HttpKernel adoption

Full Symfony HttpKernel adoption (PSR-7 → HttpFoundation, kernel
events, standard ArgumentResolver pipeline, exception → response flow)
is a separate architectural decision. Not in scope for §1.7.
`symfony/http-kernel` is **not** currently a dependency (`grep
http-kernel composer.json` returns nothing); adoption would add it.
HttpKernel uses the existing Symfony EventDispatcher (same bus, no
second bus added) but introduces a new request-lifecycle event suite
(`kernel.request`, `kernel.response`, `kernel.exception`, …) and
changes the request/response data structures (PSR-7 is in tree via
`psr/http-message` + `psr/http-server-handler` + `psr/http-server-middleware`,
with `nyholm/psr7` + `nyholm/psr7-server` as the concrete
implementation in `Http/RequestFactory.php` + `Http/ResponseFactory.php`,
used in **37 src/Piwigo files** including `Core/Kernel.php`,
`Http/MiddlewarePipeline.php`, `Http/ResponseEmitter.php`).
Revisit when there's a concrete need. The DTOs from Phase 1 carry over
unchanged if that adoption ever happens.

##### Migration order

1. Decide architectural pattern (a) vs (b) above.
2. Land first web-side DTO + (if pattern b) `PayloadFactory` glue as
   proof-of-pattern. `PictureCommentRenderer` is the natural first
   target — the WS sibling `AddCommentParams` already exists for
   cross-reference.
3. Sweep multi-field admin endpoints (`MaintenanceController`,
   `BatchManagerController`, etc.).
4. *(If pattern b is chosen)* Optional follow-on: a ~30-line
   ArgumentResolver in the dispatcher that reads `#[MapRequestPayload]`
   on the controller signature and calls `PayloadFactory::create()`
   automatically. Pure ergonomics, same dependencies. Decide based on
   how repetitive explicit `create()` calls feel.

> The `#[ApiMethod]` per-endpoint decoration is defined
> (`src/Piwigo/Ws/OpenApi/ApiMethod.php`) and consumed by `SpecBuilder`,
> but **0 endpoint classes currently carry it** as of 2026-05-23. Phase 1
> doesn't depend on it — the attribute can layer onto WS endpoints
> later under a §1.4 follow-on without re-sweeping any payloads.

#### Phase 2 — Repository entity layer (DB boundary)

**Status:** 🟢 Active ▸ pattern in place · sweep open

##### Done — both `*/Entity/` and `*/Projection/` patterns shipped

The repository layer uses **two complementary typed-row patterns**:

**Wide Entity pattern** — one class per table, every column typed,
value-objects (ImageId, RelPath, MysqlDateTime, Md5Sum, …) for
column-level safety. Used for identity-based queries (`findById`,
`findByPath`, `findByFilePattern`). As of 2026-05-23: **7 Entity
classes** under `src/Piwigo/{Image,Category,Tag,Comment}/Entity/`
(Image=4 entity classes including `Image`, `ImageIdFilename`,
`ImageIdPathRepresentative`, `PathRepresentative`; Category=1; Tag=1;
Comment=1), backed by **21 ValueObject classes** in
`src/Piwigo/Common/ValueObject/` (the F5 master plan inventories ~32
target VOs across identifiers, shaped strings, temporals, and enums —
shipped 21, remaining ~11 are mostly admin-side IDs and string-shape
wrappers). Six repository methods return `?Image`/`?Category`/`?Tag`
directly; nine `@return list<Image|Category|Tag>` annotations exist.
`ImageRepository::findById(int): ?Image` is the canonical example —
and is exactly what the original §1.7 draft proposed (with
value-objects added).

**Narrow Projection pattern** — query-specific subset, no identity,
no value-objects. Used for listings, lookups, distinct queries,
calendar/stats rollups. As of 2026-05-23, **73 projection-shape
classes** in tree across three location conventions:

- **56 under `*/Projection/`** — the canonical location, across
  `src/Piwigo/{Image,Category,Tag,Users,Activity,Comment,Group,Notification,Rate,Auth}/Projection/`
  (per-namespace counts: Category=24, Users=6, Comment=5, Image=4,
  Tag=4, Activity=4, Notification=3, Rate=3, Group=2, Auth=1).
- **10 `*Row.php` at namespace roots** — Search=6 (AuthorCountRow,
  ImageFilesizeRow, ImageDateRow, ImageDimensionRow, ImageRatingRow,
  AddedByCountRow), History=3, Activity=1. Same shape as `Projection/`
  classes, just located one level up. Pre-dates or sidesteps the
  `Projection/` convention; a code-quality follow-up could consolidate
  the location.
- **7 in `*/Entity/`** — counted in the wide-Entity pattern above; they
  share the `fromRow()` shape with Projections.

Plus the generic wrapper: **`Piwigo\Common\Dto\PaginatedResult<T>`**
(`@template T`, used in 19 call sites across Image, Category, User
repos) wraps `list<T> $rows + ?int $total` for paginated reads. T is
parametrized over `Image`, `AdminCategoryRow`, inline array tuples, or
(in one stale call site) `array<string, mixed>` — the untyped variant
counts as part of the bare-`array` sweep target.

Total of **64 `function fromRow` factories** across the tree
(54 in `Projection/`, 7 in `Entity/`, 3 outside both:
`Common/Dto/UserGroupPair.php`, `Telemetry/TelemetryActivityGroup.php`,
`Activity/ActivityRow.php`). Outlier outside the 64: `Piwigo\Users\User`
at namespace root uses `fromUserArray()` instead of `fromRow()` — same
shape, different naming. The 10 root-level `*Row.php` classes also use
`fromRow()` (already counted in the 64 above for the `Activity/ActivityRow`
case; spot-check the 9 Search/History rows when sweeping).

Representative examples already in tree:

- `src/Piwigo/Image/Entity/Image.php` — wide entity, 24 value-object-typed
  properties, `Image::fromRow($row)` factory. Covered by
  `tests/Unit/Image/Entity/ImageTest.php` (one of 5 Entity unit-test
  files: Image, ImageIdFilename, Category, Tag, Comment — 26 tests, 142
  assertions, all green at 2026-05-23).
- `src/Piwigo/Image/Projection/ImageDimension.php` — narrow projection,
  width+height only, used by `findDistinctDimensions()`.
- `src/Piwigo/Category/Projection/CategoryNamePermalink.php` — the
  `(id, name, permalink)` projection used by admin pickers.

Both patterns are established. The open work is migrating the methods
that pre-date them and picking which pattern fits each case (Entity
for identity-based row reads; Projection for query-specific subsets).

##### Open — bare-`array` repo-return sweep

Of **646 public methods** across `src/Piwigo/**/*Repository.php`,
**249 still return bare `array`** (`: array$` without a typed wrapper).
Most carry a tight `@return list<TypedProjection>` or
`@return list<array{key: type, …}>` docblock that PHPStan validates
against — those call sites are already typed via PHPStan inference;
the runtime declaration can't be tightened further because **PHP has no
`list<T>` runtime type** (`list` is the destructuring keyword). The
real sweep target is methods whose docblock is still
`@return list<array<string, mixed>>` / `@return array<string, mixed>`
(or has no `@return` at all): **31 such docblock occurrences across 18
repository files** as of 2026-05-23. ⚠ Caveat: an unknown subset of
these are **orphaned dead docblocks** — leftover `@return` annotations
sitting above unrelated methods whose own docblock immediately follows
(spot-checked in `Tag/TagRepository.php:192` and
`Image/ImageRepository.php:316`, both orphans). The audit-first step
must categorise each docblock as orphan / tuple-shape / real-attached.

Concrete example of the tightening, using a real attached method:

```php
// Today — src/Piwigo/Search/SearchRepository.php:159
/**
 * Date thresholds row (24h/7d/30d/3m/6m) for the "date_posted" widget.
 *
 * @return array<string, mixed>
 */
public function findDatePostedThresholds(): array
{
    $rows = $this->conn->executeQuery(/* SELECT 5 date constants */)
        ->fetchAllAssociative();
    return $rows[0] ?? [];
}

// After — caller receives typed projection; defensive `is_string` /
// `is_numeric` guards at call sites drop
public function findDatePostedThresholds(): ?DatePostedThresholds
{
    $rows = $this->conn->executeQuery(/* … */)->fetchAllAssociative();
    return $rows === [] ? null : DatePostedThresholds::fromRow($rows[0]);
}

// New file: src/Piwigo/Search/DatePostedThresholds.php (namespace-root,
// matching Search/'s existing *Row convention — AuthorCountRow,
// ImageDateRow, etc. — rather than Search/Projection/ which doesn't
// exist; a follow-up could consolidate Search/ into Projection/ for
// consistency with the rest of the tree)
final readonly class DatePostedThresholds
{
    public function __construct(
        public string $past24h,
        public string $past7d,
        public string $past30d,
        public string $past3m,
        public string $past6m,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self { /* defensive casts */ }
}
```

The work is a single-file PR per migrated method: new Projection class
when needed, repository method's docblock + `array_map` updated, callers
updated to access typed properties, removed-`is_*`-guards diff in the
PR description (see Verification below — there's no static-analysis
baseline file to diff against).

##### The audit already exists: `docs/SQL-DTO-AUDIT.md`

⚠ The "audit-first" step the original §1.7 reconciliation
recommended is **already done** and **already shipped substantially**.
`docs/SQL-DTO-AUDIT.md` (181 lines, as of 2026-05-23) catalogs the
genuinely-untyped repository methods with table-per-repository
structure: ID (A1, A2…I7), method name + line, SELECT shape, proposed
projection class. The "feat(dto)" commit series executes against
those IDs:

- `091fd0c32 feat(dto): SQL-DTO-AUDIT — 31 projection classes across
  8 repositories` (the big drop)
- `0552664de feat(dto): A6/Tier4-6/Tier3/B3-B4 — remainder of audit-4`
- `9a4c94c90 feat(dto): A1-A5/A7 — audit-4 Tier 1 named result DTOs`
- … plus follow-ups for B/C/D/E groups

So Phase 2 work is **substantially underway via the SQL-DTO-AUDIT
campaign**, not in a "let's design the audit" phase. Remaining work
breakdown:

- **Done** — audit-4 Tier 1 (A1-A7, named result DTOs); B1-B6;
  C1-C10; D1-D3; E1; F-I groups per the 091fd0c32 manifest.
- **Open** — audit-4 remainder (Tier 5-6, follow-ons); plus any
  drift since the audit doc was written.
- **Out of audit scope** — methods marked "Skip" in the audit (dynamic
  column names like `findAllByObjectWithUsername()`; SELECT * blobs
  with admin-configured columns); orphaned dead `@return` docblocks
  (e.g. `Tag/TagRepository.php:192`, `Image/ImageRepository.php:316`
  — leftover from deleted methods, separate cleanup).

The original "31 docblocks across 18 files" scan number I reported
overcounted because it included methods already covered by `feat(dto)`
commits but not yet re-grepped, plus the orphans. Cross-reference
`docs/SQL-DTO-AUDIT.md` for the canonical open list.

This audit produces the **real** target count, replacing the original
draft's fictional "21 entities" number. Output of the audit is a
revised Phase 2 task list; the migration itself runs as multiple
follow-up sessions, one repository at a time.

##### Companion: the mixed-lambda cleanup

`fn (mixed $v)` lambdas at DB call sites: **291 occurrences across 95
files** as of 2026-05-23 (drift from the original draft's 257). These
get removed naturally as queries move into typed repository methods
that return id-arrays (e.g. `: array` with `@return list<int>` so
PHPStan infers the element type at call sites) — no transitional
helper API, but the count is a useful progress signal during the
sweep.

##### Migration order

One repository at a time. `ImageRepository` remains the natural
starter because its rows touch the most callers (`CategoryDefaultRenderer`,
`PictureController`, `BatchManagerController`, the photo-admin pages).
Each migration is a single PR: tightened return types, new Projection
classes when needed, callers updated to access typed properties, and
the removed-`is_*`-guards diff committed (see Verification below).

> **ServiceLocator** was deleted in §1.3 — callers receive their
> repositories via constructor injection. Confirmed
> 2026-05-23 (`find src -name 'ServiceLocator.php'` returns nothing,
> grep for `ServiceLocator::` in `src/` returns nothing).

#### Additional in-scope work (referenced from other ROADMAP sections)

Other sections of this ROADMAP and `docs/SECURITY.md` explicitly
delegate further work to §1.7 beyond the HTTP-input + DB-row split
above. Catalogued here for visibility — each is a separate sub-task
when its time comes:

- **Typed HTTP `Response` body — partly shipped, mostly open.** Two
  separate gaps live behind the original draft's "typed `Response`
  body" wording:
  - **Success-side**: 7/94 handlers already return `WsResult`
    subclasses (see Phase 1 "Done" above). The remaining ~87 still
    return `array<string, mixed>` or `array<string, mixed>|PwgError`
    (12 explicitly carry the union return type). Migrating them is
    the bulk of the work.
  - **Error-side**: `PwgError` is **already a typed value object**
    (`final readonly class` with `code(): ?int` + `message(): string`)
    — not an untyped envelope. The rate-limit cite at ROADMAP.md:2076
    and `docs/SECURITY.md:182, 235` refers to `PwgError(429, …)`
    thrown in 2 sites (`Session/LoginHandler.php:47,59`); what's
    "typed" here is the *message string* — currently a free-form
    English phrase, candidate for replacement with a typed error-code
    enum + translation key. Lower priority than the success-side sweep.
- **Eliminate `PageState::loginFailureReason` back-channel** — when
  §1.7 promotes the WS / form login paths to a throw-and-catch flow,
  `AuthException::accountLocked()` (already defined but unthrown)
  replaces the current side-effect flag. Referenced from
  ROADMAP.md:2078–2083 (Review finding F5).
- **`AuthService` result-DTO** — the lockout-vs-bad-credentials
  signal is currently surfaced via `AuthService::getLastFailureReason()`;
  restructuring to an explicit result-DTO belongs to §1.7. Referenced
  from ROADMAP.md:2240–2243.
- **`UserEntity` design follow-up** — §1.6b's relationship note
  (ROADMAP.md:2517) refers to "`UserEntity`'s design," but the typed
  user wrapper actually shipped as `Piwigo\Users\User` at namespace
  root (not under `Users/Entity/`). The §1.6b reference is to a
  hypothetical class name, not a real one; the real `User` wrapper
  exists and works.

#### Cross-references

- §1.6a — tactical type tightening; ✅ closed 2026-05-23. Cleared the
  ground for the Phase 2 sweep.
- §1.6b — globals cleanup; ✅ closed 2026-05-16 via Wave A reference-
  bridge cleanup and the Phase 3 channel migration. It did **not**
  block on a typed user wrapper specifically (the original §1.7 cross-ref
  framed that as a prerequisite — corrected). A typed user wrapper
  does exist: `Piwigo\Users\User` at `src/Piwigo/Users/User.php`
  (uses `UserStatus` enum + has `fromUserArray()` factory), used
  throughout `CurrentUser::get()`.
- §1.4 — plugin/theme system shipped. The WS handler-class pattern
  (`WsAction` + `WsParams`) was delivered as the F5-h commit series
  within §1.4's WS-method registration work, giving Phase 1 its
  WS-side win; the remaining web-side sweep is independent.
  `#[ApiMethod]` decoration is wired (§1.4 Phase 3) but no endpoint
  classes carry it — orthogonal to §1.7.
- §1.8 (test infrastructure) — Projection fixtures and web-side DTO
  factories are good candidates for the early Pest/PHPUnit suite.
- `docs/SQL-DTO-AUDIT.md` — canonical list of Phase 2 work items
  with A/B/C/D/E/F/G/H/I IDs. The `feat(dto):` commit series tracks
  execution against these IDs.
- `docs/ARRAY-REFACTOR-AUDIT.md` + `-2.md` + `-3.md` + `-4.md` —
  earlier audit rounds; the round-4 doc is the one currently active.
- `.claude/plans/what-is-the-proper-magical-taco.md` — F5 master plan
  (506 lines, "Ground-up Refactor: Eliminate `mixed` from the Domain").
  Defines all 11 F5 sub-codes (F5-a through F5-k), the 5-boundary
  framing, and the value-object inventory (32 planned identifiers /
  strings / temporal / enums — `ImageId`, `Email`, `MysqlDateTime`,
  `UserStatus`, etc.; 21 shipped today).
- `docs/F5-PENDING.md` — live status of the F5 series, audited against
  the codebase (not git history). Open work as of 2026-05-23:
  - **F5-b** — extract 50+ inline `factory(static fn …)` closures
    from `config/container.php` into `src/Piwigo/Core/Container/*Factory.php`.
    Boundary 5 (PSR-11). Out of §1.7 scope.
  - **F5-c** — rename `SessionService` → `SessionStore` (87 lines,
    6 call sites; cosmetic). `Session.php` typed wrapper itself is
    shipped (boundary 4). Out of §1.7 scope.
  - **F5-h** — Result DTOs sparse (7 vs 83 Params); F5-PENDING lists
    the same 11 zero-params endpoints I identified above as a
    low-hanging chunk for 100% coverage. **§1.7 Phase 1 territory.**
  - **F5-i** — `SearchRules` deep adoption (200+ mixed accesses;
    `SearchFilterRenderer` is the #1 Psalm-info hotspot at 67 issues).
    Boundary 2 (Stored JSON). Out of §1.7 scope; load-bearing for the
    F5-k psalm-info gate.
  - **F5-k** — acceptance gates: `psalm --show-info <50` (currently
    **1814**), `grep 'is_array(.* ?? null)' src/` count = 0 (currently
    154), every `@psalm-suppress` / `@phpstan-ignore` has rationale
    (28 sites need re-audit).
  Suggested order per F5-PENDING: F5-i → F5-h → F5-b → F5-c → residue.

#### Verification

The repo runs PHPStan at level 10 with **no baseline file** and Psalm
at errorLevel 2 with **no baseline file** (only two narrow
`<issueHandlers>` suppressions in `psalm.xml`). The original §1.7 draft
proposed "baseline diff per migration" as evidence; that workflow
doesn't apply for errors. Errors-only state (2026-05-23): PHPStan
clean, Psalm clean (fixed in commit `dd15d9bf4` as a §1.7 prerequisite).

**Info-level signal exists separately.** `psalm --show-info` reports
**1815 informational issues** today; the F5-k acceptance gate in
`docs/F5-PENDING.md` targets `<50`. Top hotspots:
`SearchFilterRenderer.php` (67), `CategoryRepository.php` (57),
`SectionInitializer.php` (56), `TelemetryService.php` (55) — the
SearchRenderer one alone is load-bearing for F5-i (deep `SearchRules`
adoption). §1.7 migrations should not increase the 1815 count and
should opportunistically reduce it.

Replacement evidence per migration PR:

- `composer analyse` (= phpstan + psalm) stays clean — any tightening
  that introduced a regression would fail level-10 / errorLevel-2
  immediately.
- Diff inspection — the value of the migration is **removed defensive
  `is_string` / `is_numeric` / `is_array` guards at call sites** that
  the loose return type forced. Count those removals in the PR
  description as the concrete win.
- Snapshot test of one full request path through the new boundary
  (e.g. comment submission round-trip for Phase 1; a typed
  `ImageRepository` consumer for Phase 2 — adapt to whatever the
  migrated repo exposes).
- `composer test && composer lint` clean after each migration.
- **Phase 1 migrations:** `tests/Integration/WsApiTest.php` (292
  lines) exercises the WS contract end-to-end — must stay green;
  any handler-shape change has to keep this test passing.
- **Phase 2 migrations:** `tests/Integration/Repository/*` (e.g.
  `SearchRepositoryTest.php`, `ThemeRepositoryTest.php`) and
  `tests/Unit/{Image,Category,Tag,Comment}/Entity/*Test.php` (5 files,
  26 tests, 142 assertions) exercise repository contracts + entity
  factories — must stay green.
- For Phase 2: `composer test:parallel` passes — surfaces regressions
  the regular unit suite misses.
  ⚠ `tools/openapi-dump.php` is **broken on `16.x-rewrite` head as of
  2026-05-23** (`Piwigo\Users\User::__construct()` TypeError at line
  56 — passes `string` where `UserStatus` enum is expected). The script
  is a useful Phase 2 evidence gate once fixed, but isn't usable today.
  Don't add it to the per-migration checklist until the User-construction
  bug is resolved (separate issue).

---

### 1.8 Test infrastructure

**Status:** 🟡 Not started · **Effort:** M + L + S · 3 chained items

Three coupled items, sequenced because each enables the next.

#### 1.8.1 Pest — first

**Status:** 🟡 Not started · **Effort:** M

Replace the dual-runner setup (PHPUnit 13 for unit tests + Playwright
TypeScript for E2E) with a single `vendor/bin/pest` command.

##### Install

```bash
composer require pestphp/pest --dev --with-all-dependencies
vendor/bin/pest --init
composer require pestphp/pest-plugin-browser --dev
```

Pest is PHPUnit-compatible — the existing 378 unit tests in `tests/Unit/`
run unchanged with `vendor/bin/pest`. The browser plugin installs
Playwright via Node.js under the hood
(`npx playwright install --with-deps chromium`).

##### Configure the browser plugin

```php
// tests/Pest.php
use function Pest\Browser\browser;

uses()->browser(baseUrl: 'http://localhost')->in('tests/Browser');
```

##### Port E2E specs from TypeScript to PHP

```typescript
// before — tests/E2e/gallery-home.spec.ts
test('gallery home loads', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('h1')).toBeVisible();
});
```

```php
// after — tests/Browser/GalleryHome.php
test('gallery home loads', function () {
    browser()
        ->visit('/')
        ->assertVisible('h1');
});
```

Port all 16 specs. Keep one PHP file per existing `.spec.ts` for
reviewability.

##### Migrate unit tests to `expect()` style (incremental, optional)

Raw `$this->assert*` works unchanged — migrate only when a test file is
touched for other reasons. New tests written from this point use
`expect()` style.

##### Update CI

Replace the two-step setup with a single `vendor/bin/pest` step. Group
unit and browser into separate Pest groups so each can run in isolation
during debugging:

```yaml
- run: vendor/bin/pest # all
- run: vendor/bin/pest --group=unit # unit only
- run: vendor/bin/pest --group=browser # browser only
```

##### Drop TypeScript test infrastructure

```bash
rm -rf tests/E2e playwright.config.ts
```

Remove the `npx playwright test` step from CI. Vitest stays for TS unit
tests (separate boundary — see 2.2).

##### Verification

```bash
vendor/bin/pest                              # all green
find tests/E2e -name '*.spec.ts' | wc -l     # 0 — all ported
find tests/Browser -name '*.php' | wc -l     # 16 — one per original spec
```

#### 1.8.2 Unit-test coverage 13% → ≥40%

**Status:** 🔵 Continuous · **Effort:** L · depends on Pest landing

Coverage baseline is 13% of `src/` statements today across 378 test
methods. Target ≥40%. Continuous work — does not gate later items.

##### Establish baseline

```bash
vendor/bin/pest --coverage-html=coverage/
open coverage/index.html
```

Record namespaces below 20% — those drive priority.

##### Priority order

1. **Core typed services** (`Config`, `PageState`, `Lang`, `CurrentUser`,
   `Kernel`). Highest leverage — they underpin every
   other component. Target 90%+ each.
2. **WS encoders** (`PwgJsonEncoder`, `PwgRestEncoder`, `PwgXmlWriter`,
   `PwgServer::register/verifyParams`). Pure logic, no DB. Target 85%+.
3. **Search Q-classes + Calendar.** Already partially covered. Add
   `AbstractDbStub` to `tests/Unit/stubs/` returning canned result sets
   for the calendar classes that need DB.
4. **`ScriptLoader` + manifest logic.** With and without `dist/manifest.json`
   present. Use a temp-directory fixture in `setUp/tearDown`.
5. **`Admin/Image/ImageGd`** against `dev/fixtures/sample.jpg`. GD is
   always available in the test container; Imagick/ext_imagick backends
   stay integration-only.

##### Don't add to Unit suite

DB-dependent or HTTP-dependent tests belong in Integration or E2E. Unit
suite must stay fast (<10s currently).

##### Verification

```bash
vendor/bin/pest --coverage-text | grep 'Lines:'
# Target: ≥ 40.00%
```

#### 1.8.3 Mutation testing — Infection — last

**Status:** 🟡 Not started · **Effort:** S · depends on coverage from 1.8.2

Mutation testing complements coverage % — high coverage with weak
assertions still scores low MSI, surfacing tests that exercise but don't
verify.

##### Install

```bash
composer require --dev infection/infection
```

##### Configure `infection.json5`

```json5
{
  $schema: 'vendor/infection/infection/resources/schema.json',
  source: { directories: ['src'] },
  logs: {
    text: 'build/infection/log.txt',
    summary: 'build/infection/summary.json',
    html: 'build/infection/report.html',
  },
  mutators: { '@default': true },
  phpUnit: { configDir: '.' },
  minMsi: 60,
  minCoveredMsi: 75,
}
```

##### Initial sweep

```bash
vendor/bin/infection --threads=4
```

Triage surviving mutants — most will be assertions that should be
tightened (`assertNotEmpty($result)` → `assertSame($expected, $result)`).

##### CI integration

Mutation testing is slower than unit tests — run on `main` push (after
merge), not on every PR:

```yaml
mutation:
  runs-on: ubuntu-latest
  if: github.event_name == 'push' && github.ref == 'refs/heads/main'
  steps:
    - uses: actions/checkout@v6
    - run: composer install --no-progress
    - run: vendor/bin/infection --threads=4 --min-msi=60 --min-covered-msi=75
```

##### Threshold ratchet

Start at MSI 60% / covered-MSI 75%. Raise to 70 → 80 over time as test
quality improves. Bumps require a PR with rationale.

##### Verification

```bash
vendor/bin/infection --threads=4 --min-msi=60 --min-covered-msi=75
# exits 0 on a clean run
open build/infection/report.html   # visualize surviving mutants per file
```

---

### 1.9 Deferred / on-demand

**Status:** 🟠 On-demand · 5 items · no scheduled effort

Real backlog — passive, executed only when a deployment or audit demands.
Each item has a clear trigger condition; do nothing until that condition
is met.

#### Logger backend swap to Monolog

**Trigger:** structured logging or external aggregation needed
(Elasticsearch, Loki, CloudWatch).

`Piwigo\Core\Logger` already implements `Psr\Log\LoggerInterface` — the
file/DB backend keeps working today. To swap:

```bash
composer require monolog/monolog
```

Replace `Piwigo\Core\Logger` factory in `config/container.php` with a
Monolog factory; leave callsites unchanged. The interface is the
contract.

#### File storage S3/SFTP adapters

**Trigger:** disk pressure on the local volume; multi-server deployment;
backup-offloading requirement.

```bash
composer require league/flysystem-aws-s3-v3   # or -sftp-v3
```

Edit the closure in `config/storage.php` for the disk that needs
offloading (`derivatives`, `uploads`, `exports`, etc.):

```php
// before — local
'derivatives' => fn () => new Filesystem(new LocalFilesystemAdapter('_data/i')),

// after — S3
'derivatives' => fn () => new Filesystem(new AwsS3V3Adapter(
    new S3Client([...]),
    'piwigo-derivatives',
    'derivatives/',
)),
```

Plugin code does not change — `StorageRegistry::disk('derivatives')->write(...)`
returns the new adapter. Disk names stay the same.

#### Worker daemon ops config

**Trigger:** first deployment that genuinely runs the worker daemon (not
just relies on lazy derivative generation in `ImageDerivativeController`).

Package supervisor / systemd unit files alongside the documented worker
command:

```bash
bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M
```

Currently ops-by-doc only. The worker runs derivative regen, async
notification email, batch upload assembly, search reindex — all advisory
or delay-tolerant work. Without a worker the queue stays idle and lazy
generation handles derivatives on first request.

#### Renovate dev-dep auto-merge workflow

**Trigger:** dependency-update churn that warrants automation.

Dependabot (current) ships no built-in auto-merge. Today's manual review
is fine for the cadence we see (a handful of PRs/week, all reviewable in
minutes). If churn grows or the team scales, port the auto-merge
workflow from the original Renovate spec.

#### Drop ScriptLoader dependency machinery

**Trigger:** next asset-pipeline change that touches `ScriptLoader::add()`
or `getFooterScripts()`, or when adding an entry whose declaration order
is non-obvious.

Follow-up to commit `9dcc8b15b` (concat-era cleanup). With every JS entry
now a Vite manifest entry, `<script type="module">` execution is
document-ordered, so the `require:` / `precedents` /
`computeScriptTopologicalOrder` / `checkLoadDep` plumbing in
`src/Piwigo/Template/ScriptLoader.php` is largely redundant. Its one
load-bearing job is the rule "predecessor of an `async` script must be
`footer`," which has 2 callers today
(`themes/_base/template/picture.latte` rating chain;
`themes/admin/_base/template/batch_manager_global.latte`). The other 13
`require:` uses are `footer` → `footer` chains where the Latte template
already declares the predecessor lexically first.

To remove:

1. Audit all 15 `require:`-using `.latte` templates; confirm the
   predecessor's `combineScript()` precedes the dependent's lexically.
   Reorder where needed.
2. Convert the 2 async-with-`require:` cases to plain `footer`, or split
   the chain so the predecessor stays in `footer` and the dependent's
   `async` doesn't need to wait.
3. Drop the `require:` parameter from `combineScript()` /
   `ScriptLoader::add()`, the `precedents` field on `Script`, and
   `computeScriptTopologicalOrder` / `checkLoadDep`.
4. Reduce `cmpByModeAndOrder` to a `load_mode` + `id` sort.

Net delta: another ~80 lines off `ScriptLoader.php` plus one named arg
gone from the public asset-pipeline surface.

---

### 1.10 `PHPWG_ROOT_PATH` elimination

**Status:** ✅ Done (2026-05-17) · **Effort:** L · 13 commits + 1 fix

`define('PHPWG_ROOT_PATH', './')` was the last surviving piece of pre-PSR-4,
pre-DI Piwigo bootstrap — a global string with **195 reads across 72 files**
that carried _dual_ semantics (URL-relative prefix AND filesystem path).
Replaced by `Piwigo\Core\Paths`, an immutable value object minted once from
the entry point's physical location and threaded explicitly through DI:

```php
// index.php — the entire bootstrap setup
require_once __DIR__ . '/vendor/autoload.php';
$paths = Paths::fromIndex(__FILE__);
```

`Container::build(Paths $paths)` binds it; `Kernel::boot/bootMinimal` and
`CommonBootstrap::run` accept it; service constructors declare
`private readonly Paths $paths`. URL-context callers (`HtmlService`,
`UrlService::getRootUrl`, `CookieService::cookiePath`, `SectionInitializer`)
were simplified separately — the "URL root" in v17 is always the empty
string (the legacy `./`-stripping dance produced the same result), so those
prefixes are gone too. Net –18 LOC just on the URL cleanup pass.

Full phase-by-phase narrative in [docs/ROADMAP-PHP.md #33](docs/ROADMAP-PHP.md#33--eliminate-phpwg_root_path-global-replace-with-typed-paths).
A regression detected mid-migration (absolute filesystem paths leaking into
`<a href>` because tests don't assert on rendered URL prefixes) added a
permanent verification step:

```bash
curl -sS http://localhost/piwigo16/ | grep -c '/home/' && echo 'leak!'
```

### 1.11 Runtime `define()` retirement

**Status:** ✅ Done (2026-05-18) · **Effort:** M · 7 commits + 1 follow-up

After §1.10 retired `PHPWG_ROOT_PATH`, twelve runtime `define()`-style
constants survived as residual pre-PSR-4 globals. A survey turned up a
mix of pure dead code, mechanical moves, and a few that needed proper
typed homes. All twelve are now retired.

Dispositions:

- **Pure deletions** — `PHPWG_DOMAIN` (3 writers, 0 readers — 36 lines of
  locale-keyed `*.piwigo.org` subdomain switching, never consumed) and
  `PWG_HELP` (2 writers, 0 readers).
- **Class constants** — `BUTTONS_RANK_NEUTRAL` →
  `Template::BUTTONS_RANK_NEUTRAL`, `DEFAULT_PREFIX_TABLE` →
  `InstallController::DEFAULT_DB_PREFIX`, `PHPWG_URL` →
  `AppInfo::PROJECT_URL = 'https://piwigo.example'` (RFC 2606 reserved
  TLD as a placeholder pending the fork-website launch — telemetry
  failure-closed semantics preserved).
- **Inlined at use site** — `PHOTOS_ADD_BASE_URL` becomes
  `$urlGenerator->admin('photos_add')` at the four readers;
  `PREFIX_TABLE` becomes `Tables::upgrade()` in `UpgradeFeedController`;
  `UPGRADES_PATH` becomes `$this->paths->root . 'install/db/'`.
- **Routed through existing Paths** — `PWG_LOCAL_DIR` reads migrate to
  `$paths->local` (already on `Piwigo\Core\Paths` since §1.10); 17
  composition sites updated, 6 writers deleted.
- **Replaced by typed context flags** — `PWG_API_KEY_REQUEST` becomes
  `Piwigo\Http\ApiKeyAuthRegistry::isApiKeyAuth()`, a thin static
  singleton matching the established `RequestContextRegistry` shape;
  `defined('UPGRADES_PATH')` in `LangService` becomes
  `RequestContextRegistry::current() !== RequestContext::Upgrade`.
- **Replaced by typed service** — `PEM_URL` becomes
  `Piwigo\Admin\PemUrlResolver::url()`, constructor-injected into the
  9 services that consume the extension-marketplace base URL
  (Plugins/Themes/Languages/Updates/TelemetryService/ExtensionsController).

The final invariant:

```bash
grep -rn 'define(' src/ index.php config/ tools/ --include='*.php' --include='*.phpstub'
# returns only doc-comment hits explaining what each legacy define()
# was replaced with — zero live calls.
```

Full phase-by-phase narrative in [docs/ROADMAP-PHP.md #34](docs/ROADMAP-PHP.md#34--retire-the-remaining-define-constants).

---

## 2. TypeScript / frontend glue

### 2.1 `any` reduction 478 → 0

**Status:** ✅ Done · **Effort:** M · shipped 2026-05-22

`@typescript-eslint/no-explicit-any: error` is enforced and `npm run
typecheck` + `npm run lint:js` both exit clean. The 478 instances were
eliminated across a sequence of commits (`340f378c2` through `937c7c4b3`)
via typed `window` augmentations, `Record<string, unknown>` plugin-callback
casts, and explicit WS-response interfaces. `noUncheckedIndexedAccess` was
also enabled (`feat(ts)` commit) and all 197 resulting errors resolved.

#### Verification

```bash
grep -rn ': any\b\|as any\b\|(window as any)' themes/admin/_base/js/ themes/_base/js/ \
  --include='*.ts' | wc -l
# 0

npm run typecheck   # exits 0
npm run lint:js     # exits 0
```

---

### 2.2 Vitest unit tests

**Status:** 🟡 Not started · **Effort:** M

Today the only JS test infrastructure is Playwright E2E — useful for
end-to-end flows, slow and high-friction for testing pure functions like
validators, formatters, URL builders, and state reducers.

#### Install

```bash
npm i -D vitest @vitest/coverage-v8 happy-dom
```

##### `vitest.config.ts`

```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['themes/_base/js/**/*.test.ts', 'themes/admin/_base/js/**/*.test.ts'],
    environmentMatchGlobs: [['**/*.dom.test.ts', 'happy-dom']],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['themes/_base/js/**/*.ts', 'themes/admin/_base/js/**/*.ts'],
      exclude: ['**/*.test.ts', '**/types/*.d.ts', '**/plugins/**'],
      thresholds: { lines: 50, functions: 50, branches: 40 },
    },
  },
});
```

##### npm scripts

```json
{
  "test:unit": "vitest run",
  "test:unit:watch": "vitest",
  "test:unit:ui": "vitest --ui"
}
```

##### First wave — pure utility tests

Co-locate `<source>.test.ts` next to each module:

- `common.test.ts` — format/parse helpers (no DOM).
- `urls.test.ts` — URL builders (no DOM).
- `getPageData.test.ts` — JSON page-data extraction (uses happy-dom).

##### Second wave — state reducers

`batchManagerGlobal.test.ts` covers the reducer-shaped functions that
compute selection state, filter results, etc. These are pure given a
snapshot.

##### CI integration

Append to `.github/workflows/ci.yml`:

```yaml
- run: npm run test:unit -- --coverage
```

##### Threshold

Start at 50% lines / 50% functions / 40% branches. Raise to 70% after the
first wave. Track in `vitest.config.ts` so CI fails on regression.

##### Boundary with PHP test infra

1.8.1 Pest absorbs the _browser_ tests (Playwright →
`pest-plugin-browser`); Vitest stays for TS unit tests. Non-overlapping —
no item to merge across tracks.

##### Verification

```bash
npm run test:unit                  # all green
npm run test:unit -- --coverage    # ≥ 50% line coverage
```

---

### 2.3 Bundle size budgets

**Status:** 🟡 Not started · **Effort:** S

Per-entrypoint bundle size budgets gate every PR. Regressions block
merge. Bundle composition is visualizable as a CI artifact for debugging.

#### Install

```bash
npm i -D size-limit @size-limit/file vite-bundle-visualizer
```

##### Baseline first

```bash
npm run build && npx size-limit
```

Set budgets ~5–10% above today's measured numbers to allow normal drift.

##### `.size-limit.json`

```json
[
  { "name": "admin/admin", "path": "dist/assets/admin-*.js", "limit": "85 kB" },
  { "name": "admin/batchManager*", "path": "dist/assets/batchManager*-*.js", "limit": "60 kB" },
  { "name": "admin/picture_modify", "path": "dist/assets/picture_modify-*.js", "limit": "55 kB" },
  { "name": "themes/_base/script", "path": "dist/assets/script-*.js", "limit": "45 kB" }
]
```

`size-limit` measures gzipped size by default — that's the relevant
transfer cost.

##### CI integration

```yaml
- run: npm run build
- run: npx size-limit
```

Failure = budget must be raised in `.size-limit.json` with a one-line
rationale, or the change reworked. A PR that adds
`import _ from 'lodash'` (without tree-shaking) is rejected by the gate.

##### Optional visualizer

On `main` push (not every PR), run `vite-bundle-visualizer` and upload
the HTML as a workflow artifact. Use to debug regressions: which dep got
pulled in, which module bloomed.

##### Document the budget-change policy

In `CONTRIBUTING.md`: "Raising a budget requires a one-line rationale in
the PR description citing the trade-off."

---

### 2.4 Vendored library migration

**Status:** 🟡 Not started · **Effort:** S · webfonts only after the bundled-plugin removal

The original scope of this section was the vendored libraries inside the
five bundled plugins (video.js mirrors, Leaflet 0.7, CodeMirror v2,
tablesorter, jquery.addtags). With those plugins removed from core, the
only remaining vendored asset that warrants an npm migration is the Open
Sans webfont, kept under `themes/`.

#### Inventory

| Lib                     | Location                                                          | Pinned version           | Approx size | npm package                      |
| ----------------------- | ----------------------------------------------------------------- | ------------------------ | ----------: | -------------------------------- |
| Open Sans webfont       | `themes/admin/_base/fonts/open-sans/`                             | locally generated subset |     ~250 KB | `@fontsource/open-sans`          |
| Open Sans variable font | `themes/standard_pages/fonts/OpenSans-VariableFont_wdth,wght.ttf` | Google Fonts dump        |     ~340 KB | `@fontsource-variable/open-sans` |

Stays as static asset (cannot move to npm):

- Fontello custom-glyph subsets in `themes/admin/_base/fontello/` and
  `themes/_base/vendor/fontello/` — project-specific glyph builds, not
  packageable.
- ~~Bundled themes (`themes/elegant`, `themes/modus`, `themes/smartpocket`,
  `themes/bootstrap_darkroom`)~~ — **gone** alongside the bundled-plugin
  removal; the only themes shipped today are `themes/_base`,
  `themes/standard_pages`, and the admin trio (`admin/_base`, `admin/light`,
  `admin/dark`). Nothing here to migrate.
- `themes/_base/js/plugins/piecon.ts` — already authored TS, ~180 LOC, no
  maintenance burden.

##### Steps

```bash
npm i @fontsource/open-sans
# replace themes/admin/_base/fonts/open-sans/ — Vite serves WOFF2/CSS via npm
git rm -r themes/admin/_base/fonts/open-sans/
```

Same pattern for `@fontsource-variable/open-sans` replacing the Google
Fonts TTF dump in `themes/standard_pages/fonts/`.

##### Two-commit discipline

Per font:

1. Add the npm dep + glue (Vite font import in the right entrypoint).
2. Delete the vendor dir.

Two commits make the actual replacement reviewable; the deletion commit
is otherwise a 250 KB+ binary diff that hides the real change.

##### Verification

```bash
# Vendor disappears
git ls-files themes/admin/_base/fonts/open-sans/           # empty
git ls-files themes/standard_pages/fonts/                  # only the variable-font entry remains, then empty

# Dependency shows up where it belongs
jq '.dependencies' package.json | grep -E '@fontsource'

# Lint output stops mentioning the font path
npm run lint:css 2>&1 | grep open-sans
# empty

# Bundle still builds + smoke-tests pass
npm run build
npx playwright test
```

---

## 3. CSS / themes

### 3.1 Design tokens + Stylelint

**Status:** ✅ Done ▸ all 13 steps shipped · **Effort:** M (delivered) · `declaration-no-important: warning` reinstated; 689 → 101 `!important` (−85%)

#### Goal

- Stylelint passing for all first-party CSS with zero errors and no
  `!important` outside JS-toggled visibility rules.
- CSS custom properties for all repeated colors, spacing values, and
  breakpoints.
- Monolithic files split into per-concern files:
  `themes/admin/_base/theme.css` (9,635 lines) and
  `themes/_base/theme.css` (1,305 lines) become thin `@import` lists.
- Admin child themes (`light`, `dark`) reduced to `:root {}` variable
  override blocks.
- `themes/standard_pages/skins/*.css` refactored from hundreds of element
  overrides to single `:root {}` blocks.

##### Current state

- `themes/admin/_base/theme.css`: **9,561 lines**, still monolithic
  (60+ `/* name.css */` section markers baked in).
- `themes/admin/dark/theme.css`: **2,805 lines** — duplicates parent
  section headers; carries far more than color overrides.
- `themes/admin/light/theme.css`: **1,226 lines** — same problem.
- `themes/_base/theme.css`: **1,241 lines**, unsplit (currently just
  `@import "iconset.css"` + bulk content).
- `themes/_base/fix-khtml.css`: **15 lines**, orphan — zero references.
- `themes/_base/fix-ie5-ie6.css`, `fix-ie7.css`: referenced only from
  `<!--[if lt IE 7]>` / `<!--[if IE 7]>` conditional comments. IE
  conditionals are dead in modern browsers — both files are de facto
  orphans.
- `themes/standard_pages/skins/*.css`: **11 skin files** (cadmium,
  cobalt, default, fuchsia, green, lime, purple, red, sienna, silver,
  teal), each ~337 lines, each with **20 `!important` instances** —
  fight specificity with the parent theme.
- **~689 `!important` declarations** across first-party CSS:
  - `themes/admin/dark/theme.css`: 162
  - `themes/admin/_base/theme.css`: 150
  - `themes/admin/light/theme.css`: 45
  - `themes/admin/_base/css/**`: ~71 (pages/components combined)
  - `themes/standard_pages/`: ~235 (theme + 11 skins)
  - `themes/_base/theme.css`: 10, `print.css`: 1
  - Roughly half justified (child-theme load order, JS-toggled
    visibility); the rest disappear when the token system lands.
- Stylelint coverage and rules are correctly configured. Current state:
  **0 errors, 0 warnings**. The 328-error backlog from the tightened
  config (`color-named`, `no-duplicate-selectors`, unit/zero-length,
  single-line declarations) was cleared.
- `declaration-no-important` was tried as a warning then dropped — too
  noisy until the token system reduces the genuine count. Reinstate as
  warning once Step 15 lands.
- Zero CSS custom properties anywhere in project CSS (one-off
  `var(--col-w)` style hooks for inline-extracted dynamic blocks aside).
- No canonical breakpoints — `576px`, `640px`, `800px`, `1100px` used
  inconsistently.

##### Already done

Stylelint rule set, mechanical auto-fix, and inline-`<style>` extraction
are already in place. **All 13 steps done** (steps 12 + 15 shipped 2026-05-22 in `fb33fcc31`):

##### Steps

| Step | What                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ~~3~~  | ~~Delete orphans~~ ✅ `themes/_base/fix-khtml.css` deleted. `fix-ie5-ie6.css`, `fix-ie7.css`, `admin/_base/fix-ie7.css` were already gone.                                                                                                                                                                                                                                                                                                                                                             |
| ~~4~~  | ~~Split `themes/_base/theme.css`~~ ✅ 1,242 lines → 10-line `@import` list. 9 per-concern files: `utilities.css` (holds `:root{}` tokens), `menubar.css`, `content.css`, `calendar.css`, `gallery.css`, `picture.css`, `layout.css`, `forms.css`, `colors.css`.                                                                                                                                                                                                                                        |
| ~~5~~  | ~~Collapse search CSS variants~~ ✅ 34 `--search-*` tokens in `search.css` `:root {}`. All shared color rules moved into `search.css` via `var()`. Each variant file now holds only its `:root {}` overrides + structural-only rules unique to that mode. `clear-search.css`: 343 → 121 lines; `dark-search.css`: 333 → 98 lines. Total: 1,673 → 1,414 (−259 lines).                                                                                                                                |
| ~~6~~  | ~~Non-color design tokens~~ ✅ `:root {}` added to `themes/_base/css/utilities.css` with `--space-*`, `--radius-*`, `--z-*`. `_base` breakpoint: `600px` (not 576). Tokens used in `.switchBox`, `.mcs-side-results > div`, `.commentElement`.                                                                                                                                                                                                                                                          |
| ~~7~~  | ~~Color tokens for `themes/standard_pages/`~~ ✅ `:root {}` with 6 `--color-*` tokens added; all skin color rules absorbed into `theme.css` using `var()`.                                                                                                                                                                                                                                                                                                                                             |
| ~~8~~  | ~~Skin refactor~~ ✅ 11 skins × 337 lines × 20 `!important` → 11 × 8-line `:root {}` blocks. Bug fix: malformed merged selector in `default.css` corrected. Net: −3,290 lines (4,565 → 1,275).                                                                                                                                                                                                                                                                                                         |
| ~~10~~ | ~~Admin component tokens~~ ✅ 8 `--admin-filter-*` / `--admin-info-*` tokens in `_base/css/components/general.css`; `dark/css/components/general.css` collapses to 11-line `:root {}`. The Smarty `base.css.tpl` approach is obsolete (Smarty removed in §1.2 F) — CSS tokens via the existing per-theme `general.css` load are the correct mechanism. `{* Temporary solution *}` comment removed from `header.latte`.                                                                                  |
| ~~11~~ | ~~Split `themes/admin/_base/theme.css`~~ ✅ 9,521 → 9 lines (−99.9%). Phase B: 32 page-specific sections moved to per-page `css/pages/*.css` + `css/features/` (5 new files). Phase A: remaining 1,706 global lines split into 9 `css/base/` files (`utilities`, `navigation`, `layout`, `menubar`, `tabsheets`, `typography`, `notifications`, `gallery-view`, `icons`). `theme.css` is now a pure `@import` list. |
| ~~12~~ | ~~Slim admin child themes~~ ✅ 205 `!important` dropped from `dark/theme.css` (161) and `light/theme.css` (44). After Phase A, the child theme loads AFTER the base in the `combineCss` queue (same order -10 but higher counter), so its rules already win by cascade position — the `!important` was never needed. Full `:root {}` token system deferred: no rules are identical to the base; every rule genuinely overrides something, requiring --admin-* tokens throughout. |
| ~~15~~ | ~~`!important` final elimination~~ ✅ 689 → 101 (−85%). 172 removals (cascade-redundant), 47 newly documented with `/* keep: reason */`. Tier 1 (JS-toggled visibility, UA autofill, third-party libs) preserved. `declaration-no-important: warning` reinstated in `.stylelintrc.json` — exit code 0, 101 warnings remaining (84 in admin+`_base/css`, 17 across `themes/_base/css/*` + `themes/standard_pages/theme.css` + `tools/ws/ws.css`). |

##### Deferred follow-up — admin `:root {}` tokenization

Step 12 dropped the 205 redundant `!important` from `dark/theme.css` +
`light/theme.css` but did **not** slim the files themselves
(`dark`: 2,795 lines, `light`: 1,221 lines). The original plan target
of ~700/~300 lines requires a full `--admin-*` token system defined in
`themes/admin/_base/css/base/` and consumed throughout — every
child-theme rule today is a genuine override (none are identical to the
base), so the rewrite has to land token-by-token rather than as a
mechanical sweep. Treat as a future §3.1.bis when token coverage of the
admin base is itself a goal; not blocking any other roadmap item.

##### Concrete examples

###### Step 6 — token block at theme root

```css
:root {
  --space-xs: 5px;
  --space-sm: 10px;
  --space-md: 15px;
  --space-lg: 20px;
  --space-xl: 30px;

  --font-size-sm: 13px;
  --font-size-base: 15px;
  --font-size-lg: 20px;
  --line-height-base: 1.5;

  --radius-sm: 5px;
  --radius-md: 10px;

  --z-dropdown: 100;
  --z-overlay: 500;
  --z-modal: 1000;

  --bp-sm: 576px;
  --bp-md: 800px;
  --bp-lg: 1100px;
}
```

Add `/* Breakpoints: sm=576px md=800px lg=1100px */` at the top of every
file that uses media queries.

**Step 8 — skin reduces to `:root` overrides**

```css
/* default.css — before: 333 lines, 20× !important */
.button.primary,
button.primary {
  background: #f70 !important;
  color: white !important;
}
.divider {
  border-color: #d8d8d8 !important;
}
/* … 330 more lines fighting specificity … */

/* default.css — after: ~30 lines, 0× !important */
:root {
  --color-accent: #f70;
  --color-button-primary-bg: #f70;
  --color-button-primary-fg: white;
  --color-divider: #d8d8d8;
  /* … */
}
```

###### Step 10 — admin design tokens via Smarty

```smarty
{* themes/admin/_base/css/base.css.tpl *}
:root {
  --admin-bg:      {$admin_skin.page.backgroundColor};
  --admin-fg:      {$admin_skin.page.color};
  --admin-accent:  {$admin_skin.accent};
  --admin-border:  {$admin_skin.border};
}
```

Each child theme's `themeconf.inc.php` defines its `$admin_skin` array;
`themes/admin/_base/template/header.tpl` loads `base.css.tpl` with
`template=true` before the split CSS.

##### Target directory layout (post-split)

`themes/admin/_base/css/`:

```text
base/
  reset-defaults.css       ← "General defaults", forms, "Tables & forms"
  typography.css
  utilities.css            ← .u-* atomic classes
components/
  general.css              ← (existing)
  album_selector.css       ← (existing)
  batch_manager.css        ← (existing)
  flatpickr.css            ← (existing)
  pagination.css
  waiting.css
  tipTip.css
  menubar.css
  tabsheets.css
  dropdown.css
  search-bar.css
  datepicker.css
  tomselect-item.css       ← shared Tier 2 fix
pages/
  dashboard.css
  history.css                ← (existing)
  batch_manager_unit.css     ← (existing)
  batch_manager_global.css   ← (existing)
  picture_modify.css         ← (existing)
  picture-edit.css           ← "Picture Edit" + "Format tab"
  album-manager.css
  user-manager.css
  user-activity.css          ← (existing)
  user-list.css              ← (existing)
  comments.css               ← (existing)
  watermark.css
  upload.css
  photos_add_direct.css      ← (existing)
  plugins.css
  install-upgrade.css        ← (existing)
  intro.css                  ← (existing)
  rating.css                 ← (existing)
  cat-modify.css             ← (existing)
  cat-list.css               ← (existing)
  cat-search.css
  cat-perm.css
  albums.css                 ← (existing)
  permalinks.css             ← (existing)
  site_manager.css           ← (existing)
  configuration_display.css  ← (existing)
  configuration_sizes.css    ← (existing)
  maintenance-actions.css    ← (existing)
  maintenance-env.css        ← (existing)
  maintenance-sys.css        ← (existing)
  updates-pwg.css            ← (existing)
  menubar.css                ← (existing)
  help.css                   ← (existing)
features/
  selection-mode.css
  merge-options.css
  group-editor.css
  jqtree-overrides.css
  icons.css
base.css.tpl                ← Smarty token emitter (Step 10)
theme.css                   ← thin entry: @import the above in order
```

##### `!important` tier breakdown

**Tier 1 — Keep permanently.** Add `/* reason */` comment where missing:

| Reason                                                                                                                       | Files                                                                   | Count |
| ---------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | ----: |
| Child-theme load-order (child CSS loads before parent; overrides need `!important` until CSS variable migration is complete) | `themes/admin/dark/theme.css`, `themes/admin/light/theme.css`           |  ~150 |
| Third-party CSS override (search popin / mcs-search injects its own CSS)                                                     | `themes/_base/css/search.css`, `clear-search.css`, `dark-search.css`    |   ~30 |
| `[hidden]` HTML5 attribute beating `display: flex/block` class rules                                                         | `themes/admin/_base/theme.css`, `themes/_base/theme.css`                |     2 |
| JS-toggled visibility (`display: none/flex/block`)                                                                           | `themes/admin/_base/css/pages/user-list.css`, `user-activity.css`, etc. |   ~10 |

**Tier 2 — Fix with higher specificity (tom-select overrides).**
`batch_manager_unit.css` and `picture_modify.css` carry tom-select
`.ts-control .item` overrides at specificity (0,2,0); tom-select ships
`.ts-control > .item` at (0,1,1) — our specificity already wins,
`!important` is redundant. Verify against
`node_modules/tom-select/dist/css/tom-select.css`, drop `!important`,
extract the shared block into
`themes/admin/_base/css/components/tomselect-item.css`.

**Tier 3 — Fix internal specificity battles.**
These exist because rules that used to live in cascade order inside the
monolithic `theme.css` were extracted to per-page files (during inline-
style extraction) and lost their position advantage. Concrete hot spots:

| File                                                                   |  Count | Notes                                                      |
| ---------------------------------------------------------------------- | -----: | ---------------------------------------------------------- |
| `themes/admin/_base/css/pages/user-list.css`                           |     20 | Mixed: tom-select items + layout + a few JS-toggled (keep) |
| `themes/admin/_base/css/pages/picture_modify.css`                      |     11 | Tom-select `.item` (Tier 2)                                |
| `themes/admin/_base/css/components/general.css`                        |      9 | Buttons, head-buttons fighting parent specificity          |
| `themes/admin/_base/css/pages/albums.css`                              |      6 | Tom-select `.item` + margin                                |
| `themes/admin/_base/css/pages/maintenance-sys.css`                     |      4 |                                                            |
| `themes/admin/_base/css/pages/user-activity.css`                       |      3 | Excluding JS-toggled                                       |
| `themes/admin/_base/css/pages/history.css`                             |      3 |                                                            |
| `themes/admin/_base/css/pages/{batch_manager_unit,cat-list,intro}.css` | 2 each |                                                            |
| `themes/admin/_base/css/components/{album_selector}.css`               |      2 |                                                            |

Approach per instance: (1) note the property + selector, (2) grep for
conflicting rule, (3) fix by raising specificity, lowering source rule
specificity, or reordering within the file.

**Tier 4 — `themes/standard_pages/skins/*.css`.**
11 skin files × 20 `!important` ≈ 220 instances. Disappear once the
parent uses CSS variables and the skins reduce to `:root {}` overrides
(Step 8).

##### Verification

After each step:

```bash
bun run lint:css                                   # zero errors
git ls-files | grep -E 'fix-(khtml|ie5-ie6|ie7)'   # empty (after Step 3)
git ls-files | grep -E 'clear-search|dark-search'  # empty (after Step 5)
wc -l themes/admin/_base/theme.css                 # ≤ 30 (just @imports, after Step 11)
wc -l themes/_base/theme.css                       # ≤ 15 (just @imports, after Step 4)
grep -rn '!important' themes/standard_pages/skins/ # empty (after Step 8)
```

Visual smoke-test on the admin pages touched after each step: dashboard,
each sidebar section, toggle clear/roma via the head button, gallery
index/category/picture, search popin (light + dark), at least 3
`standard_pages` skins.

---

### 3.2 A11y audit — axe-core in Playwright

**Status:** 🟡 Not started · **Effort:** M · soft dep on 3.1

#### Goal

Integrate `@axe-core/playwright` into the existing E2E suite. WCAG 2.1
AA violations of severity _moderate_ and above fail CI. Existing
violations are triaged: fixable ones get fixed, justified exemptions go
into a documented allowlist.

##### Current state

- **Zero accessibility testing.** 16 Playwright E2E specs in `tests/E2e/`
  cover functional flows but never invoke axe-core.
- No `aria-*` audit, no focus-management tests, no contrast checks.
- Color contrast issues are likely once 3.1 design tokens land — values
  currently inline are easier to evaluate against tokenized variables.
- `package.json` has Playwright; no axe-core dependency yet.

##### Install

```bash
npm i -D @axe-core/playwright axe-core
```

##### Helper

```typescript
// tests/E2e/utils/a11y.ts
import AxeBuilder from '@axe-core/playwright';
import { expect, Page } from '@playwright/test';

export async function runA11y(page: Page, opts: { disable?: string[] } = {}) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag21aa', 'wcag2aa'])
    .disableRules(opts.disable ?? [])
    .analyze();
  const blocking = results.violations.filter((v) =>
    ['critical', 'serious', 'moderate'].includes(v.impact ?? '')
  );
  expect(blocking, JSON.stringify(blocking, null, 2)).toEqual([]);
}
```

##### Wrap critical pages

Add `runA11y(page)` calls in existing E2E specs covering:

- Gallery index, picture page, search results, login, register.
- Admin dashboard, batch manager, picture edit, user management.

##### Initial sweep

Run the augmented suite once. Snapshot every violation. Triage:

- **Fixable**: open one task per violation; fix in template / CSS /
  TypeScript.
- **Accepted with rationale**: add to a per-page `disable: [rule-id]`
  list with an inline comment explaining why (e.g., "color-contrast:
  this is a brand-color admonition; contrast verified manually at 4.6:1
  by the design team").
- **Out of scope**: vendor third-party libraries (Tom Select, jqTree) —
  listed in a global allowlist with version-pinned rationale.

##### Pair with 3.1

Most color-contrast violations dissolve once colors come from
`--color-*` variables — central change fixes all callers. So 3.1 first
means a smaller triage list for 3.2.

##### CI gate

The Playwright job (already in CI) now also enforces a11y. Any new
violation fails the build.

##### Document

Add an "Accessibility" section to `CONTRIBUTING.md` with:

- The rationale-on-disable rule.
- The workflow for adding new pages to the audit.

##### Verification

```bash
npx playwright test tests/E2e/                     # all pages green, a11y included
npx playwright test --grep '@a11y'                 # focused a11y-only run

# Regression check: introduce a button without label, expect failure
echo '<button>X</button>' > /tmp/probe && \
  ! npx playwright test tests/E2e/probe.spec.ts    # exits non-zero
```
