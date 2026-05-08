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
| 1.5 | Type correctness | 🟡 **Not started** | M–L | mixed-types · entity layer · HTTP boundary · globals · schema metadata |
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

> **Working principle (continuous, no checkpoint):** when touching a service
> that still resolves dependencies via `ServiceLocator::get(...)`, migrate
> to constructor injection in the same commit. Not a discrete item — applied
> opportunistically as files are edited for any other reason.

### 1.1 Concrete bugs

**Status:** 🟡 Not started · **Effort:** S · 8 items

8 individual bugs and perf notes from the in-code audit. Each is small
enough to land as its own commit; ordering is opportunistic — work them in
whatever sequence the wider work touches the affected files. None block
each other.

#### 6 deferred markers (real bugs with no current owner)

These are flagged as `// DEFERRED:` in the codebase. Each is a concrete
bug, not a stylistic concern.

- **`Updates.php` plugin-era redirect target.** The plugin-update flow
  redirects to a route that no longer exists post-front-controller
  migration. Symptom: 404 after applying a plugin update. Fix: redirect
  through `UrlGenerator::admin('plugins')`.
- **Search cat-id access gap.** A search code path reads category IDs from
  a request shape that may be absent on first-load, returning empty
  results without an error. Fix: explicit `?? []` and guard the empty case.
- **Admin URL leaking into the gallery filter.** The filter UI on the
  public gallery uses an admin-side URL helper for one of its links;
  guests get a 403 when clicking. Fix: switch to `UrlGenerator::gallery()`.
- **Category position persistence.** Drag-reordering categories in the
  admin updates the order in the UI but doesn't always persist on refresh
  due to a stale write in `CategoryAdminService`. Fix: order the UPDATEs
  by id descending so the final write wins.
- **Stub `cache_size` returning a placeholder.** The cache-size accessor
  returns a hardcoded number instead of measuring `_data/cache/`. Fix:
  walk the directory and sum file sizes; cache the result for 5 minutes.
- **Image-id / filename precedence ambiguity.** When both an `image_id`
  and a `filename` are present in a request, the resolution order varies
  per controller. Pick one (`image_id` wins) and document.

#### 2 perf notes (advisory, not bug-fix urgency)

- **Redundant `get_available_tags()` call.** The tag-cloud renderer calls
  this twice per request when the result is already cached. Replace the
  second call with the `RequestCache` hit.
- **All-rows-before-PHP-count in WS history pagination.** The WS history
  endpoint loads every row and counts in PHP rather than running
  `SELECT COUNT(*)`. Switch to a separate count query; the row fetch can
  add `LIMIT/OFFSET`.

#### Verification

```bash
grep -rn 'DEFERRED' src/ --include='*.php'   # zero matches when 1.1 is done
grep -rn 'PERF:' src/ --include='*.php'      # zero matches
vendor/bin/phpunit                            # green
```

---

### 1.2 Templates: hygiene → Latte → precompile

**Status:** 🟡 Not started · **Effort:** XL · 3 sequential waves

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

**Status:** 🟡 Not started · **Effort:** M · 8 actions

Eight ordered hygiene actions on the existing `.tpl` files, low-risk
first. These run **before** the Latte conversion so the converter sees
correct source — if skipped, the bugs propagate verbatim into `.latte`.

Suggested execution order (each row a separate commit):

| # | Action | Files | Risk |
|---|---|---|---|
| 1 | Personal email leak in installer (this fork) | `themes/admin/_base/template/install.tpl:151` | trivial |
| 2 | Translation order bug | `themes/admin/_base/template/batch_manager_global.tpl:55` | trivial |
| 3 | Plural via `translate_dec` | `themes/admin/_base/template/intro.tpl:112-117` and similar | low |
| 4 | Invalid HTML | `picture_modify.tpl`, `batch_manager_global.tpl`, `user_list.tpl` | low |
| 5 | `http://` → `https://` | `photos_add_applications.tpl` (4 links) | trivial |
| 6 | Delete dead browser code | IE conditional `<link>` blocks; stale mail-css overlays | low |
| 7 | `\|@translate` → `\|translate` mechanical sweep | ~1203 occurrences across `themes/` | low |
| 8 | `javascript:` URLs and inline `onclick` → data-attribute handlers | 5 sites; touches TS event binding | medium |

Concrete patterns for the trickier rows:

**Row 1 — installer fork-private leak**

```smarty
{* before *}
value="{if $F_ADMIN_EMAIL}{$F_ADMIN_EMAIL}{else}torres.dark@gmail.com{/if}"
{* after *}
value="{if $F_ADMIN_EMAIL}{$F_ADMIN_EMAIL}{else}{$DEFAULT_ADMIN_EMAIL|default:''}{/if}"
```

**Row 2 — translation order**

```smarty
{* before — calls sprintf first, then translates "Level 5" which doesn't exist *}
{'Level %d'|@sprintf:$thumbnail.level|@translate}

{* after — translates the format string, then sprintf-substitutes *}
{'Level %d'|translate|sprintf:$thumbnail.level}
```

The `@` prefix is legacy "do not auto-escape" — redundant when
`escape_html = false` already. Row 7 strips it everywhere.

**Row 3 — plurals**

```smarty
{* before — same string for "1 edition" and "5 editions" *}
title="{'%s editions'|translate:$number}"

{* after *}
title="{$number|translate_dec:'%s edition':'%s editions'}"
```

**Row 8 — javascript: URLs**

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

**Status:** 🟡 Not started · **Effort:** XL · depends on Wave 1

##### Engine architecture

`composer require latte/latte`. Define a `Piwigo\Template\TemplateEngine`
interface both engines satisfy:

```php
interface TemplateEngine
{
    public function assign(string $name, mixed $value): void;
    /** @param array<string, mixed> $params */
    public function render(string $template, array $params = []): string;
    public function parse(string $template): string;
}
```

`Piwigo\Template\SmartyEngine` (the existing `Template.php` wrapper,
renamed) and `Piwigo\Template\LatteEngine` (new) implement it. The
existing `TemplateRegistry::current()` returns the interface; the
dispatcher inside `current()` picks the engine based on file extension
(`.latte` → Latte, `.tpl` → Smarty) so the two coexist during the
conversion window.

Latte configuration:

```php
$engine = new Latte\Engine();
$engine->setStrictTypes(true);
$engine->setTempDirectory(PHPWG_DATA_LOCATION . 'templates_c/latte/');
$engine->addExtension(new Piwigo\Template\Latte\PiwigoExtension());

// Sandbox for plugin-supplied templates from untrusted sources
$engine->setPolicy(new Piwigo\Template\Latte\PiwigoPolicy());
```

##### Smarty-plugin → Latte-extension port

Map each registered Smarty plugin to a Latte filter/function/extension:

| Smarty | Latte equivalent | Notes |
|---|---|---|
| `\|translate`, `\|translate_dec` | filters in `Latte\Extension` backed by `Piwigo\Lang\Translator` | The `\|translate` deferral lands here |
| `\|l10n` | alias for `\|translate` | |
| `\|sprintf`, `\|urlencode`, `\|intval`, `\|htmlspecialchars`, `\|trim`, `\|md5`, `\|stripslashes`, `\|ternary`, … | built-in Latte filters or one-line aliases | About 25 of the registered Smarty modifiers are already Latte built-ins |
| `{combine_script}`, `{get_combined_scripts}`, `{combine_css}`, `{get_combined_css}`, `{define_derivative}` | function tags in `AssetExtension` and `DerivativeExtension` | |
| `{html_head}` block | Latte block-extension; only `themes/_base/template/notification.tpl` uses it | |
| `{html_style}`, `{footer_script}` blocks | zero in-scope callers post-extraction; defer port until the `{html_style}` + nonce path materializes | |
| `prefilter_white_space` filter | Latte template-loader wrapper run before compile | |

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

| DTO | Properties |
|---|---|
| `AlbumPageContext` | `category`, `subAlbums`, `photos`, `pagination`, `baseUrl`, `section` |
| `PicturePageContext` | `picture`, `relatedCategories`, `items`, `category`, `commentAction`, `urlSelf` |
| `SearchPageContext` | `query`, `filters`, `results`, `pagination` |
| `TagsPageContext` | `tags`, `selectedTags`, `photos`, `displayMode` |
| `AdminPageContext` *(base, non-final)* | `pageTitle`, `pageMeta`, `themeAssets`, `flashMessages` |

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
4. **Plugin templates** — ~31 files across the 5 bundled plugins. One
   plugin per commit.

##### Mechanical conversion helpers

Most Smarty syntax is regex-replaceable. Build
`tools/smarty-to-latte/convert.php`:

| Smarty | Latte |
|---|---|
| `{if $foo}` | `{if $foo}` (compatible) |
| `{foreach from=$arr item=x}` | `{foreach $arr as $x}` |
| `{$x\|escape}` | `{$x}` (Latte escapes by default) |
| `{$x\|escape:'none'}` | `{$x\|noescape}` |
| `{include file=foo.tpl}` | `{include 'foo.latte'}` |
| `{$x\|@count}` | `{count($x)}` |
| `{section name=i loop=$arr}…{/section}` | `{foreach $arr as $i => $val}…{/foreach}` |
| `{capture name=foo}…{/capture}…{$smarty.capture.foo}` | `{capture $foo}…{/capture}…{$foo}` |
| `{literal}…{/literal}` | `{syntax off}…{syntax on}` |

The converter applies the rewrites file-by-file; hand-fix the residue
(custom modifiers, complex assignments, broken-on-purpose constructs).
Run the existing PHPUnit + browser smoke tests after each wave commit.

##### Findings folded in as design notes

Several issues from the earlier `.tpl` audit are folded into Latte rather
than fixed in Smarty — Latte's escape-by-default and sandbox solve them
systemically:

| Concern | Latte approach |
|---|---|
| **XSS surface from inconsistent manual escaping** (the dominant security risk in the current `.tpl` set — every `{$var}` outside a `<script>`/`<style>` block missing an explicit `escape`/`translate`/`json_encode`/`urlencode` modifier is a potential injection) | Context-aware escape-by-default; sandbox + `PiwigoPolicy` for plugin-supplied templates. The audit reduces to "remove redundant escapes" rather than "find missed escapes." |
| **Markup in translation strings** (e.g. `'Return to <a href="…">Sign in</a>'\|translate\|replace:…` — translators must preserve HTML and a magic placeholder filename) | Handled at conversion: split markup out of `.po` keys via `{capture}` patterns; translation strings carry only `%s` placeholders that controllers fill with HTML. |
| **`{section name=…}` legacy loop** in `search.tpl` (Smarty 5 still supports it but flags for removal) | Mechanical converter rewrite to `{foreach}` |
| **Dynamic `{include file=$var}`** (plugin extension hook backed by `get_extent()`) | `TemplateExtensionRegistry` whitelist + Latte sandbox compile-time check; a path outside the project root or containing `..` rejects at compile time. |
| **Inline mail-CSS rendered through Smarty-in-Smarty** (`mail/text/html/header.tpl` includes `mail-css.tpl` whose body is itself templated) | Pre-render the mail CSS at deploy time; load as a static asset in mail templates. There's no plugin extension point that justifies the runtime path. |

Out of scope (informational — leave alone): HTML4 mail attributes
(`cellspacing`, `cellpadding` — still acceptable for Outlook), mixed
tab/space indentation in template files (`.editorconfig` covers new
edits).

##### Plugin compatibility shim

Plugins that haven't migrated their `.tpl` files keep getting rendered by
`SmartyEngine`. The dispatcher in `TemplateRegistry::current()` picks the
engine based on file extension:

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
    public function getId(): string;             // 'piwigo-openstreetmap'
    public function getVersion(): string;        // '1.4.0'
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
  "id": "piwigo-openstreetmap",
  "version": "1.4.0",
  "name": "OpenStreetMap",
  "minPiwigo": "16.0",
  "minForkVersion": "1.0",
  "main": "Piwigo\\Plugin\\OpenStreetMap\\Plugin",
  "autoload": { "psr-4": { "Piwigo\\Plugin\\OpenStreetMap\\": "src/" } }
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

##### Migration walkthrough — `LocalFilesEditor`

Existing layout:

```
plugins/LocalFilesEditor/
  main.inc.php             # registers handlers via add_event_handler()
  maintain.inc.php         # extends PluginMaintain
  include/                 # tab handlers
  codemirror/              # vendored library (Tier 5 of TS 2.4)
  template/                # Smarty .tpl files
```

Post-migration:

```
plugins/LocalFilesEditor/
  plugin.json              # declarative manifest
  src/
    Plugin.php             # implements PluginInterface
    Maintain.php           # implements lifecycle methods
    Tab/
      ConfigTab.php
      CssTab.php
      TplTab.php
      LangTab.php
      PluginTab.php
  template/                # Latte .latte files (after 1.2 wave 4)
```

Steps to migrate one plugin (one commit each, in order):

1. Move source under `plugins/<id>/src/` with PSR-4 namespace
   `Piwigo\Plugin\<Pascal>\`.
2. Convert `main.inc.php` event-handler registrations to a `Plugin` class
   with `subscribedEvents()`.
3. Convert `maintain.inc.php` to a `Maintain` class implementing the
   lifecycle methods.
4. Add `plugin.json`.
5. Convert templates to Latte (folded into 1.2 Wave 2).

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

##### Bundled plugins to migrate

One commit per plugin:

- `LocalFilesEditor`
- `nbc_ThemeChanger`
- `piwigo-openstreetmap`
- `piwigo-videojs`
- `user_tags`

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

Plugins (notably `nbc_ThemeChanger`) subscribe to this instead of using
procedural hooks. `ThemeRegistry::activate($id)` dispatches it after the
swap.

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
5. Convert templates to Latte (folded into 1.2 Wave 4).
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

# All bundled plugins use the new API:
for p in plugins/*/; do
  test -f "$p/plugin.json" || echo "MISSING: $p"
done

# Legacy bridge still works:
vendor/bin/phpunit --filter LegacyEventBridgeTest

# Event dispatcher conforms to PSR-14:
php -r 'echo (new Piwigo\Event\EventDispatcher) instanceof Psr\EventDispatcher\EventDispatcherInterface ? "ok" : "fail";'

# E2E with all plugins activated:
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

| Item | Files | Effort |
|---|---:|---|
| `ImageInterface::compose(mixed $overlay)` → `ImageInterface $overlay` | 4 | trivial |
| `CookieService::getCookieVar()` → `string\|null` (cookies are always strings) | 1 | low |
| ID parameters `mixed $id` / `$userId` → `int\|string` | ~25 methods | low |
| `Config::raw()` typed return — `string\|int\|bool\|array<mixed>\|null` | 1 | low (annotation) |
| `EventDispatcher::dispatch()` → `@template T` generic — eliminates many downstream `mixed`s | 1 | medium |
| Typed DB query helpers (`DbConnection::fetchIntColumn`, `fetchStringColumn`) — removes ~100 `fn (mixed $v)` lambdas | several | medium |
| `RequestCache` / `PersistentCache` → `@template T` generic — typed cache reads | 2 | medium |
| **Repository entity layer** — repositories return typed `*Entity` objects instead of `array<string, mixed>`; `fromRow()` is the single cast boundary | 20 repos + callers | high |
| **HTTP input boundary** — route all remaining raw `$_POST`/`$_GET` reads through `StringUtil::input*`; no raw superglobal access outside that helper | ~30 sites | medium |

##### Concrete examples

**ImageInterface::compose**

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

**ID parameters**

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

**Typed DB query helpers**

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

**EventDispatcher generic**

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

**Repository entity layer**

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

**HTTP input boundary**

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
Add a `--target=<path>` flag so per-plugin `Config` classes regenerate
from the same tool:

```bash
php tools/build-config-accessors.php   # default — Piwigo Config
php tools/build-config-accessors.php --target=plugins/LocalFilesEditor/src/Config.php
php tools/build-config-accessors.php --check   # CI guard — no diff
```

Running with `--check` in CI catches accessor/SCHEMA drift across all
Config classes (Piwigo + the 4 plugin ones).

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
- run: vendor/bin/pest                       # all
- run: vendor/bin/pest --group=unit          # unit only
- run: vendor/bin/pest --group=browser       # browser only
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

##### Current concentration

Ordered for "biggest files first" attack:

| File | Count |
|---|---:|
| `tags.ts` | 80 |
| `user_list.ts` | 58 |
| `albums.ts` | 52 |
| `group_list.ts` | 45 |
| `album_selector.ts` | 35 |
| `batchManagerUnit.ts` | 31 |
| `batchManagerGlobal.ts` | 27 |
| (other ~24 files) | ~150 |

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
  .then(r => r.json())
  .then((data: any) => render(data.result.images));

// after
fetch('/ws.php?method=pwg.images.search&format=json')
  .then(r => r.json() as Promise<{ stat: 'ok'; result: ImageSearchResponse }>)
  .then(data => render(data.result.images));
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

##### Install

```bash
npm i -D vitest @vitest/coverage-v8 happy-dom
```

##### `vitest.config.ts`

```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: [
      'themes/_base/js/**/*.test.ts',
      'themes/admin/_base/js/**/*.test.ts',
    ],
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

1.6.1 Pest absorbs the *browser* tests (Playwright →
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

##### Install

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
  { "name": "admin/admin",          "path": "dist/assets/admin-*.js",          "limit": "85 kB" },
  { "name": "admin/batchManager*",  "path": "dist/assets/batchManager*-*.js",  "limit": "60 kB" },
  { "name": "admin/picture_modify", "path": "dist/assets/picture_modify-*.js", "limit": "55 kB" },
  { "name": "themes/_base/script",  "path": "dist/assets/script-*.js",         "limit": "45 kB" }
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

**Status:** 🟢 Active ▸ 1 of 5 tiers done · **Effort:** L · 4 tiers remain

Replace third-party JS/CSS libraries currently checked into the repo
under `plugins/`, `themes/admin/`, and `themes/standard_pages/fonts/`
with versioned npm dependencies. Outcome: a single canonical version per
library, no ~12 MB of stale `video-js-{4,5,6,7}` mirrors, and a clean
Stylelint/ESLint scope (vendor stops appearing in lint output by virtue
of being in `node_modules/`).

##### Inventory

| Lib | Location | Pinned version | Approx size | npm package |
|---|---|---|---:|---|
| video.js (×4 mirrors) | `plugins/piwigo-videojs/video-js-{4,5,6,7}/` | 4.12.15 / 5.x / 6.12.1 / 7.21.5 | ~12 MB | `video.js` |
| Leaflet | `plugins/piwigo-openstreetmap/leaflet/leaflet.js` | **0.7.7** (2015) | ~135 KB | `leaflet` |
| Leaflet plugins (×8) | `plugins/piwigo-openstreetmap/leaflet/*` | all 0.7-era | ~500 KB | `leaflet.markercluster`, `leaflet-search`, `leaflet.contextmenu`, `leaflet-providers`, `leaflet.elevation`, `leaflet-control-minimap`, `leaflet-omnivore` |
| CodeMirror | `plugins/LocalFilesEditor/codemirror/` | ~v2 (1915 LOC) | ~70 KB | `codemirror` |
| Open Sans webfont | `themes/admin/_base/fonts/open-sans/` | locally generated subset | ~250 KB | `@fontsource/open-sans` |
| Open Sans variable font | `themes/standard_pages/fonts/OpenSans-VariableFont_wdth,wght.ttf` | Google Fonts dump | ~340 KB | `@fontsource-variable/open-sans` |
| jQuery tablesorter | `plugins/nbc_ThemeChanger/include/jquery.tablesorter.js` | ancient | ~15 KB | `tablesorter` |
| jquery.addtags | `plugins/user_tags/js/jquery.addtags.js` | packed/obfuscated | ~3 KB | replace with `tom-select` (drops jQuery dep) |

Stays as static asset (cannot move to npm):

- Fontello custom-glyph subsets in `themes/admin/_base/fontello/`,
  `themes/_base/vendor/fontello/`, `plugins/piwigo-openstreetmap/fontello/`
  — project-specific glyph builds, not packageable.
- Bundled themes (`themes/elegant`, `themes/modus`, `themes/smartpocket`,
  `themes/bootstrap_darkroom`) — themes, not libs, out of 16.x core scope.
- `themes/_base/js/plugins/piecon.ts` — already authored TS, ~100 LOC, no
  maintenance burden.

##### Tier 1 — Quick wins (S each)

Each item is ≤1 day:

```bash
npm i @fontsource/open-sans
# replace themes/admin/_base/fonts/open-sans/ — Vite serves WOFF2/CSS via npm
git rm -r themes/admin/_base/fonts/open-sans/
```

Same pattern for:

- `@fontsource-variable/open-sans` replacing the Google Fonts TTF dump.
- `tablesorter` replacing the jQuery script in `nbc_ThemeChanger`.
- `tom-select` swap for `user_tags/jquery.addtags.js`. tom-select is
  already a Piwigo dep — converting `init.php`/templates to load the
  shared bundle drops the jQuery dependency for that plugin entirely.

##### Tier 3 — video.js consolidation (M)

Drop `video-js-4` and `video-js-5` outright (vintage admins almost
certainly absent in 16.x install base). Pin npm `video.js@7` (or `@8`
if a smoke pass on the test gallery passes).

Port the bundled add-ons to npm equivalents:

- `videojs.thumbnails` → `videojs-thumbnails`
- `videojs.watermark` → `videojs-watermark`
- `videojs.zoomrotate` → `videojs-zoomrotate`
- `videojs-resolution-switcher` → `videojs-hls-quality-selector`

Net: ~12 MB removed from repo, single version to maintain.

##### Tier 4 — Leaflet 0.7 → 1.9 (L, highest blast radius)

Plan:

1. Audit which Leaflet plugins `osmmap.php` / `osmmap2.php` /
   `osmmap3.php` / `osmmap4.php` actually call. Some bundled plugins may
   be dead weight.
2. Stand up `leaflet@1.9` + `leaflet.markercluster` + `leaflet-search`
   on a feature branch. The 0.7 → 1.x core-API delta is mostly
   tile-layer/marker construction; plugin APIs vary.
3. Replace:
   - `Leaflet.Elevation-0.0.2` → `@raruto/leaflet-elevation`
   - `Control.MiniMap.js` → `leaflet-control-minimap`
   - `leaflet-omnivore.min.js` → `leaflet-omnivore`
   - `qleaflet.jquery.js` is a thin jQuery wrapper — drop or rewrite
     without jQuery.
4. Smoke-test all four `osmmap*.php` entry pages and the gallery
   picture-page map widget across a few sample galleries with multi-marker
   clusters.

##### Tier 5 — CodeMirror (M)

Two paths:

- **Low risk**: `codemirror@5` (still maintained as a legacy line). Drop-in
  close to v2; `LocalFilesEditor`'s wiring needs minor edits.
- **Long term**: `codemirror@6` — rewrite. Different module shape, separate
  language packages (`@codemirror/lang-php`, etc.). Better future but full
  editor re-init.

Pick one based on appetite; v5 is the recommended default unless someone
wants to invest.

##### Two-commit-per-tier discipline

Each tier produces two commits:

1. Add the npm dep + glue.
2. Delete the vendor dir.

Two commits make the actual replacement reviewable; the deletion commit
is otherwise a 12 MB diff that hides the real change.

##### Verification

```bash
# Vendor disappears
git ls-files plugins/piwigo-videojs/video-js-{4,5}/        # empty after Tier 3
git ls-files plugins/piwigo-openstreetmap/leaflet/         # only Piwigo glue after Tier 4
git ls-files plugins/LocalFilesEditor/codemirror/          # empty after Tier 5
git ls-files themes/admin/_base/fonts/open-sans/           # empty after Tier 1.1

# Dependencies show up where they belong
jq '.dependencies' package.json | grep -E 'video.js|leaflet|codemirror|@fontsource'

# Lint output stops mentioning vendor paths
npm run lint:css 2>&1 | grep -E '(piwigo-videojs|piwigo-openstreetmap|codemirror|open-sans)'
# empty

# Bundle still builds + smoke-tests pass
npm run build
npx playwright test
```

---

## 3. CSS / themes

### 3.1 Design tokens + Stylelint

**Status:** 🟢 Active ▸ 3 of 13 steps done · **Effort:** M · 10 live steps remain

##### Goal

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

##### Concrete examples

**Step 6 — token block at theme root**

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
button.primary { background: #f70 !important; color: white !important; }
.divider { border-color: #d8d8d8 !important; }
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

**Step 10 — admin design tokens via Smarty**

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

```
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

| Reason | Files | Count |
|---|---|---:|
| Child-theme load-order (child CSS loads before parent; overrides need `!important` until CSS variable migration is complete) | `themes/admin/dark/theme.css`, `themes/admin/light/theme.css` | ~150 |
| Third-party CSS override (search popin / mcs-search injects its own CSS) | `themes/_base/css/search.css`, `clear-search.css`, `dark-search.css` | ~30 |
| `[hidden]` HTML5 attribute beating `display: flex/block` class rules | `themes/admin/_base/theme.css`, `themes/_base/theme.css` | 2 |
| JS-toggled visibility (`display: none/flex/block`) | `themes/admin/_base/css/pages/user-list.css`, `user-activity.css`, etc. | ~10 |

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

| File | Count | Notes |
|---|---:|---|
| `themes/admin/_base/css/pages/user-list.css` | 20 | Mixed: tom-select items + layout + a few JS-toggled (keep) |
| `themes/admin/_base/css/pages/picture_modify.css` | 11 | Tom-select `.item` (Tier 2) |
| `themes/admin/_base/css/components/general.css` | 9 | Buttons, head-buttons fighting parent specificity |
| `themes/admin/_base/css/pages/albums.css` | 6 | Tom-select `.item` + margin |
| `themes/admin/_base/css/pages/maintenance-sys.css` | 4 | |
| `themes/admin/_base/css/pages/user-activity.css` | 3 | Excluding JS-toggled |
| `themes/admin/_base/css/pages/history.css` | 3 | |
| `themes/admin/_base/css/pages/{batch_manager_unit,cat-list,intro}.css` | 2 each | |
| `themes/admin/_base/css/components/{album_selector}.css` | 2 | |

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

##### Goal

Integrate `@axe-core/playwright` into the existing E2E suite. WCAG 2.1
AA violations of severity *moderate* and above fail CI. Existing
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
  const blocking = results.violations.filter(v =>
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
