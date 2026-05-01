# Plugin migration guide — Piwigo 16.x-rewrite

This guide is for plugin authors whose code runs against the Piwigo 16.x-rewrite fork.
It covers every behavioural change that can break a plugin without any PHP fatal error.

---

## 1. Configuration access (`$conf`)

### What changed

In Piwigo 16.x, `$conf` is backed by the typed `Piwigo\Core\Config` service.
After `Kernel::boot()` runs, `$GLOBALS['conf']` is a PHP reference to
`Config::$data`, so **reading `$conf['key']` continues to work with no changes
required**. Writing `$conf['key'] = $value` also works and stays in sync.

> **Note:** An earlier 16.x preview build (Phase 4 Wave C) temporarily wrapped
> `$conf` in a deprecation-emitting `ConfProxy` that logged `E_USER_DEPRECATED`
> for every `$conf['key']` access. That proxy has been **removed** in Phase 6.
> If you saw deprecation notices during testing against a preview build, they are
> gone in the current build.

### Optional: use typed getters

Plugin code can voluntarily call the typed getters for stronger safety:

| Legacy access | Typed getter |
|---|---|
| `$conf['some_string']` | `\Piwigo\Core\Config::getString('some_string')` |
| `$conf['some_int']` | `\Piwigo\Core\Config::getInt('some_int')` |
| `$conf['some_bool']` | `\Piwigo\Core\Config::getBool('some_bool')` |
| `$conf['some_key']` | `\Piwigo\Core\Config::get('some_key')` (returns `mixed`) |
| `$conf['some_key'] = $v` | `\Piwigo\Core\Config::override('some_key', $v)` |

Use the FQN (`\Piwigo\Core\Config::get(...)`) to avoid relying on global aliases.
The `override()` method mutates the in-memory value for the current request only;
it does NOT persist to the database. For persistent changes, continue to use
`conf_update_param()`.

---

## 2. Database layer

### What changed

Only `mysqli` is supported. The `pgsql` and `sqlite` layers were removed in
Phase 1. If your plugin detected the active layer via `$conf['dblayer']`, that
key still exists in `$conf` (populated from `local/config/database.inc.php`)
and will always be `'mysqli'`.

The dynamic `include("functions_{$conf['dblayer']}.inc.php")` call in
`include/common.inc.php` is now a static `include("functions_mysqli.inc.php")`.
No plugin action required.

---

## 3. Upgrade floor

### What changed

`upgrade.php` now **refuses** databases older than Piwigo 15.0.0 with HTTP 409.
If your plugin ships its own upgrade logic in `main.inc.php` or a separate
upgrade file, it is unaffected — this guard only applies to Piwigo core upgrades.

---

## 4. Namespaced class names

### What changed

All first-party Piwigo classes were moved to the `Piwigo\` namespace in Phase 3.
The old unqualified names remain available via `src/Piwigo/Compat/aliases.php`
(loaded by Composer on every request), so `class_alias` or bare class names in
plugin code continue to work.

**Voluntary migration:** if your plugin extends a Piwigo class, use the
namespaced name to opt into IDE tooling and PHPStan analysis:

| Old name | Namespaced name |
|---|---|
| `PluginMaintain` | `Piwigo\Admin\PluginMaintain` |
| `ThemeMaintain` | `Piwigo\Admin\ThemeMaintain` |
| `Template` | `Piwigo\Template\Template` |
| `PwgSession` | `Piwigo\Session\PwgSession` |

The full alias list is in `src/Piwigo/Compat/aliases.php`.

---

## 5. PHP version floor

Piwigo 16.x requires **PHP 8.5**. If your plugin uses syntax or functions
removed before 8.5, it will fail with a fatal error on activation.

Key 8.5 compatibility notes:
- `curl_close()` is deprecated — use `unset($ch)` instead.
- `mysql_*` functions do not exist — use `pwg_query()` / `pwg_db_*` wrappers.
- Dynamic properties (`$obj->dynamic = 'val'` on undeclared properties) are
  deprecated. Declare all properties explicitly or use `#[AllowDynamicProperties]`.

---

## 6. Testing your plugin against 16.x

1. Install a fresh Piwigo 16.x-rewrite instance via `install.php`.
2. Activate your plugin from the admin panel.
3. Run a request with `error_reporting(E_ALL)` and check the Apache/PHP error log
   for `E_DEPRECATED`, `E_NOTICE`, or `E_WARNING` messages.
4. Browse the gallery home, a photo page, and the admin dashboard with the plugin
   active and confirm no fatal errors or visible breakage.
