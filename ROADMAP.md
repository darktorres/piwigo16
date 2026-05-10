# Piwigo 16.x — Active Roadmap

> Open work only. Completed phases live in git history. The repository
> restructure plan is held out separately while its design decisions
> settle.

---

## At a glance (2026-05-09)

| §   | Section                       | Status                                        | Effort    | TL;DR                                                                                                                               |
| --- | ----------------------------- | --------------------------------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| 1.1 | Concrete bugs                 | 🟢 **Active** ▸ 7 / 9                         | S + M     | 2 left: cat-id gap (likely a no-op) · history pagination refactor (M)                                                               |
| 1.2 | Templates pipeline            | 🟢 Active ▸ W2 D.\* 133/133 .latte lint-clean | XL        | wave 1 hygiene done → wave 2 Latte foundation + converter iterating + admin/public/standard_pages all converted → wave 3 precompile |
| 1.3 | Plugin / theme + WS           | 🟡 **Not started**                            | XL        | `PluginInterface`, `ThemeInterface`, OpenAPI follow-ups                                                                             |
| 1.4 | Security hardening            | 🟢 **Active** ▸ 1 / 6                         | M         | CSP, rate limit, lockout, sessions, `SECURITY.md`                                                                                   |
| 1.5 | Type correctness              | 🟡 **Not started**                            | M–L       | mixed-types · entity layer · HTTP boundary · globals · schema metadata                                                              |
| 1.6 | Test infrastructure           | 🟡 **Not started**                            | M + L + S | Pest → coverage → Infection (chained)                                                                                               |
| 1.7 | Deferred / on-demand          | 🟠 **On-demand**                              | —         | Monolog · S3/SFTP · supervisor · Renovate                                                                                           |
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

> **Working principle (continuous, no checkpoint):** when touching a service
> that still resolves dependencies via `ServiceLocator::get(...)`, migrate
> to constructor injection in the same commit. Not a discrete item — applied
> opportunistically as files are edited for any other reason.

### 1.1 Concrete bugs

**Status:** 🟢 Active ▸ 7 of 9 done · **Effort:** S (1 left) + M (1 left)

The 7 fixes shipped 2026-05-09 as separate commits on `16.x-rewrite`.
Verified by `vendor/bin/phpunit` (386 tests, 2041 assertions green).

The PERF history pagination item from the original audit was split in
two while sweeping: the cheap part (push sort to SQL) shipped; the
deeper part (separate COUNT + LIMIT/OFFSET + summary aggregate split)
needs more design and is tracked below as its own item.

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

#### Still open

- 🟡 **Search cat-id access gap** · _S, blocked on info_. Original
  description: "A search code path reads category IDs from a request
  shape that may be absent on first-load, returning empty results
  without an error. Fix: explicit `?? []` and guard the empty case."
  Couldn't reproduce a specific unguarded site after tracing all
  `cat_id` / `cat_words` reads in `src/` — every one is already guarded
  with `is_array(...)` and `?? []` patterns, almost certainly added by
  the recent psalm-level2 sweep. Likely already fixed by that sweep.
  **Action:** drop unless the original audit notes can be located, or a
  user reports the symptom (first-load search returning empty without
  an error) against the current branch.

- 🟡 **PERF — history pagination refactor (LIMIT/OFFSET path)** ·
  _Effort: M_. The hot WS endpoint
  `Piwigo\Ws\Method\GeneralEndpoints::historySearch` still loads every
  matching history row into PHP and slices in memory. Sort moved to SQL
  (commit `0d87baba0`); the row-volume reduction is not done because
  the same loop that builds display rows also computes the summary
  aggregates shown above the table:
  - `summary.total_filesize` — sum of `images.filesize` for `image_type
= 'high'` rows.
  - `summary.guests_IP` — `IP → count` map for `user_id = guest`.
  - `summary.nb_members` — distinct non-guest user_ids.

  To paginate the row fetch, those aggregates have to come from
  separate queries that scan the full filtered result set. Sketch:

  ```sql
  -- 1. nb_lines
  SELECT COUNT(*) FROM history WHERE <where>;

  -- 2. total_filesize (only for high-image-type rows)
  SELECT SUM(i.filesize)
    FROM history h JOIN images i ON i.id = h.image_id
   WHERE h.image_type = 'high' AND <where>;

  -- 3. guest IP histogram
  SELECT IP, COUNT(*) FROM history
   WHERE user_id = :guest_id AND <where>
   GROUP BY IP;

  -- 4. distinct non-guest member ids
  SELECT DISTINCT user_id FROM history
   WHERE user_id <> :guest_id AND <where>;

  -- 5. paginated detail rows
  SELECT … FROM history
   WHERE <where> ORDER BY date, time
   LIMIT 300 OFFSET :pageNumber * 300;
  ```

  Risk: the existing endpoint has no unit-test coverage, so summary
  numbers must be cross-checked against the current implementation
  before/after on a representative dataset. Recommended sequence:
  1. Land basic pest-coverage for `historySearch` summary numbers
     (depends on §1.6.1 Pest landing, or write standalone PHPUnit for
     this method).
  2. Refactor to the 5-query shape above.
  3. Confirm summary rendering unchanged on staging fixture.

  Until then the endpoint is correct but slow on installs with large
  history tables (>~50 K rows).

#### Verification

```bash
vendor/bin/phpunit            # 386 tests, 2041 assertions green ✅
vendor/bin/phpstan analyse    # one pre-existing error unrelated to 1.1
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

**Status:** 🟢 Active ▸ Phase A + B + E done · C iterating · D.\* done · 133 / 133 .latte · F.0 routing facade live (1 / 84 call-sites flipped) · **Effort:** XL · depends on Wave 1

##### Phase progress

| Phase            | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Status                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A                | latte/latte 3.1, `TemplateEngine` interface, `LatteEngine`, `PiwigoExtension` (translate, translate_dec) + 7 unit tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| A.tooling        | `composer lint:latte` wrapper around `Latte\Tools\Linter` + parallel CI job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| A.tooling+       | `efabrica/phpstan-latte` vendored fork (PHP 8.5 + Latte 3.1 patches), engine bootstrap, custom `PiwigoLatteEngineResolver`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.1+B.2          | 18 PHP-passthrough filters + 8 stateless custom modifiers (l10n, explode, ternary, url_is_remote, is_admin, is_classic_user, get_device, get_gallery_home_url, get_extent)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.3              | 8 stateful asset functions (combineScript, getCombinedScripts, combineCss, getCombinedCss, defineDerivative, htmlHead, htmlStyle, footerScript) sharing `TemplateRegistry::current()` state                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.4              | prefilter_white_space + postfilterLanguage documented as intentionally not ported                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.5              | `LatteEngine::default()` factory using Piwigo's data location                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| B.6              | First template conversion: `themes/admin/_base/template/help.latte` (parallel with the .tpl) + 2 integration tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | ✅ Done                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| C                | `tools/smarty-to-latte/convert.php` mechanical rewrite tool — 49 unit tests pin behavior. Covers: foreach (with optional `name=`), dot-access, if-not (any expression), Smarty operator keywords (`eq`/`neq`/`ne`/`gt`/`lt`/`gte`/`lte`/`is odd`/`is even`), `{else if}`→`{elseif}`, escape filter (any arg form), combine*script/css/get_combined*_, define_derivative, include path, printed-literal filter prefix, assign (named + positional, parenthesizes pipes), section→foreach, capture, literal, strip→spaceless, html_head/style/footer_script blocks, regex_replace→replaceRe, multi-arg pipe `:`→`,`, function definition (named + positional, dedupes Smarty 5 dual-form declarations), `$smarty.foreach.X.{index,iteration,first,last,total}`→`$iterator->_`. CLI driver gains `--force`flag (default skips existing`.latte`so manual`\|noescape` annotations aren't lost). | 🟢 Iterating                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| D.admin          | Convert ~55 admin templates (`themes/admin/_base/template/`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | ✅ Done · **70 / 70 lint-clean**. Iteration 3 lifted the remaining 32 templates by registering `htmlOptions` / `htmlRadios` / `math` as PiwigoExtension functions (Smarty plugin ports), adding converter rules for `{counter}` strip, user-defined function call → `{include NAME, k: v, …}` rewrite, embedded `{$X}` print sub-tags inside `{if}`/`{elseif}`/`{var}`, Smarty backtick string interpolation → `.` concat (Latte's `~` is rejected inside function-call args), Smarty 5 `$item@index/iteration/first/last/total/key` iterator-attribute syntax, `{if X}{break}{/if}` → `{breakIf X}` idiom, pipe-in-`{if}` (`$x                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | count`→`count($x)`), nested-`:{round(...)}` filter-arg unwrap, `$arr.$varname`(variable-index dot-access),`{capture assign=NAME}`keyword variant, and an extended args parser that accepts`key = value`(with whitespace) plus expressions containing`,` `:` ` | ` `()`. Two templates needed hand-fixes that don't generalise: `header.latte`(JSON config script tag rebuilt with`\|json_encode\|noescape`since Latte rejects`{$X}`print-statements inside JS string literals) and`queue.tpl`source (HTML attribute quoting flipped to single-quoted to escape inner`"` chars in the Smarty translate-string literal).                                                                                                                                                                                                                                                                                                     |
| D.public         | Convert ~55 public theme templates (`themes/_base/template/` incl. `mail/`, `include/`, `help/` subtrees)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | ✅ Done · **55 / 55 lint-clean**. Iteration 4 added two converter rules (dot-access leading-expr now walks `->prop` PHP-property chains so `$block->data.qsearch` → `$block->data['qsearch']`; `combine_css` / `combine_script` regexes now allow nested `{...}` in path values for `path="…/{$themeconf.colorscheme}.css"`) and four PiwigoExtension filters (`count`, `strip_tags`, `str_repeat`, `default`, `date_format`) plus two functions (`url_is_remote` is now also exposed as a function, not just a filter; `l10n` likewise). Source-level hygiene fixes: `header.tpl` × 2 (`pwg-config` JSON now built via `[…]\|json_encode` array literal, working in both Smarty and Latte); `related_tags.inc.tpl` + `menubar_tags.tpl` (href-split-across-`{if}` rebalanced so each branch produces matching HTML — the original "split `<a … href=\\n{if}…\\n{/if}>`" idiom isn't representable in Latte's tag-aware parser). One hand-fix that doesn't generalise: `search.latte` blocks `{section name=day start=1 loop=32}` (one-off; .tpl source keeps Smarty form, .latte uses `{foreach range(1, 32) as $day}` and `--force` regen requires reapplying).                                                                                                                                                                                                                                      |
| D.standard_pages | Convert 7 templates in `themes/standard_pages/template/` (footer, header, identification, password, profile, register, toaster) + the orphan `themes/_base/local_head.tpl`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | ✅ Done · **8 / 8 lint-clean** on first conversion. The converter rules accumulated through D.admin + D.public covered every construct in this corpus — no rule additions, no source-level hygiene fixes, no hand-fixes required.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| E                | Implement `Piwigo\Template\Latte\PiwigoPolicy` sandbox for plugin-supplied templates                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | ✅ Done. `PiwigoPolicy` extends Latte's `Sandbox\SecurityPolicy` with two factory methods: `createPluginPolicy()` is the default-deny allowlist for plugin templates (permits structural tags, escape filters, the translation pair, read-only Piwigo helpers; denies `php`/`include`/`extends`/`do`, the asset-pipeline functions, filesystem-touching filters, `math()`, and opaque payload decoders); `createCorePolicy()` is the trusted-core superset that allows the asset-pipeline functions and `do`/`include`/`extends` while still keeping `{php}` denied. `LatteEngine` gained a `?Policy $policy` constructor arg + `LatteEngine::sandboxed()` factory that segregates the plugin compile cache (`templates_c/latte_plugin/`) from the trusted-engine cache so a malicious plugin can't poison core. 10 unit tests pin the allow/deny matrix and round-trip representative `SecurityViolationException` cases (`{php}`, `\|file_exists`, `combineScript()`) through a sandboxed engine. Plugin-loader integration lives in §1.3.                                                                                                                                                                                                                                                                                                                                                           |
| F.0              | Runtime engine routing facade in `Template::parse()`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | 🟢 Active ▸ 1 / 84 call-sites flipped. `Template::parse()` now dispatches by file extension: `.latte` paths route to a private `renderLatte()` that threads Smarty's accumulated template-vars through `LatteEngine::default()` and resolves the bare filename against Smarty's `template_dir`. Smarty plugin pre/post filters and `compile_id` language-cache keys are deliberately not applied to Latte — Latte caches by content hash and plugin extension lands separately in §1.3. Proof-of-concept flip: `MiscController::help()` now points at `help.latte` instead of `help.tpl`; the Phase B.6 fixture coverage extends to the dispatch + var-threading path via `test_template_render_latte_threads_smarty_vars_into_latte`. **Hard-won lesson from a reverted bulk flip:** lint-clean ≠ runtime-safe. 13 templates contained `$smarty.foreach.X.Y`, `$smarty.now`, `$smarty.server.X`, `$smarty.cookies.X`, `$smarty.capture.NAME` references — Smarty's implicit globals that lint-pass under Latte (the bracket form `$smarty['foreach']['X']` looks like a normal array access) but fail at render with "Undefined variable $smarty". Converter now rewrites all five residue families (matches both the dotted form and the bracketed form left by `rewriteSmartyDotAccess`); `rewritePrintedLiteralFilter` widened to accept function-call and parenthesized leading exprs so `{time() | ...}`and`{($\_SERVER['X'] ?? '')                                                                                                                                                                                                                              | ...}`get the`{=...}`print marker.`LatteEngine::default()`and`::sandboxed()`chmod their cache dirs 0o775 after creation so`\_data/templates_c/latte\*`is shareable between Apache (`www-data`) and CLI (developer); without the chmod, mode bits clamp the parent ACL mask to r-x and whoever creates the dir first locks the other out. Remaining ~83 call-sites can flip one-by-one or in small per-controller batches — each flip needs e2e validation (not just lint), and`\|noescape`annotations on raw-HTML prints survive across`--force`regen at the .latte level only (the converter is intentionally faithful and never auto-adds`\|noescape`). |
| F                | Drop`smarty/smarty` (gated on F.0 cutover + plugin deprecation window)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | 🟡 Not started                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |

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

**Caveat:** `Template` (the Smarty wrapper) does NOT yet implement
`TemplateEngine`. The roadmap originally called for renaming Template →
`SmartyEngine` and having both engines satisfy the interface; that
rename touches every `TemplateRegistry::current()` caller in the
codebase and was deferred. Until then `LatteEngine` is the sole
implementer. The interface is the forward-compatible contract for
controllers that move to engine-agnostic rendering.

`Piwigo\Template\LatteEngine` is the new engine. The dispatcher
originally specified inside `TemplateRegistry::current()` (returning the
interface, routing by file extension) was replaced with a simpler
`LatteEngine::default()` static factory that resolves the cache
directory from Piwigo's data location. Controllers that want to render
a `.latte` call `LatteEngine::default()->render($path, $params)`
directly. The full extension-routing dispatcher lands once typed page-
context DTOs flow through controllers — controllers will then call a
`TemplateService` / engine-agnostic accessor instead.

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
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| `\|translate`, `\|translate_dec`                                                                                                                                                                                                                                | filters in `PiwigoExtension` backed by `Piwigo\Core\Lang::t` / `Piwigo\Lang\Translator::plural`                                                                                                                                                                                                                          | ✅ Phase A                                                                                                                                         |
| `\|l10n`                                                                                                                                                                                                                                                        | filter alias for `\|translate` (same `Lang::t` callback)                                                                                                                                                                                                                                                                 | ✅ Phase B.2                                                                                                                                       |
| `\|sprintf`, `\|urlencode`, `\|intval`, `\|file_exists`, `\|constant`, `\|json_encode`, `\|json_decode`, `\|htmlspecialchars`, `\|stripslashes`, `\|in_array`, `\|ucfirst`, `\|trim`, `\|md5`, `\|strtolower`, `\|is_null`, `\|is_file`, `\|strpos`, `\|sizeOf` | filters dispatched directly to PHP first-class-callable functions                                                                                                                                                                                                                                                        | ✅ Phase B.1                                                                                                                                       |
| `\|implode`, `\|str_replace`, `\|str_ireplace`, `\|preg_match`, `\|strstr`, `\|stristr`, `\|array_key_exists`                                                                                                                                                   | **deliberately omitted** — Smarty pipes the value first, but PHP wants `$glue`/`$search`/`$pattern` first; PHP 8's deprecation of swapped args makes the legacy form a TypeError. Verified zero pipe usage in `themes/` + `plugins/`; templates using these in Latte call them as expressions: `{=implode(',', $arr)}`.  | ✅ Phase B.1 (intentional non-port)                                                                                                                |
| `\|explode`, `\|ternary`, `\|url_is_remote`, `\|is_admin`, `\|is_classic_user`, `\|get_device`, `\|get_gallery_home_url`, `\|get_extent`                                                                                                                        | one-line wrappers in `PiwigoExtension` delegating to existing services (`UrlService`, `PermissionService`, `Util`, `UrlGenerator`)                                                                                                                                                                                       | ✅ Phase B.2 + B.3 (get_extent)                                                                                                                    |
| `{combine_script}`, `{get_combined_scripts}`, `{combine_css}`, `{get_combined_css}`, `{define_derivative}`                                                                                                                                                      | functions in `PiwigoExtension::getFunctions()`, called via `{do combineScript(...)}` (void) or `{var $x = defineDerivative(...)}` (returns); delegate to `TemplateRegistry::current()`'s `scriptLoader` / `cssLoader` so a `.latte` template's combine_script accumulates into the same bundle a `.tpl` template's would | ✅ Phase B.3                                                                                                                                       |
| `{html_head}`, `{html_style}`, `{footer_script}` blocks                                                                                                                                                                                                         | functions in `PiwigoExtension::getFunctions()`, called as `{capture $x}…{/capture}{do htmlHead($x)}`; `htmlStyle` writes through `Template::appendHtmlStyle()` (added in Phase B.3 — the buffer is private and shared between the two engines)                                                                           | ✅ Phase B.3                                                                                                                                       |
| `prefilter_white_space` filter                                                                                                                                                                                                                                  | **deliberately omitted** — Smarty-specific source rewrite; Latte handles whitespace differently and provides `{spaceless}` for explicit zones. Revisit only if profiling shows need.                                                                                                                                     | ✅ Phase B.4 (intentional non-port, documented in PiwigoExtension docblock)                                                                        |
| `postfilterLanguage` filter                                                                                                                                                                                                                                     | **deliberately omitted** — Smarty constant-folds `<?php echo 'literal'?>` after `Lang::t('key')` resolution in `compiledTemplateCacheLanguage` mode. Latte equivalent would be a NodeVisitor compiler pass that rewrites `{=$x                                                                                           | translate}`to a literal when`$x` is a string-literal expression and language caching is enabled. Defer until profiling justifies the optimization. | ✅ Phase B.4 (intentional non-port, documented) |

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

**Status:** 🟡 Not started (Phase C). The converter is pending. The
single template converted to date (`help.latte`, Phase B.6) was
hand-rewritten; the rewrite list below has been corrected against what
actually worked in the Latte runtime, plus extra rows surfaced during
the hand conversion.

Build `tools/smarty-to-latte/convert.php`:

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

##### Plugin compatibility shim

**Caveat from delivery:** the spec called for the dispatcher inside
`TemplateRegistry::current()` (returning the engine interface, routing
by file extension). What actually shipped is a `LatteEngine::default()`
static factory that resolves the cache directory from
`Config::dataLocation()`. Callers wanting a `.latte` render call
`LatteEngine::default()->render($path, $params)`; callers staying on
Smarty go through the existing `Template::pparse()` + handle-based
flow.

This is a deliberate scope reduction:

- The full extension-routing dispatcher needs `Template` (Smarty) to
  implement `TemplateEngine`. That requires aligning Template's
  `assign(string|array, mixed)` signature with the interface's
  `assign(string, mixed)`, which would break every Smarty-array-form
  caller in the codebase. Deferred.
- During the migration, no controller calls render() with a runtime-
  chosen extension — the engine choice is known statically per call
  site (a `.tpl` site stays Smarty, a `.latte` site uses LatteEngine
  directly). The static-factory pattern fits the actual call shape.

Once typed page-context DTOs flow through controllers and the rename
of `Template` → `SmartyEngine` lands, the dispatcher takes the form
the spec originally described:

```php
public function render(string $template, array $params = []): string
{
    $engine = str_ends_with($template, '.latte') ? $this->latte : $this->smarty;
    return $engine->render($template, $params);
}
```

##### When to drop Smarty

Drop `smarty/smarty` from `composer.json` once:

- All bundled `.tpl` are converted (0 `.tpl` under `themes/_base/`,
  `themes/admin/_base/`, `themes/standard_pages/`).
- The top-3 plugins by usage ship Latte versions.
- A deprecation notice has run for one minor release for plugins still
  using Smarty.

Then: delete `Piwigo\Template\SmartyEngine`; remove the dispatcher
fallback in `TemplateRegistry::current()`.

##### Verification

```bash
find . -name '*.tpl' -not -path '*/_data/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' | wc -l
# baseline: 135 today; target: 0 (all converted to .latte)

composer show smarty/smarty 2>&1 | grep 'not installed'   # after final removal
vendor/bin/phpunit                                         # green
npx playwright test                                        # green (no visual regression)
```

#### Wave 3 — Precompile at deploy

**Status:** 🟡 Not started · **Effort:** S · depends on Wave 2

Once Latte is the primary engine, ship `tools/precompile_templates.php` —
a CLI entrypoint that boots Piwigo without emitting output, then for each
engine:

```php
#!/usr/bin/env php
<?php declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

Piwigo\Bootstrap\CommonBootstrap::run();
Piwigo\Core\Kernel::boot();

$failed = [];

// Walk every active theme + admin context, push/pop the dir stack between runs
foreach (Piwigo\Theme\ThemeRegistry::installed() as $theme) {
    $tpls = TemplateDirStack::push($theme);
    try {
        // Latte (primary post-conversion)
        foreach (glob_recursive($theme->getTemplateDir(), '*.latte') as $tpl) {
            try {
                $latte->warmupCache($tpl);
            } catch (Throwable $e) {
                $failed[] = "$tpl: {$e->getMessage()}";
            }
        }
        // Smarty (transition window only)
        $smarty->compileAllTemplates('.tpl', force: true);
    } finally {
        TemplateDirStack::pop();
    }
}

if ($failed) {
    fwrite(STDERR, implode("\n", $failed) . "\n");
    exit(1);
}
echo "Compiled successfully.\n";
```

Outcome:

- First-request compile latency disappears; `_data/templates_c/` is warm
  at deploy time.
- Enables flipping `template_compile_check = 0` in production. With
  `compile_check` off, Smarty/Latte don't `stat()` source files on every
  render — measurable wins on hot pages.
- CI hook catches Latte syntax regressions in plugin templates that lack
  unit-test coverage. Run on every PR; failure means the precompile script
  reported a broken template.
- Iterate per-theme (push/pop the dir stack between runs — compiled cache
  keys are bound to the resolved `template_dir` stack) and per-plugin-set
  (plugins inject Smarty prefilters and Latte extensions at boot, both of
  which alter compiled output).

##### Deploy integration

Add to `INSTALL.md` after `composer install --no-dev`:

```bash
php tools/precompile_templates.php
```

Add a `make precompile-templates` target. Document in `CONTRIBUTING.md`
that staging environments with different plugin sets need a separate
warm.

##### OPcache guidance

`_data/templates_c/` holds plain PHP. Hosters should leave OPcache
enabled with a generous `opcache.max_accelerated_files` (file count is
~150 today, similar post-Latte). For truly hot files,
`opcache.preload` of the precompiled templates yields another small win.

##### Verification

```bash
rm -rf _data/templates_c/* _data/templates_c/latte/*
php tools/precompile_templates.php          # exits 0; reports N templates compiled
ls _data/templates_c/ | wc -l               # > 0, matches reported count

# After warming, the first request must not write to _data/templates_c/:
mtime_before=$(stat -c %Y _data/templates_c/)
curl -s http://localhost/ > /dev/null
mtime_after=$(stat -c %Y _data/templates_c/)
[ "$mtime_before" = "$mtime_after" ] && echo "no recompile on first hit"
```

---

### 1.3 Plugin / theme system + WS plugin surface

**Status:** 🟡 Not started · **Effort:** XL · 4 sub-items

**Why one section, not four.** The same plugin contract drives all four
sub-items: typed event dispatching, declarative manifests, lifecycle
methods, and reflection-based WS handler discovery. Phase 1 plugins lay
the foundation; Phase 2 themes reuse it for theme-specific concerns; the
WS-side work is the same plugin migration viewed from the WS layer.

#### Phase 1 — Plugins

**Status:** 🟡 Not started

##### `PluginInterface`

```php
namespace Piwigo\Plugin;

interface PluginInterface
{
    public function getId(): string;             // e.g. 'my-plugin'
    public function getVersion(): string;        // e.g. '1.4.0'
    public function getName(): string;           // human-readable

    public function boot(ContainerInterface $c): void;
    public function shutdown(): void;

    public function install(): void;
    public function activate(): void;
    public function deactivate(): void;
    public function uninstall(): void;

    /** @return array<class-string, string> */
    public function subscribedEvents(): array;   // typed-event class → handler method
}
```

##### PSR-14 typed events

`composer require psr/event-dispatcher symfony/event-dispatcher`. Replace
string event names with typed event objects under `src/Piwigo/Event/`:

```php
namespace Piwigo\Event;

final readonly class PictureRendered
{
    public function __construct(
        public int $pictureId,
        public string $renderedHtml,
    ) {}
}
```

Subscribers receive the typed object and can mutate output via clone-and-modify
methods for the `trigger_change` use case:

```php
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

`Piwigo\Event\EventDispatcher` is registered in the DI container as the
`Psr\EventDispatcher\EventDispatcherInterface` implementation.

##### Legacy compatibility bridge

`add_event_handler('user_login', $callback)` keeps working — both for
plugins and for themes that register handlers from `themeconf.inc.php`.
The bridge in `src/Piwigo/Compat/LegacyEvents.php` maps string event
names to the new typed events; when a typed `UserAuthenticated` is
dispatched, registered legacy listeners are also invoked with the bridged
args:

```php
final class LegacyEvents
{
    /** @var array<string, list<callable>> */
    private static array $handlers = [];

    public static function bridge(string $oldName, callable $cb): void
    {
        if (!isset(self::$bridged[$oldName])) {
            trigger_error(
                "add_event_handler('$oldName') is deprecated; use PSR-14 typed events.",
                E_USER_DEPRECATED
            );
        }
        self::$handlers[$oldName][] = $cb;
    }

    public static function dispatchLegacy(object $event): void
    {
        $oldName = self::EVENT_MAP[$event::class] ?? null;
        if ($oldName === null) return;
        foreach (self::$handlers[$oldName] ?? [] as $cb) {
            $cb(...self::flatten($event));
        }
    }
}
```

##### Fork identity

`AppInfo` carries a `FORK_VERSION` constant alongside the upstream
`VERSION`. Its presence is the signal that this is the rewrite fork;
stock Piwigo 16 does not define it.

```php
// src/Piwigo/Core/AppInfo.php
public const string VERSION       = '16.3.0';  // upstream traceability
public const string FORK_VERSION  = '1.0.0';   // rewrite fork's own release line
```

Plugins and themes detect the fork with:

```php
if (!defined('Piwigo\Core\AppInfo::FORK_VERSION')) {
    // running on stock Piwigo — bail or degrade gracefully
}
```

No name constant is needed — there is only one fork, so the presence of
`FORK_VERSION` is sufficient.

##### Declarative `plugin.json`

```json
{
  "id": "my-plugin",
  "version": "1.4.0",
  "name": "My Plugin",
  "minPiwigo": "16.0",
  "minForkVersion": "1.0",
  "main": "Piwigo\\Plugin\\MyPlugin\\Plugin",
  "autoload": { "psr-4": { "Piwigo\\Plugin\\MyPlugin\\": "src/" } }
}
```

`minForkVersion` is optional. Omitting it means the plugin targets stock
Piwigo and will be rejected by `PluginRegistry` on the rewrite. During
the migration window the legacy bridge loads old plugins that have no
`plugin.json` at all; once the bridge is removed, `plugin.json` with
`minForkVersion` is required.

`Piwigo\Plugin\PluginRegistry` reads the manifest, registers PSR-4
autoload, instantiates the main class, and calls `boot()`. The plugin
admin UI reads `plugin.json` instead of parsing `main.inc.php` headers.

##### Migration walkthrough — generic plugin

Existing layout (legacy plugin):

```text
plugins/<id>/
  main.inc.php             # registers handlers via add_event_handler()
  maintain.inc.php         # extends PluginMaintain
  include/                 # tab handlers
  template/                # Smarty .tpl files
```

Post-migration:

```text
plugins/<id>/
  plugin.json              # declarative manifest
  src/
    Plugin.php             # implements PluginInterface
    Maintain.php           # implements lifecycle methods
  template/                # Latte .latte files
```

Steps to migrate one plugin (one commit each, in order):

1. Move source under `plugins/<id>/src/` with PSR-4 namespace
   `Piwigo\Plugin\<Pascal>\`.
2. Convert `main.inc.php` event-handler registrations to a `Plugin` class
   with `subscribedEvents()`.
3. Convert `maintain.inc.php` to a `Maintain` class implementing the
   lifecycle methods.
4. Add `plugin.json`.
5. Convert templates to Latte using `tools/smarty-to-latte/convert.php`.

##### DI for plugins

Plugins receive the container in `boot()` and register their own services:

```php
public function boot(ContainerInterface $c): void
{
    $c->set(Piwigo\Plugin\OpenStreetMap\TileService::class, /* … */);
    // listener methods on this class get auto-resolved deps via reflection
}
```

##### Deprecation timeline

Keep the legacy API working through one minor release with
`E_USER_DEPRECATED`. Plan removal one major release later. Document the
timeline in `docs/PLUGIN-DEVELOPMENT.md`.

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

    /** @return array<class-string, string> */
    public function subscribedEvents(): array;
}
```

##### Declarative `theme.json`

```json
{
  "id": "standard_pages",
  "version": "1.0.0",
  "name": "Standard Pages",
  "parent": "default",
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

##### Legacy `themeconf.inc.php` shim

For themes that haven't migrated, the registry detects a missing
`theme.json`, falls back to including `themeconf.inc.php`, and synthesizes
a `LegacyTheme` instance from the resulting `$themeconf` array. The
synthesized instance routes any registered legacy event handlers through
the Phase 1 bridge.

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

One commit per theme:

1. Add `theme.json`.
2. Add `Theme` class under `themes/<id>/src/` (or
   `themes/admin/<id>/src/`).
3. Move `themeconf.inc.php` side-effects into `boot()`.
4. Convert `ThemeMaintain` callers to the new lifecycle methods.
5. Convert templates to Latte (folded into 1.2 Wave 2 — D.public / D.standard_pages).
6. Replace `themeconf.inc.php` with a one-liner that throws
   `E_USER_DEPRECATED` if any legacy code reaches for `$themeconf`
   directly.

##### Soft dependency on 3.1

The CSS skin refactor in 3.1 step 8 presumes the `theme.json` layout —
specifically the per-skin `assets:` map. Whichever lands first sets the
layout the other adopts.

#### Migrate plugins off `PwgServer::addMethod()`

**Status:** 🟡 Not started · folded into Phase 1 work

`PwgServer::addMethod()` was retired during the front-controller migration;
`register(MethodDefinition)` is the only WS-method registration path now.
The registration moved from anonymous-callback to typed-definition:

```php
// before — addMethod (no longer exists)
$service->addMethod('pwg.my.method', 'my_handler', [
    'photo_id' => ['default' => null],
]);

// after — register
$service->register(new MethodDefinition(
    name:         'pwg.my.method',
    callback:     ServiceLocator::get(MyEndpoints::class)->myMethod(...),
    description:  'Description shown in the API browser',
    params:       [
        ParamDefinition::required(name: 'photo_id', type: WS_TYPE_INT | WS_TYPE_POSITIVE),
    ],
    tags:         ['my'],
    requiresAuth: true,
));
```

Plugins still calling `addMethod` will fatal at runtime. Same work as
Phase 1 — the `register()` migration happens inside each plugin's
`PluginInterface` conversion (specifically inside `subscribedEvents()`
on the `WsServerBoot` event).

#### OpenAPI follow-ups

**Status:** 🟡 Not started · depends on Phase 1

Once plugin handlers are reflection-accessible controller classes (which
they become as part of Phase 1), two follow-ups land:

##### `#[ApiMethod]` attribute reading

Teach `SpecBuilder` to read the existing `#[ApiMethod]` attribute for
per-method enrichment:

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

`SpecBuilder` walks the registered endpoint classes via reflection, reads
the attribute, and emits richer OpenAPI metadata than what
`MethodDefinition` carries today.

##### CI gate validating the generated spec

Three options, pick one when the work lands:

- **PHPUnit structural test** (no new dep, recommended start). Build the
  spec from a populated server, assert required OpenAPI 3.1 keys and
  field types are present.
- **`cebe/php-openapi`** as `require-dev`. Full PHP validator with `$ref`
  resolution and schema semantics; callable from a test.
- **External**: `openapi-spec-validator` (Python) or `redocly lint` in CI.
  More thorough but adds an out-of-PHP dependency.

##### Verification

```bash
# Spec served via the WS endpoint
curl -s 'http://localhost/index.php?/ws?_openapi=json' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"))->info->title;'
# → Piwigo Web Services

# Legacy bridge still works for third-party plugins:
vendor/bin/phpunit --filter LegacyEventBridgeTest

# Event dispatcher conforms to PSR-14:
php -r 'echo (new Piwigo\Event\EventDispatcher) instanceof Psr\EventDispatcher\EventDispatcherInterface ? "ok" : "fail";'

# E2E with the empty plugins/ tree:
npx playwright test
```

---

### 1.4 Security hardening

**Status:** 🟢 Active ▸ 1 of 6 sub-tasks done · **Effort:** M

CSRF middleware is already in place (centralized in `CsrfMiddleware`
during the front-controller work). It validates `pwg_token` on POST
requests, with a small allow-list for endpoints that don't carry CSRF
state (`/ws`, `/install`, `/upgrade`, `/identification`, `/register`).

The remaining five hardening sub-tasks:

#### `SecurityHeadersMiddleware`

A PSR-15 middleware sitting near the top of the pipeline that decorates
every response with security headers:

```php
public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
{
    $nonce = bin2hex(random_bytes(16));
    $req = $req->withAttribute('csp_nonce', $nonce);

    $response = $next->handle($req);

    return $response
        ->withHeader('Content-Security-Policy',
            "default-src 'self'; "
          . "img-src 'self' data: blob:; "
          . "style-src 'self'; "                    // 0 <style> blocks remain
          . "style-src-elem 'self'; "               // explicit; matches style-src
          . "style-src-attr 'unsafe-inline'; "      // 13 PHP-driven '--var: value' attrs
          . "script-src 'self' 'nonce-{$nonce}'; "
          . "frame-ancestors 'self'; "
          . "form-action 'self'")
        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
}
```

The per-request `$nonce` is also injected into the template engine so
`<script>` tags in templates can render `nonce="{$nonce}"`. The 13
surviving inline `style="…"` attributes are uniform `--var: value` shape
(CSS custom properties) and are covered by `style-src-attr`.

If a future stricter policy demands `style-src-attr 'none'`, resurrect
the existing `{html_style}` mechanism (implementation in `Template.php`
is intact, callers were removed) to emit a single nonce'd `<style>` tag
per request with the runtime CSS rules keyed by data-attribute selectors.

#### Login rate limiting

`composer require symfony/rate-limiter`. Configure two policies:

```php
// config/security.php
return [
    'limiters' => [
        'login_ip' => [
            'policy' => 'token_bucket',
            'limit' => 5,
            'rate' => ['interval' => '1 minute', 'amount' => 5],
        ],
        'login_account' => [
            'policy' => 'token_bucket',
            'limit' => 10,
            'rate' => ['interval' => '10 minutes', 'amount' => 10],
        ],
    ],
];
```

Apply in the login controller:

```php
$ipLimiter = $factory->create('login_ip', $request->getClientIp());
if (!$ipLimiter->consume()->isAccepted()) {
    return new Response(429);
}
$accountLimiter = $factory->create('login_account', $username);
if (!$accountLimiter->consume()->isAccepted()) {
    $this->lockoutAndEmail($username);
    return new Response(429);
}
```

#### Brute-force lockout

Schema:

```sql
CREATE TABLE phpwg_user_failed_logins (
    user_id INT UNSIGNED NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_user_time (user_id, attempted_at)
);
```

`AuthService::login()` checks the threshold before verifying the
password — even a correct password is rejected while the user is locked
out:

```php
public function login(string $username, string $password): User
{
    $user = $this->userRepo->findByUsername($username);
    if ($user !== null && $this->isLockedOut($user->id)) {
        throw AuthException::accountLocked();
    }
    if ($user === null || !$this->verifyPassword($password, $user->passwordHash)) {
        $this->recordFailure($user?->id, $this->request->getClientIp());
        throw AuthException::invalidCredentials();
    }
    $this->clearFailures($user->id);
    return $user;
}
```

Admin "Unlock account" action clears `phpwg_user_failed_logins` for the
target user.

#### Session hardening

`SessionMiddleware` sets cookie params before `session_start()`:

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'samesite' => 'Strict',
    'secure'   => true,
    'httponly' => true,
]);
```

On successful login: `session_regenerate_id(true)` to rotate the session
ID and prevent session fixation.

#### `docs/SECURITY.md` threat model

Document:

- Threat model (who's attacking what).
- CSP override procedure (when a plugin needs `unsafe-inline`).
- Account-lockout admin actions.
- Vulnerability reporting (private email, response SLA).

##### Verification

```bash
curl -sI http://localhost/ | grep -i content-security-policy
curl -sI http://localhost/ | grep -i x-frame-options
curl -sI http://localhost/ | grep -i strict-transport-security

# 6th login attempt within a minute returns 429
for i in $(seq 1 6); do
  curl -sw '%{http_code}\n' -o /dev/null \
    -X POST http://localhost/identification \
    -d 'username=test&password=wrong'
done   # expect: 200,200,200,200,200,429

# CSRF without token (should already be blocking)
curl -sw '%{http_code}\n' -o /dev/null \
  -X POST http://localhost/admin/category_delete \
  -d 'cat_id=1'   # expect: 403
```

---

### 1.5 Type correctness — three converging streams

**Status:** 🟡 Not started · **Effort:** M · 3 streams (7 + 2 + 5 items)

After PHPStan level 10 landed, three threads describe the remaining
type-tightening surface:

- **5a** — finite list of high-ROI mixed-type fixes from the codebase
  audit (the audit catalogues ~879 unique `mixed` lines across 160 files;
  these 7 fixes eliminate ~880 of ~1272 occurrences).
- **5b** — `$GLOBALS` cleanup that's been deferred since the
  modernization phases that retired the procedural layer. Gated by direct
  `$GLOBALS[...]` reads in `src/` being eliminated first.
- **5c** — five `Config::SCHEMA` enhancements left as deferred design
  surface from the schema work.

Same gating constraint, same review effort — tackle as one section, work
the streams in parallel where possible.

#### 1.5a Mixed-type fixes

**Status:** 🟡 Not started · 9 items

Nine items from the codebase mixed-type audit, ordered by effort.
Estimated reduction: the first 7 eliminate ~880 of ~1272 mixed
occurrences; items 8–9 address the three remaining boundary categories
(DB rows, HTTP input, global state) that the original audit deferred as
"architectural decisions".

| Item                                                                                                                                                 |              Files | Effort           |
| ---------------------------------------------------------------------------------------------------------------------------------------------------- | -----------------: | ---------------- |
| `ImageInterface::compose(mixed $overlay)` → `ImageInterface $overlay`                                                                                |                  4 | trivial          |
| `CookieService::getCookieVar()` → `string\|null` (cookies are always strings)                                                                        |                  1 | low              |
| ID parameters `mixed $id` / `$userId` → `int\|string`                                                                                                |        ~25 methods | low              |
| `Config::raw()` typed return — `string\|int\|bool\|array<mixed>\|null`                                                                               |                  1 | low (annotation) |
| `EventDispatcher::dispatch()` → `@template T` generic — eliminates many downstream `mixed`s                                                          |                  1 | medium           |
| Typed DB query helpers (`DbConnection::fetchIntColumn`, `fetchStringColumn`) — removes ~100 `fn (mixed $v)` lambdas                                  |            several | medium           |
| `RequestCache` / `PersistentCache` → `@template T` generic — typed cache reads                                                                       |                  2 | medium           |
| **Repository entity layer** — repositories return typed `*Entity` objects instead of `array<string, mixed>`; `fromRow()` is the single cast boundary | 20 repos + callers | high             |
| **HTTP input boundary** — route all remaining raw `$_POST`/`$_GET` reads through `StringUtil::input*`; no raw superglobal access outside that helper |          ~30 sites | medium           |

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

###### ID parameters

```php
// before
public function deleteUser(mixed $userId): void
public function deleteSite(mixed $id): void
public function getGroupname(mixed $groupId): string|false

// after
public function deleteUser(int|string $userId): void
public function deleteSite(int|string $id): void
public function getGroupname(int|string $groupId): string|false
```

Callers already narrow internally with `(int)` casts; the union-type
annotation just documents what's actually accepted.

###### Typed DB query helpers

```php
// before — every caller writes the same lambda
$ids = array_map(
    fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
    $conn->fetchFirstColumn('SELECT id FROM …')
);

// after — DbConnection wraps the cast
$ids = $conn->fetchIntColumn('SELECT id FROM …');   // returns list<int>
```

About 100 lambdas of the same shape disappear once `fetchIntColumn` and
`fetchStringColumn` exist.

###### EventDispatcher generic

```php
// before — every caller widens to mixed
$result = $dispatcher->dispatch('foo_event', $someArray);
// $result is array<mixed>

// after — @template T preserves the input type
/** @template T */
class EventDispatcher {
    /** @param T $data @return T */
    public function dispatch(string $event, mixed $data): mixed { /* … */ }
}
$result = $dispatcher->dispatch('foo_event', ['k' => 1]);
// $result is array{k: int}
```

###### Repository entity layer

The goal: every repository method returns a typed object, never a raw
`array<string, mixed>`. The `fromRow()` static constructor is the **one**
place in the codebase where `is_scalar`/`is_numeric` guards appear — at
the DB boundary — and nowhere else.

```php
// Entity definition
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

// Repository — returns typed object, not array
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

One entity class per table (20 repositories → 20 entities). The migration
can be done repository-by-repository: start with `ImageRepository` since
its row shape touches the most callers (`CategoryDefaultRenderer`,
`PictureController`, `BatchManagerController`, photo-admin pages).

###### HTTP input boundary

No raw `$_POST`/`$_GET` access outside `StringUtil::input*`. The helpers
already exist and are used in many places; the work is eliminating the
remaining ~30 sites that still reach into the superglobals directly:

```php
// before — type is mixed, no length/pattern validation
$action   = $_POST['action'] ?? '';
$imageId  = $_GET['image_id'] ?? 0;
$count    = $_POST['regenerateSuccess'] ?? '0';

// after — typed at the boundary, validated by the helper
$action   = StringUtil::get()->inputString('action',   '',  $_POST);
$imageId  = StringUtil::get()->inputInt(   'image_id', 0,   $_GET);
$count    = StringUtil::get()->inputString('regenerateSuccess', '0', $_POST);
```

After this, every value that crosses the HTTP boundary is a `string`,
`int`, `float`, or `bool` — never `mixed` — before it reaches any service
or controller logic.

#### 1.5b Globals cleanup

**Status:** 🟡 Not started · 2 items · gated by `$GLOBALS[...]` reads in `src/` being eliminated first

Both items below are gated by the same precondition — direct
`$GLOBALS[...]` reads in `src/` being eliminated first — so tackle them
together as one closing pass.

**Relationship to the entity layer (1.5a item 8).** The `$user` global is
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

#### 1.5c Config schema metadata

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

### 1.6 Test infrastructure

**Status:** 🟡 Not started · **Effort:** M + L + S · 3 chained items

Three coupled items, sequenced because each enables the next.

#### 1.6.1 Pest — first

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

#### 1.6.2 Unit-test coverage 13% → ≥40%

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

#### 1.6.3 Mutation testing — Infection — last

**Status:** 🟡 Not started · **Effort:** S · depends on coverage from 1.6.2

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

### 1.7 Deferred / on-demand

**Status:** 🟠 On-demand · 4 items · no scheduled effort

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

1.6.1 Pest absorbs the _browser_ tests (Playwright →
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
| 8    | Refactor `themes/standard_pages/skins/*.css` (11 skins × ~337 lines × 20 `!important` ≈ 220 instances). With tokens in place, each skin reduces to a single `:root {}` override block (~30 lines, 0 `!important`). **Soft dep on 1.3 phase 2** — `theme.json` layout.                                                       |
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
