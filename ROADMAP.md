# Piwigo 16.x — Active Roadmap

> Open work only. Completed phases live in git history. The repository
> restructure plan is held out separately while its design decisions
> settle.

---

## At a glance (2026-05-10)

| §   | Section                       | Status                                        | Effort    | TL;DR                                                                                                                               |
| --- | ----------------------------- | --------------------------------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 1.1 | Concrete bugs                 | ✅ **Done** ▸ 9 / 9                            | —         | history pagination refactor shipped 2026-05-10 (6-query split + snapshot tests); cat-id gap closed without code change               |
| 1.2 | Templates pipeline            | ✅ **Done**                                   | XL        | waves 1+2+3 done — Smarty hygiene → 133/133 Latte conversion → deploy-time precompile (`composer precompile:templates`) + CI gate |
| 1.3 | Kill ServiceLocator + DI      | ✅ **Done**                                   | L         | constructor injection everywhere; `ServiceLocator.php` deleted; `DbConnection::get()` callers eliminated                            |
| 1.4 | Plugin / theme + WS           | 🟡 **Not started**                            | L         | `PluginInterface`, `ThemeInterface`, OpenAPI follow-ups; depends on §1.3                                                            |
| 1.5 | Security hardening            | 🟢 **Active** ▸ 1 / 6                         | M         | 4 waves: session cookie → lockout + rate limit → CSP/headers → `SECURITY.md`                                                        |
| 1.6 | Type correctness              | 🟡 **Not started**                            | M         | mixed-types · globals · schema metadata                                                                                             |
| 1.7 | Typed boundaries              | 🟡 **Not started**                            | L         | HTTP request DTOs (Phase 1) → repository entity layer (Phase 2)                                                                     |
| 1.8 | Test infrastructure           | 🟡 **Not started**                            | M + L + S | Pest → coverage → Infection (chained)                                                                                               |
| 1.9 | Deferred / on-demand          | 🟠 **On-demand**                              | —         | Monolog · S3/SFTP · supervisor · Renovate · ScriptLoader dep graph                                                                  |
| 2.1 | TS `any` reduction            | 🟡 **Not started**                            | M         | 478 → ≤250 patterns                                                                                                                 |
| 2.2 | Vitest unit tests             | 🟡 **Not started**                            | M         | TS unit-test runner + first wave                                                                                                    |
| 2.3 | Bundle size budgets           | 🟡 **Not started**                            | S         | per-entrypoint gzip limits in CI                                                                                                    |
| 2.4 | Vendored library migration    | 🟡 **Not started**                            | S         | Open Sans webfonts → `@fontsource` (scope shrunk after bundled-plugin removal)                                                      |
| 3.1 | CSS design tokens + Stylelint | 🟢 **Active** ▸ 3 / 13                        | M         | 10 live steps remaining                                                                                                             |
| 3.2 | A11y audit (axe-core)         | 🟡 **Not started**                            | M         | WCAG 2.1 AA gating                                                                                                                  |

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
- **1.7 typed boundaries.** Phase 1 (HTTP request DTOs) ↔ §1.4 WS endpoints
  — coordinate `PwgServer::addMethod()` migration so plugin authors meet one
  new pattern, not two. Phase 2 (repository entity layer) closes out the
  globals work in §1.6b — `$user`, `$page` etc. become typed entity reads.
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

**Status:** 🟢 Active ▸ Wave 1 done · **Effort:** XL · 3 sequential waves

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

**Status:** 🟢 Active ▸ Phases A + B + C + D.\* + E + F.0 + F + F.1 done · 133 / 133 .latte · `smarty/smarty` removed; `Template`'s handle-based API replaced with direct `.latte`-path calls · Wave 2 closed · **Effort:** XL · depends on Wave 1

##### Phase progress

| Phase            | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Status                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| A                | latte/latte 3.1, `TemplateEngine` interface, `LatteEngine`, `PiwigoExtension` (translate, translate_dec) + 7 unit tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| A.tooling        | `composer lint:latte` wrapper around `Latte\Tools\Linter` + parallel CI job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| A.tooling+       | `efabrica/phpstan-latte` vendored fork (PHP 8.5 + Latte 3.1 patches), engine bootstrap, custom `PiwigoLatteEngineResolver`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.1+B.2          | 18 PHP-passthrough filters + 8 stateless custom modifiers (l10n, explode, ternary, url_is_remote, is_admin, is_classic_user, get_device, get_gallery_home_url, get_extent)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.3              | 8 stateful asset functions (combineScript, getCombinedScripts, combineCss, getCombinedCss, defineDerivative, htmlHead, htmlStyle, footerScript) sharing `TemplateRegistry::current()` state                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.4              | prefilter_white_space + postfilterLanguage documented as intentionally not ported                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.5              | `LatteEngine::default()` factory using Piwigo's data location                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.6              | First template conversion: `themes/admin/_base/template/help.latte` (parallel with the .tpl) + 2 integration tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| C                | `tools/smarty-to-latte/convert.php` mechanical rewrite tool — 67 unit tests pin behavior, 40 private rewrite passes. Covers: foreach (with optional `name=`), dot-access (incl. PHP-property chains and `$arr.$varname` variable-index), if-not (any expression), Smarty operator keywords (`eq`/`neq`/`ne`/`gt`/`lt`/`gte`/`lte`/`is odd`/`is even`), `{else if}`→`{elseif}`, escape filter (any arg form), combine*script/css/get_combined*_, define_derivative, include path, printed-literal filter prefix, assign (named + positional, parenthesizes pipes), section→foreach, capture, literal, strip→spaceless, html_head/style/footer_script blocks, regex_replace→replaceRe, multi-arg pipe `:`→`,`, function definition (named + positional, dedupes Smarty 5 dual-form declarations), `$smarty.foreach.X.{index,iteration,first,last,total}`→`$iterator->_`, `$smarty.{now,server,cookies,capture}` residue rewrites, Smarty 5 `$item@index/iteration/first/last/total/key` iterator attributes, `{html_options}`/`{html_radios}`/`{math}` Smarty plugins → `htmlOptions`/`htmlRadios`/`math` PiwigoExtension function calls, `{counter}` strip, `{break}` idiom → `{breakIf}`, pipe-in-`{if}`, embedded `{$X}` print sub-tags inside tag args, backtick interp → `.` concat. **Audit-driven passes** (added during the 133-pair walkthrough): `addNoescapeToHtmlLiteralRepeats` (HTML literal `\|str_repeat:N`), `addNoescapeToHtmlBearingTranslations` (translate args containing markup), `addNoescapeToJsonScriptBlocks` (`<script type="application/json">` payloads). CLI driver `--force` flag (default skips existing `.latte` so manual `\|noescape` annotations aren't lost). | ✅ Done · audit walkthrough closed; remaining hand-fixes documented as non-generalisable (plugin HTML payloads, attribute-fragment vars, sprintf-built HTML, dynamic include scope-pass, `quick_search` arg-rename) |
| D.admin          | Convert ~55 admin templates (`themes/admin/_base/template/`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | ✅ Done · **70 / 70 lint-clean**. Iteration 3 lifted the remaining 32 templates by registering `htmlOptions` / `htmlRadios` / `math` as PiwigoExtension functions (Smarty plugin ports), adding converter rules for `{counter}` strip, user-defined function call → `{include NAME, k: v, …}` rewrite, embedded `{$X}` print sub-tags inside `{if}`/`{elseif}`/`{var}`, Smarty backtick string interpolation → `.` concat (Latte's `~` is rejected inside function-call args), Smarty 5 `$item@index/iteration/first/last/total/key` iterator-attribute syntax, `{if X}{break}{/if}` → `{breakIf X}` idiom, pipe-in-`{if}` (`$x\|count`→`count($x)`), nested-`:{round(...)}` filter-arg unwrap, `$arr.$varname`(variable-index dot-access),`{capture assign=NAME}`keyword variant, and an extended args parser that accepts`key = value`(with whitespace) plus expressions containing`,` `:` `\|` `()`. Two templates needed hand-fixes that don't generalise: `header.latte`(JSON config script tag rebuilt with`\|json_encode\|noescape`since Latte rejects`{$X}`print-statements inside JS string literals) and`queue.tpl`source (HTML attribute quoting flipped to single-quoted to escape inner`"` chars in the Smarty translate-string literal). |
| D.public         | Convert ~55 public theme templates (`themes/_base/template/` incl. `mail/`, `include/`, `help/` subtrees)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | ✅ Done · **55 / 55 lint-clean**. Iteration 4 added two converter rules (dot-access leading-expr now walks `->prop` PHP-property chains so `$block->data.qsearch` → `$block->data['qsearch']`; `combine_css` / `combine_script` regexes now allow nested `{...}` in path values for `path="…/{$themeconf.colorscheme}.css"`) and four PiwigoExtension filters (`count`, `strip_tags`, `str_repeat`, `default`, `date_format`) plus two functions (`url_is_remote` is now also exposed as a function, not just a filter; `l10n` likewise). Source-level hygiene fixes: `header.tpl` × 2 (`pwg-config` JSON now built via `[…]\|json_encode` array literal, working in both Smarty and Latte); `related_tags.inc.tpl` + `menubar_tags.tpl` (href-split-across-`{if}` rebalanced so each branch produces matching HTML — the original "split `<a … href=\\n{if}…\\n{/if}>`" idiom isn't representable in Latte's tag-aware parser). One hand-fix that doesn't generalise: `search.latte` blocks `{section name=day start=1 loop=32}` (one-off; .tpl source keeps Smarty form, .latte uses `{foreach range(1, 32) as $day}` and `--force` regen requires reapplying).                                                                                                                                                                                                                                      |
| D.standard_pages | Convert 7 templates in `themes/standard_pages/template/` (footer, header, identification, password, profile, register, toaster) + the orphan `themes/_base/local_head.tpl`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done · **8 / 8 lint-clean** on first conversion. The converter rules accumulated through D.admin + D.public covered every construct in this corpus — no rule additions, no source-level hygiene fixes, no hand-fixes required.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| E                | Implement `Piwigo\Template\Latte\PiwigoPolicy` sandbox for plugin-supplied templates                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | ✅ Done. `PiwigoPolicy` extends Latte's `Sandbox\SecurityPolicy` with two factory methods: `createPluginPolicy()` is the default-deny allowlist for plugin templates (permits structural tags, escape filters, the translation pair, read-only Piwigo helpers; denies `php`/`include`/`extends`/`do`, the asset-pipeline functions, filesystem-touching filters, `math()`, and opaque payload decoders); `createCorePolicy()` is the trusted-core superset that allows the asset-pipeline functions and `do`/`include`/`extends` while still keeping `{php}` denied. `LatteEngine` gained a `?Policy $policy` constructor arg + `LatteEngine::sandboxed()` factory that segregates the plugin compile cache (`templates_c/latte_plugin/`) from the trusted-engine cache so a malicious plugin can't poison core. 10 unit tests pin the allow/deny matrix and round-trip representative `SecurityViolationException` cases (`{php}`, `\|file_exists`, `combineScript()`) through a sandboxed engine. Plugin-loader integration lives in §1.4.                                                                                                                                                                                                                                                                                                                                                           |
| F.0              | Runtime engine routing facade in `Template::parse()`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | ✅ Done. `Template::parse()` dispatches by file extension: `.latte` paths route to a private `renderLatte()` that threads Smarty's accumulated template-vars through `LatteEngine::default()` and resolves the bare filename against Smarty's `template_dir`. Smarty plugin pre/post filters and `compile_id` language-cache keys are deliberately not applied to Latte — Latte caches by content hash and plugin extension lands separately in §1.4. All in-tree controller and service call-sites now register `.latte` filenames (last two flips landed in `MailService.php`: the `mail-css-{theme}` theme-overlay CSS check at line 567 and the dynamic `{tplFilename}` mail-content selector at line 603). The Smarty branch of the dispatcher is now unreachable from in-tree code; Phase F removes it along with the `smarty/smarty` dependency. **Hard-won lesson from an early reverted bulk flip:** lint-clean ≠ runtime-safe. 13 templates contained `$smarty.foreach.X.Y`, `$smarty.now`, `$smarty.server.X`, `$smarty.cookies.X`, `$smarty.capture.NAME` references — Smarty's implicit globals that lint-pass under Latte (the bracket form `$smarty['foreach']['X']` looks like a normal array access) but fail at render with "Undefined variable $smarty". Converter now rewrites all five residue families (matches both the dotted form and the bracketed form left by `rewriteSmartyDotAccess`); `rewritePrintedLiteralFilter` widened to accept function-call and parenthesized leading exprs so `{time()\|...}`and`{($\_SERVER['X'] ?? '') \|...}`get the`{=...}`print marker.`LatteEngine::default()`and`::sandboxed()`chmod their cache dirs 0o775 after creation so`\_data/templates_c/latte\*`is shareable between Apache (`www-data`) and CLI (developer); without the chmod, mode bits clamp the parent ACL mask to r-x and whoever creates the dir first locks the other out. Each controller flip got e2e validation, not just lint;`\|noescape`annotations on raw-HTML prints survive across`--force`regen at the .latte level only (the converter is intentionally faithful and never auto-adds`\|noescape`). |
| F                | Drop `smarty/smarty`; strip Smarty internals from `Template`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done. `Template` (1322 → 456 lines, –866) lost all Smarty machinery: 50+ `registerPlugin` / `registerFilter` calls, plugin handlers (`blockHtml{Head,Style}`, `blockFooterScript`, `funcDefineDerivative`, `funcCombine{Script,Css}`, `funcGetCombined{Scripts,Css}`), filter callbacks (`modcompiler{Translate,TranslateDec}`, `modExplode`, `modTernary`, `prefilterWhiteSpace`, `postfilterLanguage`, `prefilterLocalCss`), and the plugin-extension surface (`setExtent(s)`, `setPrefilter`/`setPostfilter`/`setOutputfilter`, `loadExternalFilters`/`unloadExternalFilters`). The `$this->vars` array replaces Smarty's variable bag; `$this->template_dirs` replaces `Smarty::setTemplateDir`; `parse()` always renders via `LatteEngine::default()`. Public API unchanged so callers don't move (Phase F.1). Companion deletes: `PwgTemplateAdapter` (Smarty's `$pwg.X` accessor — `derivative()` ported to `PiwigoExtension` as a Latte function so 4 templates that used `$pwg->derivative(...)` continue to work); 137 `.tpl` source files (133 in core themes + 4 sample plugin templates under `template-extension/distributed/samples/`); `smarty/smarty: ^5.0` from `composer.json`/`composer.lock`. New methods on `Template`: `templateExists($file)` replaces the public `$tpl->smarty->templateExists()` accessor used by mail rendering. Stale doc comments in `LatteEngine` and `TemplateEngine` updated to reflect the single-engine post-Phase-F state. |
| F.1              | Migrate callers off `Template`'s handle-based API to direct `.latte`-path calls                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | ✅ Done. `Template::parse($handle, $return)` / `pparse($handle)` / `assignVarFromHandle($var, $handle)` were a Smarty-era inheritance — controllers always called `setFilename($handle, $file.latte)` immediately followed by `parse($handle, …)` in the same method, so the handle indirection added no value over passing the path directly. Public API now: `parse($file, $return = false)`, `pparse($file)`, `assignVarFromTemplate($var, $file)` — each takes the bare `.latte` filename (resolved against the registered template directories) or an absolute path. Companion deletes: `setFilename`, `setFilenames`, `assignVarFromHandle`, the `$files` map. ~30 consumer files migrated (controllers under `Controller/` + `Controller/Admin/`, plus `Page/*Renderer`, `Mail/MailService`, `Admin/Tabsheet`, `Admin/Integrity/CheckIntegrity`, `Admin/Notification/NotificationAdminService`, `Category/CategoryCatsRenderer` + `CategoryDefaultRenderer`, `Picture/PictureCommentRenderer` + `PictureContentRenderer`, `Tag/SelectedTagsRenderer`, `Menu/BlockManager`, `Core/Util`). Output buffer + `scriptLoader` / `cssLoader` + button accumulators + theme-search-path infrastructure stay where they are (page-coordination state, not engine-specific). |

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

| Smarty                                                                                                                                                                                                                                                          | Latte equivalent                                                                                                                                                                                                                                                                                                         | Status                                                                                                                                             |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `\|translate`, `\|translate_dec`                                                                                                                                                                                                                                | filters in `PiwigoExtension` backed by `Piwigo\Core\Lang::t` / `Piwigo\Lang\Translator::plural`                                                                                                                                                                                                                          | ✅ Phase A                                                                                                                                         |
| `\|l10n`                                                                                                                                                                                                                                                        | filter alias for `\|translate` (same `Lang::t` callback)                                                                                                                                                                                                                                                                 | ✅ Phase B.2                                                                                                                                       |
| `\|sprintf`, `\|urlencode`, `\|intval`, `\|file_exists`, `\|constant`, `\|json_encode`, `\|json_decode`, `\|htmlspecialchars`, `\|stripslashes`, `\|in_array`, `\|ucfirst`, `\|trim`, `\|md5`, `\|strtolower`, `\|is_null`, `\|is_file`, `\|strpos`, `\|sizeOf` | filters dispatched directly to PHP first-class-callable functions                                                                                                                                                                                                                                                        | ✅ Phase B.1                                                                                                                                       |
| `\|implode`, `\|str_replace`, `\|str_ireplace`, `\|preg_match`, `\|strstr`, `\|stristr`, `\|array_key_exists`                                                                                                                                                   | **deliberately omitted** — Smarty pipes the value first, but PHP wants `$glue`/`$search`/`$pattern` first; PHP 8's deprecation of swapped args makes the legacy form a TypeError. Verified zero pipe usage in `themes/` + `plugins/`; templates using these in Latte call them as expressions: `{=implode(',', $arr)}`.  | ✅ Phase B.1 (intentional non-port)                                                                                                                |
| `\|explode`, `\|ternary`, `\|url_is_remote`, `\|is_admin`, `\|is_classic_user`, `\|get_device`, `\|get_gallery_home_url`, `\|get_extent`                                                                                                                        | one-line wrappers in `PiwigoExtension` delegating to existing services (`UrlService`, `PermissionService`, `Util`, `UrlGenerator`)                                                                                                                                                                                       | ✅ Phase B.2 + B.3 (get_extent)                                                                                                                    |
| `{combine_script}`, `{get_combined_scripts}`, `{combine_css}`, `{get_combined_css}`, `{define_derivative}`                                                                                                                                                      | functions in `PiwigoExtension::getFunctions()`, called via `{do combineScript(...)}` (void) or `{var $x = defineDerivative(...)}` (returns); delegate to `TemplateRegistry::current()`'s `scriptLoader` / `cssLoader` so a `.latte` template's combine_script accumulates into the same bundle a `.tpl` template's would | ✅ Phase B.3                                                                                                                                       |
| `{html_head}`, `{html_style}`, `{footer_script}` blocks                                                                                                                                                                                                         | functions in `PiwigoExtension::getFunctions()`, called as `{capture $x}…{/capture}{do htmlHead($x)}`; `htmlStyle` writes through `Template::appendHtmlStyle()` (added in Phase B.3 — the buffer is private and shared between the two engines)                                                                           | ✅ Phase B.3                                                                                                                                       |
| `prefilter_white_space` filter                                                                                                                                                                                                                                  | **deliberately omitted** — Smarty-specific source rewrite; Latte handles whitespace differently and provides `{spaceless}` for explicit zones. Revisit only if profiling shows need.                                                                                                                                     | ✅ Phase B.4 (intentional non-port, documented in PiwigoExtension docblock)                                                                        |
| `postfilterLanguage` filter | **deliberately omitted** — Smarty constant-folds `<?php echo 'literal'?>` after `Lang::t('key')` resolution in `compiledTemplateCacheLanguage` mode. Latte equivalent would be a NodeVisitor compiler pass that rewrites `{=$x\|translate}`to a literal when`$x` is a string-literal expression and language caching is enabled. Defer until profiling justifies the optimization. | ✅ Phase B.4 (intentional non-port, documented) |

**Filter coverage shipped:** 27 filters (translate, translate_dec, l10n,
explode, ternary, url_is_remote, is_admin, is_classic_user, get_device,
get_gallery_home_url, get_extent, sprintf, urlencode, intval,
file_exists, constant, json_encode, json_decode, htmlspecialchars,
stripslashes, in_array, ucfirst, trim, md5, strtolower, is_null, is_file,
strpos, sizeOf).

**Functions shipped:** 8 (combineScript, getCombinedScripts, combineCss,
getCombinedCss, defineDerivative, htmlHead, htmlStyle, footerScript).

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
`themeconf.inc.php`'s `local_head` key, resolved by
`Template::setTheme()` against the theme root, not its `template/`
subdir). The single-root walk is also future-proof for §1.4 themes.

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
  (`templates_c/latte_plugin/`) is parked until §1.4 introduces plugin
  `.latte` templates; zero exist in tree today.
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

**Status:** 🟢 Active ▸ foundation partial · **Effort:** L · 3 phases

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

**Status:** 🟡 Not started

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

4. Migrate the ~217 `Piwigo\Plugins\EventDispatcher::dispatch()` /
   `::notify()` callsites to dispatch typed event objects through the
   new instance dispatcher.
5. Once the last callsite is gone, delete
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

| `main.inc.php` header  | `plugin.json` key  | Required |
|------------------------|--------------------|----------|
| (directory basename)   | `id`               | yes      |
| `Plugin Name:`         | `name`             | yes      |
| `Version:`             | `version`          | yes      |
| `Description:`         | `description`      | yes      |
| `Plugin URI:`          | `homepage`         | no       |
| `Author:`              | `author`           | no       |
| `Author URI:`          | `authorUri`        | no       |
| `Has Settings:`        | `hasSettings`      | no       |
| (no legacy header)     | `license`          | yes      |

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

```
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
            ParamDefinition::required(name: 'photo_id', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
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
`requiresAuth: bool` with `Piwigo\Ws\AccessLevel`:

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

   | Smarty form              | Plugin uses | Latte target                             |
   |--------------------------|------------:|------------------------------------------|
   | `{combine_script ...}`   |         391 | `{combineScript(...)}` (Piwigo extension)|
   | `{combine_css ...}`      |         381 | `{combineCss(...)}`                      |
   | `{footer_script}…{/foot…}` |       219 | `{block footer-scripts}…{/block}`        |
   | `{html_head}…{/html_head}` |        76 | `{block html-head}…{/block}`             |
   | `{html_style}…{/html_st…}` |        73 | inline `<style>` block                   |
   | `{html_options ...}`     |         139 | `{foreach}` over an options array        |
   | `{known_script ...}`     |          22 | `{knownScript(...)}` (Piwigo extension)  |
   | `{lang ...}`             |          88 | `{=$x\|translate}` or `{=t($x)}`          |
   | `{ldelim}` / `{rdelim}`  |         338 | literal `{` / `}` (Latte: `{l}` / `{r}`) |
   | `\|translate` modifier   |       1,318 | `\|translate` filter (Piwigo extension)  |
   | `\|cat` (concat)         |         212 | Latte `~` operator                       |

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

| Service (typed, via `$c->get(…::class)`)         | Replaces legacy function(s) / global                              | Plugin calls today |
|--------------------------------------------------|-------------------------------------------------------------------|--------------------|
| `Piwigo\Db\DbConnection` (Doctrine DBAL)         | `pwg_query`, `query2array`, `mass_inserts`, `mass_updates`        | 1315               |
| `Piwigo\Lang\LangService`                        | `load_language`, `l10n`, `l10n_dec`                               | 2700+              |
| `Piwigo\Url\UrlService`                          | `get_root_url`                                                    | 292                |
| `Psr\EventDispatcher\EventDispatcherInterface`   | `trigger_change`, `trigger_event`, `trigger_notify`               | 136                |
| `Piwigo\Core\StringUtil`                         | `safe_unserialize`, `safe_serialize`                              | 87                 |
| `Piwigo\Config\ConfigService`                    | `conf_update_param`, `conf_delete_param`, `$conf[…]` reads        | 65+ writes, 800+ reads |
| `Piwigo\Page\PageState`                          | `global $page`, `$page['errors'][]`, `$page['infos'][]`           | 227 globals reads  |
| `Piwigo\Users\CurrentUser`                       | `global $user`, `is_admin`, `is_webmaster`                        | (same 227)         |
| `Piwigo\Users\UserCacheService`                  | `invalidate_user_cache`                                           | 34                 |
| `Piwigo\Users\UserService`                       | `get_username`, `get_user_language`, etc.                         | ~10                |
| `Piwigo\Session\SessionService`                  | `$_SESSION[…]` direct reads/writes                                | 49 plugins         |
| `Piwigo\Security\CsrfTokenService`               | `get_pwg_token`, `check_pwg_token`                                | 39 plugins         |
| `Psr\Log\LoggerInterface`                        | `pwg_log`                                                         | (low; coarsely tracked) |
| `Piwigo\Image\DerivativeService`                 | `DerivativeImage`, `get_derivative_url`, `derivative_path` reads  | 77 plugins         |
| `Symfony\Contracts\HttpClient\HttpClientInterface` | `curl_init`/`curl_exec` raw, `fetchRemote`                      | 33 plugins         |
| `Piwigo\Mail\MailService`                        | `pwg_mail`                                                        | 25 plugins         |
| `Piwigo\Cache\PersistentCache`                   | `cache_set`/`cache_get`/`PERSISTENT_CACHE`                        | 5 plugins          |
| `Piwigo\Storage\LocalStorage`                    | `PWG_LOCAL_DIR` reads, `_data/` writes, raw `mkdir`/`file_put_contents` for plugin data | 82+47+54+11 ≈ 194 hits |
| `Piwigo\Session\FlashService`                    | one-shot `$page['infos'][]` / `$page['errors'][]` writes survived across a redirect      | (post-redirect-GET pattern) |
| Typed entity repositories (`ImageRepository`, `UserRepository`, `AlbumRepository`, `TagRepository`) | direct table reads (`IMAGES_TABLE` 425, `USERS_TABLE` 133, `CATEGORIES_TABLE` 214, `TAGS_TABLE` 45) | 800+ table-constant references |

(Some services already exist in `src/Piwigo/…/`; `UserCacheService`,
`PageState`, `CurrentUser`, `SessionService`, `CsrfTokenService`, and
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

| Legacy surface                  | New mechanism                                                   |
|---------------------------------|-----------------------------------------------------------------|
| `$GLOBALS['…']` reads/writes    | typed services + PSR-7 request/response                         |
| `$conf` array access            | `ConfigService` (typed reads, validated writes)                 |
| top-level `pwg_query()`         | `DbConnection` from the container                               |
| `$page['errors'][] = …`         | typed `PageState` accumulator on the response                   |
| `pwg_log(…)`                    | PSR-3 `LoggerInterface` from the container                      |
| `set_status_header()`, `redirect()` | return `ResponseInterface` from the handler                 |
| `script_basename()` (113 callsites) | PSR-15 controllers know their route via request URI         |
| `IN_ADMIN` constant             | route-based admin gating in the dispatcher                      |
| `PHPWG_ROOT_PATH` (1,041 file uses) | constructor-inject `Piwigo\Core\PathService` or `string $rootPath` |
| `defined('PHPWG_ROOT_PATH') or die(...)` guard | gone — controllers run only when routed     |

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
   (13 callsites) maps to standard gettext `ngettext`-shaped
   `LangService::tn(...)`.

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
uses the same `Piwigo\Ws\AccessLevel` enum that `MethodDefinition`
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
and they migrate to the new `CsrfTokenService` (see the API surface
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

**Status:** 🟡 Not started · depends on Phase 1

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
and `ServiceLocator::get(ConfigService::class)->confGetParam(...)` at
file-include time. That code moves into `boot()` where it has DI access:

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

**Status:** 🟡 Not started · depends on Phase 1

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

2. Teach `SpecBuilder` to walk registered endpoint classes via
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

**Status:** 🟢 Active ▸ 1 of 6 sub-tasks done · **Effort:** M

CSRF middleware is already in place (centralized in `CsrfMiddleware`
during the front-controller work). It validates `pwg_token` on POST
requests, with a small allow-list for endpoints that don't carry CSRF
state (`/ws`, `/install`, `/upgrade`, `/identification`, `/register`).

The five remaining sub-tasks are sequenced into four PR-sized waves.
Each merge keeps CI green and improves the security posture
monotonically. Wave order is set so the docs (Wave D) describe the
final posture on first publish, and so the no-DB changes (Wave A) land
before the migration-bearing wave (Wave B).

#### Wave A — Session cookie hardening + `SECURITY.md` skeleton

**Status:** 🟡 Not started · **Effort:** XS

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

**Status:** 🟡 Not started · **Effort:** M

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

| Policy           | Limit       | Reset window |
| ---------------- | ----------- | ------------ |
| `login_ip`       | 5 attempts  | 1 minute     |
| `login_account`  | 10 attempts | 10 minutes   |

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

**Status:** 🟡 Not started · **Effort:** S

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

**Status:** 🟡 Not started · **Effort:** XS

Replace the Wave A skeleton with the real content once the posture
is locked:

- **Threat model.** Anonymous attackers (credential stuffing, CSRF,
  XSS, clickjacking), authenticated low-priv attackers (privilege
  escalation, IDOR), supply chain (composer/npm). Map each to the
  defense in tree.
- **CSP override procedure.** Documented as "not yet supported, file
  an issue"; the §1.4 plugin work is the proper home for relaxation
  hooks.
- **Account-lockout admin runbook.** Manual unlock via
  `DELETE FROM phpwg_user_failed_logins WHERE user_id = …` until
  the §1.4 admin-UX work lands a button.
- **Vulnerability reporting.** Private channel + SLA. Channel choice
  confirmed before merge.

##### Deferred to follow-ups

- **WS-API login lockout.** `/ws` bypasses `IdentificationController`.
  Wave B should also wire the limiter + throttle into
  `WsAuthMethods::login()`; confirm scope during Wave B
  implementation.
- **Config keys for thresholds.** Gated on §1.6c (config-schema
  metadata).
- **Admin UI for unlocking users.** Gated on §1.4 admin-UX work.
- **CSP `report-uri` / `report-to`.** No reporting endpoint to design
  yet; revisit if violations appear in production.
- **Per-plugin CSP relaxation hook.** §1.4 territory.
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

**Status:** 🟡 Not started · **Effort:** M · 3 streams (6 + 2 + 5 items)

After PHPStan level 10 landed, three threads tighten the remaining
mixed-type surface that doesn't require new architectural patterns:

- **5a** — six high-ROI mixed-type fixes from the codebase audit.
- **5b** — `$GLOBALS` cleanup that's been deferred since the
  modernization phases that retired the procedural layer. Gated by direct
  `$GLOBALS[...]` reads in `src/` being eliminated first.
- **5c** — five `Config::SCHEMA` enhancements left as deferred design
  surface from the schema work.

Architectural boundary work — typed entities for DB rows and typed DTOs
for HTTP input — was originally drafted under 1.6a (items 8–9) but lives
in §1.7 now. Same gating constraint, same review effort — tackle 1.6
as one section, work the streams in parallel where possible.

#### 1.6a Mixed-type fixes

**Status:** 🟡 Not started · 6 items

Six fixes ordered by effort. None require behavior changes — all are
type narrowings supported by existing runtime invariants.

| Item                                                                                                | Files | Effort           |
| --------------------------------------------------------------------------------------------------- | ----: | ---------------- |
| `ImageInterface::compose(mixed $overlay)` → `ImageInterface $overlay`                               |     4 | trivial          |
| `CookieService::getCookieVar()` → `?string` (cookies are always strings)                            |     1 | low              |
| `CategoryAdminService::deleteSite(mixed $id)` → `int\|string`                                       |     1 | trivial          |
| `Config::raw()` typed return — `string\|int\|bool\|float\|array<mixed>\|null`                       |     1 | low (annotation) |
| `EventDispatcher::dispatch()` → `@template T` generic — eliminates many downstream `mixed`s         |     1 | medium           |
| `RequestCache` / `PersistentCache` → `@template T` generic on `remember()` / templated value types  |     2 | medium           |

##### Concrete examples

###### ImageInterface::compose

```php
// before
interface ImageInterface
{
    public function compose(mixed $overlay, int $x, int $y, int $opacity): bool;
}

// after — every concrete implementation already passes ImageInterface
interface ImageInterface
{
    public function compose(self $overlay, int $x, int $y, int $opacity): bool;
}
```

###### CategoryAdminService::deleteSite

```php
// before
public function deleteSite(mixed $id): void

// after
public function deleteSite(int|string $id): void
```

Callers already narrow internally with `(int)` casts; the union-type
annotation just documents what's actually accepted. The other
`mixed $id`-shaped sites the original audit catalogued live inside
`array_map(fn (mixed $v) => …)` lambdas at DB call boundaries — those
get removed naturally as queries move into typed repository methods
under §1.7 Phase 2.

###### EventDispatcher generic

```php
// before — every caller widens to mixed
$result = $dispatcher->dispatch('foo_event', $someArray);
// $result is array<mixed>

// after — @template T preserves the input type, variadic split for templating
/** @template T */
class EventDispatcher {
    /**
     * @param T $data
     * @return T
     */
    public static function dispatch(string $event, mixed $data = null, mixed ...$extraArgs): mixed { /* … */ }
}
$result = EventDispatcher::dispatch('foo_event', ['k' => 1]);
// $result is array{k: int}
```

The variadic split is BC: the existing body already extracted
`$args[0] ?? null` as `$data` and passed the rest to handlers via
`call_user_func_array`. The new body reassembles `[$data, ...$extraArgs]`
before dispatch so handlers see identical positional arguments.

##### Sequencing

Land in this order, smallest blast radius first:

1. `CategoryAdminService::deleteSite` (1 line).
2. `ImageInterface::compose` (4 files, no caller updates).
3. `CookieService::getCookieVar` (1 file + 2 callers).
4. `Config::raw` — first tighten `Config::src()` phpdoc to the typed
   union, then annotate `raw()` (1 file + 23 callers verified by PHPStan).
5. `RequestCache` / `PersistentCache` templates (2 files + 13 callers).
6. `EventDispatcher::dispatch` (1 file + 217 callers verified by PHPStan).

#### 1.6b Globals cleanup

**Status:** 🟡 Not started · 2 items · gated by `$GLOBALS[...]` reads in `src/` being eliminated first

Both items below are gated by the same precondition — direct
`$GLOBALS[...]` reads in `src/` being eliminated first — so tackle them
together as one closing pass.

**Relationship to the entity layer (§1.7 Phase 2).** The `$user` global is
itself a raw DB row (`array<string, mixed>`). Once `UserRepository` returns
a typed `UserEntity`, `CurrentUser::get()` can expose typed properties
(`->id`, `->username`, `->status`) instead of routing through
`rawAttributes`. The globals cleanup and the entity layer are therefore
the same work seen from two angles: entities eliminate the need for
`$GLOBALS['user']`; retiring `$GLOBALS['user']` motivates finishing the
`UserEntity`.

##### Drop `$GLOBALS` reference bridges in `phpstan-bootstrap.php`

The bridges for `$page`, `$user`, `$lang`, `$template`, etc. exist only
because `src/` still does direct `$GLOBALS[...]` reads. The bridges are
type aliases, not runtime objects:

```php
// phpstan-bootstrap.php (current)
/** @var array<string, mixed> $page */
$page = &$GLOBALS['page'];
/** @var array<string, mixed> $user */
$user = &$GLOBALS['user'];
```

Once direct reads are gone, drop the bridges. PHPStan re-analyses with no
errors because the typed services (`PageState::current()`,
`CurrentUser::get()`) are the new entry points.

##### Retire `$GLOBALS` reads in renderers

Three renderers still read globals — residuals from earlier modernization
passes that didn't fully retire the value-object design:

- `PageHeaderRenderer.php:25` reads `$GLOBALS['page']`; line 59 writes
  back.
- `PageTailRenderer.php:56,62` reads `$GLOBALS['debug']` and
  `$GLOBALS['t2']`.
- `NoPhotoYetRenderer.php:26` reads `$GLOBALS['user']`.

Each should accept its dependencies via constructor or a per-method
typed context object:

```php
// after
final class PageHeaderRenderer
{
    public function __construct(private PageState $page) {}

    public function render(HeaderContext $ctx): string
    {
        $title = $ctx->title;
        $this->page->addMeta('description', $ctx->description);
        // …
    }
}
```

These are cheap to retire if the value-object design is later adopted in
full, but they're harmless given the reference-bridge model in
`Kernel::boot()`. Low priority — schedule when the bridge cleanup happens
anyway.

#### 1.6c Config schema metadata

**Status:** 🟡 Not started · 5 items

Five `Config::SCHEMA` enhancements that are still deferred design surface.
They're independent of each other; pick whichever delivers value first.

##### `'required' => true` field + validation

```php
// Config::SCHEMA additions
'db_host' => ['type' => 'string', 'default' => 'localhost', 'required' => true],
'secret_key' => ['type' => 'string', 'default' => '', 'required' => true],
```

`ConfigLoader::applyDefaults()` walks SCHEMA after env overrides and
throws if any required key is unset:

```php
foreach (Config::SCHEMA as $key => $meta) {
    if (($meta['required'] ?? false) && !Config::has($key)) {
        throw new MissingRequiredConfigException("Required config key '$key' is unset.");
    }
}
```

##### `'description'` field → populated reference doc

```php
'gallery_title' => [
    'type' => 'string',
    'default' => 'Piwigo',
    'description' => 'Title shown in the browser tab and in the gallery header.',
],
```

The generator that emits the config-reference doc reads
`Config::SCHEMA[$key]['description']` and writes it into the table. The
description column is empty for all 287 keys today.

##### `'sensitive'` field + `Config::dumpForLog()`

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

##### Namespace-prefix support in `ConfigStorage` for plugin keys

Today plugin keys collide in the global `config` table. After the change,
a per-plugin `Config` class can declare its prefix:

```php
namespace Piwigo\Plugin\OpenStreetMap;

final class Config
{
    private const PREFIX = 'openstreetmap.';
    public const SCHEMA = [
        'tile_provider' => ['type' => 'string', 'default' => 'osm'],
        'default_zoom' => ['type' => 'int', 'default' => 13],
    ];
}
```

`ConfigStorage` stores the row as `openstreetmap.tile_provider` so
plugins can use short keys without colliding.

##### `--target=<path>` flag on `tools/build-config-accessors.php`

The generator currently regenerates `src/Piwigo/Config/Config.php` only.
Add a `--target=<path>` flag so future per-plugin `Config` classes (or
any other generated accessor target) regenerate from the same tool:

```bash
php tools/build-config-accessors.php   # default — Piwigo Config
php tools/build-config-accessors.php --target=plugins/<id>/src/Config.php
php tools/build-config-accessors.php --check   # CI guard — no diff
```

Running with `--check` in CI catches accessor/SCHEMA drift across every
target the build script knows about.

---

### 1.7 Typed boundaries — HTTP input and DB rows

**Status:** 🟡 Not started · **Effort:** L · 2 phases

The codebase has two boundaries where untyped data crosses into the
domain: HTTP input (`$_POST`/`$_GET` is `array<string, mixed>`) and DB
rows (`fetchAssociative()` returns `array<string, mixed>`). Both are
solved by the same architectural pattern: a single-cast factory at the
boundary that produces a typed object, and business logic that consumes
typed properties without `is_*` guards.

This section was extracted from §1.6 because it's no longer tactical
type tightening — it introduces new patterns, new vocabulary, and (for
HTTP) new dependencies. It's comparable in scope to §1.2 (templates) or
§1.4 (plugins).

#### Phase 1 — Request DTO layer (HTTP boundary)

**Status:** 🟡 Not started · ~30–50 DTOs · adds `symfony/serializer` + `symfony/validator`

Per-action Request DTO classes with typed properties.
`symfony/serializer` denormalizes `$_POST + $_GET` (or PSR-7 parsed
body) into the DTO; `symfony/validator` runs attribute-based
constraints. A small `PayloadFactory` glues them together — no
HttpKernel required.

```php
final readonly class CommentSubmitRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $content,

        #[Assert\Length(max: 100)]
        public ?string $author,

        #[Assert\Email]
        public ?string $email,

        #[Assert\Url]
        public ?string $website,

        #[Assert\NotBlank]
        public string $key,
    ) {}
}

final class PayloadFactory
{
    public function __construct(
        private readonly DenormalizerInterface $serializer,
        private readonly ValidatorInterface    $validator,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $source
     * @return T
     */
    public function create(string $class, array $source): object
    {
        $payload = $this->serializer->denormalize($source, $class);
        $violations = $this->validator->validate($payload);
        if (count($violations) > 0) {
            throw new InvalidPayloadException($violations);
        }
        return $payload;
    }
}

// Controller usage
$req = $this->payloads->create(CommentSubmitRequest::class, $_POST + $_GET);
$this->comments->submit($req);
```

##### Hybrid scope

DTOs only for multi-field endpoints (admin forms, comment submission,
picture upload, maintenance, batch manager). Keep `StringUtil::input*`
for one-shot reads (`?image_id=42`) where a DTO is ceremony.

Realistic estimate: 30–50 DTOs covering ~80% of the 626 raw `$_POST` /
`$_GET` reads concentrated in 45 files; the long tail stays on the
helpers.

##### Optional follow-on: attribute auto-resolution

A ~30-line ArgumentResolver in the existing dispatcher reads a
`#[MapRequestPayload]` attribute on the controller signature and calls
`PayloadFactory::create()` automatically. Pure ergonomics — same DTOs,
same Serializer + Validator. Zero new dependencies.

##### Deferred — HttpKernel adoption

Full Symfony HttpKernel adoption (PSR-7 → HttpFoundation, kernel
events, standard ArgumentResolver pipeline, exception → response flow)
is a separate architectural decision. Not in scope for §1.7; revisit
when §1.4 (plugin/theme system) is far enough along to know whether
the codebase wants one event bus or two. The DTOs and `PayloadFactory`
from Phase 1 carry over unchanged if that adoption ever happens.

##### Migration order

1. Land `PayloadFactory` + first DTO (probably `CommentSubmitRequest`
   in `PictureCommentRenderer`) as proof-of-pattern.
2. Sweep multi-field admin endpoints (forms in `MaintenanceController`,
   `BatchManagerController`, etc.).
3. Sweep WS endpoint payloads (`ImagesEndpoints`, etc.) — coordinate
   with §1.4's `PwgServer::addMethod()` migration so plugin authors
   see one new pattern, not two.
4. Decide whether to add the optional attribute resolver based on how
   repetitive `PayloadFactory::create()` calls feel in practice.

#### Phase 2 — Repository entity layer (DB boundary)

**Status:** 🟡 Not started · 21 entities

Every repository method returns a typed `*Entity` object instead of
`array<string, mixed>`. `fromRow()` is the one place in the codebase
where `is_scalar`/`is_numeric` guards appear — at the DB boundary —
and nowhere else.

```php
final readonly class ImageEntity
{
    public function __construct(
        public int     $id,
        public string  $file,
        public int     $hit,
        public ?float  $ratingScore,
        public ?string $dateAvailable,
        public int     $width,
        public int     $height,
        public int     $filesize,
        // … all columns
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int)   ($row['id']            ?? 0),
            file:          is_string($row['file']   ?? null) ? $row['file']   : '',
            hit:           is_numeric($row['hit']   ?? null) ? (int) $row['hit']   : 0,
            ratingScore:   is_numeric($row['rating_score'] ?? null) ? (float) $row['rating_score'] : null,
            dateAvailable: is_string($row['date_available'] ?? null) ? $row['date_available'] : null,
            width:         is_numeric($row['width']  ?? null) ? (int) $row['width']  : 0,
            height:        is_numeric($row['height'] ?? null) ? (int) $row['height'] : 0,
            filesize:      is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0,
        );
    }
}

final class ImageRepository extends AbstractRepository
{
    public function findById(int $id): ?ImageEntity
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')->from($this->table('images'))
            ->where('id = :id')->setParameter('id', $id)
            ->executeQuery()->fetchAssociative();
        return $row !== false ? ImageEntity::fromRow($row) : null;
    }

    /** @return list<ImageEntity> */
    public function findByIds(array $ids): array
    {
        // …
        return array_map(ImageEntity::fromRow(...), $rows);
    }
}

// Caller — accesses typed properties, no guards needed
$image = ServiceLocator::get(ImageRepository::class)->findById($id);
if ($image === null) { /* not found */ }
Lang::t('Visited %d times', $image->hit);          // int, no is_numeric guard
Lang::t('%s', $image->file);                        // string, no is_string guard
Lang::t('%d Kb', $image->filesize);                 // int, no is_numeric guard
```

##### Migration order

One entity per table, repository-by-repository. Start with
`ImageRepository` since its row shape touches the most callers
(`CategoryDefaultRenderer`, `PictureController`, `BatchManagerController`,
photo-admin pages). Each migration is a single PR: entity class,
repository methods returning typed entities, callers updated to access
typed properties, baseline diff committed.

The 257 `fn (mixed $v)` lambdas at DB call sites get removed naturally
as queries move into typed repository methods (`findIdsByCategory(): list<int>`,
etc.). No transitional helper API.

#### Cross-references

- §1.6a — tactical type tightening; lands first because it has no
  blockers.
- §1.6b — globals cleanup closes once `UserRepository` returns a typed
  `UserEntity` and `CurrentUser::get()` exposes typed properties.
- §1.4 — plugin/theme system; coordinate Phase 1 sequencing with the
  WS endpoint migration there.
- §1.8 (test infrastructure) — entity factories and DTO fixtures are
  good candidates for the early Pest/PHPUnit suite.

#### Verification

Per phase:
- PHPStan/Psalm baseline diff per DTO or per repository; removed
  baseline lines are direct evidence.
- Snapshot test of one full request path through the new boundary
  (e.g., comment submission round-trip for Phase 1; a typed
  `ImageRepository::findById()` consumer for Phase 2).
- `composer analyse && composer test && composer lint` clean after
  each migration.

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
// before — tests/e2e/gallery-home.spec.ts
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
rm -rf tests/e2e playwright.config.ts
```

Remove the `npx playwright test` step from CI. Vitest stays for TS unit
tests (separate boundary — see 2.2).

##### Verification

```bash
vendor/bin/pest                              # all green
find tests/e2e -name '*.spec.ts' | wc -l     # 0 — all ported
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
   `Kernel`, `ServiceLocator`). Highest leverage — they underpin every
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

## 2. TypeScript / frontend glue

### 2.1 `any` reduction 478 → ≤250

**Status:** 🟡 Not started · **Effort:** M · 3 tiers

ESLint `@typescript-eslint/no-explicit-any: error` is configured but
undermined by the existing 478 `any` patterns. Closing this item is what
unlocks a clean `npm run lint` exit — currently the rule is enforced for
new code via review only.

#### Current concentration

Ordered for "biggest files first" attack:

| File                    | Count |
| ----------------------- | ----: |
| `tags.ts`               |    80 |
| `user_list.ts`          |    58 |
| `albums.ts`             |    52 |
| `group_list.ts`         |    45 |
| `album_selector.ts`     |    35 |
| `batchManagerUnit.ts`   |    31 |
| `batchManagerGlobal.ts` |    27 |
| (other ~24 files)       |  ~150 |

#### Tier 1 (~130 instances) — window globals for plugin interop

Functions assigned to `window` so Smarty-rendered inline scripts can call
them: `applyFontCheckbox`, `array_delete`, `sprintf`, `TemporaryState`,
etc. These should stay on `window` for the plugin contract but get typed
via a declaration file:

```typescript
// themes/_base/js/types/admin-globals.d.ts
interface Window {
  applyFontCheckbox(el: HTMLInputElement): void;
  array_delete<T>(arr: T[], value: T): T[];
  sprintf(format: string, ...args: unknown[]): string;
  TemporaryState: typeof TemporaryState;
  // …
}
```

With the interface in place, replace `(window as any).applyFontCheckbox`
with `window.applyFontCheckbox`.

#### Tier 2 (~80 instances) — untyped plugin callbacks

Plugin function maps in `batchManagerUnit.ts` and `batchManagerGlobal.ts`
use `(window as any)[pluginId + '_save']`:

```typescript
// before
const result = (window as any)[pluginId + '_save'](pictureId);

// after
type PluginSaveCallback = (pictureId: number) => Promise<void> | void;
const pluginSave = (window as Record<string, unknown>)[pluginId + '_save'] as
  | PluginSaveCallback
  | undefined;
const result = pluginSave?.(pictureId);
```

#### Tier 3 (~100 instances) — fetch response shapes

`fetch()` responses typed as `any`. Replace with explicit interfaces:

```typescript
// themes/_base/js/types/ws-responses.d.ts
export interface ImageSearchResponse {
  paging: { page: number; per_page: number; count: number; total_count: number };
  images: Array<{
    id: number;
    file: string;
    width: number;
    height: number;
    derivatives: Record<string, { url: string; width: number; height: number }>;
  }>;
}
```

```typescript
// before
fetch('/ws.php?method=pwg.images.search&format=json')
  .then((r) => r.json())
  .then((data: any) => render(data.result.images));

// after
fetch('/ws.php?method=pwg.images.search&format=json')
  .then((r) => r.json() as Promise<{ stat: 'ok'; result: ImageSearchResponse }>)
  .then((data) => render(data.result.images));
```

Start with the most-used responses: `pwg.images.search`,
`pwg.categories.getList`, `pwg.tags.getList`.

##### Keep as `any` (legitimate)

- `(window as any).pluginValues` and
  `(window as any)[pluginId + '_batchManagerSave']` in plugin interop
  hot-paths — the call target is truly dynamic.

##### Verification

```bash
grep -rn ': any\b\|as any\b\|(window as any)' themes/admin/_base/js/ themes/_base/js/ \
  --include='*.ts' | wc -l
# current: 478 — target: ≤ 250

npm run typecheck   # still zero errors
npm run lint        # eventually exits 0 once `no-explicit-any` is satisfied
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
- Bundled themes (`themes/elegant`, `themes/modus`, `themes/smartpocket`,
  `themes/bootstrap_darkroom`) — themes, not libs, out of 16.x core scope.
- `themes/_base/js/plugins/piecon.ts` — already authored TS, ~100 LOC, no
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

**Status:** 🟢 Active ▸ 3 of 13 steps done · **Effort:** M · 10 live steps remain

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
are already in place. The remaining 10 live steps:

##### Steps

| Step | What                                                                                                                                                                                                                                                                                                                        |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3    | Delete orphans: `themes/_base/fix-{khtml,ie5-ie6,ie7}.css`; broken `admin/_base/fix-ie7.css` `<link>` in `install.tpl` / `upgrade.tpl`. IE conditional comments are inert in modern browsers.                                                                                                                               |
| 4    | Split `themes/_base/theme.css` (1,305 lines) along section markers into per-concern files (`menubar.css`, `content.css`, `picture.css`, `layout.css`, `colors.css`, `forms.css`, `calendar.css`, `comments.css`, `popup.css`). `theme.css` becomes an `@import` list.                                                       |
| 5    | Collapse search CSS variants. Replace `search.css` + `clear-search.css` + `dark-search.css` with a single variable-driven `search.css` using `--search-*` tokens. Net savings: ~500 lines.                                                                                                                                  |
| 6    | Non-color design tokens at theme root — `:root {}` block with `--space-*`, `--font-size-*`, `--line-height-*`, `--radius-*`, `--z-*`, `--bp-*` (canonical breakpoints `sm=576 md=800 lg=1100`). Replace hardcoded values throughout.                                                                                        |
| 7    | Color tokens for `themes/standard_pages/`. Emit `:root {}` color block in parent; replace direct color literals with `var(--color-*)`.                                                                                                                                                                                      |
| 8    | Refactor `themes/standard_pages/skins/*.css` (11 skins × ~337 lines × 20 `!important` ≈ 220 instances). With tokens in place, each skin reduces to a single `:root {}` override block (~30 lines, 0 `!important`). **Soft dep on 1.4 phase 2** — `theme.json` layout.                                                       |
| 10   | Admin-parent CSS design tokens via `base.css.tpl` — Smarty-templated `:root {}` block emitting `--admin-{bg,fg,accent,border}` from `$admin_skin` in each child theme's `themeconf.inc.php`. Removes the `{combine_css path="…/$theme.id/css/components/general.css" order=-9}` `{* Temporary solution *}` workaround.      |
| 11   | Split `themes/admin/_base/theme.css` (9,635 lines) along its 60+ `/* name.css */` section markers into base/components/pages/features. `theme.css` becomes an `@import` list. Utility classes `.u-*` (added by inline-style extraction) land in `base/utilities.css`.                                                       |
| 12   | Slim admin child themes — `themes/admin/{light,dark}/theme.css` reduce to `:root {}` variable override blocks. Structural rules currently duplicated (borders, padding, grid, `@keyframes`) move up into the parent's split CSS.                                                                                            |
| 15   | `!important` final elimination pass. Tier 2 (tom-select redundant `!important` — our specificity already wins): `batch_manager_unit.css`, `picture_modify.css`, `albums.css`, `user-list.css`. Then Tier 3 file-by-file from largest to smallest. Reinstate `declaration-no-important` Stylelint warning once count is low. |

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

- **Zero accessibility testing.** 16 Playwright E2E specs in `tests/e2e/`
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
// tests/e2e/utils/a11y.ts
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
npx playwright test tests/e2e/                     # all pages green, a11y included
npx playwright test --grep '@a11y'                 # focused a11y-only run

# Regression check: introduce a button without label, expect failure
echo '<button>X</button>' > /tmp/probe && \
  ! npx playwright test tests/e2e/probe.spec.ts    # exits non-zero
```
