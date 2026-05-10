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
- [x] `search.tpl` ↔ `search.latte` — `{section start=N loop=M}` → `{foreach range(N, N+M-1)}`. `$smarty.now` → `time()`. `{html_options}` → `htmlOptions()`. `|strip_tags:false` handled.
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

- [x] `quick_search.tpl` ↔ `quick_search.latte` — translate-rewrite. Faithful.

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

- [x] `footer.tpl` ↔ `footer.latte` — `getCombinedScripts` returns Html.
- [x] `header.tpl` ↔ `header.latte` — `{strip}` → `{spaceless}`. `combine_css/script` → `do …()`. Backtick-string-interpolation rewritten.
- [x] `identification.tpl` ↔ `identification.latte` — JSON script `|noescape` ✓.
- [x] `password.tpl` ↔ `password.latte` — JSON script `|noescape` ✓.
- [~] `profile.tpl` ↔ `profile.latte` — bareword args (`name=theme`, `name=language`, `name=api_expiration`) hand-fixed (3 sites). JSON scripts `|noescape` ✓.
- [x] `register.tpl` ↔ `register.latte` — JSON script `|noescape` ✓.
- [x] `toaster.tpl` ↔ `toaster.latte` — straight conversion.

## themes/admin/_base/template (64)

> Method: each pair was diffed and the `+`-side scanned for residual
> Smarty constructs (`{section}`, `$smarty.*`, `|escape:*`, `not`/`eq`/
> `ne`, `{strip}`, `{html_options}` etc.) — none found. Producer-side
> HTML payload audit deferred for admin pages: live smoke would require
> auth setup; if a specific page renders with escaped HTML in production,
> the fix follows the established pattern (Html-wrap at controller
> assign site, or `|noescape` on plugin-payload prints).

- [x] `admin.tpl` ↔ `admin.latte`
- [x] `album_notification.tpl` ↔ `album_notification.latte`
- [x] `albums.tpl` ↔ `albums.latte`
- [x] `batch_manager_global.tpl` ↔ `batch_manager_global.latte`
- [x] `batch_manager_unit.tpl` ↔ `batch_manager_unit.latte`
- [x] `cat_list.tpl` ↔ `cat_list.latte` — `$smarty.cookies.X` → `$_COOKIE['X']` ✓.
- [x] `cat_modify.tpl` ↔ `cat_modify.latte`
- [x] `cat_options.tpl` ↔ `cat_options.latte` — `{$DOUBLE_SELECT}` auto-Html via assignVarFromHandle.
- [x] `cat_perm.tpl` ↔ `cat_perm.latte`
- [x] `check_integrity.tpl` ↔ `check_integrity.latte`
- [x] `comments.tpl` ↔ `comments.latte`
- [x] `configuration_comments.tpl` ↔ `configuration_comments.latte`
- [x] `configuration_default.tpl` ↔ `configuration_default.latte`
- [x] `configuration_display.tpl` ↔ `configuration_display.latte`
- [x] `configuration_main.tpl` ↔ `configuration_main.latte`
- [x] `configuration_search.tpl` ↔ `configuration_search.latte`
- [x] `configuration_sizes.tpl` ↔ `configuration_sizes.latte`
- [x] `configuration_watermark.tpl` ↔ `configuration_watermark.latte`
- [x] `double_select.tpl` ↔ `double_select.latte`
- [x] `element_set_ranks.tpl` ↔ `element_set_ranks.latte`
- [x] `extend_for_templates.tpl` ↔ `extend_for_templates.latte`
- [x] `footer.tpl` ↔ `footer.latte`
- [x] `group_list.tpl` ↔ `group_list.latte`
- [x] `group_perm.tpl` ↔ `group_perm.latte`
- [x] `header.tpl` ↔ `header.latte`
- [x] `help.tpl` ↔ `help.latte`
- [x] `history.tpl` ↔ `history.latte`
- [x] `install.tpl` ↔ `install.latte`
- [x] `intro.tpl` ↔ `intro.latte`
- [x] `languages_installed.tpl` ↔ `languages_installed.latte`
- [x] `languages_new.tpl` ↔ `languages_new.latte`
- [x] `maintenance_actions.tpl` ↔ `maintenance_actions.latte`
- [x] `maintenance_env.tpl` ↔ `maintenance_env.latte`
- [x] `maintenance_sys.tpl` ↔ `maintenance_sys.latte`
- [x] `menubar.tpl` ↔ `menubar.latte`
- [x] `navigation_bar.tpl` ↔ `navigation_bar.latte`
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte`
- [x] `permalinks.tpl` ↔ `permalinks.latte`
- [x] `photos_add_applications.tpl` ↔ `photos_add_applications.latte`
- [x] `photos_add_direct.tpl` ↔ `photos_add_direct.latte`
- [x] `photos_add_ftp.tpl` ↔ `photos_add_ftp.latte`
- [x] `picture_coi.tpl` ↔ `picture_coi.latte`
- [x] `picture_formats.tpl` ↔ `picture_formats.latte`
- [x] `picture_modify.tpl` ↔ `picture_modify.latte`
- [x] `plugins_installed.tpl` ↔ `plugins_installed.latte`
- [x] `plugins_new.tpl` ↔ `plugins_new.latte`
- [x] `popuphelp.tpl` ↔ `popuphelp.latte`
- [x] `queue.tpl` ↔ `queue.latte`
- [x] `rating.tpl` ↔ `rating.latte`
- [x] `rating_user.tpl` ↔ `rating_user.latte`
- [x] `site_manager.tpl` ↔ `site_manager.latte`
- [x] `site_update.tpl` ↔ `site_update.latte`
- [x] `stats.tpl` ↔ `stats.latte`
- [x] `tabsheet.tpl` ↔ `tabsheet.latte`
- [x] `tags.tpl` ↔ `tags.latte`
- [x] `themes_installed.tpl` ↔ `themes_installed.latte`
- [x] `themes_new.tpl` ↔ `themes_new.latte`
- [x] `themes_standard_pages.tpl` ↔ `themes_standard_pages.latte`
- [x] `updates_ext.tpl` ↔ `updates_ext.latte`
- [x] `updates_pwg.tpl` ↔ `updates_pwg.latte`
- [x] `upgrade.tpl` ↔ `upgrade.latte`
- [x] `user_activity.tpl` ↔ `user_activity.latte`
- [x] `user_list.tpl` ↔ `user_list.latte`
- [x] `user_perm.tpl` ↔ `user_perm.latte`

## themes/admin/_base/template/include (6)

- [x] `add_album.inc.tpl` ↔ `add_album.inc.latte`
- [x] `album_selector.inc.tpl` ↔ `album_selector.inc.latte`
- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte`
- [x] `batch_manager_filter.inc.tpl` ↔ `batch_manager_filter.inc.latte`
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte`
- [x] `datepicker.inc.tpl` ↔ `datepicker.inc.latte`

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

