# Modus → piwigo17-rewrite: Port Analysis

Source: `/home/torres/piwigo16-themes/modus_16.3.0.1` (v16.3.0.1, GPL-2.0-or-later, `piwigo.org/ext/extension_view.php?eid=728`)
Target: `/home/torres/piwigo17-rewrite`, porting to `/home/torres/piwigo16-themes/modus_17.0.0/`

**Status:** First real theme port attempted on this fork. Zero themes ported to date; the prior blanket "genuinely clean" static audit for all themes was reverted 2026-08-25 for being unreliable — this document is built from 7 independent, source-verified deep-dive passes (gap-pattern audit, lifecycle/manifest/skins architecture, admin settings, Smarty→Latte templates, CSS/skins pipeline, JS→TS, i18n/images/packaging), cross-checked against each other and against real target-repo source, not assumed from precedent, run via a multi-agent workflow.

**Relationship to `bootstrap-darkroom-port-analysis.md`** (same directory): that analysis independently hits several of the same cross-cutting gaps this one does (theme i18n load-timing, `SettingsPageInterface` having zero real implementors, `colorscheme`'s static-enum-vs-runtime-switch mismatch) — cross-referenced by name at each occurrence below (§4). This document is organized by porting *area* (manifest/lifecycle, admin, templates, CSS, JS, i18n) with an explicit per-item verdict (clean port / needs adaptation in piwigo17-rewrite / needs adaptation in modus's own package / drop / no direct target equivalent / needs a human decision) rather than by verdict-tier-first, so a future implementer can read one section per work area; §3 and §4 below still separate every item by that same verdict inline.

---

## 1. Executive summary

**Modus is a bad first-port candidate, and materially more involved than the porting guide's median case.** Of the theme's 154 files, only 3 (`themeconf.inc.php`, `admin/admin.inc.php`, `admin/maintain.inc.php`) contain any of the 10 audited legacy-API gap patterns — on that narrow metric modus looks clean. But the real difficulty is architectural, not pattern-matching: modus is built almost entirely on **Smarty compile-time extensibility** (`registerFilter('pre', ...)`, `set_prefilter()`, `registerPlugin('modifier'/'function', ...)`) — a mechanism class this fork's Latte pipeline has **zero equivalent for, confirmed independently by four of the seven dimensions** (gap-audit, manifest, templates, CSS). Every one of modus's signature UX features — the mobile action-button collapse, the album-action switcher, the "recent" icon glyph, all vendor-prefixed skin gradients and DPI media queries, and the entire custom masonry ("modus mode") thumbnail layout — is delivered through this now-nonexistent mechanism. None of it can be translated call-site-by-call-site; all of it has to be re-authored by hand into modus's own Latte template overrides, several of which (`picture.latte`, `index.latte`, and the header/footer chrome now merged into `layout.latte`) are large, currently-un-overridden, shared `default`-theme files. On top of that, modus is the **first theme ever to exercise** two load-bearing but so-far-untested fork mechanisms end-to-end in production: the `SettingsPageInterface`/`ExtensionContext` settings-page contract, and the `GetPageAssets`/`AssetContribution`/`PageAssets` theme-JS-delivery pipeline (currently exercised only by unit tests). It also surfaces a genuine architecture/schema mismatch (per-skin light/dark variance vs. `theme.json`'s single static `colorscheme` enum) and at least 6 confirmed `ExtensionContext` capability gaps (shared session keys, cookies, request-scoped config overrides, query-param access, `ImageStdParams`, per-theme `localHead`). None of these gaps are fatal — every one has a documented, concrete resolution path — but they are real, numerous, and mostly require either a human design call or a small `piwigo17-rewrite` core change before implementation can proceed cleanly. A team should expect this port to look more like "port + several small core/`default`-theme changes + two new integration-test surfaces" than "translate templates and ship."

The good news, and the reason the port is still worth doing as the *first* one: almost everything genuinely obsolete about modus (IE shims, vendor-prefixed gradients, `fotorama.tpl`, `thumb.pop.js`, `mail-css.tpl`, `iconset.css`, RVCDN/RVPT third-party-plugin branches) drops cleanly with zero translation cost, and the fork's design already has the *right* replacement idiom for modus's one hard, recurring need (request-computed values reaching otherwise-static CSS) already proven twice in shipped code (`thumbnails.latte`, `rating_user.latte`). The work is real, but it is bounded, well-understood, and the seven dimensions agree with each other everywhere they overlap.

---

## 2. Proposed target layout

```
modus_17.0.0/
├── theme.json
├── screenshot.png
├── src/
│   └── Theme.php                         # ExtensionInterface + SettingsPageInterface + subscribedEvents()
│       # (constants, DEFAULT_CONFIG, SkinCatalog data may live in Theme.php or split out;
│       #  shown split below for clarity — either is acceptable)
│   ├── Constants.php                     # MODUS_STR_RECENT / MODUS_STR_RECENT_CHILD glyphs
│   ├── SkinCatalog.php                   # static array/enum of 18 skins -> {label, colorscheme, cssVars}
│   ├── Skin.php                          # typed value object, one per legacy skins/*.inc.php
│   ├── ModusThemeSettings.php            # optional VO for the 5-field settings blob (or plain array)
│   └── Projection/
│       └── ModusSettingsView.php         # #[Template(__DIR__.'/../template/modus_admin.latte')]
├── template/
│   ├── modus_admin.latte                 # settings page (replaces admin/modus_admin.tpl)
│   ├── layout.latte                      # OPEN DECISION (§4-B): full-copy override, or a {block}-only
│   │                                      #   override once default/layout.latte grows named blocks
│   ├── picture.latte                     # override: titrePage class + actionButtonsWrapper + switcher
│   ├── slideshow.latte                   # override: titrePage class only
│   ├── index.latte                       # override: albumActionsSwitcher button before categoryActions
│   ├── mainpage_categories.latte         # override: albSymbol glyph span (+ "modus mode" branch, §4-C)
│   ├── thumbnails.latte                  # override: albSymbol glyph span (+ "modus mode" branch, §4-C)
│   ├── menubar.latte                     # small shell override: menuSwitcher + menuh.ts load
│   ├── menubar_links.latte               # horizontal-menu-specific DOM
│   ├── menubar_tags.latte                # horizontal-menu-specific DOM
│   ├── menubar_specials.latte            # horizontal-menu-specific DOM (hoisting gap, §4-G)
│   ├── menubar_identification.latte      # horizontal-menu-specific DOM
│   └── picture_content_asize.latte       # new: adaptive-size picture display (RVAS-driven)
├── theme.css                              # consolidated base+menuh+index+picture .css.tpl → static,
│                                          #   var(--modus-*) custom properties, @import-ing the below
├── hf_base.css                           # @import'd from theme.css (gating decision, §4-N)
├── plugin_compatibility.css              # @import'd, inert until UserCollections-equivalent exists
├── print.css                             # @import'd
├── tags.css                              # @import'd
├── vendor/
│   ├── fontello/
│   │   ├── config.json                   # keep for future glyph regen
│   │   ├── LICENSE.txt
│   │   ├── css/modus.css                 # real glyph-code stylesheet (@import'd from theme.css)
│   │   └── font/modus.{eot,svg,ttf,woff,woff2}
│   └── open-sans/
│       ├── open-sans.css                 # @import'd from theme.css (no longer needs raw <link> special-casing)
│       └── fonts/**                      # + fonts/LICENSE.txt
├── images/
│   ├── img_next.png
│   └── img_prev.png
├── js/
│   ├── menuh.ts                          # menu toggle (clean port)
│   ├── modus-async.ts                    # action-button switcher (clean JS; needs native markup first)
│   ├── photo-autosize.ts                 # derivative auto-sizing (needs page-data.ts + queue patterns)
│   └── thumb-arrange.ts                  # RVGTLine/RVGThumbs justified-row layout (new module)
└── language/
    └── <46 locale dirs>/
        └── theme.po                       # converted from theme.lang.php via tools/i18n/php-to-po-fn.php
```

**Explicitly NOT ported** (confirmed dead/obsolete by at least one dimension, uncontested by the others): `template/fotorama.tpl`, `mail-css.tpl`, `css/iconset.css`, `html5shiv.js`, `js/thumb.pop.js`, `obsolete.list`, `pem_metadata.txt`, `images/index.php`, `language/index.php`, the RVCDN and RVPT_JQUERY_SRC branches in `themeconf.inc.php`, `MODUS_CSS_VERSION`'s crc32 cache-bust, the tabsheet in `admin/admin.inc.php`, the IE7/embedded/codes fontello CSS variants and `demo.html`, and the open-sans package's build-only files (`bower.json`, `*.scss`/`*.less`, `sass/**`, `*.expanded.css`, `*.prefixed.css`, `index.html`, `README.md`).

### `theme.json` (full content)

```json
{
  "$schema": "https://raw.githubusercontent.com/darktorres/piwigo17/17.x-rewrite/docs/schemas/theme.schema.json",
  "id": "modus",
  "name": "modus",
  "version": "16.3.0.1",
  "description": "Responsive, horizontal menu, retina aware, no lost space.",
  "homepage": "https://piwigo.org/ext/extension_view.php?eid=728",
  "author": "rvelices",
  "authorUri": "http://www.modusoptimus.com",
  "license": "GPL-2.0-or-later",
  "minPiwigo": "17.0.0",
  "main": "Piwigo\\Theme\\Modus\\Theme",
  "autoload": { "psr-4": { "Piwigo\\Theme\\Modus\\": "src/" } },
  "hasSettings": true,
  "parent": "default",
  "loadParentCss": false,
  "colorscheme": "dark",
  "assets": { "screenshot": "screenshot.png" }
}
```

Notes on fields that needed cross-dimension reconciliation (all other fields were uncontested across dimensions):

- **`minPiwigo: "17.0.0"`** — the manifest/lifecycle dimension's draft proposed `"16.3.0"` (the *legacy* theme's own declared minimum). That is very likely a copy-paste of the wrong number: the ported code depends entirely on `ExtensionContext`/`ExtensionInterface`/Latte, none of which exist before this fork — it cannot run on anything older than `17.0.0`. Corrected here.
- **`version: "16.3.0.1"`** — kept as the manifest dimension proposed, deliberately *not* `"17.0.0"`: `theme.schema.json`'s own docblock explicitly cites modus's real upstream version ("16.3.0.1") as its grounding example, i.e. `version` tracks the *upstream* release this port is based on, while the directory suffix (`modus_17.0.0/`) and `manifest.json`'s new catalog entry track the *port* target — the same convention already used elsewhere in this fork's ported-theme precedents.
- **`loadParentCss: false`** — the manifest dimension's draft omitted this field entirely; the CSS/skins dimension worked out, with a specific confirmed collision (`default/theme.css`'s sprite-based `.pwg-icon{background-image:...}` vs. modus's own font-glyph `.pwg-icon`), that `true` would be a real visual regression legacy modus never had. No dimension disputes this once raised — treated here as settled, not open.
- **`colorscheme: "dark"`** — the manifest, gap-audit, and CSS dimensions all independently raise the per-skin colorscheme mismatch as `needs_human_decision`, but all three land on compatible answers once you separate the two same-named-but-unrelated concepts (see §4-A). `"dark"` here is Option A (this schema field stays a static, secondary fact matching the *theme-level* legacy default), pending a human sign-off — not a considered-final answer, flagged accordingly in §4.
- **`hasSettings: true`** vs. a `'webmaster'`-scoped variant — the admin dimension flags this as a one-word open decision (schema apparently supports both); `true` here matches legacy's actual behavior (no webmaster-only restriction existed).

---

## 3. Portability breakdown by area

### 3.1 Exhaustive gap-pattern audit (ground truth for the other 6 dimensions)

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| 9× `add_event_handler()` registrations | needs_human_decision | 6/9 map to a live event class; 2 are dead (RVCDN/RVPT never defined); 1 (`combinable_preparse`) has zero current equivalent |
| Smarty compile-hook/plugin registration (8 occurrences) | unsolved_gap | Zero equivalent exists on this Latte fork; 3 different payloads (cssGradient, cssResolution, modus_thumbs) each need separate disposition |
| `modus_smarty_prefilter()` (raw-source rewriting) | unsolved_gap | No compile-time source-rewrite hook of any kind on this fork |
| `pwg_query()` raw SQL in `theme_delete()` | clean_port | `ExtensionContext::deleteSetting()` covers it exactly |
| `pwg_mail()` | drop | Zero occurrences |
| `pwg_get/set_session_var('caps', ...)` | clean_port | modus-own key, not shared with core |
| `pwg_get_session_var('index_deriv'/'picture_deriv')` (reads) | unsolved_gap | These are real, already-in-use **shared core** session keys; `ExtensionContext::session()` is permanently namespaced per-extension, so a theme cannot read core's own value |
| 11× `global $template`/`$conf`/`$page`/`$picture`/`$lang` | adapt_in_piwigo17_rewrite | None exist as raw superglobals on this fork; each site needs its own `ExtensionContext`/event-payload replacement |
| `ws_add_methods` | drop | Zero occurrences |
| `$_GET['skin']` preview override | needs_human_decision | Regex fully blocks path traversal (safe), but no allowlist against real skin ids, and absent from the admin UI's own preview links — may be vestigial |
| `caps`/`picture_deriv`/`phavsz` cookie read/write | unsolved_gap | `ExtensionContext` has zero cookie accessors at all; `cookie_path()` has no confirmed equivalent |
| `admin/admin.inc.php` POST handling has **no CSRF check at all** | adapt_in_piwigo17_rewrite | Real, pre-existing legacy security gap — must be *added*, not faithfully reproduced |
| `conf_update_param()`/unserialize round-trip (3 sites) | clean_port | `getSetting()`/`setSetting()` already accept/return a plain array; drop the serialize/addslashes layer entirely |
| `conf_delete_param()` | clean_port | Same disposition as the `pwg_query()` item above |
| Dynamic `include()` of `skins/<id>.inc.php` (2 sites) | adapt_in_piwigo17_rewrite | No "includable file selected by runtime data" concept in `ExtensionInterface`; all 18 skin files are pure data — replace with an array/data lookup |
| 12-of-18-skins runtime `colorscheme` override vs. schema's static enum | needs_human_decision | See §4-A |
| Direct mutation of `$conf['tag_letters_column_number']` per device | unsolved_gap | Target `CurrentConfig::$tagLettersColumnNumber` is `private(set)`; no request-scoped (non-persistent) override mechanism exists on `ExtensionContext` |
| `$page['tab']` raw-include admin tabsheet | drop | The entire raw-include admin-settings mechanism is gone (P29.15); `SettingsPageInterface` replaces it wholesale |
| Structural: `themeconf.inc.php` runs with `$this` bound to the Smarty `Template` object itself | unsolved_gap | `boot()` is the only entry point and its `template()`/`render()` both throw pre-`Template`-construction; there is no lifecycle point offering unmediated compiling-template access the way legacy assumes throughout |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **9× `add_event_handler()` hook registrations** (`themeconf.inc.php:78,90,122,130,257,266,286,354,369`) — *needs_human_decision*. 6 of 9 hooks map cleanly to a live current event class per `docs/events-legacy-map.md` (loc_begin_index, loc_begin_page_header, loc_end_index, get_index_derivative_params, loc_end_index_category_thumbnails, loc_begin_picture, render_element_content). 2 are gated on RVCDN/RVPT_JQUERY_SRC constants that are never defined anywhere in the theme (dead code, external private plugin dependency). 1 (combinable_preparse) has zero current equivalent — the whole Combinable/FileCombiner mechanism was deleted by P41-G. *Proposed approach:* Register the 6 live hooks via `subscribedEvents()`. Drop the 2 RVCDN-gated handlers entirely (unreachable in any real install). Treat combinable_preparse's handler body (skin-parameterized CSS generation) as a separate unsolved_gap.

> **Smarty compile-time hook / custom plugin registration** (`themeconf.inc.php:67,77,94-95,137-138,153,183`; payload bodies in `functions.inc.php:2-14,27-81`; consumed at `css/base.css.tpl:70,102,119,156`, `css/menuh.css.tpl:75`, `template/thumbnails.tpl` ×8, `mainpage_categories.tpl` ×2, `comment_list.tpl` ×1, `css/index.css.tpl` ×2) — *unsolved_gap*. Confirmed zero equivalent exists anywhere in this fork: the Latte engine gets exactly one fixed `PiwigoExtension` at construction time with no per-plugin/theme registration point at all. Three distinct payloads are registered this way and each needs a different disposition: cssGradient (vendor-prefixed gradients) and cssResolution (DPI media-query builder) are IE/legacy-era compatibility shims that modern CSS makes unnecessary outright; modus_thumbs is real, load-bearing per-request layout logic (justified thumbnail grid) with no shim available. *Proposed approach:* Drop cssGradient and cssResolution as mechanisms; hand-author the resulting CSS at each of the ~20 call sites using plain modern linear-gradient()/min-resolution/image-set() (CSS/skins dimension's job). For modus_thumbs, this is the hardest single piece of behavior in the theme — needs an architecture decision (server-computed View passed through ExtensionContext::render(), vs. a client-side flex/grid rewrite) before any implementation starts.

> **Smarty prefilter `modus_smarty_prefilter()`** (`themeconf.inc.php:67-72`; `functions.inc.php:27-81`) — *unsolved_gap*. A `registerFilter('pre', ...)` prefilter rewrites the compiled template's own textual source before Latte/Smarty ever parses it — there is no analogous hook on this Latte-based fork. *Proposed approach:* Not a call-site fix — each individual markup change this prefilter makes needs to be hand-applied directly into the already-Latte-converted parent 'default' theme templates that modus overrides, as literal Latte source edits in modus's own override templates, rather than as a runtime string-rewrite step.

> **`pwg_get_session_var('index_deriv')` / `('picture_deriv', ...)`** (reads of SHARED CORE session keys, `themeconf.inc.php:270,377,473`) — *unsolved_gap*. Verified directly against target-repo source: `index_deriv`/`picture_deriv` are real, already-in-use core session keys (stored as `pwg_index_deriv`/`pwg_picture_deriv`) that `GalleryController`/`PictureController` themselves read and write. `ExtensionContext::session()` is deliberately, permanently namespaced per extension id specifically to prevent cross-extension key collisions — there is no accessor anywhere on `ExtensionContext` for a shared/un-namespaced core session key. Modus's handlers need to READ core's own already-set value (to avoid overriding a visitor's explicit manual choice with a device-capability guess), which is categorically impossible through the namespaced `session()` accessor. *Proposed approach:* Requires a new `ExtensionContext` capability (e.g. a narrow, explicitly-named accessor for these two specific, already-shared derivative-size session keys) before this handler logic can be ported at all. Flag for a human decision: either add such an accessor, or accept behavioral degradation (modus's HDPI/manual-choice-respecting logic silently stops working) as a scoped, documented gap.

> **`$_GET['skin']` per-request skin-preview override** (`themeconf.inc.php:24-25`) — *needs_human_decision*. The regex (`/[^a-zA-Z0-9_-]/`, deny-listed via `!preg_match` on the whole string) fully blocks path traversal — confirmed safe against LFI. But it has no allowlist against the 18 real skin ids (robustness gap, not security), and this query-string override is absent from the admin UI's own skin-preview links (`modus_admin.tpl` uses static `*-screenshot.jpg` thumbnails only, never a live `?skin=` preview link) — suggesting it may be a vestigial/developer-only feature, not a real end-user-facing one. *Proposed approach:* Human call needed: either preserve `?skin=` as a documented, allowlist-validated preview feature (add an `in_array()` check against the real skin list), or drop it entirely as dead/undocumented surface area.

> **`'caps'`/`'picture_deriv'`/`'phavsz'` cookie reads and `setcookie()` calls** (`themeconf.inc.php:53,55,398-399,402`) — *unsolved_gap*. `ExtensionContext` has zero cookie-related accessors at all — neither reading nor writing a cookie is a sanctioned operation for an extension on this fork. `cookie_path()` itself (a legacy helper used at both `setcookie()` call sites) also has no confirmed equivalent anywhere in this fork's source. *Proposed approach:* Needs either a new `ExtensionContext` cookie accessor (mirroring `session()`'s narrow, purpose-built shape) or a documented decision to keep this one narrow slice of raw `$_COOKIE`/`setcookie()` PHP as an accepted, unmediated escape hatch specifically for read-once client-capability probes.

> **12-of-18-skins runtime `colorscheme` override vs. `theme.schema.json`'s single static enum** (`themeconf.inc.php:13`; 12 skin files) — *needs_human_decision*. `theme.schema.json`'s `colorscheme` field is a single static, per-theme enum (dark/light) set once in `theme.json` — it cannot express a value that changes per-request based on the user's/admin's chosen skin (10 of 18 modus skins are effectively light ('clear'), 8 are dark, chosen at runtime via the same `$_GET['skin']`/`setSetting()` mechanism). This is a structural mismatch between the manifest contract and modus's actual runtime behavior, not a missing accessor. *Proposed approach:* See §4-A for full option set and recommendation.

> **`$conf['tag_letters_column_number']` per-device mutation** (`themeconf.inc.php:62-65`; `CurrentConfig.php:1440-1444`) — *unsolved_gap*. Verified directly: `CurrentConfig::$tagLettersColumnNumber` has `private(set)` visibility — external code, including a theme via `ExtensionContext::config()`, cannot write to it at all. Even if it could, `ExtensionContext::setSetting()` is the only settings-write path and persists to the DB for ALL future requests/users, not a per-request, per-device, non-persistent override — using it here would incorrectly leak a mobile visitor's override into every subsequent desktop visitor's session too. *Proposed approach:* No existing `ExtensionContext` mechanism supports a request-scoped (non-persistent) override of a typed `CurrentConfig` property. Requires either a new `ExtensionContext` capability for scoped/ephemeral config overrides, or accepting this device-responsive behavior as dropped in the port.

> **Structural: `themeconf.inc.php` runs with `$this` bound to the legacy Template/Smarty object itself** (`themeconf.inc.php:30-45,67,77,153,183`) — *unsolved_gap*. `boot(ExtensionContext $context)` is this fork's only theme entry point, and `ExtensionContext::template()`/`render()` both explicitly throw when called from `boot()` because `CurrentTemplate` isn't constructed yet at that point in the request lifecycle. Legacy `themeconf.inc.php`'s entire top-level (non-event-handler) body assumes unmediated write access to the compiling template object at theme-selection time — there is no lifecycle point on this fork, `boot()` or otherwise, that offers an analogous capability. *Proposed approach:* Root-cause, not per-call-site: every one of `themeconf.inc.php`'s top-level `$this->assign`/`$this->smarty->` statements needs to be re-expressed as either (a) static `theme.json` manifest fields resolved through `getSetting()` inside a later-lifecycle event handler instead of at load time, or (b) accepted as an architecturally unsupported pattern requiring the CSS-generation redesign already flagged. Resolve once as a design decision before touching any individual call site.

---

### 3.2 Manifest, lifecycle & config/skins architecture

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| `themeconf` name/parent/colorscheme → `theme.json` | clean_port | Direct 1:1 field mapping |
| `MODUS_STR_RECENT`/`_CHILD` constants | clean_port | Trivial PHP class constants |
| `modus_theme` config-blob unserialize/normalize | drop | `getSetting()` already returns native shape; guard has nothing left to guard |
| `$_GET['skin']` preview override | unsolved_gap | No request/query-param accessor exists on `ExtensionContext` at all |
| `skins/<skin>.inc.php` conditional `include()` | needs_human_decision | No CSS templating pipeline exists on this fork at all; needs full skins redesign |
| `MODUS_CSS_VERSION` crc32 cache-bust | drop | No boot()-time template access target; `AssetContribution` already owns versioning |
| `MODUS_DISPLAY_PAGE_BANNER`/`MODUS_ALBUM_THUMB_SIZE`/`MODUS_CSS_SKIN` assigns | adapt_in_modus_repo | Move onto typed View properties, this fork's idiom |
| `load_language('theme.lang')` cache gate | needs_human_decision | Cross-references i18n dimension; gate itself may be dead weight |
| `$_COOKIE['caps']` read + session promotion | unsolved_gap | Session half clean; cookie-read half has no accessor |
| `get_device()`-based `tag_letters_column_number` mutation | clean_port | `ExtensionContext::config()` is boot()-safe and mutable |
| Smarty instance-wide prefilter | unsolved_gap | Same as gap-audit finding |
| Named-template prefilters via `loc_begin_index` | drop | No equivalent; duplicate-fontello-strip half is likely moot |
| RVCDN integration branch | drop | Unrelated third-party plugin, permanently dead |
| RVPT_JQUERY_SRC branch | drop | Same, different unrelated plugin |
| `combinable_preparse` handler | drop | Event itself is dead (P41-G); payload also obsolete once CSS is static |
| `cssResolution` custom function | drop | No Latte equivalent; precompute once, hand-author |
| `modus_css_gradient()` modifier | drop | Dead-browser-era + no registration mechanism |
| `modus_thumbs` custom function | unsolved_gap | No registration mechanism; needs `$pwg`/TemplateAdapter-style helper instead |
| `loc_end_index` → `modus_on_end_index` | adapt_in_modus_repo | `Template::concat()` confirmed as the clean replacement |
| `get_index_derivative_params` → handler | adapt_in_modus_repo | Clean registration; body needs getSetting()/session() reads only |
| `loc_end_index_category_thumbnails` → handler | adapt_in_modus_repo | Clean registration; crop-math needs cross-dimension re-verification |
| `loc_begin_picture` → handler | adapt_in_modus_repo | `Template::concat()` clean replacement |
| `render_element_content` → handler | adapt_in_modus_repo | `Template::parse()` exists but its file-resolution contract needs confirmation |
| `theme_activate()` | adapt_in_modus_repo | Clean mapping onto `getSetting()`/`setSetting()`, serialize layer drops entirely |
| `theme_delete()` | clean_port | `deleteSetting()` is a complete, confirmed replacement |
| Settings page + `glob()` skin discovery | needs_human_decision | Confirms `hasSettings:true` requirement; glob() itself still works, just needs a new skin-catalog source |
| Proposed `theme.json` | adapt_in_modus_repo | See §2 |
| Proposed `Theme.php` skeleton | adapt_in_modus_repo | See §2 |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale** (items already fully quoted in §3.1 are cross-referenced rather than repeated: `$_GET['skin']`, `$_COOKIE['caps']`, Smarty prefilters):

> **`skins/<skin>.inc.php` conditional include()** (`themeconf.inc.php:28`; 18 files) — *needs_human_decision*. Legacy includable PHP files mixing a `$skin` CSS-variable array with an optional `$themeconf['colorscheme']` override have no structural equivalent: this fork's CSS pipeline is fully static (no `.css.latte`/`.css.tpl` found anywhere), and `theme.json`'s `colorscheme` is a single static enum with no runtime override path. *Proposed approach:* Precompile all 18 skins into static CSS (one shared base + 18 override files, or 18 full files, mirroring the 11 skins that already ship static `.css` today) driven by a theme-owned static PHP skin-catalog array; separately resolve the colorscheme mismatch via one of the 3 options in §4-A.

> **`modus_thumbs` custom Smarty function (server-side thumbnail `<li>` rendering)** (`themeconf.inc.php:183-255`) — *unsolved_gap*. Registration mechanism has no equivalent; additionally uses `block_html_style()`/`block_footer_script()`/`scriptLoader->add()`, none of which were confirmed present on `Template`'s public surface within this dimension's read. *Proposed approach:* Cross-reference the templates/JS dimension: either a plain static PHP method called inline via Latte's `{=Namespace\Class::method(...)}` print-expression, or a View-layer data-prep step feeding a native Latte `{foreach}`. Confirm separately whether `block_html_style`/`block_footer_script`/`scriptLoader` survive on `Template` at all.

> **`render_element_content` → `modus_picture_content`** (`themeconf.inc.php:369-497`) — *adapt_in_modus_repo* with an open detail. `Template::parse(string $file): string` is confirmed still a real public method, but `set_filenames()`'s array-registration API was not found under that name — needs the templates dimension to confirm `parse()`'s real current file-resolution contract before calling this fully solved.

> **Settings page + `glob()`-based skin discovery** (`admin/admin.inc.php:104-116`) — *needs_human_decision*. Confirms `theme.json` must declare `hasSettings:true`; the `glob('skins/*.inc.php')` discovery mechanism has no future once skins are no longer includable files, and the full settings-page port is cross-dimension work. *Proposed approach:* `Theme.php` implements `SettingsPageInterface::handleSettingsRequest()`, consuming the same static skin-catalog array proposed above as its dropdown source instead of `glob()`.

---

### 3.3 Admin settings page rewrite

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| Controller/rendering architecture | adapt_in_modus_repo | `ThemeSubController` never includes raw PHP; real shape is `SettingsPageInterface`, already proven end-to-end by a test fixture |
| `modus_theme` blob persistence | adapt_in_modus_repo | `getSetting()`/`setSetting()` accept plain arrays natively; drops serialize/allowlist dance |
| CSRF protection | adapt_in_modus_repo | Legacy has **zero** CSRF check; must be *added*, matching every other real caller |
| Skin picker (18 skins, colorbox) | adapt_in_modus_repo | `glob()` still works from a theme's own PHP; colorbox already natively ported |
| Album-thumbnail slider + checkboxes | adapt_in_modus_repo | `vendor/slider.ts` is an already-shipped drop-in replacement |
| Derivative-size dropdowns | adapt_in_piwigo17_rewrite | `ImageStdParams` has no `ExtensionContext` accessor — real core gap |
| Theme-owned settings-page JS never reaches Vite | unsolved_gap | `collectScriptEntries()` only scans core `src/`, never `themes/**/src` |
| Single "Configuration" tabsheet | drop | Dispatcher builds no tab chrome at all; zero functional payload |
| `#[Template(...)]` resolution for a theme-owned `.latte` | clean_port | `__DIR__`-based absolute path, confirmed working, already used by the test fixture |
| Language strings (`theme.lang`, 47 locales) | needs_human_decision | Defers to i18n dimension |
| Cross-dimension: shared `modus_theme` key with lifecycle hooks | adapt_in_modus_repo | Must agree with §3.2's persistence design (it does) |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **Derivative-size dropdowns sourced from `ImageStdParams::get_defined_type_map()`** (`admin/admin.inc.php:100-102`; `modus_admin.tpl:161-174`) — *adapt_in_piwigo17_rewrite*. `ImageStdParams::getDefinedTypeMap()` is the exact replacement, and `configuration_sizes.latte:147`'s own `{$type|translate}` idiom is already precedented. But `ImageStdParams` is a container-shared singleton, reached elsewhere only via direct constructor injection or `Kernel::container()->get()` — `ExtensionContext`'s full constructor and every public method expose no way to reach one, and reaching for the raw container would violate `ExtensionContext`'s own stated design rule ("narrow, named collaborators, never the container"). *Proposed approach:* Add a narrow `ExtensionContext::imageStdParams(): ImageStdParams` accessor (or a purpose-built read facade matching the `images()`/`users()`/`themes()` pattern), threaded through `ExtensionContextFactory`. This is the one item in this dimension that cannot be resolved inside modus's own package.

> **Theme-owned settings-page JS never reaches Vite's build** (`collectScriptEntries.ts:25,55-72`; `vite.config.ts:86`) — *unsolved_gap*. `collectScriptEntries()` text-scans only `piwigo17-rewrite/src/**/*.php` for literal `AssetContribution::script(...)` calls. Every existing real call site lives in a core `src/` class. A theme's own `View` implementing `HasPageAssets` is necessarily autoloaded from `themes/<id>/src/`, which this scan never visits — confirmed by reading the scan function directly. A modus settings-page `.ts` file declared this way would never become a real Vite entry and would 404/never load in production, regardless of how correct the PHP side is. Checked for an escape hatch: zero literal inline `<script>` blocks exist in any shipped `.latte` template today, and `AssetContribution::inlineScript()` is not a proper substitute for a whole page's typed, linted interactive module. *Proposed approach:* Framework fix, not fixable from inside modus's own package: broaden `collectScriptEntries()`'s scanned root(s) to also include `themes/**/src` (and, for future symmetry, `plugins/**/src`). Low risk for bundled themes since `themes/modus/` (once ported) lives inside the same git/build tree as `src/`, exactly like `themes/default/` already does. A separate, deeper question this surfaces but doesn't need resolving for modus: a genuinely external, later-installed third-party theme/plugin still couldn't be Vite-bundled this way at all.

> **Language strings for the new settings page** — *needs_human_decision*, deferred whole to the i18n dimension (§3.7).

---

### 3.4 Smarty template → Latte port

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| `header.tpl`+`footer.tpl` chrome | adapt_in_modus_repo | P41 already merged both into `layout.latte`; only real remaining diff is a 2-line `PAGE_BANNER` gate |
| Prefilter transform 1 (`imageHeaderBar` titrePage class) | adapt_in_modus_repo | Applies to **both** `picture.latte` and `slideshow.latte`, not just picture |
| Prefilter transform 2 (`actionButtonsWrapper`) | adapt_in_modus_repo | Only `picture.latte` has both `imageToolBar` and `actionButtons` together |
| Prefilter transform 3 (`albumActionsSwitcher`) | needs_human_decision | `index.latte` clearly qualifies; `tags.latte`'s 2-item list is a boundary call from an accidental legacy threshold |
| Prefilter transform 4a/4b (recent-icon → albSymbol glyph) | adapt_in_modus_repo | Direct markup translation, confirmed live in both target files |
| Overall prefilter-replacement architecture | needs_human_decision | Option A (full-file copy overrides) vs. Option B (block-refactor `default`'s own templates) — see §4-B |
| `fotorama.tpl` | drop | Confirmed dead code, no library bundled, zero references |
| `mail-css.tpl` | drop | Unreachable by legacy Piwigo's own hardcoded mail-theme lookup; already superseded on this fork |
| `month_calendar.tpl` responsive CSS | drop | Default's current template already emits the needed CSS custom properties |
| `comment_list.tpl` responsive CSS | drop | Same reasoning |
| `mainpage_categories.tpl` standard mode | adapt_in_modus_repo | Full-file override proportionate; only real diff is the recent-icon transform |
| `thumbnails.tpl` standard mode | adapt_in_modus_repo | Same reasoning |
| "modus mode" masonry layout (both files) | needs_human_decision | Template shape is fine; underlying per-item computation needs a new helper shape — see §4-C |
| `menubar.tpl` shell strategy | adapt_in_modus_repo | Default's shell is now a 9-line concatenator of independently-rendered partials; modus must override each partial individually |
| menubar special-link hoisting | needs_human_decision | `MenubarSpecialsView` has no stable discriminator to reliably identify "Most Visited" etc. |
| `picture_content_asize.tpl` | adapt_in_modus_repo | Wiring hook confirmed live; template syntax has established translation recipes |
| `admin/modus_admin.tpl` syntax | needs_human_decision | Owned primarily by the admin dimension; flagged for a `colorbox.inc.tpl` dependency check |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **Prefilter transform 3 — `categoryActions` gets an `albumActionsSwitcher` button when it has "enough" actions** (`functions.inc.php:49-54` vs. `index.latte:15-221`, `tags.latte:7-22`, 9 other templates with an empty list) — *needs_human_decision*. `index.latte` clearly qualifies (13 real `<li>` entries); 9 other templates render an always-empty `<ul>` and never qualify. `tags.latte` has exactly 2 real entries — under the legacy literal `>2` threshold (an accident of how the *compiled Smarty source* happened to be formatted, not a deliberate UX rule), it would not trigger byte-for-byte, but the underlying intent ("collapse when there are several buttons") could reasonably apply there too. *Proposed approach:* Bake the switcher into `index.latte` unconditionally (it always qualifies); ask a human whether `tags.latte`'s 2-item list should also get it before deciding its own override.

> **Overall architecture for shipping the ~4 prefilter DOM edits with no compile-time hook** — *needs_human_decision*. Two real designs exist: (A) full-file copy overrides of the 4 large default templates (zero core changes, but a big diff-to-fork ratio and silent staleness risk as `default` evolves), or (B) refactor `default`'s own templates to expose small named `{block}` regions at exactly these seams so modus's overrides shrink to a handful of lines each and automatically track future `default` changes. Confirmed technically plausible (cross-theme `{include}` with `$ROOT_PATH`-prefixed paths is a real, already-used precedent), but cross-theme `{layout}` block-inheritance specifically has no literal precedent yet and should be prototyped. Option B touches shared theme code outside modus's own directory and sets precedent for the next 2 theme ports (`elegant`, `smartpocket`). *Proposed approach:* Recommend Option B after a small prototype confirms cross-theme `{layout}` chaining works; fall back to Option A per-file otherwise. See §4-B for the full consolidated decision (this is the same underlying question the CSS dimension raises independently as the `resolveLocalHeadOnce()` generalization).

> **"modus mode" custom masonry thumbnail/category layout** — *needs_human_decision*. The template-level shape (a runtime if/else branch) is directly portable. The categories side has a confirmed live event mapping. The thumbnails side's `{modus_thumbs}` is a Smarty function-plugin that emits raw HTML directly from inline PHP — `ExtensionContext` has no "register a custom template tag/function" mechanism, so this needs a different shape (a PHP helper called via the existing `$pwg`/`TemplateAdapter` bridge) rather than a direct translation. *Proposed approach:* See §4-C.

> **menubar `mbMostVisited`/`mbBestRated`/`mbSpecials` hoisting** — *needs_human_decision*. Legacy modus's horizontal-menu UX hoists 3 specific "special" links out of the normal dropdown into always-visible items, keyed by array keys like `$blocks.mbSpecials->data.most_visited`. Today's `MenubarSpecialsView` exposes only a flat list of `{url,title,name,noFollow}` link objects with no stable, non-translated discriminator for which entry is "most visited" — confirmed by reading the whole file. There is no safe way to peel these out by matching translated label text. *Proposed approach:* Either (a) make a small, localized addition to `MenubarSpecialsView`'s item shape (a stable `id`/`kind` per well-known special link) — a small `adapt_in_piwigo17_rewrite` change — or (b) modus drops this specific hoisting behavior and folds all specials into the normal dropdown like every other theme.

---

### 3.5 CSS/SCSS pipeline & visual skins

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| 5 `.css.tpl` files (Smarty-templated stylesheets) | adapt_in_modus_repo | No request-time CSS re-templating exists on this fork; use the proven `<style>:root{}`custom-property idiom |
| 18 skin definitions | adapt_in_modus_repo | Growable N-way per-property-optional palette; matches the custom-property model far better than binary file-swap or 18 duplicate files |
| `cssResolution` function | clean_port | Every real call site is a compile-time-fixed literal; fully precomputable |
| `modus_css_gradient()` modifier | clean_port | Purely a function of each skin's fixed 2-color array; also legacy IE cruft |
| `MODUS_CSS_VERSION` | drop | Nothing left to cache-bust once dynamic values move to a per-request `<style>` block |
| `hf_base.css`/`plugin_compatibility.css`/`print.css`/`tags.css` | clean_port | No Smarty syntax; `@import` into consolidated `theme.css` |
| `url()` relative-path assumptions | adapt_in_modus_repo | Real P39-class risk: consolidating into one theme-root file changes every relative path's resolution |
| `css/iconset.css` | drop | Confirmed zero references anywhere in the theme; would visually conflict with the font-glyph icon system if wired up |
| Fontello icon font kit | clean_port | Keep real files, drop IE7/embedded/codes variants and `demo.html` |
| Vendored open-sans | adapt_in_modus_repo | Legacy's raw-`<link>` special-casing is moot now that no physical file-combining exists |
| `html5shiv.js` | drop | Dead IE<9 shim, inert on every real target browser |
| Plugin-presence-conditional icon CSS | drop | Depends on a legacy `$loaded_plugins` global with no equivalent; none of the 3 referenced plugins exist on this fork yet |
| `index_posted/created_date_icon` conditionals | clean_port | Real, named, typed `CurrentConfig` properties, no gap |
| `theme.json`'s `loadParentCss` | needs_human_decision (resolved) | See §2 — recommend `false`, confirmed collision risk if `true` |
| `theme.json`'s `colorscheme` vs. modus's internal per-skin concept | needs_human_decision | Two same-named, functionally unrelated concepts — see §4-A |
| `Template::resolveLocalHeadOnce()` hardcoded to `default` only | adapt_in_piwigo17_rewrite | Confirmed; its own docblock already anticipates generalizing it — see §4-B |
| `modus_thumbs()`'s `block_html_style()` CSS injection | needs_human_decision | Same custom-property shape as the rest of this dimension; ownership sits with the templates/masonry dimension |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **`skins/<skin>.inc.php` skin selection reaching the browser (13/18-way palette swap)** — *adapt_in_modus_repo*. This fork's one existing "a config value swaps CSS" precedent (`colorscheme` → `selectize.dark.css`/`selectize.light.css`) is a binary whole-file swap for a narrow, fork-owned purpose. Modus's real shape is a growable N-way (18) per-property-optional palette, which matches the CSS-custom-property idiom far better than either a binary file-swap or hand-maintaining 18 near-duplicate static CSS files. *Proposed approach:* Port each `skins/<id>.inc.php`'s `$skin` array into one typed PHP structure, selected at request time from a persisted setting, exposed to a `<style>:root{...--modus-*}` block. Fold the 11 skins' extra `.css` files into the same custom-property model unless hand-inspection finds skin-specific structural rules worth keeping literally.

> **`theme.json`'s `loadParentCss` field for modus** — *needs_human_decision*, resolved in this report to `false` (see §2's rationale) — confirmed by a specific, concrete cascade-collision finding, not asserted.

> **`theme.json` `colorscheme` field vs. modus's own internal per-skin colorscheme concept** — *needs_human_decision*. These are two same-named but functionally unrelated concepts. The schema field is narrowly scoped ("Picks a CSS variant in standard_pages/batch-manager templates"). Legacy modus's own `$themeconf['colorscheme']` is a theme-internal per-skin bucket label with no connection to that fork mechanism. A naive port attempting to wire modus's 18-way skin selector into the schema's `colorscheme` field would hit a hard enum-validation failure and would be conflating two different concerns even if it didn't. *Proposed approach:* Keep them fully separate: `theme.json`'s `colorscheme` stays a simple dark/light declaration feeding only the fork's own selectize-variant mechanism; modus's own 18-skin selection is a wholly separate persisted setting with no relationship to this field. See §4-A.

> **`Template::resolveLocalHeadOnce()` hardcoded to `themes/default/local_head.latte` only** — *adapt_in_piwigo17_rewrite*. Confirmed by direct read: the method compares each theme-chain entry's `localHead` path against a literal `realpath('themes/default/local_head.latte')` and only ever renders that one file, with its own docblock explicitly anticipating exactly this need. This is the one genuine, but small and pre-scoped, core gap: no theme other than `default` can inject `<head>` content through the typed, declared `localHead` mechanism today. *Proposed approach:* Generalize into a real loop over every theme-chain entry with a resolved `localHead`, each with its own dedicated View/template pair. Benefits modus (the skin-driven `<style>:root{}` block) and is likely needed by `elegant`/`smartpocket` too. See §4-B — this is the same underlying "how much shared/core surface do we touch" question the templates dimension raises independently.

> **`modus_thumbs()`'s `block_html_style()` CSS injection** — *needs_human_decision*, primarily a templates/markup-generation concern, but structurally the exact same "a request-computed numeric value must reach otherwise-static CSS" shape as `thumbnails.latte`'s own existing custom properties. *Proposed approach:* Replace with the same `<style>:root{--modus-thumb-margin:...}` + `var()` idiom rather than a novel injection mechanism.

---

### 3.6 JS → TS module port

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| `menuh.js` | clean_port | Pure jQuery→`dom.ts` mapping, no special gaps |
| `modus.async.js` (JS body) | adapt_in_modus_repo | Trivial once native markup exists |
| `modus.async.js` (delivery mechanism) | needs_human_decision | `set_prefilter` mechanism is dead — same finding as §3.4's prefilter architecture question |
| `photo.autosize.js` — data/derivative-switching | adapt_in_modus_repo | `RVAS` global → `page-data.ts` pattern; `rvas_choose()` inline call → queue-drain pattern |
| `photo.autosize.js` — `changeImgSrc` override | unsolved_gap | No global/exported hook exists on `picture.ts` to monkey-patch |
| `thumb.arrange.js`/`.min.js` | adapt_in_modus_repo | Genuinely new module, no house precedent, but not blocked; the shipped file is the minified one |
| `thumb.pop.js` | drop | Confirmed dead/unwired in this theme version |
| Theme JS asset-loading mechanism (cross-cutting) | needs_human_decision | Real, tested plumbing (`GetPageAssets`), but zero real production consumers today |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **`photo.autosize.js` — `window.changeImgSrc` monkey-patch override** (`photo.autosize.js:97-118`; target `picture.ts:19-64`) — *unsolved_gap*. Legacy assumes `changeImgSrc` is a plain global function it can read-then-reassign. On this fork, `picture.ts`'s `changeImgSrc` is a module-private, non-exported function invoked only from a local `addEventListener` closure — there is no global to patch and no exported reference to import and wrap. This is a genuine cross-module override need the fork has no precedent for (the `SwitchBoxQueue` pattern solves "invoke a queued call," not "wrap another module's internal function"). *Proposed approach:* No shaky workaround proposed. Two legitimate real designs: (a) add a small, deliberate override hook to `themes/default/js/picture.ts` itself (e.g. a mutable `window._pwgDerivativeSwitchOverride` slot the click handler consults before its own default logic, in the spirit of `SwitchBoxQueue`) — a real, if small, change to already-shipped shared code for a not-yet-existing second theme; or (b) modus's own module fully reimplements DPR-aware derivative-switching independently, duplicating most of `picture.ts`'s own logic, to avoid touching shared code at all.

> **Theme-contributed JS/CSS asset registration mechanism (cross-cutting precondition)** — *needs_human_decision*. `ExtensionContext` has zero methods for registering a script/CSS asset. The only real mechanism, `GetPageAssets` (a PSR-14 event dispatched once per page, confirmed live in the real dispatch path, not stubbed), has **zero real production consumers today** — every real reference in the repo is a unit test. `GetPageAssets.php`'s own docblock ("No real dispatch site yet") is stale documentation, superseded by P42's real wiring — but the staleness itself signals nobody has exercised this path against a real page. A modus port would be the very first real-world theme/plugin use of this mechanism. Separately, there is no `ExtensionContext` accessor for "what page/view is currently rendering," so per-page-type conditional asset loading has no obvious signal to key off of — though each of modus's own scripts already self-guards against absent DOM. *Proposed approach:* Theme's main class implements `subscribedEvents() => [GetPageAssets::class => ...]`, pushing `AssetContribution` entries unconditionally, relying on each script's own DOM-presence guards for correctness. Recommend real golden-html/VR coverage of a page using it — not just unit tests — before calling the mechanism itself validated, independent of modus's own correctness. See §4-D (this is the same underlying gap as the admin dimension's Vite entry-discovery finding).

---

### 3.7 i18n, images & repo/manifest bookkeeping

| Feature | Verdict | Rationale (one line) |
|---|---|---|
| 46 `theme.lang.php` → `.po` conversion | adapt_in_piwigo17_rewrite | Existing `tools/i18n/php-to-po-fn.php` handles this directly, no tool change needed |
| Theme's own `boot()`-time translation loading | unsolved_gap | No theme/plugin anywhere in this codebase calls the loading path end-to-end today |
| `themeconf.inc.php:50`'s local-override reload | drop | Generic 16.x boilerplate, unrelated to modus's own strings; no `Paths` accessor exists on `ExtensionContext` |
| `images/img_next.png`, `img_prev.png` | clean_port | Plain static PNGs, resolve independent of the `imgDir` manifest mechanism |
| `imgDir`/`iconDir`/`mimeIconDir` fields | clean_port | Correctly omitted, inherits `default`'s values |
| `images/index.php`, `language/index.php` | drop | Standard directory-listing guard boilerplate, no theme subdir is web-reachable on this fork |
| `screenshot.png` | clean_port | Plain PNG, no logic |
| `obsolete.list` | drop | No mechanism on this fork reads/replays a file-diff manifest at all |
| `pem_metadata.txt` | drop | Old SVN/Git-mirror provenance stamp, zero runtime relevance even on legacy Piwigo |
| `manifest.json` `modus_17.0.0` entry | adapt_in_modus_repo | Existing entry's thumbnail confirmed present on disk; carry it over unchanged |
| Zip packaging shape | adapt_in_modus_repo | Must extract to bare `modus/`, not `modus_17.0.0/` |
| 46-locale coverage drift (3 sparse locales) | clean_port | Pre-existing upstream incompleteness; translator's msgid-fallback already handles it safely |

**Unsolved_gap / needs_human_decision items, verbatim, full rationale:**

> **Theme's own `boot()`-time translation loading** (`Lang::load('theme', $themeDir)`) — *unsolved_gap*. `ExtensionContext::lang()` is the only reachable accessor and its own docblock warns `boot()`-time reads may not resolve (`RequestBootstrap::finalize()` loads translations *after* `boot()` in the request pipeline). The purpose-built `LangService::loadLanguageForPlugin()` is hardcoded to `'plugin.po'` (wrong filename for a theme) and has zero production callers anywhere in this repo. No theme or plugin anywhere in this codebase calls `Core\Lang::load()` end-to-end today. **This is the exact same open question a prior, independent theme-port analysis (`bootstrap-darkroom-port-analysis.md` §5.12/§7-Q10) already raised and left unverified for a different theme** — confirming it's a real cross-cutting gap, not theme-specific. *Proposed approach:* `Theme::boot()` calls `$context->lang()->load('theme', $themeDir)`; verify end-to-end against a real running instance (not assumed) that both `boot()`-time `t()` calls and the settings page's later `handleSettingsRequest()` (which also runs after the same `boot()` call) resolve modus's strings correctly. Track the underlying design question as a single cross-theme item, not re-solved per port.

---

## 4. Cross-cutting architectural decisions requiring a human call

Consolidated and deduplicated from all 7 dimensions' `open_questions` arrays, ordered by how foundational/blocking each is to the rest of the port.

### Tier 1 — blocks committing to an implementation design

**A. Per-skin `colorscheme` variance vs. `theme.schema.json`'s static enum.** *(Raised independently, and in agreement, by the gap-audit, manifest/lifecycle, and CSS/skins dimensions.)* Net fact, reconciled across all three: 10 of 18 skins are effectively light ("clear"), 8 effectively dark, selected per-request/per-admin-config — but `theme.json`'s `colorscheme` is a single static fact read once from disk with no runtime override path anywhere in `ThemeChain`. The CSS dimension's framing is the cleanest resolution: **these are two same-named but functionally unrelated concepts** — the schema field's real consumers are narrow, secondary surfaces (`standard_pages`, admin batch-manager dark/light CSS variant), while modus's own 18-way palette is a wholly separate, theme-owned concern.
  - **Option A (recommended):** Hardcode `theme.json`'s `colorscheme` to a static value (`"dark"`, matching the theme-level legacy default) and handle all 18 skins' actual light/dark palette entirely through the CSS-custom-property mechanism, completely decoupled from this schema field. Zero core change; accept the documented, minor cosmetic mismatch where `standard_pages`/batch-manager won't track whichever skin is actually selected.
  - **Option B:** Add a real runtime-override hook to `ThemeChain`/`ExtensionContext` (a new event dispatched after `loadThemeconf()` resolves `colorscheme`) so a theme can compute it per-request from its own setting. Real core change, sets a precedent every future themed-skin port would then expect.
  - **Option C:** Drop per-skin colorscheme variance as a design choice, functionally identical outcome to A but framed as deliberate rather than an accepted regression.

**B. How to replace Smarty's compile-time source-rewriting for `default`-theme fragments modus needs to alter, and how much shared/core surface to touch doing it.** *(Raised by the templates dimension as Option A/B for `picture.latte`/`index.latte`/`layout.latte`, and independently by the CSS dimension as the `Template::resolveLocalHeadOnce()` generalization question — both are the same underlying "invest in shared extensibility now, or fork wholesale" fork.)*
  - **Option A — full-file copy overrides.** Modus ships complete copies of `layout.latte` (322 lines), `picture.latte` (538 lines), `slideshow.latte`, `index.latte` with the real edits baked in. Zero core/`default` changes. Real cost: disproportionate diff-to-payload ratio (a handful of real semantic lines in hundreds of copied ones) and silent staleness as `default` evolves with no compiler check to catch drift.
  - **Option B — refactor `default`'s own templates to expose named `{block}` regions at the exact seams a child theme needs**, then have modus `{layout}`-extend and override only those blocks (`{block pageBanner}`, `{block imageHeaderBar}`, etc.). Modus's own files shrink to a handful of lines each; every block it doesn't override stays byte-identical to `default` forever. Real, ongoing engineering investment in shared theme code, not modus-specific, and sets a structural precedent for the next 2 theme ports (`elegant`, `smartpocket`). Cross-theme `{include}` (admin→default) has a real precedent in this repo; cross-theme `{layout}` block-inheritance chaining does **not** yet and should be prototyped once before committing.
  - **Sub-decision, separately confirmed needed regardless of A/B:** generalize `Template::resolveLocalHeadOnce()` (currently hardcoded to `themes/default/local_head.latte` only, with its own docblock already anticipating this) into a real per-theme-chain loop, so modus can inject its skin-driven `<style>:root{}` block through the typed `localHead` mechanism instead of needing a full `layout.latte` fork just to reach `<head>`. Small, low-risk, already-scoped-out core change; recommended regardless of the A/B answer above.

**C. Is "modus mode" (the `album_thumb_size`-driven custom masonry thumbnail/category layout — `modus_thumbs` Smarty function + `thumb.arrange.js`'s `RVGThumbs` client-side justified-row algorithm) in scope for the first port at all?** *(Raised by the templates dimension as a scope question, load-bearing for the gap-audit's hardest-flagged item, and material to the JS dimension's "genuinely new module" finding.)* This is the single largest chunk of genuinely new, non-trivial work in the whole port — it needs a new PHP-callable-from-template helper shape (no precedent anywhere in this fork) *and* a from-scratch TS port of a real layout algorithm with no existing equivalent.
  - **Option 1 (recommended for a first port):** Ship "standard mode" only (the near-1:1 markup default already renders, plus the recent-icon glyph transform) for v1, and treat "modus mode" as an explicit, separately-scoped follow-up. This alone removes the single riskiest, most novel piece of work from the critical path.
  - **Option 2:** Port both modes together. Requires resolving the `modus_thumbs` registration-mechanism gap (a `$pwg`/`TemplateAdapter`-style helper, or a View-layer data-prep step) *and* the full `RVGThumbs` TS port *and* the `IndexCategoryThumbnailsRendered` crop-math re-verification, all before the feature can ship at parity.

**D. Theme-owned JS/CSS delivery pipeline is functionally real but has zero production exercise.** *(Raised by the JS dimension for `GetPageAssets`/`AssetContribution`/`PageAssets`, and independently by the admin dimension for the Vite `collectScriptEntries()` scan gap — these are two halves of the same underlying "can a theme's own JS actually reach a real page" question.)* Concretely: (1) `GetPageAssets` is wired end-to-end in `Template::dispatchPageAssetsOnce()` but every real reference to it in the repo is a unit test; (2) even if a theme correctly implements it, `collectScriptEntries()` never scans `themes/**/src`, so the referenced `.ts` file would never become a real Vite entry and would never load in production.
  - This port would be the **first real-world exercise** of both halves simultaneously. Recommend: (a) fix `collectScriptEntries()` to scan `themes/**/src` (small, low-risk, core change) as a precondition, not a nice-to-have; (b) accompany the port with real golden-html/VR/integration coverage of a page actually using `GetPageAssets`-registered theme JS, not just PHP-side unit tests, before calling either mechanism validated.

### Tier 2 — real, scoped gaps needing a small `ExtensionContext`/core capability, each independently confirmed absent

**E. `ExtensionContext` capability gaps** (each independently confirmed absent by direct code inspection, not inferred):
  1. No accessor for shared/un-namespaced **core session keys** (`pwg_index_deriv`/`pwg_picture_deriv`) — blocks faithfully porting `get_index_derivative_params`/`render_element_content`'s "respect the visitor's manual choice" logic. *Options:* add a narrow, explicitly-named accessor for these two keys specifically, or accept the HDPI/manual-override logic as a documented behavioral regression.
  2. No **cookie** read/write accessor at all, and no confirmed equivalent for the legacy `cookie_path()` helper — blocks the `caps`/`picture_deriv`/`phavsz` read-once-then-expire capability probes. *Options:* add a narrow cookie accessor mirroring `session()`'s shape, or accept raw unmediated `$_COOKIE`/`setcookie()` PHP as a documented, narrow escape hatch.
  3. No **request-scoped, non-persistent config override** mechanism — blocks the device-responsive `tag_letters_column_number` mutation (target property is `private(set)`, and `setSetting()` persists globally to the DB, which would be actively wrong for a per-device value). *Options:* add such a mechanism, or drop the device-responsive behavior.
  4. No **request/query-parameter** accessor reachable from `boot()` or any early-firing event — blocks the `?skin=` live preview override at the point it needs to run. *Options:* add an accessor/event-payload field, or drop the preview feature (see Tier 3-J).
  5. No accessor for **`ImageStdParams`** — blocks the admin settings page's derivative-size dropdowns. *Options:* a general `imageStdParams(): ImageStdParams` accessor, or a narrower purpose-built read facade matching the `images()`/`users()`/`themes()` pattern (interface-segregation-preferring option).

**F. No cross-module override hook exists for wrapping another module's private function** (`picture.ts`'s `changeImgSrc`), needed for `photo.autosize.js`'s DPR-aware derivative-switching override. *Options:* (a) add a small, deliberate override hook to `picture.ts` itself (a mutable `window` slot in the `SwitchBoxQueue` spirit) — real, if small, change to already-shipped shared code for a not-yet-existing second theme; or (b) modus's own module duplicates the relevant logic independently rather than touching shared code.

**G. `MenubarSpecialsView`'s item shape has no stable, non-translated discriminator** for "this is the Most Visited/Best Rated/Recent link," needed for modus's horizontal-menu hoisting UX. *Options:* (a) add a stable `id`/`kind` field per well-known special link — small, localized `adapt_in_piwigo17_rewrite` change; or (b) drop the hoisting UX and fold all specials into the normal dropdown like every other theme.

**H. i18n loading-timing infrastructure is unverified end-to-end for any real extension.** Independently corroborated by a prior, separate theme-port analysis (`bootstrap-darkroom-port-analysis.md`) raising the identical question. Bundles: (1) does `$context->lang()->load()` called in `boot()` actually make strings resolve later in the same request (traced as plausible, never verified against a real running instance); (2) should `LangService::loadLanguageForPlugin()` be generalized/renamed and actually wired into `PluginRegistry`/`ThemeRegistry`'s boot sequence instead of requiring every author to hand-call `Lang::load()`; (3) does this fork still support a per-install local-language-override mechanism for plugin/theme strings, and if so does `ExtensionContext` need a `Paths`-backed accessor for it. Recommend resolving all three once, as shared infrastructure work, rather than per-theme (two independent theme ports have now hit the same wall).

**I. Config-value migration format for a real 16.x→17.x upgrade.** A real legacy install's `config.value` for `modus_theme` (and every other legacy param) holds a PHP `serialize()`d string; `ConfigService::confGetParam()`/`confUpdateParam()` now do a bare `json_decode()`/`json_encode()` with **no legacy-format fallback**. If there is no separate, already-planned migration converting every legacy-serialized `config.value` row to JSON during upgrade, the first admin who opens modus's (or any ported extension's) settings page post-upgrade would silently see defaults instead of their saved choices — a general, cross-cutting concern affecting every extension's persisted settings, not modus-specific, and should be resolved once at the framework level.

### Tier 3 — minor, scoped, or purely verification-shaped calls (low blast radius; resolve during implementation, not before)

**J.** Should `?skin=`'s live preview override survive the port at all, given it's absent from the admin UI's own preview links and would need a new `ExtensionContext` capability (Tier 2-E.4) to work at all? Leaning "drop" absent other guidance.

**K.** Should `tags.latte`'s 2-item `categoryActions` list also get the mobile action-switcher button, given the legacy ">2" threshold was an accidental byproduct of counting literal `<li>` substrings in compiled Smarty source rather than a deliberate UX rule?

**L.** Should the CSS/skins dimension's assumption — that `month_calendar.latte`'s/`comment_list.latte`'s/`mainpage_categories.latte`'s/`thumbnails.latte`'s existing CSS custom properties fully replace modus's retina/`cssResolution` CSS-injection logic — be confirmed with an actual visual check before those template overrides are dropped from the plan?

**M.** Do `themes/default/icon/errors.png`, `infos.png`, and `rating-stars.gif` (referenced by modus's own CSS via relative `url()`) actually exist at those paths on the already-ported `default` theme? Unverified by the CSS dimension; if absent, those specific rules need either a modus-local replacement asset or dropping.

**N.** Does `hf_base.css` become unconditional in the port (simplification), or should it stay gated to exactly the 11 skins that ship a matching `skins/<id>.css`, matching legacy's real `{if isset($MODUS_CSS_SKIN)}` condition precisely?

**O.** Who dispatches the custom `RVTS_loaded` DOM event `thumb.arrange.js` listens for? Not found among the 5 real `js/*.js` files — likely another modus PHP hook outside the JS dimension's assigned scope; needs confirmation before the ported thumb-arrange module's dispatcher-side counterpart is considered complete.

**P.** Should the queue-drain pattern proposed for `rvas_choose()`/`RVGThumbs` construction (mirroring `SwitchBoxQueue`) become one shared, reusable helper in `vendor/dom.ts`, or should each module hand-roll its own small queue independently, matching how `SwitchBoxQueue`/`RatingAutoQueue` currently do it in `picture.ts`? Purely a code-organization call.

**Q.** Should `hasSettings` be plain `true` (matching legacy's actual, unrestricted admin behavior) rather than a `'webmaster'`-scoped variant the schema apparently also supports? Recommend `true` per confirmed legacy behavior.

**R.** Is a typed value object (`ModusThemeSettings`) worth introducing for the 5-field settings blob, or does a plain associative array suffice? Either is defensible — a code-quality/readability call, not a correctness one.

**S.** Is `'local-modus-17.0.0'` acceptable as the `manifest.json` revision id, and is "don't backfill sparse locale files" (given `ar_SA`/`nn_NO`'s 4-of-14 key coverage) the right policy, or does the project want an explicit minimum-key-coverage bar before a locale is considered port-worthy?

**T.** Should `css/fontello/config.json` be kept for potential future icon-set regeneration, or dropped as unused runtime weight along with the other fontello build-tool artifacts?

---

## 5. Suggested execution order

Ordered so that each step either resolves a Tier-1 blocking decision before work depending on it starts, or delivers a load-bearing skeleton piece the next steps build on. Size/risk is relative to this port's own scope, not absolute.

| # | Step | Depends on resolving | Relative size | Relative risk |
|---|---|---|---|---|
| 1 | **Lifecycle + manifest + config/skins skeleton**: `theme.json`, `Theme.php`'s `install`/`activate`/`deactivate`/`uninstall`/`update` no-ops and real bodies, the 18-skin data-catalog collapse (dynamic `include()` → static PHP array/VOs), `modus_theme` setting persistence (`getSetting`/`setSetting`/`deleteSetting`). | §4-A (colorscheme) must be settled first — it determines whether `theme.json`'s `colorscheme` field and the skin catalog's shape are independent or coupled. | M | Low — every mapping here is `clean_port`/`adapt_in_modus_repo` with a confirmed target; the only real unknown is the colorscheme decision itself, not the mechanics. |
| 2 | **Admin settings page**: `SettingsPageInterface` implementation, CSRF addition, `modus_admin.latte`, skin picker + slider + dropdowns wiring. | §4-E.5 (`ImageStdParams` accessor — small core PR) and §4-D's Vite scan fix (needed before this step's own JS can ship, though the PHP/template side can proceed in parallel). | M | Medium — mechanism (`SettingsPageInterface`) is proven by a test fixture, but this is the *first real* implementation of it; also carries the CSRF-addition and 2 confirmed core gaps as hard dependencies. |
| 3 | **Templates + prefilter-replacement design**: settle §4-B (Option A vs. B), then author `picture.latte`/`slideshow.latte`/`index.latte`/`layout.latte`/`menubar_*.latte`/`mainpage_categories.latte`/`thumbnails.latte`/`picture_content_asize.latte` overrides; resolve §4-G (menubar hoisting) and §4-K (tags.latte threshold) along the way; decide and execute §4-C (modus-mode scope cut, recommended: defer). | §4-B, §4-C, §4-G must be decided before or at the start of this step — they change which files get touched and how many. | **L (largest single step)** | **High** — this is where the theme's defining Smarty-compile-hook gap actually gets paid off; touches shared `default` templates if Option B is chosen; scope-cut decision (§4-C) is the single biggest lever on this step's real size. |
| 4 | **CSS/skins pipeline**: consolidate the 5 `.css.tpl` files + skins into `theme.css` with `var(--modus-*)` custom properties, precompute `cssGradient`/`cssResolution` outputs, fix `url()` relative paths, resolve §4-N (hf_base.css gating), vendor fontello/open-sans, and — if adopted from §4-B — the `resolveLocalHeadOnce()` generalization to carry the skin `<style>:root{}` block through `localHead`. | §4-A (colorscheme, again — determines whether the custom-property model needs to also drive the schema field) and §4-B's `localHead` sub-decision. | M | Medium — the *design* is already fully worked out and precedented (`thumbnails.latte`/`rating_user.latte`'s idiom); the work is mechanical conversion plus the P39-class relative-`url()` risk, which is well-understood but easy to get wrong silently. |
| 5 | **JS → TS port**: `menuh.ts`, `modus-async.ts` (once step 3 lands its native markup), `photo-autosize.ts` (needs §4-F resolved for the `changeImgSrc` half), `thumb-arrange.ts` (only if §4-C's scope decision keeps "modus mode" in v1). | §4-D (asset pipeline fix must land first or JS from this step never reaches a real page), §4-F (`changeImgSrc` hook), §4-C (whether `thumb-arrange.ts` is needed at all this round). | M (S if modus-mode deferred) | Medium — most files are genuinely `clean_port`-quality jQuery→TS work; risk concentrates entirely in `changeImgSrc` (unsolved_gap) and in this being the first real production use of `GetPageAssets`. |
| 6 | **i18n + images + packaging + verification**: convert 46 locale files to `.po`, wire `boot()`-time loading (verify §4-H's timing question against a real instance), copy/prune images and vendor assets, `manifest.json` entry, zip packaging shape (bare `modus/`), then a real end-to-end install-through-the-local-PEM-mirror run (fetch → download → extract → install → activate → boot → real page render) as the closing gate. | §4-H must at minimum be *attempted and observed*, even if the general infrastructure question is deferred — modus's own strings need to actually resolve. | S–M | Low mechanically, but this step is the **actual proof** that everything from steps 1–5 works together on a running instance — treat its "real install" gate as non-negotiable per this repo's own established verification discipline, not a step to compress or skip. |

**Overall risk concentration**: step 3 (templates/prefilter redesign) and the two Tier-1 asset/mechanism questions threaded through steps 2, 4, and 5 (§4-D, first real production use of `GetPageAssets`+Vite-scan) are where a team should expect the real time to go. Steps 1 and 6 are comparatively mechanical and low-risk once their gating decisions are made. The single highest-leverage scope decision in the whole plan is §4-C (defer "modus mode"): taking it now removes the largest, most novel, least-precedented chunk of work from the critical path without touching anything else in the plan.