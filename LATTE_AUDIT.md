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

- [x] `local_head.tpl` ↔ `local_head.latte`

## themes/\_base/template (36)

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
- [x] `search.tpl` ↔ `search.latte` — full read both. `{section start=1 loop=32}` → `{foreach range(1, 32) as $day}` ✓ (×2 day dropdowns). `$smarty.now|date_format` → `time()|date_format` ✓ (×6 onclick handlers). `{html_options}` → `htmlOptions()|noescape` ✓ (×3). `|strip_tags:false|escape:html` → `|strip_tags:false` (escape dropped, Latte auto-escapes attribute) ✓. `$AUTHORS`, `$TAGS`, `$month_list`, `$category_options` are plain data structures from controller, no HTML payload. Faithful.
- [x] `search_rules.tpl` ↔ `search_rules.latte` — straight conversion.
- [x] `slideshow.tpl` ↔ `slideshow.latte` — `ELEMENT_CONTENT` and `COMMENT_IMG` already wrapped at producer (PictureController).
- [x] `tags.tpl` ↔ `tags.latte` — straight conversion.
- [x] `thumbnails.tpl` ↔ `thumbnails.latte` — `assign` → `var`. Faithful.

## themes/\_base/template/include (5)

- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte` — comment-only file; faithful.
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte` — combine_script/css → do.
- [x] `related_tags.inc.tpl` ↔ `related_tags.inc.latte` — straight conversion.
- [~] `search_filters.inc.tpl` ↔ `search_filters.inc.latte` — `<script type="application/json">{$page_data_json}</script>` got `|noescape` (converter rule added in `addNoescapeToJsonScriptBlocks` pass).
- [x] `selected_tags.inc.tpl` ↔ `selected_tags.inc.latte` — straight conversion.

## themes/\_base/template/help (1)

- [x] `quick_search.tpl` ↔ `quick_search.latte` — full read both. Pure static help content; only `$is_dark_mode` boolean and translate filters. No HTML-payload vars, no foreach. Faithful.

## themes/\_base/template/mail/text/html (8)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — faithful. `$CPL_CONTENT` is admin-input mail content; plain-text default expected.
- [x] `footer.tpl` ↔ `footer.latte` — `|escape:url` → `|urlencode` ✓.
- [x] `global-mail-css.tpl` ↔ `global-mail-css.latte` — identical (CSS only).
- [x] `header.tpl` ↔ `header.latte` — faithful. `MAIL_TITLE`/`MAIL_SUBTITLE` plain text.
- [x] `mail-css-dark.tpl` ↔ `mail-css-dark.latte` — identical (CSS only).
- [x] `mail-css-light.tpl` ↔ `mail-css-light.latte` — identical (CSS only).
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — faithful.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — faithful.

## themes/\_base/template/mail/text/plain (5)

- [x] `cat_group_info.tpl` ↔ `cat_group_info.latte` — faithful. (Plain-text mail; auto-escape NOT broken because translated text contains no `<`/`>`.)
- [x] `footer.tpl` ↔ `footer.latte` — `{literal}` → `{syntax off}` ✓.
- [x] `header.tpl` ↔ `header.latte` — `{literal}` → `{syntax off}` ✓.
- [x] `notification_admin.tpl` ↔ `notification_admin.latte` — faithful.
- [x] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — faithful.

## themes/standard_pages/template (7)

- [x] `footer.tpl` ↔ `footer.latte` — small file; full diff visible. `getCombinedScripts` returns Html.
- [x] `header.tpl` ↔ `header.latte` — full read both. `{strip}` → `{spaceless}`, foreach themes, backtick-string-interp rewritten. `head_elements` push wrapped Html in `PageHeaderRenderer`. Faithful.
- [~] `identification.tpl` ↔ `identification.latte` — full read both. Converter previously missed adding `=` print prefix to `{'Don\'t have an account yet ?'|translate}` (line 91) due to escaped apostrophe in regex; converter `rewritePrintedLiteralFilter` regex updated to handle `\'`. Latte 3 still accepts the unprefixed form, so existing rendering works. Other content is mechanical translates.
- [~] `password.tpl` ↔ `password.latte` — full read both. Three sites with HTML inside translate strings hand-fixed `|noescape` (lines 72 `Hello <em>%s</em>...`, 134 `Return to <a href...>`, 151 `An error has occured ... <a href...>`). Converter now has `addNoescapeToHtmlBearingTranslations` pass to apply this rule on regen.
- [~] `profile.tpl` ↔ `profile.latte` — full read both. Bareword args (`name=theme`, `name=language`, `name=api_expiration`) hand-fixed (3 sites). `API_EMAIL_INFOS` (HTML `<em>%s</em>` translation, ProfileService:235) Html-wrapped at producer; user-email arg htmlspecialchars-escaped before splicing. JSON scripts `|noescape` ✓ from converter pass.
- [x] `register.tpl` ↔ `register.latte` — full read both. `not` → `!` ✓. Plain text translates only, JSON script `|noescape` ✓.
- [x] `toaster.tpl` ↔ `toaster.latte` — small file; full diff visible.

## themes/admin/\_base/template (64)

> All 64 pairs reviewed end-to-end. Producer-side `Latte\Runtime\Html`
> wraps applied where the var holds pre-rendered HTML (sort indicators,
> category breadcrumbs, integrity HTML messages, install help link with
> email, etc.). Template-side `|noescape` hand-fixes applied where HTML
> originates from translate-arg splice or attribute-fragment payloads.
> Live admin smoke not performed (would require auth setup) — flagged
> as residual risk.

- [x] `admin.tpl` ↔ `admin.latte` — full read. Sidebar nav. `$ADMIN_PAGE_TITLE` Html-wrapped at producer. `$TABSHEET`, `$ADMIN_CONTENT` auto-Html via assignVarFromHandle. errors/infos/warnings/messages from PageState are plain l10n text.
- [x] `album_notification.tpl` ↔ `album_notification.latte` — full read. `htmlOptions|noescape` ✓. `$MAIL_CONTENT` is textarea text input. `$save_success` plain text from controller.
- [x] `albums.tpl` ↔ `albums.latte` — full read. Album-tree popup management. `$page_data_json|noescape` ✓. Plain UI markup; placeholder string preserved.
- [~] `batch_manager_global.tpl` ↔ `batch_manager_global.latte` — full read. `$thumbnail['TITLE']` Html-wrapped at producer (BatchManagerController:909). `htmlOptions|noescape` ✓. `$action['CONTENT']` (plugin payload) hand-fixed `|noescape` (line 242).
- [x] `batch_manager_unit.tpl` ↔ `batch_manager_unit.latte` — full read. Per-image edit form. `htmlOptions|noescape` ✓. `url_is_remote(…)` is registered function. PLUGIN_BATCH_MANAGER_UNIT_ELEMENT_SUBTEMPLATE foreach with dynamic `{include $PATH, …}`.
- [~] `cat_list.tpl` ↔ `cat_list.latte` — full read. `$smarty.cookies.X` → `$_COOKIE['X']` ✓. `CATEGORIES_NAV` Html-wrapped at producer (AlbumController). `$category['NAME']` is rendered name (usually plain text, plugin-overridable). `assign` → `var`. Faithful.
- [x] `cat_modify.tpl` ↔ `cat_modify.latte` — full read. `$CATEGORIES_NAV` and `$CATEGORIES_PARENT_NAV` Html-wrapped at AlbumController. `$INFO_*` all plain Lang::t/timeSince output. `$INFO_TITLE` plain text Lang::t (no HTML, just sentence). `$CAT_NAME`/`$CAT_COMMENT` plain admin input.
- [x] `cat_options.tpl` ↔ `cat_options.latte` — full read both. `$DOUBLE_SELECT` auto-Html. Faithful.
- [x] `cat_perm.tpl` ↔ `cat_perm.latte` — full read. JSON script `|noescape` ✓. `$group_details['group_name']`/`['group_users']` plain. `$save_success` plain Lang::t. `groups_selected|json_encode` in single-quoted attr ✓.
- [~] `check_integrity.tpl` ↔ `check_integrity.latte` — full read. `$c13y['anomaly']` plain Lang::t. `$c13y['correction_msg']` and `$c13y['correction_error_fct']` Html-wrapped at producer (CheckIntegrity:196,213) — sources contain `<br>` + getHtlmLinksMoreInfo() HTML.
- [x] `comments.tpl` ↔ `comments.latte` — full read. JS-driven UI; only translates and config flags. JSON script `|noescape` ✓. Faithful.
- [x] `configuration_comments.tpl` ↔ `configuration_comments.latte` — full read. Checkbox form, plain settings. `htmlOptions|noescape` ✓.
- [x] `configuration_default.tpl` ↔ `configuration_default.latte` — full read. `htmlRadios|noescape` ✓. Faithful.
- [x] `configuration_display.tpl` ↔ `configuration_display.latte` — full read. Checkbox-only UI with translate filter chains `('Edit album'|translate|ucfirst)`. Plain.
- [x] `configuration_main.tpl` ↔ `configuration_main.latte` — full read. JSON script `|noescape` ✓. `$main['CONF_PAGE_BANNER']` in textarea (plain context). `$main['mail_theme_options']` foreach over theme name strings. Tooltip title uses literal `<br><img>` (template literal, not var; Latte does not escape).
- [x] `configuration_search.tpl` ↔ `configuration_search.latte` — full read. JSON script `|noescape` ✓. Filter view config form; checkboxes/radios/selects. Plain.
- [x] `configuration_sizes.tpl` ↔ `configuration_sizes.latte` — full read. JSON script `|noescape` ✓. Derivative size config; plain numeric inputs.
- [x] `configuration_watermark.tpl` ↔ `configuration_watermark.latte` — full read. JSON script `|noescape` ✓. `htmlOptions|noescape` ✓. Watermark config form; plain.
- [x] `double_select.tpl` ↔ `double_select.latte` — full read. `htmlOptions(...)|noescape` ✓.
- [x] `element_set_ranks.tpl` ↔ `element_set_ranks.latte` — full read. `$thumbnail['NAME']` plain (filename). `htmlOptions|noescape` ✓. `$save_success` plain.
- [x] `extend_for_templates.tpl` ↔ `extend_for_templates.latte` — full read. `htmlOptions(...)|noescape` ✓ (×3).
- [~] `footer.tpl` ↔ `footer.latte` — full read. `{$elt}` foreach over `$footer_elements` hand-fixed `|noescape` (admin maintenance/search controllers push HTML comment payloads, same pattern as public footer). `$debug['QUERIES_LIST']` Html-wrapped at PageTailRenderer:57.
- [~] `group_list.tpl` ↔ `group_list.latte` — full read. JSON scripts `|noescape` ✓ (×2). Translate args with HTML literal `'<span>0</span>'` and `'<strong>39</strong>','<strong>251</strong>'` hand-fixed `|noescape` (lines 107, 177). `$grp_*` from include args plain.
- [x] `header.tpl` ↔ `header.latte` — full read. `head_elements` push wrapped Html in PageHeaderRenderer:64; `header_msgs` upgrade entry wrapped Html in CommonBootstrap:310 (passthrough auto-escape since trust marker). JSON config block `|noescape` ✓.
- [x] `help.tpl` ↔ `help.latte` — full read. `$HELP_CONTENT` Html-wrapped at producer (MiscController). `$HELP_SECTION_TITLE` plain text.
- [x] `history.tpl` ↔ `history.latte` — full read. JSON script `|noescape` ✓. `$search_summary['NB_LINES'/'FILESIZE'/'USERS'/'MEMBERS'/'GUESTS']` plain Translator plurals from GeneralEndpoints. JS-driven activity table.
- [~] `install.tpl` ↔ `install.latte` — full read. `$L_INSTALL_HELP` Html-wrapped at InstallController:274 (contains `<a href>`). `$EMAIL` Html-wrapped with `htmlspecialchars($admin_mail)` to prevent XSS. Subscribe translate body line hand-fixed `|noescape`. `htmlOptions|noescape` ✓.
- [x] `intro.tpl` ↔ `intro.latte` — full read. JSON script `|noescape` ✓. Stats counts `$NB_*` plain. `$ACTIVITY_CHART_DATA`/`$STORAGE_CHART_DATA` numeric arrays. `translate_dec` plurals plain.
- [x] `languages_installed.tpl` ↔ `languages_installed.latte` — full read. Language metadata; `name`, `deactivate_tooltip` plain. Faithful.
- [x] `languages_new.tpl` ↔ `languages_new.latte` — full read. cluetip title `|htmlspecialchars|nl2br` preserved (attribute context). Faithful.
- [x] `maintenance_actions.tpl` ↔ `maintenance_actions.latte` — full read. Maintenance action buttons. Plain text labels. Faithful.
- [x] `maintenance_env.tpl` ↔ `maintenance_env.latte` — full read. System info display, plain text vars. Faithful.
- [x] `maintenance_sys.tpl` ↔ `maintenance_sys.latte` — full read. Static activity table. Faithful.
- [x] `menubar.tpl` ↔ `menubar.latte` — full read. Admin menu block ordering form. `$block['reg']->getName()|translate` plain, `=math(equation: "abs(pos)", pos: ...)` rewritten. Faithful.
- [x] `navigation_bar.tpl` ↔ `navigation_bar.latte` — full read. Plain pagination markup. Faithful.
- [~] `notification_by_mail.tpl` ↔ `notification_by_mail.latte` — full read. `$DOUBLE_SELECT` auto-Html. `$u['CHECKED']` ('checked="checked"' attribute fragment) hand-fixed `|noescape` in tag context (line 91). `$u['USERNAME']`/`$u['EMAIL']`/`$u['LAST_SEND']` plain.
- [~] `permalinks.tpl` ↔ `permalinks.latte` — full read. `$SORT_*` and `$SORT_OLD_*` Html-wrapped at producer (MiscController::parseSortVariables — builds `<a href>...<em>↓</em></a>`). JSON script `|noescape` ✓. `$permalink[*]` plain DB values.
- [~] `photos_add_applications.tpl` ↔ `photos_add_applications.latte` — full read. `<em>Piwigo for iOS/Android</em>` translate strings hand-fixed `|noescape` (lines 25, 37) — converter pass `addNoescapeToHtmlBearingTranslations` covers on regen.
- [~] `photos_add_direct.tpl` ↔ `photos_add_direct.latte` — full read. `$ADD_TO_ALBUM` and `$selected_category_name` Html-wrapped at producer (DirectPreparer:83,96 — getCatDisplayNameCache returns HTML breadcrumb). JSON script `|noescape` ✓. `$FORMATS_ORIGINAL_INFO['name'/'formats'/'ext']` plain Lang::t. `$setup_errors`/`$setup_warnings` plain text.
- [x] `photos_add_ftp.tpl` ↔ `photos_add_ftp.latte` — full read. `$FTP_HELP_CONTENT` Html-wrapped at PhotoController:719.
- [x] `picture_coi.tpl` ↔ `picture_coi.latte` — full read. COI form, plain markup. Faithful.
- [x] `picture_formats.tpl` ↔ `picture_formats.latte` — full read. `$page_data_json|noescape` ✓. `$FORMATS` data array. Faithful.
- [~] `picture_modify.tpl` ↔ `picture_modify.latte` — full read. `$INTRO['size']` Html-wrapped at producer (PhotoController:322 — splices literal `&times;` HTML entity). `$related_categories[*]['name']` and `$STORAGE_CATEGORY` Html-wrapped at producer (PhotoController:357,361 — getCatDisplayNameCache HTML breadcrumb). JSON script `|noescape` ✓. `htmlOptions|noescape` ✓. `$INTRO` other fields plain.
- [~] `plugins_installed.tpl` ↔ `plugins_installed.latte` — full read. `$author` and `$version` built via `|sprintf:`/`|cat:` produce HTML (`<a>...</a>`, `<u>...</u>`). Print sites hand-fixed `|noescape` (line 115, 117, 128) — covers translate-arg HTML splice + plugin DESC HTML markup. `htmlOptions|noescape` ✓. JSON script `|noescape` ✓.
- [x] `plugins_new.tpl` ↔ `plugins_new.latte` — full read. `$plugin['BIG_DESC']|nl2br` — Latte's nl2br htmlspecialchars-escapes input first (safer than Smarty's escape_html=false; plugin-author-controlled descriptions correctly not trusted). Plain text otherwise. `htmlOptions|noescape` ✓.
- [x] `popuphelp.tpl` ↔ `popuphelp.latte` — full read. `$HELP_CONTENT` Html-wrapped at MiscController.
- [x] `queue.tpl` ↔ `queue.latte` — full read. Queue status/failed-jobs table. `data-confirm='{"\"...\""|translate}'` (escaped-quote translation literal) preserved verbatim from .tpl. Faithful.
- [x] `rating.tpl` ↔ `rating.latte` — full read. `$image[*]` and `$rate[*]` all plain numeric/text. `htmlOptions|noescape` ✓ (×2). JSON script `|noescape` ✓.
- [~] `rating_user.tpl` ↔ `rating_user.latte` — full read. `|replace:' ','<br>'` produces `<br>` HTML; hand-fixed `|noescape` (×2, lines 45, 46). `{capture $rate_over}{foreach … {breakIf …}…}{/capture}` for thumbnail tooltip ✓.
- [x] `site_manager.tpl` ↔ `site_manager.latte` — full read. Site list with synchronize/delete actions. Faithful.
- [x] `site_update.tpl` ↔ `site_update.latte` — full read. `$L_RESULT_*` plain Lang::t. `$update_result[*]`/`$metadata_result[*]` plain numeric stats. `$sync_errors[*]` plain labels. `$METADATA_LIST` comma-list of metadata field names. `htmlOptions|noescape` ✓ (×2).
- [x] `stats.tpl` ↔ `stats.latte` — full read. JSON encode in single-quoted attributes (`data-hours='{json_encode(...)}'`); attribute escape preserves `"` as `&quot;`, browser dataset returns decoded — JSON.parse works.
- [x] `tabsheet.tpl` ↔ `tabsheet.latte` — full read. `$tabsheet[*]['caption']` plain l10n text, `['url']` plain.
- [~] `tags.tpl` ↔ `tags.latte` — full read. `$warning_tags` Html-wrapped at producer (MiscController:383 — orphan-tag review link). `$message_tags` plain Lang::t. JSON `data-tags` ✓. `{$tag_name}`, `{$tag_raw_name}`, `{$tag_count|translate_dec:...}` plain.
- [~] `themes_installed.tpl` ↔ `themes_installed.latte` — full read. `$author`/`$version` HTML produced via `|sprintf:`/`|cat:` (same pattern as plugins_installed). Print sites hand-fixed `|noescape` (lines 51, 53, 56). `$theme['DESC']` `|noescape` (theme description may contain HTML).
- [x] `themes_new.tpl` ↔ `themes_new.latte` — full read. theme metadata from API; plain.
- [x] `themes_standard_pages.tpl` ↔ `themes_standard_pages.latte` — full read. Checkbox/radio config form. `$std_pgs_skin_options` foreach over skin id strings. Plain.
- [x] `updates_ext.tpl` ↔ `updates_ext.latte` — full read. Extension update list. Faithful.
- [~] `updates_pwg.tpl` ↔ `updates_pwg.latte` — full read. `<a href="%s">` HTML translate strings hand-fixed `|noescape` (lines 54, 99). JSON script `|noescape` ✓. `$STEP`/`$DEV_VERSION`/version strings plain.
- [~] `upgrade.tpl` ↔ `upgrade.latte` — full read. `<strong>release %s</strong>` HTML translate hand-fixed `|noescape` (line 59). `htmlOptions|noescape` ✓. `$introduction['F_ACTION']`/version strings plain.
- [x] `user_activity.tpl` ↔ `user_activity.latte` — full read. JSON scripts `|noescape` ✓ (×2). `$ulist[*]`, `$ACTIONS[*]`, `$ADDITIONAL_FILT['name']` plain. JS-driven detail rendering.
- [x] `user_list.tpl` ↔ `user_list.latte` — full read (1069 lines). Mostly JS-rendered UI templates with placeholder text. JSON script `|noescape` ✓. `htmlOptions|noescape` ✓ (×6). All variable interpolations are plain text. Tooltip titles with literal HTML inside attributes (lines 509-515, 963-969) are template literals, not vars — Latte does not escape them.
- [~] `user_perm.tpl` ↔ `user_perm.latte` — full read. `$TITLE` plain Lang::t. `$categories_because_of_groups` entries Html-wrapped at producer (UsersController:312, getCatDisplayNameCache returns HTML breadcrumb).

## themes/admin/\_base/template/include (6)

- [x] `add_album.inc.tpl` ↔ `add_album.inc.latte` — full read. Form for add-album popin. Faithful.
- [x] `album_selector.inc.tpl` ↔ `album_selector.inc.latte` — full read. `{capture $inc_album_selector}1{/capture}` idempotency guard. JSON script `|noescape` ✓.
- [x] `autosize.inc.tpl` ↔ `autosize.inc.latte` — comment-only file (CSS does the work).
- [~] `batch_manager_filter.inc.tpl` ↔ `batch_manager_filter.inc.latte` — full read. Filter form (prefilter/category/tags/level/dimension/filesize/search). Hand-fixed `{include 'quick_search.latte', dark_mode:…}` → `is_dark_mode:…` (the .tpl convention passed `dark_mode` but quick_search uses `$is_dark_mode`; Smarty's auto-propagation hid the bug, Latte's strict scoping surfaces it).
- [x] `colorbox.inc.tpl` ↔ `colorbox.inc.latte` — full read. Conditional `$load_mode` default + script combine.
- [x] `datepicker.inc.tpl` ↔ `datepicker.inc.latte` — full read. Same pattern as colorbox.

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

| Var                           | Source                                                                    | Found in templates                                                                    |
| ----------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `MENUBAR`                     | `BlockManager::apply` ← `menubar` handle                                  | `about.latte:1`, `comments.latte:1`, others (header/index will surface when reviewed) |
| `ADMIN_CONTENT`               | every admin controller's `assignVarFromHandle('ADMIN_CONTENT', '<page>')` | likely `admin.latte`                                                                  |
| `CATEGORIES`                  | `CategoryCatsRenderer` ← `index_category_thumbnails`                      | likely `index.latte`                                                                  |
| `THUMBNAILS`                  | `CategoryDefaultRenderer` ← `index_thumbnails`                            | likely `index.latte`                                                                  |
| `COMMENT_LIST`                | `PictureCommentRenderer`/`CommentsController` ← `comment_list`            | `comments.latte:107`, `picture.latte:305`                                             |
| `DOUBLE_SELECT`               | various ← `double_select`                                                 | likely group/user perm pages                                                          |
| `PROFILE_CONTENT`             | `ProfileController` ← `profile_content`                                   | `profile.latte`                                                                       |
| `SELECTED_TAGS_TEMPLATE`      | `SelectedTagsRenderer` ← `selected_tags`                                  | tag pages                                                                             |
| `GLOBAL_MAIL_CSS`, `MAIL_CSS` | `MailService` ← `global-css` / `css`                                      | mail/header.latte                                                                     |
| `CONTENT` (mail context)      | `MailService::540ish` `assign('CONTENT', $mailContent)`                   | mail templates only                                                                   |
| Tabsheet output               | `Tabsheet::assignVarFromHandle($this->name, 'tabsheet')`                  | wherever `{$tabsheet_name}` is printed                                                |

Plus less obvious but commonly HTML-bearing:

- `ABOUT_MESSAGE`, `THEME_ABOUT`, `$elt` in `$about_msgs` (free-form admin-set HTML)
- `HELP_CONTENT` (help.latte already has `|noescape`)
- `LEVEL_SEPARATOR` — default `/` is plain text, but admins can configure `&raquo;` etc. Conservative: add `|noescape`.
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
~40 .latte files across themes/\_base, themes/admin, and themes/standard_pages.

### Systemic — Smarty bareword args become bare identifiers in Latte

`{html_options name=theme ...}` — Smarty treats `theme` as the string
`'theme'`. Converter's `parseSmartyArgs` faithfully relayed the bareword
into Latte's named-arg syntax: `htmlOptions(name: theme, ...)` — Latte
then evaluates `theme` as an undefined constant or variable. Fixed in
`Converter::normalizeArgValue` by quoting bareword identifiers (skipping
`true`/`false`/`null`).

### Per-pair findings

**Admin producer-side Html wraps applied during the end-to-end pass:**

- `MiscController::parseSortVariables` (used by permalinks) — `SORT_*`/`SORT_OLD_*`
  built as `<a href="...">↓</a>` link markup. Wrapped Html.
- `CheckIntegrity::display` — `correction_msg` and `correction_error_fct`
  contain `<br>` + `getHtlmLinksMoreInfo()` HTML. Wrapped Html at producer.
- `DirectPreparer::prepare` — `ADD_TO_ALBUM` and `selected_category_name`
  (HTML breadcrumb from `getCatDisplayNameCache()`). Wrapped Html.
- `PhotoController::picture_modify` — `INTRO['size']` (literal `&times;`),
  `related_categories[*]['name']`, `STORAGE_CATEGORY` (HTML breadcrumb).
  Wrapped Html.
- `MiscController::tags` — `warning_tags` (orphan-tag review link sprintf'd
  with HTML). Wrapped Html.
- `InstallController::displayForm` — `EMAIL` (`<span class="adminEmail">…`,
  user input htmlspecialchars-escaped before splicing) and `L_INSTALL_HELP`
  (`<a href>` link). Wrapped Html.

**Admin template-side `|noescape` hand-fixes applied during the end-to-end pass:**

- `themes/admin/_base/template/footer.latte:9` — `{$elt|noescape}` for
  `$footer_elements` foreach (admin maintenance/search controllers push
  HTML comment debug payloads).
- `themes/admin/_base/template/notification_by_mail.latte:91` — `{$u['CHECKED']|noescape}`
  (HTML attribute fragment `'checked="checked"'` in tag context).
- `themes/admin/_base/template/install.latte:155` — `{='Subscribe %s …'|translate:$EMAIL|noescape}`
  (translate sprintf substitutes Html as plain string; trust must be re-marked).
- `themes/admin/_base/template/plugins_installed.latte:115,117,128` —
  `|noescape` on `{$author}`/`{$version}`/`$plugin['DESC']` print sites
  (sprintf/cat-built HTML, plus plugin-author-controlled description).
- `themes/admin/_base/template/themes_installed.latte:51,53,56` — same
  pattern as plugins_installed.
