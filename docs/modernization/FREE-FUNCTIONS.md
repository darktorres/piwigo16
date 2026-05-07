# Free-Function Inventory

Snapshot of all remaining PHP free functions outside `src/` (where zero free functions exist).
Updated 2026-05-06. Excludes `vendor/`, `tools/`, `plugins/`, `tests/`.

---

## Summary

| File | Functions | Deletable delegates | Permanent |
|---|---|---|---|
| `functions.inc.php` | 207 | ~169 | ~38 pre-boot standalones |
| `functions_user.inc.php` | 60 | ~48 | ~12 real auth logic |
| `functions_url.inc.php` | 21 | ~20 | 1 pre-boot (`get_root_url`) |
| `functions_plugins.inc.php` | 12 | ~6 | 6 (event system + factories) |
| `functions_cookie.inc.php` | 3 | 2 | 1 pre-boot (`cookie_path`) |
| `image_derivative_functions.php` | 7 | 0 | 7 (fast-path) |
| `derivative_params.inc.php` | 5 | 0 | 5 (URL helpers) |
| `common.inc.php` | 1 | 0 | 1 pre-boot |
| **Total** | **316** | **~245** | **~71** |

**Completed phases:**
- ✅ **Phase A** — `include/ws_functions/` (98 functions, 9 files) deleted
- ✅ **Phase B** — `admin/include/` delegates (~133 functions, 8 files) deleted; `add_core_tabs`, `functions_upgrade`, `functions_install` migrated to typed classes
- ✅ `include/ws_default_methods.php` deleted
- ✅ `include/functions_search.inc.php`, `functions_calendar.inc.php`, `functions_filter.inc.php` deleted
- ✅ `include/picture_functions.php`, `feed_functions.php`, `password_functions.php`, `profile_functions.php` deleted

---

## File-by-file breakdown

### `include/`

| File | Functions | Category | Notes |
|---|---|---|---|
| `functions.inc.php` | 207 | Mixed — see below | Largest file; ~169 one-line delegates, ~38 real-logic standalones |
| `functions_user.inc.php` | 60 | Mixed | ~12 real-logic functions (`log_user`, `auto_login`, `register_user`, `build_user`, etc.); ~48 delegates to `AuthService` / `UserService` / `PermissionService` / `PreferencesService` |
| `functions_url.inc.php` | 21 | Mixed | 19 `UrlService` delegates; `get_root_url()` is pre-boot permanent; `get_user_favorites()` is misplaced (belongs in `functions_user`) |
| `functions_plugins.inc.php` | 12 | Mixed | 4 event-system core (`add/remove_event_handler`, `trigger_change/notify` — permanent); 6 `PluginService` delegates; 2 factory helpers (`instantiate_*_maintain` — permanent) |
| `functions_cookie.inc.php` | 3 | Mixed | `cookie_path()` is pre-boot permanent; `pwg_set/get_cookie_var` delegate to `CookieService` |
| `derivative_params.inc.php` | 5 | 🔒 Permanent | Low-level URL helpers (`derivative_to_url`, `size_to_url`, `size_equals`, `char_to_fraction`, `fraction_to_char`) — no service boundary benefit |
| `image_derivative_functions.php` | 7 | Mostly permanent | Fast-path helpers for `index.php?/i/` bypass: `ierror`, `time_step`, `url_to_size`, `parse_custom_params`, `parse_request`, `try_switch_source`, `send_derivative` |
| `common.inc.php` | 1 | Pre-boot standalone | `sanitize_mysql_kv()` — inline helper for `array_walk_recursive` on `$_GET`/`$_POST`/`$_COOKIE` |

#### `include/ws_functions/`

Only `index.php` (security redirect) remains. All 9 function files (98 one-line delegates) were deleted in Phase A.

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

### Pre-boot standalones — permanent or gated on Latte/MVC (~38)

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

### Phase C — `include/functions_*.inc.php` delegates (~245 deletable)

Call sites in `src/` already use typed services. Call sites in `include/` still use free functions. The last step is sweeping the remaining callers in `include/` itself.

**Files:**
- `functions.inc.php` (~170 delegates)
- `functions_user.inc.php` (60 delegates)
- `functions_url.inc.php` (21 delegates)
- `functions_plugins.inc.php` (~10 delegates)
- `functions_cookie.inc.php` (3 delegates)

**Gate:** after Tier 3 rendering includes are replaced (Latte / #23), the remaining call sites in `include/` will be pre-boot standalones only. At that point the delegate files can be deleted one by one.

---

### Permanent / out-of-scope (do not delete)

| File | Reason |
|---|---|
| `include/derivative_params.inc.php` | 5 URL helpers; no service boundary benefit |
| `include/image_derivative_functions.php` | Fast-path helpers for `index.php?/i/` bypass; partly permanent |
| Pre-boot standalones in `functions.inc.php` | Listed above; must stay until MVC/Latte fully replaces page lifecycle |
