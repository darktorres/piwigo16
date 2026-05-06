# Free-Function Inventory

Snapshot of all remaining PHP free functions outside `src/` (where zero free functions exist).
Generated 2026-05-06. Excludes `vendor/`, `tools/`, `plugins/`, `tests/`.

---

## Summary

| Category | ~Count | Deletion gate |
|---|---|---|
| One-line ServiceLocator delegates (`functions_*.inc.php`) | ~500 | Call-site sweep (unlisted step after #19) |
| `ws_functions/*.php` one-line delegates | 98 | Already backed by WS endpoint classes; delete after confirming no external callers |
| Pre-boot standalones — permanent | ~50 | Must stay: called before `Kernel::boot()` (fatal\_error, l10n, load\_language, etc.) |
| `admin/include/` delegates | ~133 | Admin call-site sweep (part of #22 cleanup) |
| Install / upgrade scaffolding | 13 | 🔒 Out of scope — pre-service-layer bootstrap |
| Derivative URL helpers | 5 | 🔒 Permanent — no service boundary benefit |
| `image_derivative_functions.php` | 7 | Tied to `i.php` fast-path; partly permanent |
| **Total (excl. plugins)** | **~623** | |

---

## File-by-file breakdown

### `include/`

| File | Functions | Category | Notes |
|---|---|---|---|
| `functions.inc.php` | 207 | Mixed — see below | Largest file; ~170 one-line delegates, ~37 real-logic standalones |
| `functions_user.inc.php` | 60 | One-line delegates | All call `PermissionService` / `AuthService` / `UserService` / `PreferencesService` |
| `functions_url.inc.php` | 21 | One-line delegates | All call `UrlService` |
| `functions_search.inc.php` | 17 | One-line delegates | All call `SearchService` |
| `functions_plugins.inc.php` | 11 | Mixed | 6 event-system delegates + 2 `PluginService` delegates + 2 factory helpers (stay) + 1 define block |
| `functions_cookie.inc.php` | 3 | One-line delegates | `CookieService` |
| `functions_calendar.inc.php` | 1 | One-line delegate | `CalendarService` |
| `functions_filter.inc.php` | 1 | One-line delegate | `FilterService` |
| `derivative_params.inc.php` | 5 | 🔒 Permanent | Low-level URL helpers (`derivative_to_url`, `size_to_url`, `size_equals`, `char_to_fraction`, `fraction_to_char`) — no service boundary benefit |
| `image_derivative_functions.php` | 7 | Mostly permanent | `i.php` fast-path helpers: `ierror`, `parse_request`, `send_derivative`, `safe_unserialize` (also in functions.inc.php), `get_derivative_storage`, `mkgetdir` (dup), `get_remote_addr_session_hash` (dup) |
| `common.inc.php` | 1 | Pre-boot standalone | `get_default_theme()` — called before Kernel::boot() |
| `ws_default_methods.php` | 2 | One-line delegates | `ws_addDefaultMethods`, `ws_isInvokeAllowed` — delegates to WS endpoint classes |

#### `include/ws_functions/`

| File | Functions | Notes |
|---|---|---|
| `pwg.images.php` | 29 | One-line delegates → `ImagesEndpoints` |
| `pwg.users.php` | 16 | One-line delegates → `UsersEndpoints` |
| `pwg.php` | 13 | One-line delegates → `GeneralEndpoints` |
| `pwg.tags.php` | 8 | One-line delegates → `TagsEndpoints` |
| `pwg.groups.php` | 8 | One-line delegates → `GroupsEndpoints` |
| `pwg.categories.php` | 12 | One-line delegates → `CategoriesEndpoints` |
| `pwg.extensions.php` | 6 | One-line delegates → `ExtensionsEndpoints` |
| `pwg.permissions.php` | 3 | One-line delegates → `PermissionsEndpoints` |
| `pwg.comments.php` | 3 | One-line delegates → `CommentsEndpoints` |
| **Total** | **98** | All deletable once external callers are confirmed gone |

---

### `admin/include/`

| File | Functions | Category | Notes |
|---|---|---|---|
| `functions.php` | 80 | One-line delegates | All call `CategoryAdminService` / `ImageAdminService` / `TagAdminService` / `UserAdminService` / `AdminService` |
| `functions_upload.inc.php` | 21 | One-line delegates | All call `UploadService` |
| `functions_notification_by_mail.inc.php` | 15 | One-line delegates | All call `NotificationAdminService` |
| `functions_metadata.php` | 7 | One-line delegates | `MetadataAdminService` |
| `functions_history.inc.php` | 6 | One-line delegates | `HistoryAdminService` |
| `functions_permalinks.php` | 4 | One-line delegates | `PermalinkRepository` calls |
| `functions_plugins.inc.php` | 1 | One-line delegate | `PluginService` |
| `add_core_tabs.inc.php` | 1 | Free function | `add_core_tabs()` — builds tab sheets for the admin header; candidate for `AdminService` |
| `functions_upgrade.php` | 9 | 🔒 Out of scope | Upgrade-flow bootstrap (`prepare_conf_upgrade`, SQL file execution); runs before service layer |
| `functions_install.inc.php` | 4 | 🔒 Out of scope | Install-flow only (`execute_sqlfile`, etc.); pre-service-layer |

---

## `include/functions.inc.php` detail

The single largest file. 207 functions in three categories:

### One-line ServiceLocator delegates (~170)

Ready to delete once call sites in `include/` are updated. Grouped by service:

| Service | Delegates | Example functions |
|---|---|---|
| `StringUtil` | ~15 | `get_extension`, `get_filename_wo_extension`, `str2url`, `remove_accents`, `input_int/string/bool` |
| `DateService` | ~7 | `format_date`, `format_fromto`, `time_since`, `dateDiff` |
| `CategoryService` | ~15 | `get_cat_info`, `get_categories_menu`, `get_subcat_ids`, `get_display_images_count` |
| `TagService` | ~9 | `get_available_tags`, `get_common_tags`, `tags_counter_compare` |
| `HtmlService` | ~22 | `get_cat_display_name`, `set_status_header`, `page_not_found`, `render_element_name` |
| `PictureService` | ~6 | `decode_slideshow_params`, `encode_slideshow_params`, `increase_image_visit_counter` |
| `RateService` | ~2 | `rate_picture`, `update_rating_score` |
| `MetadataService` | ~5 | `get_iptc_data`, `get_exif_data`, `clean_iptc_value` |
| `CommentService` | ~8 | `insert_user_comment`, `delete_user_comment`, `get_comment_author_id` |
| `NotificationService` | ~17 | `nb_new_elements`, `new_comments`, `news`, `news_exists` |
| `MailService` | ~23 | `pwg_mail`, `switch_lang_to/back`, `pwg_generate_reset_password_mail` |
| `SessionService` | ~11 | `pwg_set_session_var`, `pwg_get_session_var`, `pwg_session_*` |
| `Util` | ~28 | `pwg_log`, `pwg_activity`, `check_lounge`, `check_pwg_token`, `get_icon`, `fill_caddie` |
| `ConfigService` | ~3 | `conf_update_param`, `conf_get_param`, `conf_delete_param` |
| `QueryHelper` | ~3 | `simple_hash_from_query`, `hash_from_query`, `array_from_query` |

### Pre-boot standalones — permanent or gated on Latte/MVC (~37)

These **cannot** be deleted yet because they are called before `Kernel::boot()` or implement cross-cutting concerns that no single typed service owns:

| Function | Why it stays |
|---|---|
| `fatal_error()` | Pre-boot error renderer; called from `Template`, `ConfigLoader`, `common.inc.php` before any service |
| `l10n()`, `l10n_dec()`, `l10n_args()`, `get_l10n_args()` | Pre-boot i18n; `redirect_html()` calls these before boot |
| `load_language()` | Pre-boot language file loader; delegates to `LangService` internally but must run before boot |
| `load_conf_from_db()` | Called by `common.inc.php` before `Kernel::boot()` |
| `redirect_html()`, `redirect_http()`, `redirect()` | Pre-boot redirect path used in error flows |
| `mkgetdir()` | Pre-boot directory creator; guards on `Config::dbName() === ''` |
| `script_basename()` | Pre-boot script identification |
| `get_root_url()`, `cookie_path()` | Pre-boot URL helpers |
| `generate_key()` | Crypto helper; no service dependency |
| `get_branch_from_version()` | Pure string transform; used in install/upgrade flows |
| `get_parent_language()`, `safe_unserialize()` | Language + data loading helpers |
| `get_languages()` | Language list; has pre-boot fallback path |
| `check_status()`, `check_restrictions()` | Auth guards called in controllers — candidates for `PermissionService` but currently rely on globals |
| `is_a_guest()`, `is_admin()`, `is_classic_user()`, `is_autorize_status()` etc. | Status-check wrappers; delegate to `PermissionService` but called from Smarty templates too |

---

## Deletion plan

### Phase A — `include/ws_functions/` (98 functions, 9 files)

All 9 files are one-line delegates to the WS endpoint classes already in `src/Piwigo/Ws/Method/`. Confirm no external plugins call these free functions directly, then delete all 9 files and remove the `require_once` from `WsController`.

**Gate:** confirm no external callers, remove `require_once` of `ws_functions/*.php` in `WsController`.

---

### Phase B — `admin/include/` delegates (~133 functions, 8 files)

All call sites in `src/` already use typed services directly. The `require_once` calls to these files come from `admin/include/functions.php` (the loader file) and from legacy scripts. Sweep remaining call sites, then delete.

**Gate:** audit `admin/include/functions.php` for any `require_once` of sub-files; check that no `include/*.inc.php` file calls these free functions.

---

### Phase C — `include/functions_*.inc.php` delegates (~500 functions)

Call sites in `src/` already use typed services. Call sites in `include/` still use free functions (e.g. `functions_user.inc.php` calling `functions.inc.php` helpers). The last step is sweeping the remaining callers in `include/` itself.

**Gate:** after Tier 3 rendering includes are replaced (Latte / #23), the remaining call sites in `include/` will be pre-boot standalones only. At that point the delegate files can be deleted one by one.

---

### Permanent / out-of-scope (do not delete)

| File | Reason |
|---|---|
| `include/derivative_params.inc.php` | 5 URL helpers; no service boundary benefit |
| `admin/include/functions_upgrade.php` | Upgrade bootstrap; pre-service-layer |
| `admin/include/functions_install.inc.php` | Install bootstrap; pre-service-layer |
| Pre-boot standalones in `functions.inc.php` | Listed above; must stay until MVC/Latte fully replaces page lifecycle |
