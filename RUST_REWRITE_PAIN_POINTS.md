# Piwigo Rust Rewrite — Pain Point Deep Dive

> Detailed technical investigation of every identified pain point for the ground-up Rust rewrite.
> Companion document to `RUST_REWRITE_PLAN.md`.

---

## Table of Contents

1. [Plugin & Hook System](#1-plugin--hook-system)
2. [Dynamic SQL & Query Safety](#2-dynamic-sql--query-safety)
3. [Template System & Asset Pipeline](#3-template-system--asset-pipeline)
4. [Permission System & Caching](#4-permission-system--caching)
5. [Filesystem Sync Complexity](#5-filesystem-sync-complexity)
6. [Image Pipeline & Derivative System](#6-image-pipeline--derivative-system)
7. [Admin Batch Operations & Upload Pipeline](#7-admin-batch-operations--upload-pipeline)
8. [Session Serialization & Auth Edge Cases](#8-session-serialization--auth-edge-cases)

---

## 1. Plugin & Hook System

**Severity: CRITICAL — This is the single hardest architectural problem in the rewrite.**

### 1.1 Hook Inventory

The codebase has **222 hook call sites** across 52 files, invoking **132 unique event names**.

| Metric | Count |
|---|---|
| `trigger_notify()` call sites | 105 |
| `trigger_change()` call sites | 117 |
| Unique notify events | 71 |
| Unique change events | 61 |
| Built-in plugins | 6 |
| Total handler registrations by built-in plugins | 28 |

### 1.2 Hook Type Classification

Every hook falls into one of these categories:

| Category | Count | Lua-Safe | Examples |
|---|---|---|---|
| **Page lifecycle** (`loc_begin_*`, `loc_end_*`) | ~40 | YES | `loc_begin_index`, `loc_end_picture` |
| **Text rendering** (`render_*`) | 12 | YES | `render_category_name`, `render_comment_content` |
| **Object lifecycle** (`delete_*`, `create_*`) | 8 | YES | `delete_elements`, `create_virtual_category` |
| **Auth/session** | 6 | PARTIAL | `try_log_user`, `user_init`, `login_success` |
| **SQL query modification** | 5 | YES (string ops) | `loc_begin_index_category_thumbnails_query`, `qsearch_get_images_sql_scopes` |
| **Batch operations** | 6 | YES | `batch_manager_register_filters`, `element_set_global_action` |
| **Block/template injection** | 4 | PARTIAL | `blockmanager_register_blocks`, `combinable_preparse` |
| **Image/derivative params** | 5 | PARTIAL | `get_src_image_url`, `get_index_derivative_params` |
| **Config/init** | 5 | YES | `load_conf`, `init`, `plugins_loaded` |
| **WS/API** | 3 | PARTIAL | `ws_add_methods`, `ws_invoke_allowed` |
| **Mail** | 4 | PARTIAL | `before_send_mail`, `nbm_render_global_customize_mail_content` |
| **Metadata/search** | 5 | PARTIAL | `format_exif_data`, `qsearch_results` |
| **Theme/extension management** | 5 | YES | `theme_activated`, `theme_deleted` |
| **CSS/JS combination** | 4 | YES | `combined_css`, `combined_script` |
| **Misc data filters** | ~20 | YES | `get_admin_plugin_menu_links`, `tabsheet_before_select` |

### 1.3 Security-Critical Hooks (5 hooks requiring special attention)

#### `try_log_user` — Authentication Interception

- **Type:** `trigger_change`
- **Location:** `inc/functions_user.php:1090`
- **Data:** `bool $success, string $username, string $password, bool $remember_me`
- **Default handler:** `pwg_login()` — queries DB, verifies bcrypt hash
- **Risk:** HIGH — a malicious plugin can bypass authentication entirely
- **Lua challenge:** Cannot call native `password_verify()` from Lua
- **Required Rust host API:**
  ```rust
  fn verify_password(hashed: &str, plaintext: &str) -> bool;
  fn log_user(user_id: i32, remember_me: bool);
  ```

#### `user_init` — User Context Mutation

- **Type:** `trigger_notify`
- **Location:** `inc/user.php:80`
- **Data:** `$user` array (passed by reference)
- **Critical consumer:** AdminTools MultiView — rebuilds the `$user` object as a different user, modifies `$conf` globals, and dynamically registers additional handlers (`pwg_log_allowed`, `pwg_log_update_last_visit`)
- **Risk:** HIGH — impersonates users, disables audit logging
- **Lua challenge:** Requires mutable access to request-scoped state + dynamic handler registration
- **Required Rust host APIs:**
  ```rust
  fn build_user(user_id: i32) -> LuaTable;
  fn config_set(key: &str, value: LuaValue);
  fn config_get(key: &str) -> LuaValue;
  fn register_event_handler(event: &str, callback: LuaFunction, priority: u32);
  fn get_url_param(name: &str) -> Option<String>;
  ```

#### `ws_invoke_allowed` — API Authorization Gate

- **Type:** `trigger_change`
- **Location:** `inc/PwgServer.php:383`
- **Data:** `bool $allowed, string $methodName, array $params`
- **Risk:** HIGH — controls access to all 84 API methods
- **Lua challenge:** Must be able to return `PwgError` objects
- **Required Rust host API:**
  ```rust
  fn create_pwg_error(code: i32, message: &str) -> PwgError;
  ```

#### `ws_add_methods` — API Method Registration

- **Type:** `trigger_notify`
- **Location:** `inc/ws_init.php`
- **Data:** `&PwgServer` (reference)
- **Risk:** HIGH — plugins can register arbitrary API endpoints
- **Lua challenge:** Needs `PwgServer` API to call `addMethod()`
- **Required Rust host API:**
  ```rust
  fn register_ws_method(
      name: &str, handler: LuaFunction,
      params: LuaTable, options: LuaTable
  );
  ```

#### `load_conf` — Configuration Override

- **Type:** `trigger_notify`
- **Location:** `inc/functions.php:1468`
- **Data:** `string $condition` (WHERE clause)
- **Risk:** HIGH — can override security settings after DB config load
- **Lua-safe:** YES (read/write config via host API)

### 1.4 SQL Query Modification Hooks

These 5 hooks pass raw SQL strings through handlers — the handler modifies the SQL:

| Hook | Data Type | Location | What It Modifies |
|---|---|---|---|
| `loc_begin_index_category_thumbnails_query` | `string` (full SQL) | `category_cats.php:75` | Category listing SQL — plugins inject WHERE clauses or JOIN tables |
| `get_tag_name_like_where` | `array` of WHERE clauses | `functions_admin.php:1822` | Tag search conditions |
| `qsearch_get_images_sql_scopes` | `array` of clauses | `functions_search.php:651` | Quick search SQL scopes |
| `qsearch_get_scopes` | `array` of scope definitions | `functions_search.php` | Search scope types |
| `get_category_menu_sql_where` | `string` SQL WHERE | `functions_category.php` | Category menu filtering |

**Rust implication:** Lua plugins that modify SQL create injection risk. The Rust host must either:
1. Validate returned SQL fragments (expensive, fragile)
2. Provide a structured query modification API instead of raw SQL
3. Accept the risk and document it as a plugin author responsibility

### 1.5 AdminTools — Proof-of-Concept for Lua Bridge

AdminTools is the most invasive plugin. Its MultiView feature:

1. Hooks `user_init` → reads URL params (`ato_view_as`, `ato_lang`, `ato_theme`, `ato_show_queries`, `ato_debug_l10n`, `ato_debug_template`, `ato_template_combine_files`)
2. Calls `build_user($view_as_id)` to reconstruct user object for impersonated user
3. Overwrites `$user` global with new user data
4. Modifies `$conf` globals (show_queries, debug flags)
5. Dynamically registers handlers for `pwg_log_allowed` → returns `false` (suppress logging)
6. Dynamically registers handlers for `pwg_log_update_last_visit` → returns `false`
7. Saves multiview state to session
8. Hooks `loc_after_page_header` → injects admin toolbar HTML
9. Hooks `loc_begin_picture` and `loc_begin_index` → handles quick-edit form submissions

**Minimum Lua bridge to replicate AdminTools:**
```lua
piwigo.event.register("user_init", function(ctx)
    local view_as = ctx:get_url_param("ato_view_as")
    if view_as then
        local new_user = ctx:build_user(tonumber(view_as))
        ctx:set_user(new_user)
        ctx:config_set("show_queries", ctx:get_url_param("ato_show_queries") == "1")
        ctx:event_register("pwg_log_allowed", function(do_log) return false end)
    end
end, 10) -- priority 10 (early)
```

### 1.6 Complete Rust Host API Requirements

**Tier 1 — CRITICAL (blocks basic plugin functionality):**

```rust
// Authentication
fn verify_password(hashed: &str, plaintext: &str) -> bool;
fn build_user(user_id: i32) -> LuaTable;
fn log_user(user_id: i32, remember_me: bool);

// Configuration
fn config_get(key: &str) -> LuaValue;
fn config_set(key: &str, value: LuaValue);

// Event system (dynamic registration from within handlers)
fn register_event_handler(event: &str, callback: LuaFunction, priority: u32);
fn unregister_event_handler(event: &str, handler_id: &str);

// Database (sandboxed)
fn db_query(sql: &str, params: Vec<LuaValue>) -> LuaTable;    // SELECT only for non-admin
fn db_execute(sql: &str, params: Vec<LuaValue>) -> u64;       // Admin plugins only
fn db_insert_id() -> i64;

// Template
fn template_assign(key: &str, value: LuaValue);
fn template_concat(key: &str, value: LuaValue);
fn template_set_prefilter(handle: &str, callback: LuaFunction, weight: u32);
```

**Tier 2 — HIGH (many hooks need these):**

```rust
// Session
fn session_get(key: &str) -> LuaValue;
fn session_set(key: &str, value: LuaValue);

// Request context
fn get_url_param(name: &str) -> Option<String>;
fn get_post_param(name: &str) -> Option<String>;
fn set_user(user_table: LuaTable);

// WS API
fn register_ws_method(name: &str, handler: LuaFunction, params: LuaTable, opts: LuaTable);
fn create_pwg_error(code: i32, message: &str) -> PwgError;

// Block manager
fn block_register(id: &str, name: &str, owner: &str);
fn block_set_data(id: &str, data: LuaTable);
fn block_set_template(id: &str, template: &str);

// Derivative params
fn get_derivative_params(type_name: &str) -> LuaTable;
```

**Tier 3 — MEDIUM (enhancement hooks):**

```rust
// i18n
fn l10n(key: &str) -> String;
fn l10n_dec(singular: &str, plural: &str, count: i32) -> String;

// Utilities
fn get_root_url() -> String;
fn get_pwg_token() -> String;
fn is_admin() -> bool;
fn redirect(url: &str);
```

### 1.7 Hooks NOT Safe for Lua Without Host APIs

| Hook | Reason | Required API |
|---|---|---|
| `try_log_user` | Needs password verification | `verify_password()` |
| `user_init` | Needs user object reconstruction + dynamic handler registration | `build_user()`, `register_event_handler()` |
| `ws_invoke_allowed` | Needs PwgError instantiation | `create_pwg_error()` |
| `ws_add_methods` | Needs method registration API | `register_ws_method()` |
| `blockmanager_*` | Needs BlockManager object API | `block_register()`, `block_set_data()` |
| `get_src_image_url` | Needs SrcImage object introspection | `get_src_image_path()` |
| `load_image_library` | Needs ability to inject image library | Not feasible in Lua — native only |
| `qsearch_*` | Needs QToken/QExpression types | Simplified search scope API |
| `combinable_preparse` | Needs Template + FileCombiner objects | `template_assign()` (sufficient) |
| `before_send_mail` | Needs mail object API | `mail_add_header()`, `mail_set_body()` |

**All other hooks (~90+)** are Lua-safe — they only manipulate strings, arrays, and primitive types.

---

## 2. Dynamic SQL & Query Safety

**Severity: HIGH — 523 raw queries, 4 confirmed vulnerable LIKE patterns, 0 ORDER BY injection risks.**

### 2.1 Query Pattern Taxonomy

| Pattern | Count | Safety | Rust Migration |
|---|---|---|---|
| Simple WHERE with integer IDs | ~250 | SAFE | Direct `sqlx::query!` |
| IN clause with `implode(safe_ids)` | ~80 | SAFE | `sqlx` array binding |
| LIKE with search words | ~12 | **VULNERABLE** | Must escape `%` and `_` before binding |
| String field interpolation (author, path) | ~15 | RISKY | Parameterize |
| Computed permission conditions (FandF) | ~19 | SAFE (pre-validated) | `QueryBuilder` pattern |
| ORDER BY | ~12 | SAFE (all hardcoded/whitelisted) | Enum-based column selection |
| BETWEEN with dates | ~4 | SAFE | Chrono date params |
| REGEXP with addslashes | ~2 | MOSTLY SAFE | Different escaping rules |
| GROUP_CONCAT + GROUP BY | ~2 | SAFE (whitelist) | Custom GROUP BY builder |

### 2.2 Confirmed Vulnerable Patterns

#### LIKE Injection (4 sites)

**`inc/functions_search.php:132`** — Search words directly in LIKE:
```php
$local_clauses[] = $field . " LIKE '%" . $word . "%'";
// $word comes from unserialized search data — no LIKE metacharacter escaping
```

**`inc/functions_search.php:181`** — Same pattern in allwords search.

**`inc/functions_search.php:224`** — Tag name search: `"WHERE name LIKE '%{$word}%'"`

**`inc/functions_search.php:340`** — File extension: `"path LIKE '%." . $ext . "'"`

**Rust fix:** All LIKE values must go through an escaping layer:
```rust
fn escape_like(value: &str) -> String {
    value.replace('\\', "\\\\")
         .replace('%', "\\%")
         .replace('_', "\\_")
}

// Then bind as parameter:
qb.push(" AND file LIKE ")
   .push_bind(format!("%{}%", escape_like(&word)));
```

#### IP Address Injection in History Logging

**`inc/functions.php:618`** — `$_SERVER['REMOTE_ADDR']` interpolated without escaping:
```php
$query .= "... '{$ip}' ...";
```

**Rust fix:** Always use bind parameter for IP address.

### 2.3 The 10 Most Complex Queries to Port

| # | Location | Complexity | Challenge |
|---|---|---|---|
| 1 | `functions_search.php:430` | 3 JOINs + 4 dynamic clauses | `search_clause` built from tokenized search input with AND/OR groups |
| 2 | `batch_manager.php:399` | GROUP_CONCAT + HAVING | Duplicate detection with dynamic GROUP BY columns (whitelist) |
| 3 | `functions_notification.php` | COUNT(DISTINCT) ×2 + JOIN | Date-grouped statistics with permission filtering |
| 4 | `CalendarBase.php` | SELECT DISTINCT + dynamic sql | Calendar levels with variable inner SQL |
| 5 | `functions_category.php:365` | UNION query | Permalink resolution across 2 tables |
| 6 | `functions_search.php:785` | Categories JOIN + MATCH/REGEXP | Full-text clause list varies by search token |
| 7 | `functions_user.php:563` | Favorites + category filter | Permission-filtered favorites list |
| 8 | `batch_manager.php:470` | Category image filtering | Category string implode in condition |
| 9 | `functions_search.php:206` | Tag/category image search | Multiple IN clauses from tag resolution |
| 10 | `batch_manager.php:507` | Dimension/ratio filtering | Multiple range WHEREs |

### 2.4 `get_sql_condition_FandF` — The Permission SQL Generator

Called from **22 files**, this function builds SQL WHERE conditions from cached permission data:

```php
// Generates conditions like:
// (category_id NOT IN (5,7,12) AND i.level <= 2 AND image_id NOT IN (100,205))
```

**Call sites by file:**
- `index.php` (4 calls) — gallery browsing
- `picture.php` (2 calls) — image detail
- `comments.php` (2 calls) — comments listing
- `inc/section_init.php` (3 calls) — section initialization
- `inc/functions_search.php` (1 call) — search results
- `inc/functions_category.php` (2 calls) — category queries
- `inc/ws_functions/pwg_*.php` (4 files) — API methods
- `action.php`, `random.php`, `search.php` — misc pages

**Rust equivalent:** A `PermissionConditions` struct with `apply_to(qb: &mut QueryBuilder)` method. All values go through bind parameters — never string interpolation.

### 2.5 MySQL vs PostgreSQL Divergence Beyond Abstraction

| Feature | MySQL | PostgreSQL | In-Code Handling |
|---|---|---|---|
| Regex operator | `REGEXP` | `~` | `DB_REGEX_OPERATOR` constant |
| Random function | `RAND()` | `CAST(random() AS text)` | `DB_RANDOM_FUNCTION` constant |
| Date subtraction | `SUBDATE(NOW(), INTERVAL X DAY)` | `CURRENT_TIMESTAMP - INTERVAL 'X days'` | Abstracted in dblayer functions |
| Upsert | `REPLACE INTO` | `INSERT ... ON CONFLICT` | Separate implementations |
| Transaction start | `START TRANSACTION` | `BEGIN` | Separate implementations |
| Boolean | `ENUM('true','false')` | Native BOOLEAN | Convert `'t'`/`'f'` in PostgreSQL |
| Sequences | Auto-increment | GENERATED BY DEFAULT AS IDENTITY | `sync_sequences()` in pgsql only |
| Full-text | `FULLTEXT INDEX` + `MATCH AGAINST` | `TSVECTOR` + `@@` | PostgreSQL has triggers |

**Rust approach:** `sqlx` handles most divergence automatically. The few manual differences (regex, random, date math) become helper functions that emit the correct SQL per backend.

---

## 3. Template System & Asset Pipeline

**Severity: HIGH — NOT a simple template language swap. Requires ~40-50% custom Rust infrastructure.**

### 3.1 Smarty Customization Inventory

| Customization | Count | Tera Equivalent | Effort |
|---|---|---|---|
| Registered PHP classes (static method access) | 4 | Custom Tera functions per method | HIGH |
| Modifier compilers (compile-time) | 2 | Runtime filters (loses optimization) | MEDIUM |
| Standard modifiers (PHP wrappers) | 21 | Custom filters | LOW |
| Block plugins (`html_head`, `footer_script`, `html_style`) | 3 | Post-template processing | MEDIUM |
| Function plugins (`combine_script`, `combine_css`, `define_derivative`) | 5 | Custom Tera functions | MEDIUM |
| Filter pipeline (pre/post/output) | 4 levels | Rust pre/post processing | MEDIUM |
| Compiler plugins (`get_combined_css`) | 1 | Runtime function | LOW |

### 3.2 Dangerous Modifiers

These Smarty modifiers expose operations that shouldn't happen in templates:

| Modifier | Risk | Tera Decision |
|---|---|---|
| `file_exists` | File I/O in templates | **Remove** — pre-compute in handler |
| `preg_match` | Regex execution in templates | **Remove** — pre-compute in handler |
| `constant` | Access to PHP/Rust constants | **Replace** with Tera globals |
| `md5` | Crypto in templates | Keep as custom filter |

### 3.3 ScriptLoader Dependency Resolution Algorithm

The dependency resolver is a **constraint solver**, not a topological sort:

```
REPEAT until no changes:
    FOR each script:
        FOR each dependency:
            IF dependency.load_mode > script.load_mode:
                // Promote dependency to earlier load mode
                dependency.load_mode = script.load_mode
                changed = true
            
            IF both are async AND (dependency is remote OR !combine_files):
                // Can't guarantee execution order for uncombined async scripts
                // Demote dependency to footer
                dependency.load_mode = FOOTER
                changed = true
```

Then scripts are sorted by: `load_mode ASC, topological_depth ASC, remote_first (header only), id ASC`.

**Rust implementation:** Straightforward — use a `Vec<Script>` with a fixed-point iteration loop. No external crate needed.

### 3.4 FileCombiner — Template-Parsed Assets

**Critical pattern:** Some CSS and JS files are Smarty templates themselves (`.css.tpl`, `.js.tpl`):

1. Template registered as asset: `{combine_css path='themes/style.css.tpl'}`
2. FileCombiner detects `is_template = true`
3. Triggers `combinable_preparse` hook — plugins inject vars
4. Parses template through Smarty — CSS/JS with variable substitution
5. Minifies result (CSS: `tubalmartin/CssMin`, JS: `JShrink`)
6. Caches with `t` prefix: `_data/combined/t{hash}.css`

**Cache key:** `base_convert(crc32(implode('>', [root_url, path, version, mtime])), 16, 36)`

**CSS URL rewriting:** After combining, relative URLs in CSS are rewritten to absolute paths. `@import` statements are inlined recursively.

**Rust replacement:**
- Tera can render `.css.tpl` / `.js.tpl` files
- CSS minification: `lightningcss` crate
- JS minification: `oxc_minifier` or `swc_ecma_minifier`
- URL rewriting: regex replacement on `url(...)` patterns

### 3.5 `footer_script` Block System

**106 total `{footer_script}` blocks** across all templates:
- 40 with no dependencies
- 35 requiring `jquery`
- 10 requiring `jquery.ui`
- 15 requiring various jQuery plugins
- 5 using async mode

**Pattern:**
```smarty
{footer_script require='jquery'}<script>
  jQuery(document).ready(function() { ... });
</script>{/footer_script}
```

**Rust approach:** During template rendering, collect `footer_script` blocks into a `Vec<InlineScript>`. After rendering, resolve dependencies and inject into the HTML at the appropriate location (before `</head>` for `html_head`/`html_style`, before `</body>` for `footer_script`).

### 3.6 Template Variable Inventory

~200-300 unique template variable names assigned via `$template->assign()`. The most frequently assigned:

- `ADMIN_PAGE_TITLE` (22 files)
- `PWG_TOKEN` (6 files)
- `GRAPHICS_LIBRARY` (8 files)
- `U_HOME`, `U_REGISTER`, `U_LOGOUT` (navigation URLs)
- `TITLE`, `COMMENT`, `NB_IMAGES` (page content)
- `derivative` objects with method calls

### 3.7 Object Method Calls in Templates

Templates call methods on objects passed from PHP:

```smarty
{$derivative->get_type()}
{$derivative->get_url()}
{$derivative->get_size_hr()}
{$previous.derivatives.square->get_url()}
```

**Tera limitation:** Tera does NOT support method calls on objects. These must become either:
- Pre-computed properties: `{{ derivative.type }}`, `{{ derivative.url }}`
- Custom Tera functions: `{{ derivative_url(derivative, "square") }}`

### 3.8 Theme Inheritance (get_extent)

The `get_extent` modifier enables template overrides:
```smarty
{include file='picture_nav_buttons.tpl'|get_extent:'picture_nav_buttons'}
```

This checks for: `template-extension/{handle}.tpl` → child theme override → parent theme → default.

**Rust approach:** Custom Tera template loader that implements the lookup chain:
1. `templates/extensions/{handle}.html`
2. `themes/{active_theme}/templates/{handle}.html`
3. `themes/{parent_theme}/templates/{handle}.html`
4. `templates/{handle}.html`

### 3.9 BlockManager Lifecycle

3-phase lifecycle for dynamic sidebar blocks:

1. **Register:** Plugins call `block_register(id, name, owner)` via `blockmanager_register_blocks` hook
2. **Prepare:** Load block positions from DB config, filter by visibility, trigger `blockmanager_prepare_display`
3. **Apply:** Render each block's template, assign `$blocks` to main template context

**Rust approach:** The BlockManager is pure Rust logic. Tera templates just consume a `blocks` array. Plugin hooks can add/modify blocks via the Lua bridge.

---

## 4. Permission System & Caching

**Severity: MEDIUM-HIGH — Architecturally sound but every query depends on it, and string interpolation of permission lists is pervasive.**

### 4.1 Complete Permission Computation Algorithm

```
calculate_permissions(user_id, user_status):
    private_cats = SELECT id FROM categories WHERE status = 'private'
    user_auth    = SELECT cat_id FROM user_access WHERE user_id = ?
    group_auth   = SELECT DISTINCT cat_id FROM user_group ug
                   JOIN group_access ga ON ug.group_id = ga.group_id
                   WHERE ug.user_id = ?
    
    authorized = UNION(user_auth, group_auth)
    forbidden  = private_cats - authorized
    
    IF user_status NOT IN ('admin', 'webmaster'):
        locked = SELECT id FROM categories WHERE visible = 'false'
        forbidden = UNION(forbidden, locked)
    
    IF forbidden IS EMPTY:
        forbidden = [0]   // sentinel for NOT IN queries
    
    RETURN comma_separated(forbidden)
```

Then image-level restrictions:
```
    forbidden_images = SELECT DISTINCT id FROM images
                       JOIN image_category ON id = image_id
                       WHERE category_id NOT IN (forbidden_cats)
                       AND level > user_level
```

### 4.2 Per-Request Query Count

**Cache warm (typical request):** 2 queries (user + user_infos/cache join)

**Cache cold (after invalidation):** 13-17 queries:
1. User record
2. User infos + cache + theme (JOIN)
3. All private categories
4. User direct access grants
5. Group access grants
6. Forbidden images
7. Accessible image count
8. Computed categories (complex JOIN with GROUP BY)
9-13. DELETE/INSERT for user_cache and user_cache_categories

### 4.3 Cache Invalidation Triggers (18 call sites)

Every one of these admin actions triggers `invalidate_user_cache(true)` which TRUNCATES both cache tables:

- Category status change (public/private)
- Category visibility change
- Group permission add/remove
- User permission add/remove
- Image privacy level change
- Batch operations (any)
- Photo metadata change
- Category create/delete
- Upload operations
- Extension updates
- Manual maintenance action

**Rust improvement opportunity:** Selective invalidation. Instead of TRUNCATE ALL, invalidate only affected users' caches. When a category's status changes, only users who had access to that category need recomputation.

### 4.4 The Filter System (Separate from Permissions)

The `$filter` system is a temporal filter ("show only recent photos") that is AND-ed with permissions:

```
Final SQL = (permission_conditions) AND (filter_conditions)
```

Filter state is stored in SESSION (ephemeral), while permission cache is in the DB (persistent). Both must be invalidated together when permissions change.

**Rust design:** Two separate cache layers with coordinated invalidation:
```rust
pub struct RequestPermissions {
    pub forbidden_categories: Vec<i32>,    // From permission cache
    pub forbidden_images: Vec<i32>,        // From permission cache
    pub visible_categories: Option<Vec<i32>>,  // From filter (if active)
    pub visible_images: Option<Vec<i32>>,      // From filter (if active)
    pub user_level: i32,
}
```

### 4.5 Edge Cases That Could Cause Permission Bypass

1. **Image in multiple categories:** If image is in both an authorized and forbidden category, the image should be accessible (it's in at least one authorized category). Current PHP does this correctly — `NOT IN (forbidden_cats)` only checks `image_category.category_id`.

2. **Empty forbidden list sentinel:** `[0]` is used as sentinel for empty list. `NOT IN (0)` is always true for valid IDs. If Rust accidentally uses NULL or empty array, all categories become forbidden.

3. **Admin visibility bypass:** Admins see categories with `visible='false'`. If the Rust code applies visibility filtering before checking admin status, admins lose access.

4. **Race condition:** Concurrent permission updates could cause Request A to use stale cache while Admin B invalidates. Solution: optimistic locking with `cache_update_time` comparison.

5. **Session/DB cache desync:** Filter is session-based, permissions are DB-based. If session expires but DB cache is stale with different `cache_update_time`, the filter may apply wrong restrictions.

---

## 5. Filesystem Sync Complexity

**Severity: HIGH — Most complex single subsystem. 3 sequential phases, SSE streaming, profiling, partial failure risks.**

### 5.1 Three-Phase Architecture

```
PHASE 1: DIRECTORIES → CATEGORIES
    Load DB categories → Scan FS dirs → Diff → Insert new / Delete removed
    
PHASE 2: FILES → IMAGES  
    Scan FS files → Load DB images → Diff → Insert new / Delete removed / Update changed
    
PHASE 3: METADATA
    Load candidates → Extract EXIF/IPTC per file → Batch resolve tags → Update DB
```

Phases MUST run sequentially — Phase 2 depends on categories from Phase 1, Phase 3 depends on images from Phase 2.

### 5.2 Transaction Safety (or lack thereof)

| Operation | Transaction? | Interrupted State |
|---|---|---|
| Phase 1: Category inserts | NO | Orphaned categories possible |
| Phase 1: Category deletes | YES (explicit) | Safe — atomic |
| Phase 2: Image inserts | NO | Orphaned `image_category` links |
| Phase 2: Format sync | NO | Mixed old/new formats |
| Phase 2: Image deletes | NO (per-element) | Partial deletions |
| Phase 3: Metadata updates | NO | Some images have EXIF, others don't |
| Phase 3: Tag updates | NO | `image_tag` cleared but not repopulated |

**Rust improvement:** Wrap each phase in a transaction. Use savepoints for sub-operations. On interruption, rollback the current phase and mark the sync as incomplete.

### 5.3 Progress Reporting (SSE)

When `?sse` query parameter is present:
```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no  // Disable proxy buffering

Events:
  phase_start    → {phase: 'dirs'|'files'|'meta'}
  substep_start  → {phase, id, label, has_progress}
  substep_progress → {phase, id, detail, file}
  phase_progress → {phase, current, total, updated, skipped, file}
  substep_complete → {phase, id, detail, elapsed}
  phase_complete → {phase, elapsed, new, deleted, updated}
  complete       → {simulate, update, metadata, errors}
  error          → {message}
```

**Rust approach:** `axum::response::Sse` with `tokio::sync::mpsc` channels. Each phase sends events through the channel; the SSE handler converts them to `Event` structs.

### 5.4 Metadata Extraction Pipeline

Per-file extraction sequence:
1. **Filesize check** — if `fs_filesize == db_filesize && filesize > 0`: SKIP (unchanged)
2. **Image dimensions** — via libvips header read (no full decode)
3. **EXIF data** — date, camera, GPS, orientation, keywords
4. **IPTC data** — title, description, author, keywords, date
5. **Date normalization** — 30+ format variants:
   - UNIX timestamp → `Y-m-d H:i:s`
   - `YYYY:MM:DD HH:MM:SS` → normalized
   - `YYYYMMDD` (IPTC) → `Y-m-d` with fallback to `Y-01-01`
   - SVG viewBox → pixel dimensions

**Parallelization:** Metadata extraction is CPU-bound and fully parallelizable. Use `rayon::par_iter` or `tokio::task::spawn_blocking` with a semaphore to cap concurrency.

### 5.5 Profiling Instrumentation

When `$conf->sync_profiling = true`, per-phase timing is tracked:

- Phase 1: dirs scanned, files found, readdir time
- Phase 2: representative lookups (count + time), format lookups (count + time)
- Phase 3: per-file extraction time, aggregate p50/p95/p99 percentiles, per-operation breakdown (filesize, getimagesize, exif, iptc)

**Rust approach:** Use `std::time::Instant` for timing. Collect into a `SyncProfile` struct with `percentile()` method.

### 5.6 Everything SDK / MFT Scanner

**Everything SDK** (Windows, optional):
- FFI to `Everything3_x64.dll` via PHP `ext-ffi`
- Queries Everything search index for directories and files matching patterns
- Returns `[path, size_bytes]` pairs
- Falls back to `LocalSiteReader` if Everything not running

**MFT reader** (Rust, planned replacement):
- Direct NTFS Master File Table read via `windows-rs` crate
- `FSCTL_GET_NTFS_FILE_RECORD` or `IOCTL_QUERY_USN_JOURNAL`
- Requires admin privileges
- Target: <100ms for 400k file index

---

## 6. Image Pipeline & Derivative System

**Severity: MEDIUM — Well-structured, but the PHP VIPS backend has 4 stubbed operations that libvips-rs must implement.**

### 6.1 Derivative URL Format Specification

**Standard types** (2-char codes):
```
sq = IMG_SQUARE    (120×120, crop=1.0)
th = IMG_THUMB     (144×144)
2s = IMG_XXSMALL   (240×240)
xs = IMG_XSMALL    (432×324)
sm = IMG_SMALL     (576×432)
me = IMG_MEDIUM    (792×594)
la = IMG_LARGE     (1008×756)
xl = IMG_XLARGE    (1224×918)
xx = IMG_XXLARGE   (1656×1242)
```

**Custom parameters** (`cu` prefix):
- Scale only: `cus200x150` — `s` = scale, `200x150` = dimensions
- Scale + crop: `cu200x150l100_150` — `l` = crop factor (char a-z → 0.0-1.0), `100_150` = min size
- Exact square crop: `cue120` — `e` = exact, `120` = square size

**Crop factor encoding:** `(fraction × 25).round()` → char `'a' + n`

### 6.2 Cache Invalidation Logic

Derivative regenerated if ANY of:
1. Derivative file does not exist
2. Source file mtime > derivative mtime
3. `ImageStdParams.last_mod_time` > derivative mtime (params changed in admin)

**HTTP caching:**
- `Last-Modified` always sent
- `Expires: +10 days` if source stable for 24+ hours
- `304 Not Modified` on `If-Modified-Since` match
- `?b=TIMESTAMP` forces regeneration within 100 seconds (used during COI editing)

### 6.3 COI (Center of Interest) Crop Algorithm

**Storage:** 4 chars in `images.coi` column, each encoding a 0.0-1.0 fraction as `'a' + round(fraction × 25)`.

**Algorithm:**
1. Compute aspect ratio mismatch between source and target
2. Determine if horizontal or vertical crop is needed
3. Calculate ideal crop amount (pixels to remove)
4. Cap at `max_crop × dimension`
5. Distribute crop evenly around COI center
6. Shift if COI doesn't have enough space on one side
7. Scale resulting rectangle to target dimensions

### 6.4 PHP VIPS Backend — Stubbed Operations

The current PHP VIPS backend (`image_vips.php`) has **4 critical stubs**:

| Operation | Status | Impact |
|---|---|---|
| `strip()` | Returns true, does nothing | Metadata never removed from derivatives |
| `sharpen()` | Returns true, does nothing | Derivatives lack sharpening |
| `compose()` | Returns true, does nothing | **Watermarks never applied** |
| `set_compression_quality()` | Ignored | Always outputs quality 75 instead of configured 95 |

Additionally, `resize()` re-reads the file from disk instead of operating on the loaded image, breaking the operation pipeline.

**libvips-rs capabilities:** All 4 operations ARE supported by the underlying libvips C library:
- `vips_sharpen()` — built-in sharpening
- `vips_composite2()` — alpha compositing for watermarks
- `vips_autorot()` + clear EXIF — metadata stripping
- `vips_jpegsave()` with Q parameter — quality control

### 6.5 Animated WebP Detection

Parse first 25 bytes of file:
```
Bytes 0-3:   "RIFF" magic
Bytes 8-11:  "WEBP" fourCC
Byte 15:     Format identifier:
             ' ' (0x20) = VP8 Lossy
             'L' = VP8L Lossless
             'X' = VP8X Extended
If VP8X:
    Byte 20 bit 1 (0x02) = animation flag
    Byte 20 bit 4 (0x10) = alpha/transparency flag
```

Animated WebP quality is capped at 70 (configurable) to prevent oversized thumbnails.

### 6.6 Sharpening Matrix

```
amount_range = -48 to 10 (user param 0-1 maps to this range)
center_value = abs(-48 + (amount × 0.38))

matrix = [[-1, -1, -1],
          [-1, center, -1],
          [-1, -1, -1]]
          
normalized: each element / sum(all elements)
```

---

## 7. Admin Batch Operations & Upload Pipeline

**Severity: MEDIUM — Complex but well-structured. Main challenge is the session-based filter state and the 15+ action types.**

### 7.1 Batch Manager Prefilters (10 types)

| Prefilter | SQL Pattern | Notes |
|---|---|---|
| `caddie` | `SELECT element_id FROM caddie WHERE user_id = ?` | Per-user working set |
| `favorites` | `SELECT image_id FROM favorites WHERE user_id = ?` | Per-user |
| `last_import` | `MAX(date_available)` + same-day filter | Single day window |
| `no_album` | Custom orphan detection function | Set difference |
| `no_tag` | `LEFT JOIN image_tag ... IS NULL` | Negation join |
| `no_virtual_album` | Images not in any virtual category | Multi-step |
| `duplicates` | `GROUP BY {fields} HAVING COUNT(*) > 1` | Up to 4 fields |
| `all_photos` | `SELECT id FROM images` | Full scan |
| `no_sync_md5sum` | `WHERE md5sum IS NULL` | Missing checksums |
| Plugin-registered | Via `perform_batch_manager_prefilters` hook | Extensible |

### 7.2 Batch Manager Actions (15 types)

| Action | Operations | Transaction Needed |
|---|---|---|
| `remove_from_caddie` | DELETE from caddie | No |
| `add_tags` | Resolve tag IDs + INSERT image_tag | No |
| `del_tags` | DELETE from image_tag | No |
| `associate` | INSERT image_category + update representatives | Yes |
| `move` | DELETE old links + INSERT new | Yes |
| `dissociate` | DELETE from image_category (virtual only) | No |
| `author` | mass_update images.author | No |
| `title` | mass_update images.name | No |
| `date_creation` | mass_update images.date_creation | No |
| `level` | mass_update images.level + invalidate cache | Yes (cache) |
| `add_to_caddie` | INSERT into caddie | No |
| `delete` | Full cascade delete (see §7.4) | Yes |
| `metadata` | Re-extract EXIF/IPTC + update | No |
| `delete_derivatives` | Delete specific derivative size files | No (FS only) |
| `generate_derivatives` | Trigger async derivative generation | No |

### 7.3 Upload Pipeline (11 steps)

1. **Duplicate detection** — MD5 check against existing images
2. **Directory preparation** — `_data/upload/{YYYY}/{MM}/{DD}/`
3. **File placement** — `move_uploaded_file()` + `chmod 0644`
4. **Format handling** — Plugin hook for PDF/HEIC/TIFF/video/PSD/EPS → representative generation
5. **Original resize** — Optional, if dimensions exceed configured limits
6. **EXIF rotation** — Apply orientation correction
7. **Metadata extraction** — Dimensions, filesize
8. **Database insert** — `images` table
9. **Category association** — `image_category` table
10. **Metadata sync** — EXIF/IPTC extraction + tag creation
11. **Derivative cache** — Pre-warm medium size

**Chunked upload protocol:** Browser sends chunks → server accumulates → final chunk triggers `uploadCompleted` → chunks concatenated → processed as normal upload.

### 7.4 Delete Cascade Order

**`delete_categories(ids)`:**
1. Get all subcategories (recursive)
2. Delete images whose `storage_category_id` is in the set
3. (Wrapped in transaction):
   - DELETE `image_category` links
   - DELETE `user_access` grants
   - DELETE `group_access` grants
   - DELETE `categories` records
   - DELETE `old_permalinks`
   - DELETE `user_cache_categories`
4. Update representatives for affected parent categories
5. Trigger `delete_categories` plugin hook

**`delete_elements(ids)`:**
1. Delete physical files (original, representative, formats, derivatives)
2. DELETE `comments`
3. DELETE `image_category` links
4. DELETE `image_format` records
5. DELETE `image_tag` links
6. DELETE `favorites`
7. DELETE `rate` records
8. DELETE `caddie` entries
9. DELETE `images` records
10. Fix broken `representative_picture_id` references
11. Trigger `delete_elements` plugin hook

### 7.5 Category Tree Algorithms

**`update_uppercats()`:** For each category, walk parent chain to root, build comma-separated ancestor path: `"root_id,parent_id,self_id"`. Mass update.

**`update_global_rank()`:** 
1. Query all categories ORDER BY `id_uppercat, sort_rank, name`
2. For each parent group, assign consecutive sort_rank (1, 2, 3...)
3. Build global_rank by replacing each ID in uppercats with its sort_rank → `"1.2.3"`
4. Mass update if changed

**`move_categories(ids, new_parent)`:**
1. Validate: can't move into own subtree (check uppercats)
2. UPDATE `id_uppercat`
3. Recalc `uppercats` and `global_rank`
4. If parent is private, children become private
5. Permission cascade

---

## 8. Session Serialization & Auth Edge Cases

**Severity: MEDIUM — Mostly straightforward, but the PHP serialization format requires migration and several security issues should be fixed.**

### 8.1 Session ID Construction

```
IPv4: first 2 octets → uppercase hex → 4 chars
      192.168.1.1 → "C0A8"
IPv6: empty string (NOT IMPLEMENTED)

Final session ID: "{IP_HASH}{PHP_SESSION_ID}"
Example: "C0A8abc123def456ghi789jkl012mno"
```

### 8.2 Complete Session Variable Inventory

| Key | Type | Purpose |
|---|---|---|
| `pwg_uid` | `i32` | Authenticated user ID |
| `image_order` | `i32` | Gallery sort order |
| `index_deriv` | `String` | Thumbnail derivative type |
| `show_metadata` | `bool` | Metadata visibility toggle |
| `picture_deriv` | `String` | Picture view derivative size |
| `device` | `String` | `"mobile"` or `"desktop"` |
| `mobile_theme` | `bool` | Mobile theme override |
| `comments_order` | `String` | Comment sort direction |
| `filter_enabled` | `bool` | Content filter active |
| `filter_check_key` | `Object` | Filter validation data |
| `filter_categories` | `Vec<i32>` | Filtered category IDs |
| `filter_visible_categories` | `String` | Comma-separated category IDs |
| `filter_visible_images` | `String` | Comma-separated image IDs |
| `bulk_manager_filter` | `Object` | Batch manager filter state |
| `plugins_show_details` | `bool` | Admin plugin details view |
| `plugins_new_order` | `String` | Plugin sort order |
| `multiview` | `Object` | AdminTools MultiView state |
| `lang_switch` | `String` | Language switch plugin state |

### 8.3 Remember-Me Algorithm

**Generation:**
```
data = "{timestamp}{user_id}{username}"
key  = "{config.secret_key}{user_password_hash}"
hmac = base64(HMAC-SHA1(data, key))
cookie = "{user_id}-{timestamp}-{hmac}"
```

**Validation:**
1. Split cookie by `-` → must be exactly 3 parts
2. `user_id` must be numeric
3. `timestamp` must be numeric
4. `timestamp` must be within `[now - remember_me_length, now]`
5. Recalculate HMAC with same inputs
6. Compare `calculated_hmac === cookie_hmac`
7. If valid → `log_user(user_id, true)` → establish session

**Cookie parameters:**
- Expires: `now + 5,184,000 seconds` (60 days)
- Path: cookie_path (usually `/piwigo/`)
- HttpOnly: yes
- Secure: if HTTPS

### 8.4 API Key Algorithm

**Generation:**
- `random_bytes(40)` → base64 → remove `+` and `/` → truncate to 30 chars
- Only `'normal'` and `'generic'` users can have keys (NOT admin/webmaster/guest)
- Default TTL: 259,200 seconds (3 days)
- Stored **plaintext** in `user_auth_keys` table

**Validation:**
- Format check: `/^[a-z0-9]{30}$/i`
- DB lookup by plaintext key value
- Expiry check: `expired_on > NOW()`
- Status check: only `'normal'` or `'generic'`

### 8.5 Security Issues to Fix in Rust

| Issue | PHP Behavior | Rust Fix |
|---|---|---|
| **IPv6 session binding** | Returns empty string — all IPv6 users share session space | Hash first 8 bytes of IPv6 address |
| **Non-timing-safe comparison** | `$key === $cookie[2]` in remember-me validation | Use `constant_time_eq` crate |
| **API keys stored plaintext** | `auth_key` column stores raw key | Store `bcrypt(key)` or `SHA-256(key)`, compare hash |
| **IP binding only 2 octets** | Only first 2 octets of IPv4 hashed | Use all 4 octets (or 3 for mobile-friendly) |
| **PHP native serialization** | Session data stored as PHP serialized | JSON — already planned |
| **No remember-me refresh** | Cookie expiry is fixed from creation time | Refresh expiry on each `auto_login()` |
| **Deterministic GC** | Session GC runs on every login | Probabilistic (1% of requests) or background task |
| **Case-insensitive login** | Loads ALL users for case-insensitive search | Use `LOWER(username) = LOWER(?)` in SQL |

### 8.6 Proposed Rust Session JSON Schema

```json
{
    "pwg_uid": 3,
    "image_order": 2,
    "index_deriv": "IMG_THUMB",
    "show_metadata": true,
    "device": "desktop",
    "filter": {
        "enabled": false,
        "categories": [1, 2, 3],
        "visible_categories": "1,2,3",
        "visible_images": "100,205,308"
    },
    "bulk_manager_filter": {
        "prefilter": "caddie",
        "category": 5,
        "tags": [12, 15]
    },
    "plugin_data": {}
}
```

### 8.7 All Access Level Checks in Codebase

| Level | Pages |
|---|---|
| `ACCESS_FREE` (0) | `identification.php`, `ws.php`, `register.php`, `password.php`, `nbm.php` |
| `ACCESS_GUEST` (1) | `index.php`, `picture.php`, `comments.php`, `search.php`, `tags.php`, `random.php`, `feed.php`, `about.php`, `action.php`, `notification.php`, `popuphelp.php`, `qsearch.php` |
| `ACCESS_CLASSIC` (2) | `profile.php` |
| `ACCESS_ADMINISTRATOR` (3) | All 57 admin pages |
| `ACCESS_WEBMASTER` (4) | `plugins/LocalFilesEditor/admin.php`, some notification_by_mail tabs |

---

## Cross-Cutting Concerns

### Global State Dependencies

The PHP codebase relies on 4 global variables accessible from everywhere:

| Global | Contents | Rust Equivalent |
|---|---|---|
| `$conf` | ~900 config options | `Arc<RwLock<PiwigoConfig>>` in AppState |
| `$user` | Authenticated user + permissions | Axum `Extension<AuthenticatedUser>` per-request |
| `$page` | Request-scoped page state | Axum `Extension<PageContext>` per-request |
| `$lang` | Translation strings | `Arc<I18n>` in AppState |

### PHP Serialization Migration

3 database columns store PHP-serialized data:
- `sessions.data` — session variables
- `search.rules` — search query structure
- `user_infos.preferences` — user preferences

**Migration plan:** Add `*_json` columns, dual-write during transition, backfill existing rows, drop PHP columns after 30 days.

### Error Handling Differences

PHP uses a custom error handler that writes to `console.log()` in the browser. Rust should:
- Use `tracing` for structured logging
- Return proper HTTP error pages (not console.log injection)
- Log errors to `_data/logs/` directory
- In dev mode: include error details in response body

---

*Compiled from 8 parallel deep investigations. Last updated: 2026-04-14.*
