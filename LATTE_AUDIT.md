# Latte conversion audit — §1.2 Wave 2

133 template pairs (`.tpl` Smarty source ↔ `.latte` converter output). Each
pair gets a manual diff to confirm the conversion is faithful and the
runtime payload reaches Latte intact. Items are reviewed *without
skimming* — read the full Smarty source, read the full Latte source,
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

## themes/_base (1)

- [x] `local_head.tpl` ↔ `local_head.latte`

## themes/_base/template (36)

- [x] `about.tpl` ↔ `about.latte` — `MENUBAR` auto-Html via `assignVarFromHandle`. `ABOUT_MESSAGE`/`THEME_ABOUT` wrapped Html in AboutController. `$about_msgs` is plugin territory (no current producer); plugins must wrap entries Html.
- [x] `comment_list.tpl` ↔ `comment_list.latte` — Display `CONTENT` (rendered HTML) wrapped Html in `PictureCommentRenderer:182` and `CommentsController:352`. Edit-mode `CONTENT` left raw (textarea text input — auto-escape correct).
- [x] `comments.tpl` ↔ `comments.latte` — `MENUBAR` and `COMMENT_LIST` auto-Html via `assignVarFromHandle`. `htmlOptions(...)` already gets `|noescape` from converter. Faithful.
- [~] `footer.tpl` ↔ `footer.latte` — `|escape:url` → `|urlencode` ✓ (converter rule). `getCombinedScripts` returns Html ✓. `QUERIES_LIST` wrapped Html in `PageTailRenderer:57`. `{$elt}` from `$footer_elements` hand-fixed `|noescape` (plugin debug HTML payload — preserve on regen).
- [~] `header.tpl` ↔ `header.latte` — `|strip_tags:false` handled by `PiwigoExtension::stripTags` wrapper ✓. `getCombinedCss/Scripts` return Html ✓. `PAGE_BANNER` wrapped Html in `PageHeaderRenderer:39`. `head_elements` push wrapped Html in `PageHeaderRenderer:64`. `header_msgs` upgrade-feed entry wrapped Html in `CommonBootstrap:310`. `header_notes` is plain l10n text (auto-escape neutral). JSON `<script>` data block hand-fixed `|noescape` (preserve on regen). `{strip}` → `{spaceless}` ✓.
- [x] `identification.tpl` ↔ `identification.latte` — straight conversion. `MENUBAR` auto-Html.
- [~] `index.tpl` ↔ `index.latte` — many HTML-payload vars; producer-side wraps + targeted `|noescape` hand-fixes:
  - `TITLE` Html-wrapped in `GalleryController:133` (section_title HTML breadcrumb).
  - `chronology['TITLE']` Html-wrapped in `CalendarService:203` (calendar HTML link).
  - `CONTENT_DESCRIPTION` Html-wrapped in `GalleryController:305` (album description).
  - `category_search_results[]` Html-wrapped in `GalleryController:245`.
  - `no_search_results[]` was double-escaped (`htmlspecialchars`+Latte); dropped manual escape, Latte handles.
  - `PLUGIN_INDEX_CONTENT_BEFORE/BEGIN/END/AFTER`, `PLUGIN_INDEX_ACTIONS`, `$button` (PLUGIN_INDEX_BUTTONS) hand-fixed `|noescape` in template (plugin HTML, faithful to Smarty escape_html=false).
  - `CONTENT` hand-fixed `|noescape` (page-level HTML payload).
  - `SELECTED_TAGS_TEMPLATE` undefined warning fixed by `{if isset(...)}` guard.
- [x] `infos_errors.tpl` ↔ `infos_errors.latte` — straight foreach over plain l10n strings.
- [x] `mainpage_categories.tpl` ↔ `mainpage_categories.latte` — `CAPTION_NB_IMAGES` and `DESCRIPTION` Html-wrapped in `CategoryCatsRenderer`. `NAME` plain text from `render_category_name`. `strip_tags:false` handled by extension wrapper.
- [~] `menubar.tpl` ↔ `menubar.latte` — `{$block->raw_content}` hand-fixed `|noescape` (plugin-supplied raw HTML when block has no template).
- [~] `menubar_categories.tpl` ↔ `menubar_categories.latte` — `'</ul></li>'|str_repeat` hand-fixed `|noescape` (×2). Faithful otherwise.
- [x] `menubar_identification.tpl` ↔ `menubar_identification.latte` — `$smarty.server.REQUEST_URI` → `($_SERVER['REQUEST_URI'] ?? '')`. `{strip}` → `{spaceless}`.
- [x] `menubar_links.tpl` ↔ `menubar_links.latte` — `|escape:'html'` dropped (Latte auto-escape covers).
- [~] `menubar_menu.tpl` ↔ `menubar_menu.latte` — `{$link['REL']|noescape}` hand-fix (REL is HTML attribute fragment from MenubarRenderer).
- [~] `menubar_related_categories.tpl` ↔ `menubar_related_categories.latte` — `'</ul></li>'|str_repeat` hand-fixed `|noescape` (×2).
- [~] `menubar_specials.tpl` ↔ `menubar_specials.latte` — `{$link['REL']|noescape}` hand-fix (same REL pattern).
- [x] `menubar_tags.tpl` ↔ `menubar_tags.latte` — straight conversion.
- [x] `month_calendar.tpl` ↔ `month_calendar.latte` — straight conversion, plain text labels.
- [x] `navigation_bar.tpl` ↔ `navigation_bar.latte` — `{foreach key=item}` → `as key => val`, `assign` → `var`.
- [x] `nbm.tpl` ↔ `nbm.latte` — straight conversion.
- [x] `no_photo_yet.tpl` ↔ `no_photo_yet.latte` — `$intro` is plain l10n text. Standalone HTML page (no menubar/footer).
- [x] `notification.tpl` ↔ `notification.latte` — `{html_head}` Smarty block tag → `{capture}…{do htmlHead(...)}` rewrite.
- [x] `password.tpl` ↔ `password.latte` — `eq`/`ne` → `==`/`!=`. Straight otherwise.
- [~] `picture.tpl` ↔ `picture.latte` — `SECTION_TITLE`, `COMMENT_IMG`, `INFO_CREATION_DATE`, `INFO_POSTED_DATE`, `ELEMENT_CONTENT`, `related_categories[]` Html-wrapped at producer (`PictureController`). `PLUGIN_PICTURE_BEFORE/AFTER/ACTIONS`, `$button` (BUTTONS) hand-fixed `|noescape`.
- [x] `picture_content.tpl` ↔ `picture_content.latte` — `|strip_tags:false|replace:'"',' '` survives wrapper. Faithful.
- [x] `picture_nav_buttons.tpl` ↔ `picture_nav_buttons.latte` — straight conversion, plain text labels.
- [x] `popuphelp.tpl` ↔ `popuphelp.latte` — `HELP_CONTENT` Html-wrapped at producer (PopuphelpController).
- [x] `profile.tpl` ↔ `profile.latte` — `PROFILE_CONTENT` auto-Html via `assignVarFromHandle`.
- [~] `profile_content.tpl` ↔ `profile_content.latte` — `name=theme`/`name=language` Smarty barewords incorrectly converted to bare identifiers in Latte; converter `normalizeArgValue` updated to quote barewords. Existing files hand-fixed (×3 sites).
- [x] `redirect.tpl` ↔ `redirect.latte` — `REDIRECT_MSG` is plain text from callers; faithful.
- [x] `register.tpl` ↔ `register.latte` — translate-rewrite + `not` → `!`. Faithful.
- [ ] `search.tpl` ↔ `search.latte` — diff scanned (head + tail only); 135 lines not fully read. Patterns observed are mechanical: `{section start=N loop=M}` → `{foreach range(N, N+M-1)}`, `$smarty.now` → `time()`, `{html_options}` → `htmlOptions()`, `|strip_tags:false` handled.
- [x] `search_rules.tpl` ↔ `search_rules.latte` — straight conversion.
- [x] `slideshow.tpl` ↔ `slideshow.latte` — `ELEMENT_CONTENT` and `COMMENT_IMG` already wrapped at producer (PictureController).
- [x] `tags.tpl` ↔ `tags.latte` — straight conversion.
- [x] `thumbnails.tpl` ↔ `thumbnails.latte` — `assign` → `var`. Faithful.

## themes/_base/template/include (5)

- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte` — comment-only file; faithful.
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte` — combine_script/css → do.
- [x] `related_tags.inc.tpl` ↔ `related_tags.inc.latte` — straight conversion.
- [~] `search_filters.inc.tpl` ↔ `search_filters.inc.latte` — `<script type="application/json">{$page_data_json}</script>` got `|noescape` (converter rule added in `addNoescapeToJsonScriptBlocks` pass).
- [x] `selected_tags.inc.tpl` ↔ `selected_tags.inc.latte` — straight conversion.

## themes/_base/template/help (1)

- [ ] `quick_search.tpl` ↔ `quick_search.latte` — diff scanned (head 40 lines only); 305 lines not fully read.

## themes/_base/template/mail/text/html (8)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — faithful. `$CPL_CONTENT` is admin-input mail content; plain-text default expected.
- [x] `footer.tpl` ↔ `footer.latte` — `|escape:url` → `|urlencode` ✓.
- [x] `global-mail-css.tpl` ↔ `global-mail-css.latte` — identical (CSS only).
- [x] `header.tpl` ↔ `header.latte` — faithful. `MAIL_TITLE`/`MAIL_SUBTITLE` plain text.
- [x] `mail-css-dark.tpl` ↔ `mail-css-dark.latte` — identical (CSS only).
- [x] `mail-css-light.tpl` ↔ `mail-css-light.latte` — identical (CSS only).
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — faithful.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — faithful.

## themes/_base/template/mail/text/plain (5)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — faithful. (Plain-text mail; auto-escape NOT broken because translated text contains no `<`/`>`.)
- [x] `footer.tpl` ↔ `footer.latte` — `{literal}` → `{syntax off}` ✓.
- [x] `header.tpl` ↔ `header.latte` — `{literal}` → `{syntax off}` ✓.
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — faithful.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — faithful.

## themes/standard_pages/template (7)

- [x] `footer.tpl` ↔ `footer.latte` — small file; full diff visible. `getCombinedScripts` returns Html.
- [ ] `header.tpl` ↔ `header.latte` — diff scanned (head only). 102 diff lines.
- [ ] `identification.tpl` ↔ `identification.latte` — diff scanned (head only). 25-ish diff lines.
- [ ] `password.tpl` ↔ `password.latte` — diff scanned (head only).
- [~] `profile.tpl` ↔ `profile.latte` — diff scanned (head only); bareword fixes applied during this session at lines 95, 104, 305 (`name=theme`, `name=language`, `name=api_expiration`). JSON scripts `|noescape` ✓ from converter pass. Body NOT fully read.
- [ ] `register.tpl` ↔ `register.latte` — diff scanned (head only).
- [x] `toaster.tpl` ↔ `toaster.latte` — small file; full diff visible.

## themes/admin/_base/template (64)

> NOT properly profiled. The pairs below got a structural diff scan +
> a broad grep over the converted `.latte` files for residual Smarty
> constructs (`{section}`, `$smarty.*`, `|escape:*`, `not`/`eq`/`ne`,
> `{strip}`, `{html_options}` etc.) — zero hits. That confirms the
> converter rules covered every Smarty construct present, but it does
> NOT verify per-pair runtime correctness: HTML-payload vars assigned
> in admin controllers may still need `Latte\Runtime\Html` wrapping,
> and admin pages were not live-smoked (would require auth setup).
> All 64 pairs left unchecked pending end-to-end review.

- [ ] `admin.tpl` ↔ `admin.latte`
- [ ] `album_notification.tpl` ↔ `album_notification.latte`
- [ ] `albums.tpl` ↔ `albums.latte`
- [ ] `batch_manager_global.tpl` ↔ `batch_manager_global.latte`
- [ ] `batch_manager_unit.tpl` ↔ `batch_manager_unit.latte`
- [ ] `cat_list.tpl` ↔ `cat_list.latte`
- [ ] `cat_modify.tpl` ↔ `cat_modify.latte`
- [ ] `cat_options.tpl` ↔ `cat_options.latte`
- [ ] `cat_perm.tpl` ↔ `cat_perm.latte`
- [ ] `check_integrity.tpl` ↔ `check_integrity.latte`
- [ ] `comments.tpl` ↔ `comments.latte`
- [ ] `configuration_comments.tpl` ↔ `configuration_comments.latte`
- [ ] `configuration_default.tpl` ↔ `configuration_default.latte`
- [ ] `configuration_display.tpl` ↔ `configuration_display.latte`
- [ ] `configuration_main.tpl` ↔ `configuration_main.latte`
- [ ] `configuration_search.tpl` ↔ `configuration_search.latte`
- [ ] `configuration_sizes.tpl` ↔ `configuration_sizes.latte`
- [ ] `configuration_watermark.tpl` ↔ `configuration_watermark.latte`
- [ ] `double_select.tpl` ↔ `double_select.latte`
- [ ] `element_set_ranks.tpl` ↔ `element_set_ranks.latte`
- [ ] `extend_for_templates.tpl` ↔ `extend_for_templates.latte`
- [ ] `footer.tpl` ↔ `footer.latte`
- [ ] `group_list.tpl` ↔ `group_list.latte`
- [ ] `group_perm.tpl` ↔ `group_perm.latte`
- [ ] `header.tpl` ↔ `header.latte`
- [ ] `help.tpl` ↔ `help.latte`
- [ ] `history.tpl` ↔ `history.latte`
- [ ] `install.tpl` ↔ `install.latte`
- [ ] `intro.tpl` ↔ `intro.latte`
- [ ] `languages_installed.tpl` ↔ `languages_installed.latte`
- [ ] `languages_new.tpl` ↔ `languages_new.latte`
- [ ] `maintenance_actions.tpl` ↔ `maintenance_actions.latte`
- [ ] `maintenance_env.tpl` ↔ `maintenance_env.latte`
- [ ] `maintenance_sys.tpl` ↔ `maintenance_sys.latte`
- [ ] `menubar.tpl` ↔ `menubar.latte`
- [ ] `navigation_bar.tpl` ↔ `navigation_bar.latte`
- [ ] `notification_by_mail.tpl` ↔ `notification_by_mail.latte`
- [ ] `permalinks.tpl` ↔ `permalinks.latte`
- [ ] `photos_add_applications.tpl` ↔ `photos_add_applications.latte`
- [ ] `photos_add_direct.tpl` ↔ `photos_add_direct.latte`
- [ ] `photos_add_ftp.tpl` ↔ `photos_add_ftp.latte`
- [ ] `picture_coi.tpl` ↔ `picture_coi.latte`
- [ ] `picture_formats.tpl` ↔ `picture_formats.latte`
- [ ] `picture_modify.tpl` ↔ `picture_modify.latte`
- [ ] `plugins_installed.tpl` ↔ `plugins_installed.latte`
- [ ] `plugins_new.tpl` ↔ `plugins_new.latte`
- [ ] `popuphelp.tpl` ↔ `popuphelp.latte`
- [ ] `queue.tpl` ↔ `queue.latte`
- [ ] `rating.tpl` ↔ `rating.latte`
- [ ] `rating_user.tpl` ↔ `rating_user.latte`
- [ ] `site_manager.tpl` ↔ `site_manager.latte`
- [ ] `site_update.tpl` ↔ `site_update.latte`
- [ ] `stats.tpl` ↔ `stats.latte`
- [ ] `tabsheet.tpl` ↔ `tabsheet.latte`
- [ ] `tags.tpl` ↔ `tags.latte`
- [ ] `themes_installed.tpl` ↔ `themes_installed.latte`
- [ ] `themes_new.tpl` ↔ `themes_new.latte`
- [ ] `themes_standard_pages.tpl` ↔ `themes_standard_pages.latte`
- [ ] `updates_ext.tpl` ↔ `updates_ext.latte`
- [ ] `updates_pwg.tpl` ↔ `updates_pwg.latte`
- [ ] `upgrade.tpl` ↔ `upgrade.latte`
- [ ] `user_activity.tpl` ↔ `user_activity.latte`
- [ ] `user_list.tpl` ↔ `user_list.latte`
- [ ] `user_perm.tpl` ↔ `user_perm.latte`

## themes/admin/_base/template/include (6)

> NOT properly profiled — never opened.

- [ ] `add_album.inc.tpl` ↔ `add_album.inc.latte`
- [ ] `album_selector.inc.tpl` ↔ `album_selector.inc.latte`
- [ ] `autosize.inc.tpl` ↔ `autosize.inc.latte`
- [ ] `batch_manager_filter.inc.tpl` ↔ `batch_manager_filter.inc.latte`
- [ ] `colorbox.inc.tpl` ↔ `colorbox.inc.latte`
- [ ] `datepicker.inc.tpl` ↔ `datepicker.inc.latte`

---

## Findings log

### Systemic — HTML-payload vars need `|noescape` under Latte auto-escape

The converter is intentionally faithful and does NOT add `|noescape`. But
several Smarty assigns hold pre-rendered HTML, and Smarty ran with
`escape_html=false` so `{$VAR}` printed raw. Latte auto-escapes by
default — those bare prints now render as escaped HTML text in the
browser.

Sources of HTML payloads (enumerated from `assignVarFromHandle()` and
notable `assign()` sites in `src/`):

| Var | Source | Found in templates |
|---|---|---|
| `MENUBAR` | `BlockManager::apply` ← `menubar` handle | `about.latte:1`, `comments.latte:1`, others (header/index will surface when reviewed) |
| `ADMIN_CONTENT` | every admin controller's `assignVarFromHandle('ADMIN_CONTENT', '<page>')` | likely `admin.latte` |
| `CATEGORIES` | `CategoryCatsRenderer` ← `index_category_thumbnails` | likely `index.latte` |
| `THUMBNAILS` | `CategoryDefaultRenderer` ← `index_thumbnails` | likely `index.latte` |
| `COMMENT_LIST` | `PictureCommentRenderer`/`CommentsController` ← `comment_list` | `comments.latte:107`, `picture.latte:305` |
| `DOUBLE_SELECT` | various ← `double_select` | likely group/user perm pages |
| `PROFILE_CONTENT` | `ProfileController` ← `profile_content` | `profile.latte` |
| `SELECTED_TAGS_TEMPLATE` | `SelectedTagsRenderer` ← `selected_tags` | tag pages |
| `GLOBAL_MAIL_CSS`, `MAIL_CSS` | `MailService` ← `global-css` / `css` | mail/header.latte |
| `CONTENT` (mail context) | `MailService::540ish` `assign('CONTENT', $mailContent)` | mail templates only |
| Tabsheet output | `Tabsheet::assignVarFromHandle($this->name, 'tabsheet')` | wherever `{$tabsheet_name}` is printed |

Plus less obvious but commonly HTML-bearing:

- `ABOUT_MESSAGE`, `THEME_ABOUT`, `$elt` in `$about_msgs` (free-form admin-set HTML)
- `HELP_CONTENT` (help.latte already has `|noescape`)
- `LEVEL_SEPARATOR` — default ` / ` is plain text, but admins can configure `&raquo;` etc. Conservative: add `|noescape`.
- `PLUGIN_INDEX_*`, `PLUGIN_*_CONTENT_*` — plugin-supplied raw HTML

**Action**: tag every bare `{$VAR}` print of one of these payloads with
`|noescape`. The audit will flag each occurrence per pair. The converter
is deliberately not changed — adding heuristic `|noescape` to every bare
print would be unsafe; the rule is per-var.

### Systemic — `|escape:url` dropped instead of mapped to URL-encoder

`Converter::rewriteEscapeFilters` drops every `|escape:<arg>` because
"Latte's auto-escape covers the common cases (html, htmlall, url,
javascript)" per the docblock — but that's wrong: Latte's auto-escape
is HTML-context only, not URL-encoding. `|escape:url` should map to
Latte's `|escapeUrl` filter (or a `urlencode` shim), not be dropped.
Affected `.tpl` sites: `themes/_base/template/footer.tpl:15`,
`themes/_base/template/mail/text/html/footer.tpl:15`,
`themes/admin/_base/template/footer.tpl:34`.

### Systemic — custom-fn HTML returns need `|noescape` on call site

Two PiwigoExtension functions return HTML strings but the converter
emits them as `{=fn()}` without `|noescape`:
- `getCombinedScripts(load: 'footer')` — returns `<script>` tags
- `getCombinedCss()` — returns `<link>` / inline `<style>`

Result: HTML markup gets escaped to `&lt;script&gt;...` text in the
page. `htmlOptions` and `htmlRadios` already get `|noescape` in the
converter (correct); `getCombinedScripts`/`getCombinedCss` need the
same treatment, OR the PHP functions should return
`Latte\Runtime\Html` objects so the trust travels with the value.

### Systemic — `|strip_tags:false` semantics break under PHP 8

Smarty's modifier signature is `strip_tags($string, $replace_with_space = true)` —
`:false` means "remove tags without space replacement". The current
`PiwigoExtension::filters` registers `'strip_tags' => strip_tags(...)` which
points at PHP's native `strip_tags($string, $allowed_tags = null)`. Calling
that with `(false)` as the second arg is a TypeError in PHP 8
(`$allowed_tags` is `string|array|null`).

Affected `.tpl` sites: `header.tpl:12,18`, `mainpage_categories.tpl:13`,
`search.tpl:47` (×2), `picture_content.tpl:10`. All five inherit the
breakage in their `.latte` siblings.

Two fixes possible:

1. Converter: drop the `:false` arg (since PHP's no-arg `strip_tags` matches
   Smarty's `:false` semantic — both strip without space-replacement).
2. Wrap the filter in PiwigoExtension to mimic Smarty's two-arg behavior:
   `function ($s, bool $replaceWithSpace = true) { return $replaceWithSpace
   ? preg_replace('/<[^>]*>/', ' ', (string) $s) : strip_tags((string) $s); }`.

Option 2 is safer because Smarty's bare `|strip_tags` (no arg, default true =
"replace with space") would then keep working for any plugin templates that
use the defaulted form.

### Systemic — REL attribute fragments need `|noescape` in tag context

`MenubarRenderer` populates `$block->data[*]['REL']` with HTML attribute
fragments (`'rel="nofollow"'`, `'rel="search"'`). Templates print these
between `<a` and `>` (tag context). Latte's tag-context escaper escapes
`=` and `"` even on `Html` objects, breaking the attribute. The producer
side cannot fix this — the data shape is intentionally an attribute
fragment. Hand-fix in templates: `{$link['REL']|noescape}`. Affected:
`menubar_specials.latte:5`, `menubar_menu.latte:13`. (Cleaner long-term:
refactor producer to emit `'rel' => 'nofollow'` and template does
`rel="{$link['rel']}"`. Out of scope for the audit.)

### Systemic — HTML literals piped through `str_repeat` need `|noescape`

`{='</ul></li>'|str_repeat:N}` — Smarty (escape_html=false) printed raw;
Latte auto-escapes the str_repeat output. Hand-fix `|noescape`. Affected:
`menubar_categories.latte:17,29`. Converter could detect string literals
containing `<` chars piped through repeat-style filters, but heuristic.

### Systemic — section_title is HTML, must be wrapped Html

`SectionInitializer:435` builds `$page['section_title']` as raw HTML
(`<a href="...">Home</a> / Albums`). `GalleryController:133` assigns it
to `TITLE`. Wrapped Html at the assign site.

### Systemic — `<script type="application/json">` JSON blocks need `|noescape`

Latte's auto-escape inside `<script>` is JS-context: it turns valid JSON
quotes into `\"`-escaped JS literals, breaking
`JSON.parse(document.querySelector(...).textContent)` consumers.
`type="application/json"` data blocks need the JSON output emitted raw.
Fixed in converter: new `addNoescapeToJsonScriptBlocks` pass detects
the pattern and appends `|noescape` to the inner expression. Affected
~40 .latte files across themes/_base, themes/admin, and themes/standard_pages.

### Systemic — Smarty bareword args become bare identifiers in Latte

`{html_options name=theme ...}` — Smarty treats `theme` as the string
`'theme'`. Converter's `parseSmartyArgs` faithfully relayed the bareword
into Latte's named-arg syntax: `htmlOptions(name: theme, ...)` — Latte
then evaluates `theme` as an undefined constant or variable. Fixed in
`Converter::normalizeArgValue` by quoting bareword identifiers (skipping
`true`/`false`/`null`).

### Per-pair findings

(prepend new entries here as the audit progresses)

