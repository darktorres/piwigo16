# Latte conversion audit — §1.2 Wave 2

133 template pairs (`.tpl` Smarty source ↔ `.latte` converter output). Each
pair gets a manual diff to confirm the conversion is faithful and the
runtime payload reaches Latte intact. Items are reviewed _without
skimming_ — read the full Smarty source, read the full Latte source,
flag any rewrite that is wrong, missing, or unsafe.

## What "review" means here

For each pair, walk the `.latte` against the `.tpl` and confirm:

1. **Iteration / control flow** — every `{foreach}`, `{section}`,
   `{if}/{elseif}` rewritten correctly, including loop iterator
   references (`$smarty.foreach.X.iteration` → `$iterator`).
2. **Variable shape** — Smarty dot-access (`$x.y`) → bracket access
   (`$x['y']`); object access (`$x->y`) preserved; foreach-local vars
   visible inside `{include}` (Latte does NOT inherit parent scope —
   needs explicit pass).
3. **Filters & functions** — pipe filter chains (`|translate`,
   `|sprintf:a:b`), custom functions (`combineCss`, `htmlOptions`,
   `phpwgUrl`, `getExtent`), escape semantics (Smarty
   `escape_html=false` + Latte auto-escape; raw HTML must be marked
   `|noescape`).
4. **Tag-language args** — `{include 'foo' a: $b}` named args; no
   `name, $value` shape (converter bug we hit on multi-arg pipe
   filters chewing through include named args).
5. **`$smarty.*` residues** — `$smarty.now`, `$smarty.server.*`,
   `$smarty.cookies.*`, `$smarty.capture.*`, `$smarty.foreach.*`
   should all be rewritten by the converter; flag any leftover.
6. **Hand-fix preservation** — `|noescape` annotations and
   `range(1,32)`-style hand-corrections in already-converted files
   must survive (regen would clobber them; the audit is the safety
   net).

Flag anything ambiguous as a converter rule gap, not a "fix-it-here"
patch. The goal is to refine the converter so a clean re-run from
the `.tpl` source would produce the same `.latte` we have.

## Status key

- `[ ]` — not yet reviewed
- `[~]` — reviewed, needs work (write the gap on the line)
- `[x]` — reviewed, conversion looks faithful

---

## themes/\_base (1)

- [x] `local_head.tpl` ↔ `local_head.latte` — 3-line file. `{combine_css path=… order=-10}` → `{do combineCss(path: …, order: -10)}` ✓ ({do} discards return value of side-effect function). `{if $load_css}` identical.

## themes/\_base/template (36)

- [x] `about.tpl` ↔ `about.latte` — `=` prefix on literal-filter prints ✓. `not empty` → `!empty` ✓. `{foreach from=$x item=y}` → `{foreach $x as $y}` ✓. `{include file='…tpl'}` → `{include '…latte'}` ✓. `ABOUT_MESSAGE`/`THEME_ABOUT` Html-wrapped in `AboutController.php:45,50`. `$about_msgs` has no current producer (plugin extension point).
- [x] `comment_list.tpl` ↔ `comment_list.latte` — full read both. Display `CONTENT` Html-wrapped at `PictureCommentRenderer:184` + `CommentsController:353`; edit-mode plain string (textarea body auto-escapes). `$smarty.foreach.comment_loop.index is odd` → `(($iterator->getCounter() - 1)) % 2 != 0` (verified equivalent: 0-based even/odd ↔ 1-based after shift). `|escape` on textarea content dropped (Latte body auto-escapes html).
- [x] `comments.tpl` ↔ `comments.latte` — `MENUBAR`/`COMMENT_LIST` auto-Html via `assignVarFromHandle` (verified `CommentsController:395`). `{html_options}` → `{=htmlOptions(…)|noescape}` ×5 (HTML payload). `'x.tpl'|get_extent:'navbar'` → `getExtent('x.latte', 'navbar')` ✓.
- [~] `footer.tpl` ↔ `footer.latte` — `|escape:url` → `|urlencode` ✓. `getCombinedScripts` returns `Html` (`PiwigoExtension.php:370`). `QUERIES_LIST` Html-wrapped (`PageTailRenderer:58`). Hand-fix `{$elt|noescape}` on `$footer_elements` foreach (plugin debug HTML); regen would clobber — converter gap.
- [x] `header.tpl` ↔ `header.latte` — `|strip_tags:false` ↔ `PiwigoExtension::stripTags` wrapper. `|replace:'"':' '` → `|replace:'"',' '`. `name=tag_loop`+`$smarty.foreach.X.first` → `$iterator->isFirst()`. Backtick interp → string concat. `{include $theme['local_head'], theme: $theme, load_css: …}` scope-pass. `getCombinedCss/Scripts` return Html. `PAGE_BANNER`/`head_elements`/`header_msgs` Html-wrapped at producers. JSON script `|noescape` from converter pass. `{strip}` → `{spaceless}`.
- [x] `identification.tpl` ↔ `identification.latte` — straight conversion. `MENUBAR` auto-Html. `=`-prefix on translates. `|urlencode` preserved. No foreach, no html_options, no `$smarty.*` residue.
- [~] `index.tpl` ↔ `index.latte` — full read both. `THUMBNAILS`/`CATEGORIES`/`SELECTED_TAGS_TEMPLATE` auto-Html via `assignVarFromHandle` (verified). `TITLE`/`chronology[TITLE]`/`CONTENT_DESCRIPTION`/`category_search_results[]`/`no_search_results[]` Html-wrapped at producers. Hand-fix `|noescape` on plugin HTML payloads: `PLUGIN_INDEX_CONTENT_BEFORE/BEGIN/END/AFTER`, `PLUGIN_INDEX_ACTIONS`, `$button` (PLUGIN_INDEX_BUTTONS), `CONTENT` (no core producer; plugin-supplied). `{if isset($SELECTED_TAGS_TEMPLATE)}` guard added (Latte undefined-var strictness). `name=loop` + `$smarty.foreach.X.first` → `$iterator->isFirst()` ×3. `{else if}` → `{elseif}`. `{include …'x.tpl'|get_extent:'navbar' navbar=$cats_navbar}` → `{include getExtent('x.latte', 'navbar'), navbar: $cats_navbar}`.
- [x] `infos_errors.tpl` ↔ `infos_errors.latte` — foreach rewrite + `not empty` → `!empty`. `$errors`/`$infos` plain Lang::t text (auto-escape correct).
- [x] `mainpage_categories.tpl` ↔ `mainpage_categories.latte` — `CAPTION_NB_IMAGES`/`DESCRIPTION` Html-wrapped at `CategoryCatsRenderer:262,269`. `NAME` plain. `|replace:'a':'b'` → `|replace:'a','b'`. `|strip_tags:false` via `PiwigoExtension::stripTags` wrapper. `index is odd` rewritten. `{assign var}` → `{var}`. `{combine_*}` → `{do …}`. `not empty` → `!empty`.
- [~] `menubar.tpl` ↔ `menubar.latte` — `{foreach from=$blocks key=id item=block}` → `{foreach $blocks as $id => $block}`. `{include file=$block->template|get_extent:$id}` → `{include getExtent($block->template, $id), block: $block, id: $id}` (explicit scope-pass for child). Hand-fix `{$block->raw_content|noescape}` (plugin raw HTML when block has no template).
- [x] `menubar_categories.tpl` ↔ `menubar_categories.latte` — `{assign}` → `{var}`, `$block->data.X` → `$block->data['X']`. `|str_repeat:N` on HTML literal `'</ul></li>'` `|noescape` from converter pass `addNoescapeToHtmlLiteralRepeats` ×2. `|translate_dec:'a':'b'` → `|translate_dec:'a','b'`.
- [x] `menubar_identification.tpl` ↔ `menubar_identification.latte` — `{strip}`/`{/strip}` → `{spaceless}`/`{/spaceless}`. `$smarty.server.REQUEST_URI` → `($_SERVER['REQUEST_URI'] ?? '')` (null-safe). `=`-prefix on translates.
- [x] `menubar_links.tpl` ↔ `menubar_links.latte` — `|escape:'html'` dropped (Latte attribute auto-escape). `{strip}`→`{spaceless}`. foreach rewrite. dot→bracket. Faithful.
- [~] `menubar_menu.latte` ↔ `menubar_menu.tpl` — `{$link['REL']|noescape}` hand-fix in tag context (REL is `'rel="nofollow"'` attribute fragment from MenubarRenderer; Latte escapes `=`/`"` even on Html objects). `|escape:'html'` dropped (Latte auto-escape attribute). `{strip}`→`{spaceless}`. dot→bracket.
- [x] `menubar_related_categories.tpl` ↔ `menubar_related_categories.latte` — same pattern as menubar_categories: `{assign}`→`{var}`, foreach rewrite, `|str_repeat` HTML literal `|noescape` from converter pass ×2, `|translate_dec` colon→comma args.
- [~] `menubar_specials.tpl` ↔ `menubar_specials.latte` — same REL hand-fix as `menubar_menu`: `{$link['REL']|noescape}` (HTML attribute fragment in tag context). `{strip}`→`{spaceless}`. dot→bracket.
- [x] `menubar_tags.tpl` ↔ `menubar_tags.latte` — straight conversion. foreach + dot→bracket + `{strip}`→`{spaceless}` + `|translate_dec` colon→comma args.
- [x] `month_calendar.tpl` ↔ `month_calendar.latte` — mechanical: foreach + dot→bracket + `{combine_css}`→`{do combineCss}` + `|translate_dec` colon→comma. All vars plain text labels.
- [x] `navigation_bar.tpl` ↔ `navigation_bar.latte` — `{foreach key=p item=u}` → `{foreach $x as $p => $u}`, `{assign}`→`{var}`, dot→bracket. Faithful.
- [x] `nbm.tpl` ↔ `nbm.latte` — trivial: translate `=`, include extension. `MENUBAR` auto-Html.
- [x] `no_photo_yet.tpl` ↔ `no_photo_yet.latte` — standalone HTML page (no menubar/footer). Only translate `=`-prefix. `$intro` plain l10n text.
- [x] `notification.tpl` ↔ `notification.latte` — `{html_head}…{/html_head}` Smarty block → `{capture $_pwgHead1}…{/capture}{do htmlHead($_pwgHead1)}` ✓ (capture-and-pass rewrite).
- [x] `password.tpl` ↔ `password.latte` — `ne`→`!=`, `eq`→`==`. translate `=`-prefix. `MENUBAR` auto-Html.
- [~] `picture.tpl` ↔ `picture.latte` — full read both. Mechanical: `{combine_*}`→`{do …}`, `not empty`→`!empty`, dot→bracket, `ne`→`!=`, `|escape`/`|escape:'html'` dropped (attribute auto-escape), `{strip}`→`{spaceless}`, `name=tag_loop`+`first` → `$iterator->isFirst()`, `{foreach key=k item=v}` → `{foreach as $k=>$v}`, `|translate_dec` colon→comma, `'x.tpl'|get_extent:'p'` → `getExtent('x.latte','p')`. Producer-side Html wraps for `SECTION_TITLE`, `COMMENT_IMG`, `INFO_CREATION_DATE`, `INFO_POSTED_DATE`, `ELEMENT_CONTENT`, `related_categories[]` (PictureController). Hand-fix `|noescape`: `PLUGIN_PICTURE_BEFORE/AFTER/ACTIONS`, `$button` (PLUGIN_PICTURE_BUTTONS).
- [x] `picture_content.tpl` ↔ `picture_content.latte` — mechanical: dot→bracket, `{combine_script}`→`{do …}`, `|replace:'a':'b'`→`|replace:'a','b'`, `|strip_tags:false` via `stripTags` wrapper, `{strip}`→`{spaceless}`, `{assign}`→`{var}`, foreach key/item rewrite, `=`-prefix on printed expressions (`{=($size[0]/4)|intval}`).
- [x] `picture_nav_buttons.tpl` ↔ `picture_nav_buttons.latte` — all vars plain text labels/URLs. dot→bracket, translate `=`, `{strip}`→`{spaceless}` (rewrites also applied inside Smarty `{*…*}` comments — harmless).
- [x] `popuphelp.tpl` ↔ `popuphelp.latte` — `HELP_CONTENT` Html-wrapped at PopuphelpController. translate `=`, `{combine_script}`→`{do combineScript}`.
- [x] `profile.tpl` ↔ `profile.latte` — `PROFILE_CONTENT` auto-Html via `assignVarFromHandle`. translate `=`. trivial.
- [x] `profile_content.tpl` ↔ `profile_content.latte` — `name=theme` Smarty bareword → `name: 'theme'` quoted in Latte (`Converter::normalizeArgValue`). `{html_options}`/`{html_radios}` → `{=htmlOptions(…)/htmlRadios(…)|noescape}` ×5. `not`→`!`. `{include file=$plugin_block.template}` → `{include $plugin_block['template'], plugin_block: $plugin_block}` (scope-pass).
- [x] `redirect.tpl` ↔ `redirect.latte` — `REDIRECT_MSG` Html-wrapped at producer (`Util.php:152`); template prints bare. `combine_css`→`do combineCss`, dot→bracket.
- [x] `register.tpl` ↔ `register.latte` — `not`→`!`, translate `=`. trivial form template.
- [x] `search.tpl` ↔ `search.latte` — `{section name=day start=1 loop=32}` → `{foreach range(1, 32) as $day}` ×2 (verified: start=1,loop=32 → indices 1-32 = `range(1,32)`). `$smarty.section.day.index` → `$day`. `$smarty.now|date_format` → `time()|date_format` ×6 (in onclick handlers; `=` prefix added). `|strip_tags:false|escape:html` → `|strip_tags:false`. `{html_options}` → `{=htmlOptions(…)|noescape}` ×3. `|translate_dec` colon→comma.
- [x] `search_rules.tpl` ↔ `search_rules.latte` — mechanical: foreach rewrites, translate `=`. All vars plain text.
- [x] `slideshow.tpl` ↔ `slideshow.latte` — `ELEMENT_CONTENT`/`COMMENT_IMG` wrapped Html at PictureController. `'…tpl'|get_extent:` → `getExtent('…latte', …)`. dot→bracket.
- [x] `tags.tpl` ↔ `tags.latte` — mechanical: foreach, dot→bracket, `|translate_dec` colon→comma, translate `=`.
- [x] `thumbnails.tpl` ↔ `thumbnails.latte` — `{assign}`→`{var}`, foreach, dot→bracket, `{combine_*}`→`{do …}`, `|translate_dec` colon→comma, `{strip}`→`{spaceless}`. All thumbnail vars plain text.

## themes/\_base/template/include (5)

- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte` — comment-only file (CSS does the work).
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte` — `{combine_*}`→`{do …}`. trivial.
- [x] `related_tags.inc.tpl` ↔ `related_tags.inc.latte` — foreach + dot→bracket + `{strip}`→`{spaceless}` + `|translate_dec` colon→comma.
- [x] `search_filters.inc.tpl` ↔ `search_filters.inc.latte` — `<script type="application/json">{$page_data_json|noescape}</script>` from converter pass. dot→bracket inside string interpolation `"…/{$themeconf['colorscheme']}-…"`.
- [x] `selected_tags.inc.tpl` ↔ `selected_tags.inc.latte` — foreach + dot→bracket + translate `=`. trivial.

## themes/\_base/template/help (1)

- [x] `quick_search.tpl` ↔ `quick_search.latte` — pure static help content. translate `=`, `{combine_css}`→`{do combineCss}`. `$is_dark_mode` boolean only. No foreach, no HTML-payload var.

## themes/\_base/template/mail/text/html (8)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — `{$CPL_CONTENT|noescape}` (admin-input HTML mail content; HTML format renders raw). dot→bracket.
- [x] `footer.tpl` ↔ `footer.latte` — `|escape:url` → `|urlencode`. `not empty`→`!empty`. translate `=`.
- [x] `global-mail-css.tpl` ↔ `global-mail-css.latte` — identical (CSS only).
- [x] `header.tpl` ↔ `header.latte` — dot→bracket; `not empty`→`!empty`; `MAIL_TITLE`/`MAIL_SUBTITLE` plain text. `GLOBAL_MAIL_CSS`/`MAIL_CSS` Html via `assignVarFromHandle` (`MailService.php:563,569`).
- [x] `mail-css-dark.tpl` ↔ `mail-css-dark.latte` — identical (CSS only).
- [x] `mail-css-light.tpl` ↔ `mail-css-light.latte` — identical (CSS only).
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — `{$CONTENT|noescape}` (HTML mail body, `MailService.php:614`). translate `=`, dot→bracket on `$TECHNICAL[…]`.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — translate `=`, foreach + dot→bracket + `not empty`→`!empty`. `|noescape` on `{$line}` (line 27, `$global_new_lines` HTML), `{$custom_mail_content}` (line 33), `{$recent_post['HTML_DATA']}` (line 53, raw `<ul>…</ul>`).

## themes/\_base/template/mail/text/plain (5)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — plain text mail. translate `=`. `$CPL_CONTENT` plain text in plain-text context (auto-escape neutral when no `<>&`).
- [x] `footer.tpl` ↔ `footer.latte` — `{literal}…{/literal}` → `{syntax off}…{syntax on}` ✓. `not empty`→`!empty`. translate `=`.
- [x] `header.tpl` ↔ `header.latte` — `{literal}…{/literal}` → `{syntax off}…{syntax on}` ✓. `not empty`→`!empty`.
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — translate `=`, dot→bracket on `$TECHNICAL[…]`. Plain text context.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — foreach rewrite + dot→bracket + `not empty`→`!empty` + translate `=`. Plain-text context.

## themes/standard_pages/template (7)

- [x] `footer.tpl` ↔ `footer.latte` — `{get_combined_scripts load='footer'}` → `{=getCombinedScripts(load: 'footer')}` (returns `Html`).
- [x] `header.tpl` ↔ `header.latte` — `{strip}`→`{spaceless}`, foreach themes, backtick interp → string concat, `{include $theme['local_head'], theme: $theme, load_css: …}` scope-pass. `not empty`→`!empty`. `getCombinedCss/Scripts` return Html.
- [x] `identification.tpl` ↔ `identification.latte` — JSON script `|noescape`. `{else if}`→`{elseif}`. Escaped-apostrophe `'Don\'t have…'|translate` `=`-prefixed by `rewritePrintedLiteralFilter`.
- [x] `password.tpl` ↔ `password.latte` — `eq`→`==`. JSON script `|noescape`. HTML translate strings `|noescape` from converter pass `addNoescapeToHtmlBearingTranslations` (×3). Escaped-apostrophe `=`-prefixed by converter (×2). `|replace:'a':'b'`→`|replace:'a','b'`. `{assign}`→`{var}`.
- [x] `profile.tpl` ↔ `profile.latte` — JSON scripts `|noescape`. Bareword args quoted ×3. `API_EMAIL_INFOS` Html-wrapped at producer. `|escape:html` dropped on `<p>` body. `{include file=$plugin_block.template}` → `{include $plugin_block['template'], plugin_block: …, k_block: …}` scope-pass. `not`→`!`. `{else if}`→`{elseif}`.
- [x] `register.tpl` ↔ `register.latte` — `not`→`!`. JSON script `|noescape` ✓. `{else if}`→`{elseif}`. translate `=`. Plain text only.
- [x] `toaster.tpl` ↔ `toaster.latte` — `{combine_*}`→`{do …}`. Trivial.

## themes/admin/\_base/template (64)

- [x] `admin.tpl` ↔ `admin.latte` — Sidebar nav. `$ADMIN_PAGE_TITLE` Html-wrapped at producer. `$TABSHEET`, `$ADMIN_CONTENT` auto-Html via `assignVarFromHandle`. Smarty `{if {$U_SHOW_TEMPLATE_TAB}}` (nested print-eval) → `{if $U_SHOW_TEMPLATE_TAB}`. `{$error}`/`{$info}`/`{$warning}`/`{$message}` bare prints — `PageState` accepts `string|Html` and HTML callers wrap `new Html(...)` (PasswordService, MiscController, AlbumController, ExtensionsController, MaintenanceController, BatchManagerController, ConfigurationController, UsersController).
- [x] `album_notification.tpl` ↔ `album_notification.latte` — `{combine_*}`→`{do …}`. `{html_options}`→`{=htmlOptions(…)|noescape}` ×2. `{$MAIL_CONTENT}` textarea body input. `{$save_success}` plain Lang::t.
- [x] `albums.tpl` ↔ `albums.latte` — JSON `|noescape`. `{combine_*}`→`{do …}`. Nested print-eval `{if "first" == {$POS_PREF}}` → `{if "first" == $POS_PREF}` ×2. Escaped-apostrophe `'Supprimer l\'album …'|translate` `=`-prefixed by converter.
- [~] `batch_manager_global.tpl` ↔ `batch_manager_global.latte` — `{include … title={'X'|translate}}` (Smarty `{…}` expr arg) → `{include …, title: ('X'|translate)}`. `{assign var=isSelected value=$x|in_array:$y}` → `{var $isSelected = ($x|in_array:$y)}`. `$thumbnail['TITLE']` Html-wrapped at producer. `|escape:'html'` dropped on attribute. `{html_options}`→`{=htmlOptions(…)|noescape}`. `{$action['CONTENT']|noescape}` hand-fix (plugin-supplied HTML payload, line 242 — no converter rule). foreach key/item rewrite ×2.
- [~] `batch_manager_unit.tpl` ↔ `batch_manager_unit.latte` — JSON `|noescape` ×2. `{html_options}`→`{=htmlOptions(…)|noescape}`. `|escape:html` dropped on attribute. `|count` filter registered; converter mixes `count(…)` and `|count` forms (cosmetic). Multi-line `{if count(…) \n< 1}` tag (Latte 3 accepts). `{include $PATH, element: $element, PATH: $PATH}` scope-pass for plugin sub-templates (no converter rule for dynamic include scope-pass — regen would clobber). `url_is_remote()` registered function preserved. Escaped-apostrophe `=`-prefixed by converter.
- [x] `cat_list.tpl` ↔ `cat_list.latte` — `$smarty.cookies.X` → `$_COOKIE['X']`. `{assign var=color_tab value=[…]}` → `{var $color_tab = […]}` (array literal). `$CATEGORIES_NAV` Html-wrapped at producer. foreach + dot→bracket + translate `=` + `|translate_dec` colon→comma.
- [x] `cat_modify.tpl` ↔ `cat_modify.latte` — `$CATEGORIES_NAV`, `$CATEGORIES_PARENT_NAV` Html-wrapped at AlbumController. Line 73 `{'Directory'}` standalone string-literal (no filter; Latte 3 evaluates as expression). JSON `|noescape`. dot→bracket throughout. `$CAT_NAME`/`$CAT_COMMENT` plain admin input.
- [x] `cat_options.tpl` ↔ `cat_options.latte` — trivial. `$DOUBLE_SELECT` auto-Html via `assignVarFromHandle`.
- [x] `cat_perm.tpl` ↔ `cat_perm.latte` — JSON `|noescape` ✓. `|json_encode|escape:html` → `|json_encode` (attribute auto-escape). `not`→`!`. dot→bracket. `$save_success` plain Lang::t.
- [x] `check_integrity.tpl` ↔ `check_integrity.latte` — `index is odd` rewrite. dot→bracket. Line 41 `{$c13y['c13y']['correction_error_fct']}` faithfully preserves a Smarty-source typo of double `.c13y.c13y.` (one to fix in source, not in conversion). `correction_msg`/`correction_error_fct` Html-wrapped at producer. `|nl2br` Latte std returns Html. `|json_encode|escape:html` → `|json_encode`. Escaped-apostrophe `=`-prefixed by converter.
- [x] `comments.tpl` ↔ `comments.latte` — JS-driven UI, translate `=` + JSON `|noescape`. `{combine_*}`→`{do …}`. No foreach, no HTML payload.
- [x] `configuration_comments.tpl` ↔ `configuration_comments.latte` — checkbox config. dot→bracket, `{html_options}`→`{=htmlOptions(…)|noescape}`. `$save_success` plain.
- [x] `configuration_default.tpl` ↔ `configuration_default.latte` — `{html_radios}`→`{=htmlRadios(…)|noescape}` ×3.
- [x] `configuration_display.tpl` ↔ `configuration_display.latte` — checkbox UI with `('X'|translate|ucfirst)` paren-wrapped translate-arg expressions. `{'administrators'}` standalone string literal ×4 (Latte 3 evaluates as expression).
- [x] `configuration_main.tpl` ↔ `configuration_main.latte` — JSON `|noescape`. dot→bracket, foreach key/item, `eq/ne`→`==/!=`. `{html_options}`→`{=htmlOptions(…)|noescape}` ×3. `$main['CONF_PAGE_BANNER']` in textarea (plain context). Tooltip title with literal `<br><img>` HTML in attribute (template literal — passes verbatim). Escaped-apostrophe `=`-prefixed by converter.
- [x] `configuration_search.tpl` ↔ `configuration_search.latte` — JSON `|noescape`. `$x.filters_views.$filter_name.access` → `$x['filters_views'][$filter_name]['access']`. `{else if}`→`{elseif}`. `ucfirst(str_replace(…))` function call preserved. Escaped-apostrophe `=`-prefixed by converter.
- [x] `configuration_sizes.tpl` ↔ `configuration_sizes.latte` — JSON `|noescape`. dot→bracket including var keys (`$ferrors.$type` → `$ferrors[$type]`). foreach key/item rewrite.
- [x] `configuration_watermark.tpl` ↔ `configuration_watermark.latte` — JSON `|noescape`. `eq`→`==`. `{html_options}`→`{=htmlOptions(…)|noescape}`. `|htmlspecialchars` preserved on attribute display.
- [x] `double_select.tpl` ↔ `double_select.latte` — `{html_options}`→`{=htmlOptions(…)|noescape}` ×2. Trivial.
- [x] `element_set_ranks.tpl` ↔ `element_set_ranks.latte` — `|replace:'"':' '` → `|replace:'"',' '`. dot→bracket, foreach. `{html_options}`→`{=htmlOptions(…)|noescape}`. `$thumbnail['NAME']` plain (filename).
- [x] `extend_for_templates.tpl` ↔ `extend_for_templates.latte` — `index is odd` rewrite. `{html_options}` → `{=htmlOptions(name: 'X[]', output: …, values: …, selected: …)|noescape}` ×3 (with multi-arg).
- [~] `footer.tpl` ↔ `footer.latte` — admin footer. `|escape:url` → `|urlencode`. `getCombinedScripts` returns Html. `{$debug['QUERIES_LIST']}` Html-wrapped at producer. Hand-fix `{$elt|noescape}` on `$footer_elements` foreach (admin maintenance/search controllers push HTML comment payloads — no converter rule). Escaped-apostrophe `=`-prefixed by converter. Numeric keys: `$WHATS_NEW_IMGS.1` → `$WHATS_NEW_IMGS['1']` (×3+1 commented; PHP int↔string array keys interop).
- [x] `group_list.tpl` ↔ `group_list.latte` — `{function name=groupContent}…{/function}` Smarty inline function → `{define groupContent}…{/define}`. `{groupContent ...args}` callsite → `{include groupContent, ...args}`. JSON `|noescape` ×2. Translate args with HTML literals `|noescape` from converter pass (lines 107, 177). `not empty`→`!empty`. Per-arg passes on `{include groupContent, ...}` for Latte fragment.
- [x] `group_perm.tpl` ↔ `group_perm.latte` — trivial. `$DOUBLE_SELECT` auto-Html. `$TITLE` plain. translate `=` only.
- [x] `header.tpl` ↔ `header.latte` — admin header. Same pattern as public: `{strip}`→`{spaceless}`, dot→bracket, `not empty`→`!empty`, foreach, backtick interp → string concat, `{include $theme['local_head'], theme: $theme, …}` scope-pass, JSON `|noescape`, `eq`→`==`, `{assign}`→`{var}`. `getCombinedCss/Scripts` return Html.
- [x] `help.tpl` ↔ `help.latte` — trivial. `not`→`!`. `$HELP_CONTENT` Html-wrapped at producer (MiscController). `$HELP_SECTION_TITLE` plain.
- [x] `history.tpl` ↔ `history.latte` — JSON `|noescape`, dot→bracket, `'x.tpl'|get_extent:'p'` → `getExtent('x.latte', 'p')` ×2. JS-driven UI.
- [x] `install.tpl` ↔ `install.latte` — `$L_INSTALL_HELP` Html-wrapped at producer. `$EMAIL` Html-wrapped (admin email htmlspecialchars-escaped before splice). `'Subscribe %s …'|translate:$EMAIL|noescape` from converter pass `addNoescapeToHtmlBearingTranslations`. Escaped-apostrophe `=`-prefixed by converter.
- [x] `intro.tpl` ↔ `intro.latte` — JSON `|noescape`, foreach key/item rewrites ×many, `|number_format`, `|translate_dec` colon→comma, `{else if}`→`{elseif}`, `{assign}`→`{var}`. Stats from `GeneralEndpoints` (translate_dec plurals).
- [x] `languages_installed.tpl` ↔ `languages_installed.latte` — JSON `|noescape`. dot→bracket, nested foreach. Plain text vars (language metadata).
- [x] `languages_new.tpl` ↔ `languages_new.latte` — `index is odd` rewrite. `|htmlspecialchars|nl2br` chain preserved on cluetip title attribute.
- [x] `maintenance_actions.tpl` ↔ `maintenance_actions.latte` — JSON `|noescape`. dot→bracket, foreach key/item. `|translate:{round(...)}` (Smarty nested print-eval as filter arg) → `|translate:round(...)` (Latte function-call expression).
- [x] `maintenance_env.tpl` ↔ `maintenance_env.latte` — JSON `|noescape`. Smarty `{$CONTAINER_INFO} neq 'none'` → `$CONTAINER_INFO != 'none'` (nested `{$X}` in if-condition unwrapped). `|translate:$X:$Y` → `|translate:$X,$Y` (multi-arg).
- [x] `maintenance_sys.tpl` ↔ `maintenance_sys.latte` — Static activity table. translate `=` only.
- [x] `menubar.tpl` ↔ `menubar.latte` — admin menu block ordering. `$block->reg->getName()` chained method preserved via `$block['reg']->getName()`. `{math equation="abs(pos)" pos=$block.pos}` Smarty fn → `{=math(equation: "abs(pos)", pos: $block['pos'])}` named-args.
- [x] `navigation_bar.tpl` ↔ `navigation_bar.latte` — `{assign}`→`{var}`, foreach key/item, dot→bracket. Plain pagination markup.
- [~] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — `$DOUBLE_SELECT` auto-Html. `index is odd` rewrite. dot→bracket. Line 91 `{$u['CHECKED']|noescape}` hand-fix in tag context (HTML attribute fragment `'checked="checked"'`; no converter rule — regen would clobber).
- [x] `permalinks.tpl` ↔ `permalinks.latte` — `$SORT_*`/`$SORT_OLD_*` Html-wrapped at producer (`MiscController::parseSortVariables` builds `<a href>…<em>↓</em></a>`). JSON `|noescape`. `index is odd` rewrite. `{html_options}`→`{=htmlOptions(…)|noescape}`.
- [x] `photos_add_applications.tpl` ↔ `photos_add_applications.latte` — `'<em>Piwigo for iOS/Android</em>…'|translate|noescape` from converter pass `addNoescapeToHtmlBearingTranslations` ×2.
- [x] `photos_add_direct.tpl` ↔ `photos_add_direct.latte` — `$ADD_TO_ALBUM`/`$selected_category_name` Html-wrapped at producer. JSON `|noescape`. `{$can_upload=…}` short-form assign → `{var $can_upload = …}`. `|escape:javascript` dropped (Latte body auto-escapes). `|translate:$X:$Y` → `|translate:$X,$Y`. `noscript` block added around HTML5 fallback. Escaped-apostrophe `=`-prefixed by converter.
- [x] `photos_add_ftp.tpl` ↔ `photos_add_ftp.latte` — `$FTP_HELP_CONTENT` Html-wrapped at `PhotoController:719`.
- [x] `picture_coi.tpl` ↔ `picture_coi.latte` — COI form, plain markup. foreach + dot→bracket.
- [x] `picture_formats.tpl` ↔ `picture_formats.latte` — JSON `|noescape`. foreach + double-quoted bracket access (`$format["format_id"]`). `$FORMATS` data array.
- [x] `picture_modify.tpl` ↔ `picture_modify.latte` — JSON `|noescape`. dot→bracket. `|count` mixed with `count()`. `|escape` dropped on `$NAME`. foreach key/item ×2. `|json_encode|escape:html` → `|json_encode` ×2. `{html_options}`→`{=htmlOptions(…)|noescape}`. `INTRO['size']`, `related_categories[*]['name']`, `STORAGE_CATEGORY` Html-wrapped at producer. Escaped-apostrophe `=`-prefixed by converter.
- [~] `plugins_installed.tpl` ↔ `plugins_installed.latte` — JSON `|noescape`. `{counter start=0 assign=i}` Smarty counter dropped (unused). `{assign}|sprintf:` → `{var $author = (...|sprintf:...)}`. `|cat:` chain preserved. `{$author}/{$version}/$plugin['DESC']|noescape` (lines 115, 117, 128) — sprintf/cat-built HTML + plugin DESC HTML, no converter rule.
- [x] `plugins_new.tpl` ↔ `plugins_new.latte` — JSON `|noescape`. `{html_options}`→`{=htmlOptions(…)|noescape}`. `|escape:html` dropped on tooltip title (Latte attribute auto-escape). `{assign var=color_tab value=[…]}` → `{var $color_tab = […]}`. foreach key/item ×2. `$plugin['BIG_DESC']|nl2br` — Latte std `nl2br` html-escapes first (safer than Smarty `escape_html=false`).
- [x] `popuphelp.tpl` ↔ `popuphelp.latte` — `$HELP_CONTENT` Html-wrapped at MiscController. Trivial.
- [x] `queue.tpl` ↔ `queue.latte` — `gt`→`>`. foreach + dot→bracket. Line 59 escaped-quote translate literal `data-confirm='{"\"…\""|translate}'` preserved verbatim.
- [x] `rating.tpl` ↔ `rating.latte` — JSON `|noescape`. `{html_options}`→`{=htmlOptions(…)|noescape}` ×2. `index is odd` rewrite. `|json_encode|escape:html` → `|json_encode`. dot→bracket, nested foreach.
- [~] `rating_user.tpl` ↔ `rating_user.latte` — JSON `|noescape`. `|replace:' ':'<br>'` → `|replace:' ','<br>'|noescape` ×2 (HTML in replace value, no converter rule — regen would clobber). `{capture assign=X}…{/capture}` → `{capture $X}…{/capture}`. `{foreach}{if cond}{break}{/if}…{/foreach}` → `{foreach}{breakIf cond}…{/foreach}`. `$rate_arr@index` → `$iterator->getCounter() - 1`. `|htmlspecialchars` preserved on title attribute.
- [x] `site_manager.tpl` ↔ `site_manager.latte` — JSON `|noescape`. `not empty`→`!empty`, `index is odd`, dot→bracket, `|translate_dec` colon→comma. Plain DB values.
- [x] `site_update.tpl` ↔ `site_update.latte` — `not empty`→`!empty`. dot→bracket. `{html_options}`→`{=htmlOptions(…)|noescape}` ×2. `$L_RESULT_*` plain Lang::t.
- [x] `stats.tpl` ↔ `stats.latte` — JSON `|noescape`. `data-hours='{json_encode(…)}'` in single-quoted attribute (Latte escape preserves `"` as `&quot;`, browser dataset decodes).
- [~] `tabsheet.tpl` ↔ `tabsheet.latte` — foreach key/item rewrite. `{$sheet['caption']|noescape}` hand-fix (caption can contain HTML when plugins add tabsheet entries with markup; no converter rule — regen would clobber). Better fix: producer-side Html wrap when caption contains markup, but core captions are plain Lang::t so this is plugin-territory.
- [x] `tags.tpl` ↔ `tags.latte` — `{function name=X}…{/function}` → `{define X}…{/define}`. `{tagContent args}` callsite → `{include tagContent, args}`. JSON `|noescape`. `$smarty.cookies.X` → `$_COOKIE['X']`. `$warning_tags` Html-wrapped at producer (`MiscController:383` orphan-tag review link). `$message_tags` plain Lang::t. dot→bracket, foreach.
- [~] `themes_installed.tpl` ↔ `themes_installed.latte` — Same pattern as `plugins_installed`. `{assign}|sprintf|cat:` → `{var $X = (...|sprintf:...,...)|cat:...}`. `|escape:'html'` dropped on `$theme['DESC']`, replaced with `|noescape` (line 56). `{$author}/{$version}|noescape` (lines 51, 53) — sprintf/cat-built HTML, no converter rule. `{var $field_name = …}` ×2 (state-tracking).
- [x] `themes_new.tpl` ↔ `themes_new.latte` — Trivial. foreach + dot→bracket.
- [x] `themes_standard_pages.tpl` ↔ `themes_standard_pages.latte` — `not`→`!`, foreach. Escaped-apostrophe `=`-prefixed by converter.
- [x] `updates_ext.tpl` ↔ `updates_ext.latte` — JSON `|noescape`. foreach key/item ×2. `not empty`→`!empty`. dot→bracket. `{else if}`→`{elseif}`. Plain extension metadata.
- [x] `updates_pwg.tpl` ↔ `updates_pwg.latte` — JSON `|noescape`. `'<a href="%s">new exciting features</a>'|translate:$URL|noescape` from converter pass ×2. `{counter assign=i}` Smarty counter dropped (unused). `{else if}`→`{elseif}`. foreach + dot→bracket.
- [x] `upgrade.tpl` ↔ `upgrade.latte` — `'<strong>release %s</strong>'|translate:$X|noescape` from converter pass. `{html_options}`→`{=htmlOptions(…)|noescape}`. `|translate:$X:$Y` → `|translate:$X,$Y`. dot→bracket. `getCombinedCss/Scripts` return Html.
- [x] `user_activity.tpl` ↔ `user_activity.latte` — JSON `|noescape` ×2. foreach + dot→bracket. `{else if}`→`{elseif}`. `=ucfirst($x)|translate` printed expression. JS-driven UI.
- [x] `user_list.tpl` ↔ `user_list.latte` — full read (1069 lines, mostly JS-rendered placeholder UI). `{combine_*}`→`{do …}`. JSON `|noescape`. `{html_options}`→`{=htmlOptions(…)|noescape}` ×6+. foreach key/item rewrites. `|translate|escape` → `|translate` (Smarty escape dropped, body auto-escape covers). dot→bracket throughout. Tooltip titles with literal HTML (lines ~509-515, ~963-969) are template literals.
- [x] `user_perm.tpl` ↔ `user_perm.latte` — `$TITLE` plain Lang::t. `$DOUBLE_SELECT` auto-Html. `categories_because_of_groups[*]` Html-wrapped at producer (`UsersController:313`).

## themes/admin/\_base/template/include (6)

- [x] `add_album.inc.tpl` ↔ `add_album.inc.latte` — `{$X='value'}` (Smarty short-form assign) → `{var $X = 'value'}`. `{include file='X' load_mode=$Y}` → `{include 'X', load_mode: $Y}`. Relative path `'colorbox.inc.latte'` (instead of `include/colorbox…`) since this file IS in include/ dir.
- [x] `album_selector.inc.tpl` ↔ `album_selector.inc.latte` — `{capture name="X"}` + `$smarty.capture.X` test → `{capture $X}` + `empty($X)` test (Latte capture creates a variable, not `$smarty.capture` map). JSON `|noescape`. `{$X='value'}` → `{var $X = 'value'}`.
- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte` — comment-only file (CSS does the work).
- [~] `batch_manager_filter.inc.tpl` ↔ `batch_manager_filter.inc.latte` — JSON `|noescape`. dot→bracket. `eq/ne`→`==/!=`, `not isset/not empty`→`!isset/!empty`. `{html_options}`→`{=htmlOptions(…)|noescape}`. `|json_encode|escape:html` → `|json_encode` ×2. `$res@first` → `$iterator->isFirst()`. Smarty nested print-eval `{$NB_NO_MD5SUM}` as filter arg → `$NB_NO_MD5SUM` direct. `{include file='themes/_base/template/help/quick_search.tpl' dark_mode=$is_dark_mode}` → `{include '../../../../_base/template/help/quick_search.latte', is_dark_mode: $is_dark_mode}` — hand-fix renames `dark_mode` → `is_dark_mode` (the .tpl convention passed `dark_mode` but `quick_search` reads `$is_dark_mode`; Smarty's auto-propagation hid the bug, Latte's strict scoping surfaces it). Hand-fix has no converter rule — regen would clobber.
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte` — short-form assign + `{combine_script}`→`{do combineScript}`. trivial.
- [x] `datepicker.inc.tpl` ↔ `datepicker.inc.latte` — same pattern as colorbox.

---

## Findings log

### Resolved — HTML-payload vars in `mail/text/html` templates

Five sites where Latte auto-escape was mangling intended HTML in the
HTML-format mail templates. Fixed by adding `|noescape`:

- `notification_admin.latte:1` — `{$CONTENT|noescape}`.
- `notification_by_mail.latte:27,33,53` — `{$line|noescape}`,
  `{$custom_mail_content|noescape}`, `{$recent_post['HTML_DATA']|noescape}`.
- `cat_group_info.latte:8` — `{$CPL_CONTENT|noescape}`.

### Resolved — `REDIRECT_MSG` Html wrap

`Util.php:152` now wraps `nl2br(Lang::t('Redirection…'))` (or the caller's
HTML payload from `HtmlService::pageForbidden`/`badRequest`/`pageNotFound`)
as `Html`. `redirect.latte` prints bare `{$REDIRECT_MSG}`.

### Resolved — `PageState` collections accept `string|Html`

`PageState::$errors/$warnings/$messages/$infos` typed as
`list<string|Html>`. Callers pushing HTML markup wrap with `new Html(...)`
explicitly: `PasswordService:243`, `MiscController:453,455,590`,
`AlbumController:490,660`, `BatchManagerController:798`,
`ConfigurationController:286`, `ExtensionsController:320`,
`MaintenanceController:522`, `UsersController:194`. `admin.latte` and
`infos_errors.latte` foreach sites print bare `{$x}` — Html passes
through, plain strings auto-escape.

### Resolved — converter pass `addNoescapeToHtmlLiteralRepeats`

Detects `'<HTML>'|str_repeat:N` patterns and appends `|noescape`. Covers
`menubar_categories.latte:17,29` and `menubar_related_categories.latte:11,27`
so regen preserves the hand-fix.

### Verified safe — `<style>` block in mail header

`mail/text/html/header.latte:8-9` prints `{$GLOBAL_MAIL_CSS}`/`{$MAIL_CSS}`
inside `<style>…</style>`. Both vars come through `assignVarFromHandle`
(`MailService.php:563,569`), which wraps in `Html` — trust travels with the
value, Latte CSS-context escape is bypassed correctly.

### Remaining hand-fixes (no converter rule — regen would clobber)

Documented in the per-pair `[~]` notes. Each is a narrow case where the
converter cannot mechanically detect the right rewrite from the `.tpl`
source alone:

- `$footer_elements` foreach `{$elt|noescape}` (admin + public footer) —
  plugin debug HTML payloads.
- `index.latte` plugin HTML hooks: `PLUGIN_INDEX_CONTENT_BEFORE/BEGIN/END/AFTER`,
  `PLUGIN_INDEX_ACTIONS`, `$button` (PLUGIN_INDEX_BUTTONS), `CONTENT`.
- `picture.latte` plugin hooks: `PLUGIN_PICTURE_BEFORE/AFTER/ACTIONS`, `$button`.
- `menubar_menu.latte:13`, `menubar_specials.latte:5` —
  `{$link['REL']|noescape}` HTML attribute fragment in tag context.
- `menubar.latte:8` — `{$block->raw_content|noescape}` plugin raw HTML.
- `batch_manager_global.latte:242` — `{$action['CONTENT']|noescape}` plugin
  payload.
- `batch_manager_unit.latte` — dynamic `{include $PATH, element: $element,
  PATH: $PATH}` scope-pass for plugin sub-templates.
- `notification_by_mail.latte:91` (admin) — `{$u['CHECKED']|noescape}`
  HTML attribute fragment.
- `plugins_installed.latte:115,117,128`, `themes_installed.latte:51,53,56` —
  sprintf/`|cat:`-built HTML in `{$author}/{$version}/$plugin['DESC']`.
- `rating_user.latte:45,46` — `|replace:' ','<br>'|noescape` (HTML in
  replace value).
- `tabsheet.latte:6` — `{$sheet['caption']|noescape}` defensive for plugins
  that splice HTML.
- `batch_manager_filter.inc.latte:229` — `{include …, is_dark_mode: …}` arg
  rename (`.tpl` source passes `dark_mode=` but `quick_search` reads
  `$is_dark_mode`; Smarty's auto-propagation hid the bug).
