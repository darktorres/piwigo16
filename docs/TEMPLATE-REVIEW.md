# Smarty Template (.tpl) Deep Review

## Context

Scope: 96 `.tpl` files under `themes/` (admin `_base` ~70, frontend `_base` ~50,
`standard_pages` 7, plus 2 stale `mail-css.tpl` overlays under `themes/admin/{dark,light}`).
Plugin-bundled templates excluded.

The engine wrapper is `src/Piwigo/Template/Template.php`. **Critical anchor:** at
line 79 it sets `$this->smarty->escape_html = false;` — Smarty auto-escape is OFF
for the whole project, so every variable interpolation is the template's
responsibility to escape. Combined with ~135 files, this is the dominant axis of
risk in the codebase.

The roadmap (`docs/ROADMAP-PHP.md` #23) plans a full Smarty → Latte migration in
waves. Findings below are flagged with one of:

- **NOW** — fix in Smarty before/independent of the Latte migration
- **MIGRATE** — best fixed during Latte conversion (escape-by-default solves it)
- **DELETE** — dead code / stale file
- **DESIGN** — affects how the Latte migration should be designed

---

## A. Escaping (security-critical, NOW + MIGRATE)

`escape_html = false` plus inconsistent manual escaping leaves a wide XSS surface.

**Representative offenders:**

| File:Line | Pattern | Risk |
| --- | --- | --- |
| `themes/admin/_base/template/picture_modify.tpl:68` | `value="{$AUTHOR}"` | attribute injection |
| `themes/admin/_base/template/picture_modify.tpl:122` | `<textarea>{$DESCRIPTION}</textarea>` | textarea-context XSS |
| `themes/admin/_base/template/picture_modify.tpl:97-100` | `<span id={$key} ...>` (unquoted attr) | attribute injection |
| `themes/admin/_base/template/configuration_main.tpl:16` | `value="{$main.CONF_GALLERY_TITLE}"` | attribute injection |
| `themes/admin/_base/template/configuration_main.tpl:169` | HTML stuffed into `title=` attribute (tiptip) | attribute parsing |
| `themes/_base/template/header.tpl:15` | `<meta name="keywords" content="...{$tag.name}...">` | attribute injection |
| `themes/_base/template/header.tpl:24` | `<title>{$PAGE_TITLE} | {$GALLERY_TITLE}</title>` | element-context XSS |
| `themes/_base/template/header.tpl:69` | `<script type="application/json">{ "wsUrl":"{$WS_URL}" }</script>` | JSON+JS-string injection |
| `themes/admin/_base/template/header.tpl:44` | same pattern (admin) | same |
| `themes/_base/template/picture.tpl:138, 156, 162, 167-169, 175, 198` | `<dd>{$INFO_*}</dd>` raw | element-context XSS |
| `themes/_base/template/thumbnails.tpl:18` | `alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}"` | attribute injection |

**Fix snippet — element context:**
```smarty
{* before *}
<dd>{$INFO_AUTHOR}</dd>
{* after *}
<dd>{$INFO_AUTHOR|escape:'html'}</dd>
```

**Fix snippet — attribute context:**
```smarty
{* before *}
<input ... value="{$AUTHOR}">
{* after *}
<input ... value="{$AUTHOR|escape:'html'}">
```

**Fix snippet — JSON in `<script>`:** stop hand-building the JSON. Assign a PHP
array server-side, then:
```smarty
{* before *}
<script id="pwg-config" type="application/json">{ "wsUrl":"{$WS_URL}","adminUrl":"{$ADMIN_URL}" }</script>
{* after — server assigns $pwg_config = ['wsUrl'=>..., 'adminUrl'=>...] *}
<script id="pwg-config" type="application/json">{$pwg_config|json_encode}</script>
```
`json_encode` produces JS-safe output and `<script type="application/json">`
content is parsed as text, not HTML — no further escape needed for `<` etc. when
the assigned strings cannot contain `</script>`. (For total safety, pass
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` server-side.)

**Recommendation:**

1. **NOW**: write a one-shot codemod (`tools/template-audit/escape-audit.php`)
   that flags every `{$var}` outside a `<script>`/`<style>` block missing an
   `escape`/`translate`/`json_encode`/`urlencode`/`@escape` modifier. Run it,
   triage by context (URL/attr/element/JS), fix the high-traffic templates first
   (`header.tpl`, `picture.tpl`, `picture_modify.tpl`, `configuration_*.tpl`,
   `batch_manager_global.tpl`).
2. **DESIGN**: when migrating to Latte (#23), enable `Engine::setContentType()`
   per template and rely on context-aware escaping. Audit becomes
   "remove redundant escapes." This is the only durable fix for the systemic
   issue — the Smarty audit is a holding action.

---

## B. `javascript:` URLs and inline event handlers (NOW)

5 occurrences of `href="javascript:..."` and 1 inline `onclick`. CSP-incompatible,
hard to escape correctly when the URI carries a template variable.

| File:Line | Pattern |
| --- | --- |
| `themes/_base/template/picture.tpl:36` | `<a href="javascript:phpWGOpenWindow('{$U_ORIGINAL}', ...)">` |
| `themes/admin/_base/template/batch_manager_global.tpl:222-223, 234-235` | `<a href="javascript:selectGenerateDerivAll()">` etc. (4 places) |
| `themes/admin/_base/template/queue.tpl:59` | `onclick="return confirm('{'"…"|translate}')"` — also malformed (the `{'` opens a Smarty literal that the inner `"…"` doesn't terminate cleanly) |

**Fix snippet — `picture.tpl:36`:** replace with a normal anchor + a click
handler in `themes/_base/js/scripts.js`:
```smarty
{* before *}
<a href="javascript:phpWGOpenWindow('{$U_ORIGINAL}','xxx','scrollbars=yes,...')" rel="nofollow">{'Original'|translate}</a>
{* after *}
<a href="{$U_ORIGINAL|escape:'html'}" data-popup="original" rel="nofollow noopener">{'Original'|translate}</a>
```
Then in `scripts.js`:
```js
document.addEventListener('click', e => {
  const a = e.target.closest('a[data-popup="original"]');
  if (!a) return;
  e.preventDefault();
  window.open(a.href, 'original', 'scrollbars=yes,toolbar=no,status=no,resizable=yes');
});
```

**Fix snippet — `batch_manager_global.tpl`:** the four `javascript:select*()`
calls already have IDs nearby. Drop the `href="javascript:…"`, add
`data-action="generateDerivAll"`, and bind in
`themes/admin/_base/js/batchManagerGlobal.js`.

**Fix snippet — `queue.tpl:59`:** the current source is also broken Smarty.
Replace with a `data-confirm` handler:
```smarty
<a href="{$U_PURGE_FAILED|escape:'html'}" class="icon-trash-1"
   data-confirm="{'Are you sure you want to delete all failed jobs?'|translate|escape:'html'}">
  {'Purge Failed Queue'|translate}
</a>
```
And bind once globally for `[data-confirm]`.

---

## C. Translation-order bug (NOW, easy)

`themes/admin/_base/template/batch_manager_global.tpl:55`:
```smarty
{'Level %d'|@sprintf:$thumbnail.level|@translate}
```
This calls `sprintf('Level %d', 5)` → `"Level 5"`, then translates `"Level 5"` —
a key that doesn't exist in any catalog, so the level indicator stays in
English. Order is reversed.

**Fix:**
```smarty
{'Level %d'|translate|sprintf:$thumbnail.level}
```

(`sprintf` is registered as a normal modifier at `Template.php:114`, so the chain
order matters. The `translate` modifier compiles to `Lang::t(...)` which expects
the *raw* English key.)

---

## D. `|@translate` vs `|translate` consistency (NOW, mechanical)

- 1203 uses of `|@translate` (legacy `@` modifier)
- 656 uses of `|translate` (modern)

The `@` prefix has historically meant "do not auto-escape", but with
`escape_html = false` it is redundant. Same project, same template often, mixed
inconsistently.

**Recommendation (NOW):** drop the `@` everywhere. Single `sed`:
```bash
git grep -lF '|@translate' themes/ | xargs sed -i 's/|@translate/|translate/g'
git grep -lF '|@sprintf'   themes/ | xargs sed -i 's/|@sprintf/|sprintf/g'
git grep -lF '|@escape'    themes/ | xargs sed -i 's/|@escape/|escape/g'
git grep -lF '|@count'     themes/ | xargs sed -i 's/|@count/|count/g'
git grep -lF '|@json_encode' themes/ | xargs sed -i 's/|@json_encode/|json_encode/g'
git grep -lF '|@urlencode' themes/ | xargs sed -i 's/|@urlencode/|urlencode/g'
git grep -lF '|@in_array'  themes/ | xargs sed -i 's/|@in_array/|in_array/g'
git grep -lF '|@get_extent' themes/ | xargs sed -i 's/|@get_extent/|get_extent/g'
git grep -lF '|@nl2br'     themes/ | xargs sed -i 's/|@nl2br/|nl2br/g'
git grep -lF '|@htmlspecialchars' themes/ | xargs sed -i 's/|@htmlspecialchars/|htmlspecialchars/g'
```
Then run the existing PHPUnit + a quick browser pass (gallery + admin home).

---

## E. Plural with `%s` instead of `translate_dec` (NOW)

`themes/admin/_base/template/intro.tpl:112-117` has 6 entries like:
```smarty
<span class="icon-pencil tooltip-detail" title="{"%s editions"|@translate:$number}">{$number}</span>
```
Same string for "1 edition" and "5 editions". The `translate_dec` modifier is
already registered (`Template.php:113`).

**Fix snippet:**
```smarty
{* before *}
title="{'%s editions'|translate:$number}"
{* after *}
title="{$number|translate_dec:'%s edition':'%s editions'}"
```
Apply to lines 112-117 (Edit/Add/Delete/Login/Logout/Move) and any similar place
the audit turns up.

---

## F. Translation strings containing markup (NOW)

`themes/standard_pages/template/password.tpl:134, 151`:
```smarty
{'Return to <a href="identification.php" title="Sign in">Sign in</a>'|translate|replace:'identification.php':$U_IDENTIFICATION}
```
Translators must preserve HTML and a magic placeholder filename. Brittle (every
existing language `.po` has this duplicated, see `language/*/common.po`).

**Fix snippet:** split into discrete fragments + composite:
```smarty
{$msg = 'Return to %s'|translate|sprintf:"<a href=\"`$U_IDENTIFICATION|escape:'html'`\" title=\"`'Sign in'|translate|escape:'html'`\">`'Sign in'|translate|escape:'html'`</a>"}
{$msg nofilter}
```
Or, more readably, use Smarty `{capture}` to assemble the link, then translate a
single `%s` string. Avoid embedding markup in source strings.

---

## G. Dead browser-support code (DELETE)

| File:Line | What | Action |
| --- | --- | --- |
| `themes/_base/template/header.tpl:60-62` | `<!--[if lt IE 7]>` + `pngfix.js` | Delete the 3 lines |
| `themes/_base/local_head.tpl:1-8` | IE5/6/7 `fix-ie*-css` conditionals | Delete file or its IE block |
| `themes/admin/_base/template/install.tpl:5-6` | `<meta http-equiv="Content-script-type">`, `Content-Style-Type` | Delete (HTML5 obsolete) |
| `themes/admin/_base/template/install.tpl:4` | `<meta http-equiv="Content-Type" content="text/html; charset=...">` | Replace with `<meta charset="…">` |
| `themes/admin/_base/template/install.tpl:16-18` | IE7 conditional comment | Delete |
| `themes/admin/_base/template/upgrade.tpl:6-7, 17-19` | same as install.tpl | Delete |
| `themes/_base/template/mail/text/html/header.tpl:1` | XHTML 1.0 Transitional doctype | Replace with `<!DOCTYPE html>` (mail clients accept either, modernize) |

Conditional comments stopped doing anything in IE10 (2012) and don't exist in
any modern browser. The `pngfix.js` and `fix-ie*.css` referenced files may
themselves be unused — grep confirms.

---

## H. Stale mail-css overlays (DELETE)

`themes/admin/dark/mail-css.tpl` and `themes/admin/light/mail-css.tpl`:

- Line 1 of the `light` variant: `{* $Id: mail-css.tpl 2526 2008-09-14 ... vdigital $ *}` — SVN keyword from 2008.
- All references use `${ROOT_URL}template/${themeconf.template}/mail/text/html/images/...` — the path `template/` (singular) does not exist; the actual path is `themes/_base/template/mail/text/html/`. Every image referenced 404s.
- Both files are nearly identical despite living under `dark/` and `light/`.

**Action (DELETE):** verify no caller via
`grep -rF 'admin/dark/mail-css' .` and `admin/light/mail-css`. If unreferenced,
delete both files.

---

## I. Personal-data leak in installer (NOW, this fork)

`themes/admin/_base/template/install.tpl:151`:
```smarty
value="{if $F_ADMIN_EMAIL}{$F_ADMIN_EMAIL}{else}torres.dark@gmail.com{/if}"
```
A hardcoded fallback to your personal email. Per memory this is a personal fork
so it's intentional, but the value will surface in any zip/build artifact.
Suggested replacement: `{else}{/if}` (empty fallback) or `{$DEFAULT_ADMIN_EMAIL}`
assigned by the installer controller.

---

## J. Invalid HTML (NOW, easy)

| File:Line | What | Fix |
| --- | --- | --- |
| `themes/admin/_base/template/batch_manager_global.tpl:127` | `<input ...>...</input>` | Drop `</input>` |
| `themes/admin/_base/template/user_list.tpl:384` | `<input ...>...</input>` (with `<span>` between) | Move the span outside, drop `</input>` |
| `themes/admin/_base/template/picture_modify.tpl:97-101` | `<div>` block inside `<p>` (auto-closes the `<p>`) | Replace outer `<p>` with `<div>` |
| `themes/admin/_base/template/picture_modify.tpl:97` | `id={$key} class="…"` (unquoted attr value) | Quote the attribute |

---

## K. `http://` URLs that should be `https://` (NOW)

`themes/admin/_base/template/photos_add_applications.tpl:15, 93, 105, 121` — four
links to `http://piwigo.org/ext/extension_view.php?eid=...`. Change to `https://`.

---

## L. Smarty `{section}` (MIGRATE)

`themes/_base/template/search.tpl:85, 104` — `{section name=day start=1 loop=32}`.
Smarty 5 still supports it but flags it for removal. Modern equivalent:

```smarty
{* before *}
{section name=day start=1 loop=32}
  <option value="{$smarty.section.day.index}">{$smarty.section.day.index}</option>
{/section}

{* after *}
{for $day=1 to 31}
  <option value="{$day}">{$day}</option>
{/for}
```

---

## M. Dynamic `{include file=$var}` (DESIGN)

Several templates resolve includes from variables:

- `themes/_base/template/index.tpl:178` — `{include file=$FILE_CHRONOLOGY_VIEW}`
- `themes/_base/template/menubar.tpl:6` — `{include file=$block->template|@get_extent:$id}`
- `themes/_base/template/picture.tpl:19` — `{include file='picture_nav_buttons.tpl'|@get_extent:'picture_nav_buttons'}`
- `themes/_base/template/index.tpl:207, 230` — `'navigation_bar.tpl'|@get_extent:'navbar'`

`get_extent()` (`Template.php:319-360`) maps a handle to a realpath if a
plugin/theme registered one. Today this trusts the plugin layer not to register
a path outside the project.

**DESIGN recommendation for #23 (Latte migration):**
- Keep extension paths registered through a typed `TemplateExtensionRegistry`
  (replacement for `setExtents`) that whitelists a directory root and rejects
  `..`/absolute paths.
- Latte's sandbox mode + `Loader\StringLoader`-style restriction lets you
  enforce this at compile time.

---

## N. Inline `<style>` and runtime CSS-in-template (DESIGN)

`themes/_base/template/mail/text/html/header.tpl:7-10`:
```smarty
<style type="text/css">
{if isset($GLOBAL_MAIL_CSS)}{$GLOBAL_MAIL_CSS}{/if}
{if isset($MAIL_CSS)}{$MAIL_CSS}{/if}
</style>
```
Mail HTML must inline styles, so this is correct for the email use case — but
note the styles are themselves rendered from `.tpl` (mail-css.tpl, see §H) so
this is a Smarty-in-Smarty pipeline. When migrating, keep the email-CSS file as
a *static* asset (pre-rendered) rather than a runtime template — there is no
plugin extension point for mail CSS today that justifies the runtime path.

---

## O. Old HTML4 attributes (cosmetic, NOW)

`themes/_base/template/mail/text/html/header.tpl:14, 17`:
```html
<table id="bodyTable" cellspacing="0" cellpadding="10" border="0">
```
`cellspacing`/`cellpadding`/`border="0"` are obsolete in HTML5. For email
clients this is *still acceptable* (Outlook in particular) — leave as-is for
the mail template specifically. Flag here only so it doesn't get carried into
the new admin templates.

---

## P. Indentation / whitespace (LOW, MIGRATE)

Many files mix tabs and spaces (`index.tpl`, `picture.tpl`, `tags.tpl`,
`batch_manager_global.tpl`). The custom `prefilterWhiteSpace`
(`Template.php:1107-1128`) strips leading whitespace around control tags at
compile time, masking the inconsistency. After Latte migration, run Prettier or
similar over `.latte` files; in the meantime, `.editorconfig` already covers
new edits.

---

## Verification

After applying changes from §A-K:

1. `vendor/bin/phpstan analyse --no-progress` — must stay at 0 errors level 10
2. Delete `_data/templates_c/` to force recompile, then load:
   - `/` (gallery home)
   - `index.php?/admin` (intro)
   - `index.php?/admin&page=batch_manager` (level indicator + selection actions)
   - `index.php?/admin&page=picture&image_id=1` and the `/picture` page on the public side
   - `index.php?/admin&page=configuration` (gallery_title roundtrip)
   - `index.php?/admin&page=queue` (purge confirm)
   - `index.php?/install` (no IE comments / obsolete metas in source)
3. Validator: paste a few rendered pages into validator.w3.org/nu/ — `<input>`
   void elements and the `<div>`-in-`<p>` issues will fall out.
4. `npx playwright test` — no regression in the existing E2E suite.

## Suggested execution order

1. §C (one-line bug, instant win), §I (personal email), §J (invalid HTML),
   §G+§H (delete dead code) — low-risk grooming pass.
2. §D (`|@translate` → `|translate` mechanical sweep) — single commit, easy
   review.
3. §E (singular/plural in `intro.tpl`).
4. §K (https://).
5. §B (javascript: + onclick → data-attribute handlers) — needs JS edits.
6. §A (escape audit) — biggest, do as a dedicated mini-project; consider
   deferring **only** the parts that the Latte migration in #23 will erase.
7. §F (markup-in-translation strings) — touches `.po` catalogs; coordinate.
8. §L, §M, §N — fold into the Latte migration design (#23).

## Critical files referenced

- `src/Piwigo/Template/Template.php:79` — auto-escape OFF
- `src/Piwigo/Template/Template.php:113-114` — `translate`/`translate_dec`/`sprintf` modifiers
- `src/Piwigo/Template/Template.php:1107-1128` — whitespace prefilter
- `docs/ROADMAP-PHP.md:1842` (#23) — Smarty → Latte migration plan; the durable
  fix for §A and §M lives there
