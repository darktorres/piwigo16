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

- [ ] `local_head.tpl` ↔ `local_head.latte`

## themes/_base/template (36)

- [ ] `about.tpl` ↔ `about.latte`
- [ ] `comment_list.tpl` ↔ `comment_list.latte`
- [ ] `comments.tpl` ↔ `comments.latte`
- [ ] `footer.tpl` ↔ `footer.latte`
- [ ] `header.tpl` ↔ `header.latte`
- [ ] `identification.tpl` ↔ `identification.latte`
- [ ] `index.tpl` ↔ `index.latte`
- [ ] `infos_errors.tpl` ↔ `infos_errors.latte`
- [ ] `mainpage_categories.tpl` ↔ `mainpage_categories.latte`
- [ ] `menubar.tpl` ↔ `menubar.latte`
- [ ] `menubar_categories.tpl` ↔ `menubar_categories.latte`
- [ ] `menubar_identification.tpl` ↔ `menubar_identification.latte`
- [ ] `menubar_links.tpl` ↔ `menubar_links.latte`
- [ ] `menubar_menu.tpl` ↔ `menubar_menu.latte`
- [ ] `menubar_related_categories.tpl` ↔ `menubar_related_categories.latte`
- [ ] `menubar_specials.tpl` ↔ `menubar_specials.latte`
- [ ] `menubar_tags.tpl` ↔ `menubar_tags.latte`
- [ ] `month_calendar.tpl` ↔ `month_calendar.latte`
- [ ] `navigation_bar.tpl` ↔ `navigation_bar.latte`
- [ ] `nbm.tpl` ↔ `nbm.latte`
- [ ] `no_photo_yet.tpl` ↔ `no_photo_yet.latte`
- [ ] `notification.tpl` ↔ `notification.latte`
- [ ] `password.tpl` ↔ `password.latte`
- [ ] `picture.tpl` ↔ `picture.latte`
- [ ] `picture_content.tpl` ↔ `picture_content.latte`
- [ ] `picture_nav_buttons.tpl` ↔ `picture_nav_buttons.latte`
- [ ] `popuphelp.tpl` ↔ `popuphelp.latte`
- [ ] `profile.tpl` ↔ `profile.latte`
- [ ] `profile_content.tpl` ↔ `profile_content.latte`
- [ ] `redirect.tpl` ↔ `redirect.latte`
- [ ] `register.tpl` ↔ `register.latte`
- [ ] `search.tpl` ↔ `search.latte`
- [ ] `search_rules.tpl` ↔ `search_rules.latte`
- [ ] `slideshow.tpl` ↔ `slideshow.latte`
- [ ] `tags.tpl` ↔ `tags.latte`
- [ ] `thumbnails.tpl` ↔ `thumbnails.latte`

## themes/_base/template/include (5)

- [ ] `autosize.inc.tpl` ↔ `autosize.inc.latte`
- [ ] `colorbox.inc.tpl` ↔ `colorbox.inc.latte`
- [ ] `related_tags.inc.tpl` ↔ `related_tags.inc.latte`
- [ ] `search_filters.inc.tpl` ↔ `search_filters.inc.latte`
- [ ] `selected_tags.inc.tpl` ↔ `selected_tags.inc.latte`

## themes/_base/template/help (1)

- [ ] `quick_search.tpl` ↔ `quick_search.latte`

## themes/_base/template/mail/text/html (8)

- [ ] `cat_group_info.tpl` ↔ `cat_group_info.latte`
- [ ] `footer.tpl` ↔ `footer.latte`
- [ ] `global-mail-css.tpl` ↔ `global-mail-css.latte`
- [ ] `header.tpl` ↔ `header.latte`
- [ ] `mail-css-dark.tpl` ↔ `mail-css-dark.latte`
- [ ] `mail-css-light.tpl` ↔ `mail-css-light.latte`
- [ ] `notification_admin.tpl` ↔ `notification_admin.latte`
- [ ] `notification_by_mail.tpl` ↔ `notification_by_mail.latte`

## themes/_base/template/mail/text/plain (5)

- [ ] `cat_group_info.tpl` ↔ `cat_group_info.latte`
- [ ] `footer.tpl` ↔ `footer.latte`
- [ ] `header.tpl` ↔ `header.latte`
- [ ] `notification_admin.tpl` ↔ `notification_admin.latte`
- [ ] `notification_by_mail.tpl` ↔ `notification_by_mail.latte`

## themes/standard_pages/template (7)

- [ ] `footer.tpl` ↔ `footer.latte`
- [ ] `header.tpl` ↔ `header.latte`
- [ ] `identification.tpl` ↔ `identification.latte`
- [ ] `password.tpl` ↔ `password.latte`
- [ ] `profile.tpl` ↔ `profile.latte`
- [ ] `register.tpl` ↔ `register.latte`
- [ ] `toaster.tpl` ↔ `toaster.latte`

## themes/admin/_base/template (64)

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

- [ ] `add_album.inc.tpl` ↔ `add_album.inc.latte`
- [ ] `album_selector.inc.tpl` ↔ `album_selector.inc.latte`
- [ ] `autosize.inc.tpl` ↔ `autosize.inc.latte`
- [ ] `batch_manager_filter.inc.tpl` ↔ `batch_manager_filter.inc.latte`
- [ ] `colorbox.inc.tpl` ↔ `colorbox.inc.latte`
- [ ] `datepicker.inc.tpl` ↔ `datepicker.inc.latte`

---

## Findings log

(append per-pair notes here as the audit progresses; reference the
checkbox above by directory + filename)
