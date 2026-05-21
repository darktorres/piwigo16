# Migrate upstream bundled extensions to the 17.x contracts

## Context

Upstream Piwigo ships seven "bundled" extensions with every release:

| Kind   | id                | Upstream tree                                  | Upstream LOC (PHP) |
| ------ | ----------------- | ---------------------------------------------- | -----------------: |
| plugin | `AdminTools`      | `piwigo16-plugins/AdminTools_16.3.0/`          |               ~750 |
| plugin | `LocalFilesEditor`| `piwigo16-plugins/LocalFilesEditor_16.3.0/`    |               ~600 |
| plugin | `TakeATour`       | `piwigo16-plugins/TakeATour_16.3.0/`           |              ~1.0k |
| plugin | `language_switch` | `piwigo16-plugins/language_switch_16.3.0/`     |               ~200 |
| theme  | `elegant`         | `piwigo16-themes/elegant_16.3.0/`              |               ~400 |
| theme  | `modus`           | `piwigo16-themes/modus_16.3.0.1/`              |              ~1.0k |
| theme  | `smartpocket`     | `piwigo16-themes/smartpocket_16.3.0/`          |               ~400 |

§1.4 shipped the v17 plugin/theme contracts (`PluginInterface` +
`plugin.json`, `ThemeInterface` + `theme.json`, typed PSR-14 events,
`#[ApiMethod]`) and §1.10/1.11 finished retiring `PHPWG_ROOT_PATH` and
all runtime `define()`s. The legacy procedural surfaces these
extensions target — `add_event_handler`, `pwg_query`, `l10n()`,
`script_basename`, `IN_ADMIN`, `themeconf.inc.php` side-effects,
`set_prefilter`, `$conf` array access — are **gone**. None of these
seven trees will run as-is.

ROADMAP §2.4 declared the bundled plugins out of scope for the 16.x
rewrite ("With those plugins removed from core, the only remaining
vendored asset that warrants an npm migration is the Open Sans
webfont"). This plan reverses that decision: the fork ships the same
seven extensions, rewritten against the new contracts, so a fresh
v17 install has the same out-of-box surface as upstream.

The §1.4 walkthrough at `ROADMAP.md:1286–1496` defines the per-plugin
migration steps (move under `src/`, drop `include/*.inc.php` split,
fold `maintain.*.php` into the `Plugin` class, add `plugin.json`,
Smarty → Latte, `.lang.php` → `.po`, JS → TS, replace globals). This
plan applies that walkthrough to seven concrete extensions; it does
not redefine the contracts.

Scope:

- **one commit per extension (7 total)**
- **1:1 feature parity with upstream** (every tour, skin, option preserved)
- **vendored 3rd-party JS replaced via npm + Vite** (CodeMirror v2 →
  `@codemirror/*`, bootstrap-tour → modern equivalent)

## Strategy

### Target layout (every extension)

```text
plugins/<id>/                      # for the 4 plugins
  plugin.json                      # declarative manifest
  src/
    Plugin.php                     # implements PluginInterface (lifecycle + subscribedEvents)
    <…domain classes…>             # MultiView → AdminTools/MultiView, etc.
  template/
    *.latte                        # Smarty .tpl converted via tools/smarty-to-latte
  language/<locale>/
    plugin.po                      # converted via tools/i18n/convert-ext-languages.php
  src/web/                         # TypeScript sources (jQuery-free)
    *.ts
  src/web/css/
    *.css
  migrations/                      # only for plugins that need DB tables (none of the 4 do — all state lives in $conf)
  tests/
    PluginTest.php                 # PluginTestCase-based smoke test

themes/<id>/                       # for the 3 themes
  theme.json
  src/
    Theme.php                      # implements ThemeInterface
  template/
    *.latte
  language/<locale>/
    theme.po
  src/web/
    *.ts, *.css
```

Composer autoload (`composer.json` `autoload.psr-4`) gets one entry per
extension:

```json
"Piwigo\\Plugin\\AdminTools\\":       "plugins/AdminTools/src/",
"Piwigo\\Plugin\\LocalFilesEditor\\": "plugins/LocalFilesEditor/src/",
"Piwigo\\Plugin\\TakeATour\\":        "plugins/TakeATour/src/",
"Piwigo\\Plugin\\LanguageSwitch\\":   "plugins/language_switch/src/",
"Piwigo\\Theme\\Elegant\\":           "themes/elegant/src/",
"Piwigo\\Theme\\Modus\\":             "themes/modus/src/",
"Piwigo\\Theme\\Smartpocket\\":       "themes/smartpocket/src/"
```

After each commit, `composer dump-autoload` runs (the repo's
`classmap-authoritative: true` policy means new classes are invisible
to Apache until the classmap regenerates). Pint runs before each
commit (CI runs the same job).

### Shared porting rules

1. **`add_event_handler('xxx', fn)` → `subscribedEvents()`.** Map
   legacy hook names to typed events under `src/Piwigo/Event/`:

   | Legacy hook                          | Typed event                                       |
   | ------------------------------------ | ------------------------------------------------- |
   | `init`                               | `Piwigo\Event\Lifecycle\Init`                     |
   | `user_init`                          | `Piwigo\Event\User\UserInit`                      |
   | `loading_lang`                       | `Piwigo\Event\Lifecycle\LoadingLang`              |
   | `loc_after_page_header`              | `Piwigo\Event\Lifecycle\LocAfterPageHeader`       |
   | `loc_begin_page_header`              | `Piwigo\Event\LocBeginPageHeader`                 |
   | `loc_begin_picture` / `_index`       | `Piwigo\Event\LocBeginPicture` / `LocBeginIndex`  |
   | `loc_end_themes_installed`           | `Piwigo\Event\LocEndThemesInstalled`              |
   | `loc_end_index`                      | `Piwigo\Event\LocEndIndex`                        |
   | `loc_end_help` / `no_photo_yet`      | `Piwigo\Event\LocEndHelp` / `LocEndNoPhotoYet`    |
   | `ws_add_methods`                     | `Piwigo\Event\Ws\WsMethodsRegistering`            |
   | `delete_user` / `register_user`      | `Piwigo\Event\User\DeleteUser` / `RegisterUser`   |
   | `render_element_content`             | `Piwigo\Event\Picture\RenderElementContent`       |
   | `get_index_derivative_params`        | `Piwigo\Event\Picture\GetIndexDerivativeParams`   |
   | `loc_end_index_category_thumbnails`  | `Piwigo\Event\LocEndIndexCategoryThumbnails`      |
   | `loc_index_thumbnails_selection`     | `Piwigo\Event\LocIndexThumbnailsSelection`        |
   | `loc_end_section_init`               | `Piwigo\Event\LocEndSectionInit`                  |
   | `get_admin_plugin_menu_links`        | `Piwigo\Event\Admin\GetAdminPluginMenuLinks`      |
   | `loc_begin_admin_page`               | `Piwigo\Event\LocBeginAdminPage`                  |

   159 typed events exist today (`find src/Piwigo/Event -name "*.php"
   | wc -l` → 159). Any legacy hook the upstream code uses without a
   matching event class becomes a porting blocker — add it to core in
   the same commit.

2. **Legacy global APIs → typed services** (injected via the Plugin
   constructor, autowired by PHP-DI):

   | Upstream call                                        | Service replacement                              |
   | ---------------------------------------------------- | ------------------------------------------------ |
   | `pwg_query`, `query2array`                           | `Piwigo\Db\DbConnection` (Doctrine DBAL)         |
   | `conf_update_param`, `conf_delete_param`, `$conf[…]` | `Piwigo\Config\ConfigService`                    |
   | `l10n(...)`, `load_language(...)`                    | `Piwigo\Lang\LangService` (`$lang->t($key)`)     |
   | `get_root_url`, `add_url_params`                     | `Piwigo\Url\UrlService`                          |
   | `trigger_change`, `trigger_notify`                   | `Psr\EventDispatcher\EventDispatcherInterface`   |
   | `pwg_set_session_var`, `pwg_get_session_var`         | `Piwigo\Session\SessionService`                  |
   | `get_pwg_token`, `check_pwg_token`                   | `Piwigo\Csrf\CsrfTokenService`                   |
   | `global $user`, `is_admin()`                         | `Piwigo\Users\CurrentUser`                       |
   | `global $page`                                       | `Piwigo\Page\PageState`                          |
   | `safe_unserialize`, `safe_serialize`                 | `Piwigo\Core\StringUtil`                         |

3. **Smarty `set_prefilter('xxx', fn)` has no Latte equivalent.**
   Three of the four plugins use this pattern to inject HTML into core
   templates. The new approach is **named template variables**: core
   `.latte` files already render
   `{if !empty($PLUGIN_PICTURE_BEFORE)}{$PLUGIN_PICTURE_BEFORE|noescape}{/if}`
   style hooks (verified at `themes/_base/template/picture.latte:9`).
   For each upstream `set_prefilter` callsite, the migration adds (if
   missing) a matching `{$PLUGIN_<NAME>|noescape}` slot to the relevant
   core `.latte`, then the plugin's listener assigns to that template
   variable. The same commit ships both the slot and the assignment.

   Slots added by this work:

   | Slot                              | Core template                                                    | Used by                       |
   | --------------------------------- | ---------------------------------------------------------------- | ----------------------------- |
   | `$PLUGIN_INDEX_ACTIONS`           | `themes/_base/template/index.latte` (already present, verify)    | language_switch               |
   | `$PLUGIN_LANGUAGE_SWITCH_HEADER`  | `themes/_base/template/header.latte`                             | language_switch (CSS link)    |
   | `$ADMINTOOLS_PUBLIC_TOOLBAR`      | `themes/_base/template/header.latte` / `picture.latte`           | AdminTools                    |
   | `$ADMINTOOLS_ADMIN_TOOLBAR`       | `themes/admin/_base/template/header.latte`                       | AdminTools                    |
   | `$LFE_THEMES_DROPDOWN_EXTRA`      | `themes/admin/_base/template/themes_installed.latte`             | LocalFilesEditor              |
   | `$TAT_BOOTSTRAP`                  | `themes/admin/_base/template/header.latte` (admin only)          | TakeATour                     |
   | `$TAT_HELP_LINK`                  | `themes/admin/_base/template/help.latte`                         | TakeATour                     |
   | `$TAT_NO_PHOTO_BUTTON`            | `themes/admin/_base/template/no_photo_yet.latte`                 | TakeATour                     |

4. **`.lang.php` → `.po`.** Run `tools/i18n/convert-ext-languages.php`
   pointed at each extension dir. Upstream key→string PHP arrays
   become gettext catalogs in `language/<locale>/plugin.po`;
   `LangService::loadPluginTranslations()` (already exists; called by
   `PluginRegistry::loadActiveLanguages()`) picks them up.

5. **`.tpl` → `.latte`.** Run `tools/smarty-to-latte/convert.php` on
   the extension's `template/` directory. Hand-fix anything the
   converter flags. Run `composer lint:latte` to validate.

6. **JS → TS, jQuery → vanilla DOM.** Source moves to `src/web/*.ts`,
   built by Vite into `dist/`. The two extensions with vendored
   3rd-party JS:

   - **LocalFilesEditor**: drop the entire `codemirror/` subtree (~3
     MB of CM v2). Add `@codemirror/state`, `@codemirror/view`,
     `@codemirror/lang-css`, `@codemirror/lang-javascript`,
     `@codemirror/lang-html`, `@codemirror/lang-xml`,
     `@codemirror/lang-php` (covers the upstream modes) as
     `package.json` dependencies. The plugin's `src/web/editor.ts`
     wires CodeMirror 6 into the textarea.
   - **TakeATour**: drop `js/custom-bootstrap-tour-standalone.js` and
     `css/bootstrap-tour-standalone.css`. Replace with **`shepherd.js`**
     as the npm dep (actively maintained, MIT, accessible, the
     consensus successor to bootstrap-tour). Each `tours/<name>/`
     ships a `tour.ts` exporting a `Shepherd.Tour` config; the legacy
     `tour.tpl` files convert to Latte fragments rendered into the
     step content.

7. **Replace `PHPWG_PLUGINS_PATH . 'X/'` reads.** Plugins access their
   own root via the `Piwigo\Core\Paths` value object injected into
   the Plugin class (or its services). Asset URLs go through
   `Piwigo\Asset\AssetService`.

8. **Lifecycle.** `maintain.class.php` / `maintain.inc.php` lifecycle
   functions fold onto the `Plugin` class:

   | Upstream                                                       | New                                  |
   | -------------------------------------------------------------- | ------------------------------------ |
   | `function plugin_install` / `install()` of `_maintain` class   | `Plugin::install()`                  |
   | `function plugin_activate`                                     | `Plugin::activate()`                 |
   | `function plugin_deactivate`                                   | `Plugin::deactivate()`               |
   | `function plugin_uninstall`                                    | `Plugin::uninstall()`                |
   | `function plugin_update`                                       | `Plugin::update($old, $new)`         |

9. **Tests.** Each extension ships at least one `PluginTestCase`-based
   smoke test under `tests/` that boots the plugin against a sandboxed
   container, dispatches one of its subscribed events, and asserts the
   visible effect. Required by `ROADMAP.md` §1.4 "Pre-publish gates".

10. **Per-commit hygiene** — every commit runs in order:
    ```text
    composer dump-autoload
    composer lint:php           # Pint
    composer lint:latte
    composer piwigo:lint        # zero violations on the new extension
    composer analyse            # phpstan + psalm clean
    composer test               # PHPUnit green
    ```

### Commit order (one commit per extension)

Sequencing follows risk and coupling:

1. **`language_switch`** (smallest; ~200 LOC; one `loading_lang` +
   one `loc_end_index` subscriber; no admin page; no migrations; no
   3rd-party JS). Validates the end-to-end pipeline on the smallest
   possible surface.
2. **`elegant`** (theme, child of `_base`, mostly CSS; minimal PHP —
   one `upgrade.inc.php` + one `init` handler). Validates the theme path.
3. **`smartpocket`** (theme, ~400 LOC PHP, more event handlers, mobile
   detection, but no skins). Validates a more complex theme.
4. **`AdminTools`** (plugin; ~750 LOC; admin page, WS methods,
   user_init view-as logic, prefilter on `picture.tpl`). Largest
   plugin surface but isolated.
5. **`LocalFilesEditor`** (plugin; CodeMirror 6 npm migration is the
   risk; the rest is straightforward admin-page-only PHP).
6. **`TakeATour`** (plugin; biggest because shepherd.js + 8 tour
   configs need re-authoring as Latte+TS). Lands late so it benefits
   from every extension point added above.
7. **`modus`** (theme; ~1k LOC PHP, 11 skins, custom Smarty plugins
   `cssGradient`/`cssResolution`/`modus_thumbs`, deep coupling to
   `DerivativeImage` and `ImageStdParams`). Hardest theme — saved for
   last so the contracts are stable when we hit it.

## Per-extension scope

### 1. `language_switch` (plugin)

**Upstream files:** `main.inc.php`, `language_switch.inc.php`,
`language_switch.css`, `style.css`, `language_switch_flags.tpl`,
`flag_sprite.jpg`, plus 39 `.lang.php` files.

**Migration deltas:**

- `language_controler_switch()` → `Plugin::onLoadingLang(LoadingLang $e)`
  — reads `?lang=` from PSR-7 request via `RequestContext`, mutates
  the user's `language` via `Piwigo\Users\UserService` or
  `SessionService`. The `is_a_guest()` / `is_generic()` checks use
  `CurrentUser::isGuest()` / `isGeneric()`.
- `language_controler_flags()` → `Plugin::onLocEndIndex(LocEndIndex $e)`
  — builds the language list via `LangService::availableLanguages()`,
  injects through `$template->assign('PLUGIN_INDEX_ACTIONS', …)`.
- `language_switch_flags.tpl` → `language_switch_flags.latte`.
- Inline `safe_themes` allowlist (`'clear','dark','elegant','Sylvia',…`)
  — trim to themes that exist post-rewrite (`_base`, `elegant`,
  `modus`, `smartpocket`, plus the `admin/*` set).
- `plugin.json` declares `hasSettings: false`, `minPiwigo: "17.0"`.

**No DB work, no admin page, no WS methods.**

### 2. `elegant` (theme)

**Upstream files:** `themeconf.inc.php`, `theme.css`, `local_head.tpl`,
`scripts.js`, `scripts_pp.js`, `fix-ie7.css`, `admin/upgrade.inc.php`,
`screenshot.png`, language files.

**Migration deltas:**

- `themeconf.inc.php` — the `set_config_values_elegant()` `init`
  handler folds into `Theme::onInit()`. The
  `safe_unserialize($conf['elegant'])` read becomes
  `ConfigService::get('elegant')` (already returns the decoded
  array; no manual unserialize needed in v17).
- `admin/upgrade.inc.php` becomes one or more migration files under
  `themes/elegant/migrations/Version*.php` if it does schema/config
  work. Otherwise fold idempotent config-defaulting into
  `Theme::install()`.
- `local_head.tpl` → `local_head.latte`.
- `parent: "default"` → `parent: "_base"` in `theme.json` (the §1.4
  rename per `ROADMAP.md:1768`).
- Drop `fix-ie7.css` — referenced only behind `<!--[if IE 7]>`, which
  is dead per `ROADMAP.md:3478`.
- `scripts.js` / `scripts_pp.js` → `src/web/scripts.ts` /
  `scripts_pp.ts`. Trim or replace jQuery patterns.
- Pretty-photo (`pp`) integration: keep if upstream still uses it,
  otherwise drop along with `scripts_pp.js`.

### 3. `smartpocket` (theme)

**Upstream files:** `themeconf.inc.php`, 34 `.tpl` files, 9 JS
(jquery.mobile + photoswipe), CSS, language files.

**Migration deltas:**

- `themeconf.inc.php` event handlers — `loc_index_thumbnails_selection`,
  `loc_end_index_category_thumbnails`, `loc_end_section_init`, `init`
  — all have typed events; fold into `Theme::subscribedEvents()`.
- Mobile detection: `get_device()` → `Piwigo\Core\DeviceService` (or
  whatever the §1.4-era equivalent is — check `src/Piwigo/Core/`
  first; if absent, add it as core work in this commit since at
  least Smartpocket and Modus need it).
- `SPThumbPicker` class moves to `src/SPThumbPicker.php`.
- The `$conf['use_standard_pages'] = false` write at file scope moves
  to `Theme::boot()` calling
  `ConfigService::set('use_standard_pages', false)` — or, better,
  declare it via `theme.json` if a `disablesStandardPages` flag is
  worth adding (existing schema has `useStandardPages` for the
  opposite direction; this would be its negation).
- `screen_size` cookie → typed `Piwigo\Session\SessionService` or a
  small `ScreenSize` value object on the request.
- Drop `jquery.mobile.css` (3.3k lines) only if the templates no
  longer depend on jQuery Mobile classes. If they do, port them to
  utility CSS during the conversion. **Decision deferred to
  implementation** — re-check after the Latte conversion.
- 34 `.tpl` files → 34 `.latte` files via the converter.

### 4. `AdminTools` (plugin)

**Upstream files:** `main.inc.php`, `admin.php`,
`include/MultiView.class.php` (341 LOC), `include/events.inc.php`
(380 LOC), templates, JS, CSS, fontello icons, 53 language files.

**Migration deltas:**

- `MultiView` class moves to `src/MultiView.php` under namespace
  `Piwigo\Plugin\AdminTools`. Methods drop the `global $conf`/`$user`
  pulls in favor of constructor-injected `ConfigService`,
  `CurrentUser`, `SessionService`.
- The view-as session logic stays semantically identical; rewrite as
  typed reads/writes against `SessionService`.
- `admin.php` → `src/Controller/AdminToolsAdminController.php`, plus
  an `AdminPagesRegistering` subscriber that registers slug
  `plugin-AdminTools` → that controller (mirrors how core admin pages
  register).
- The two public-page event handlers
  (`admintools_add_public_controller` and `admintools_save_picture` /
  `admintools_save_category`) become three `subscribedEvents` methods
  on the `Plugin` class.
- WS methods registered via `MultiView::register_ws` move into a
  `WsMethodsRegistering` subscriber that calls
  `$event->server->register(new MethodDefinition(...))` per
  `ROADMAP.md:1244` (use `AccessLevel::Administrator` since these are
  admin-only). Method names preserved verbatim:
  `pwg.AdminTools.setUser`, `pwg.AdminTools.deleteCache`, etc. (verify
  with the upstream call sites).
- The `admintools_remove_privacy` `set_prefilter` (which strips
  `U_SET_AS_REPRESENTATIVE` etc. from `picture.tpl`) becomes a
  template-variable conditional: core `picture.latte` already
  conditional-renders those buttons via `{if !empty($U_…)}` — the
  plugin just doesn't assign them in admin-impersonating mode.
- New core slots needed in `themes/_base/template/picture.latte` and
  `header.latte` for `$ADMINTOOLS_PUBLIC_TOOLBAR`. Add in the same
  commit.
- `admin_controller.tpl` + `public_controller.tpl` → `.latte`.
  `admin_controller.js` + `public_controller.js` + `mousetrap.min.js`
  → TypeScript under `src/web/`. Mousetrap can stay vendored (~10 KB,
  no successor) or move to npm `mousetrap` package.
- Config storage:
  `conf_update_param('AdminTools', $default_conf, true)` →
  `ConfigService::set('AdminTools', $default_conf)` in `install()`.

### 5. `LocalFilesEditor` (plugin)

**Upstream files:** `main.inc.php`, `maintain.inc.php`, `admin.php`,
`show_default.php`,
`include/{css,functions,lang,localconf,plug,tpl}.inc.php`,
`codemirror/` (~3 MB CM v2), templates, 55 language files.

**Migration deltas:**

- Whole-file editing of `local/config/*.php`, `local/css/*.css`,
  `local/language/*.lang.php`, `local/template-extension/*.tpl` — the
  upstream feature set. Map to filesystem reads/writes under the
  injected `Piwigo\Storage\LocalStorage` service. **NB:** the new
  template surface is Latte, not Smarty — the
  "template-extension" editing target is
  `local/template-extension/*.latte`.
- `show_default.php` exposes a read-only view of bundled originals
  (e.g. theme CSS) — fine to port verbatim against `LocalStorage`.
- `include/css.inc.php` injects "Customize CSS" actions per theme
  into the themes-installed admin page via a `set_prefilter`. New
  surface: subscribe to `LocEndThemesInstalled`, assign
  `$LFE_THEMES_DROPDOWN_EXTRA` (add the slot to
  `themes/admin/_base/template/themes_installed.latte`).
- `admin.php` → `src/Controller/LocalFilesEditorController.php` with
  one route per sub-action (config / CSS / lang / template).
- CodeMirror v2 dropped wholesale. Add npm deps:
  ```bash
  npm i @codemirror/state @codemirror/view @codemirror/lang-css \
        @codemirror/lang-javascript @codemirror/lang-html \
        @codemirror/lang-xml @codemirror/lang-php
  ```
  `src/web/editor.ts` builds an `EditorView` against the textarea,
  picking the language extension from a data-attribute on the
  `<textarea>`.
- `plugin_uninstall()`
  `DELETE FROM CONFIG_TABLE WHERE param='LocalFilesEditor'` →
  `ConfigService::delete('LocalFilesEditor')` in `Plugin::uninstall()`.

### 6. `TakeATour` (plugin)

**Upstream files:** `main.inc.php`, `admin.php`, 12 tour `.tpl` files
under `tours/`, `tpl/{admin,js_css}.tpl`,
`css/bootstrap-tour-standalone.css`,
`js/custom-bootstrap-tour-standalone.js`, 38 language files. Each
tour under `tours/<name>/config.inc.php` registers its own
`add_event_handler` callbacks that `set_prefilter()` admin templates
to inject tour-step anchors.

**Migration deltas:**

- bootstrap-tour → **shepherd.js**. Add `shepherd.js` npm dep; one
  `Shepherd.Tour` per `tours/<name>`.
- Tour configs (`tours/<name>/config.inc.php` + `tour.tpl`) —
  re-author each as a TypeScript module under
  `src/web/tours/<name>.ts` exporting a `defineTour()` function that
  returns the `Shepherd.Tour` config. The step copy in `tour.tpl`
  becomes `attachTo` selectors pointing at DOM elements (the same
  selectors the prefilters previously injected anchors at — but now
  the DOM exists natively).
- Where the legacy tour relied on a prefilter to add a wrapper
  element (e.g. `TAT_FC_7_prefilter` wrapping the photo-upload
  completion callback), the new approach is to **add a typed event
  or a stable CSS-class hook to the core template** in the same
  commit. Inventory per-tour:
  - `first_contact` (TAT_FC_*): 6 prefilters across photos_add /
    element_set_global / picture_modify / cat_modify /
    themes_installed. Each becomes a Shepherd step that selects an
    existing element by stable class (e.g. `.batch-completed`,
    `.photo-name-input`).
  - `manage_albums`, `edit_photos`, `config`, `plugins`, `privacy`,
    `scaling`, `2_7_0`, `2_8_0`, `2_9_0` — same pattern, smaller scope.
- The hard-coded `$version_="2_8_0"` "current" marker (verified at
  `main.inc.php:33`) becomes a `plugin.json` field or a constant on
  the Plugin class — bump to `2_9_0` (or the post-rewrite equivalent)
  during this commit.
- `tpl/admin.tpl` (the tour-launcher admin page) → Latte; the page
  registers via `AdminPagesRegistering`.
- The `loc_end_help` and `loc_end_no_photo_yet` prefilters become
  slot assignments to `$TAT_HELP_LINK` and `$TAT_NO_PHOTO_BUTTON`
  (slots added to core admin templates in this commit).
- `tours/2_7_0/` and `2_8_0/` ship verbatim (1:1 parity) even though
  they reference an obsolete pre-v17 feature set. Mark them with a
  "shows v16-era UI" caveat in the tour list.

### 7. `modus` (theme)

**Upstream files:** `themeconf.inc.php` (499 LOC — the largest file),
`functions.inc.php`, 16 `.tpl`, 25 CSS files (including 11 skins,
~600–1100 LOC each), 14 SCSS, JS, fonts.

**Migration deltas:**

- `themeconf.inc.php` — the giant file decomposes:
  - `modus_smarty_prefilter_wrap` and `modus_smarty_prefilter` — these
    are Smarty source rewrites with no Latte equivalent. Each
    prefilter rule needs to be converted to either a stable Latte
    slot, a custom Latte filter, or a server-side data
    transformation. **Likely the biggest single chunk of porting
    work in this commit.**
  - `modus_thumbs` (the `function` Smarty plugin generating the
    thumbnail HTML) — rewrite as a Latte
    `{define modusThumbs($thumbnails, $row_height)}` block or a
    custom Latte tag in `src/web/php/ModusLatteExtension.php`. The
    DerivativeImage / ImageStdParams calls stay; only the wiring
    changes.
  - `cssGradient` and `cssResolution` Smarty plugins — port to Latte
    filters registered through `Theme::boot()` against the active
    `Engine`.
  - Skin selection (`?skin=`, `$conf['modus_theme']['skin']`) — port
    to a `?skin=` query parameter handled in `Theme::onInit()`
    against `ConfigService`. The skin's `.inc.php` (which overrides
    `colorscheme`) becomes a small `skins/<name>.php` config (or
    merge config into the CSS via custom properties); no nested
    theme.json needed since these aren't independent themes.
  - `caps` cookie / session var (used to detect device-pixel-ratio
    for derivative selection) — typed `ScreenCapsService` or fold
    into `SessionService`. Keep wire-format identical for backward
    compatibility with any user mid-session at upgrade time.
- `parent: "default"` → `parent: "_base"` in `theme.json`.
- `themes/modus/template/picture_content_asize.tpl` — the size-aware
  derivative-picker output — converts to Latte; the
  `RenderElementContent` subscriber returns the rendered Latte string.
- `.scss` files: decide build path. The fork already uses Vite; add a
  `modus.scss` entry to the theme's Vite config and let Vite compile
  to CSS at build time (drop `pb` and `less` artifacts).
- `rv_cdn_*` integrations live behind `defined('RVCDN')` — drop these
  (the fork has no RVCDN deployment).
- 11 skin CSS files migrate verbatim minus `!important` cleanup
  (defer the `!important` cleanup to §3.1 — this commit ships them
  as-is).

## Files touched outside `plugins/` and `themes/<id>/`

The cross-cutting work this plan adds to **core** (separate from each
extension's own tree):

- `composer.json` — add 7 `psr-4` autoload entries (one per extension).
- `package.json` — add `@codemirror/state`, `@codemirror/view`,
  `@codemirror/lang-{css,javascript,html,xml,php}`, `shepherd.js`,
  optionally `mousetrap`.
- `vite.config.ts` — register the seven extensions' Vite entry points
  (each extension's `src/web/<entry>.ts`).
- `themes/_base/template/picture.latte` — add slots needed by
  AdminTools, language_switch.
- `themes/_base/template/header.latte` — add language_switch CSS slot.
- `themes/_base/template/index.latte` — verify the existing
  `$PLUGIN_INDEX_ACTIONS` slot or add it.
- `themes/admin/_base/template/themes_installed.latte` — add the
  `$LFE_THEMES_DROPDOWN_EXTRA` slot.
- `themes/admin/_base/template/help.latte` — add `$TAT_HELP_LINK`.
- `themes/admin/_base/template/no_photo_yet.latte` — add
  `$TAT_NO_PHOTO_BUTTON`.
- `themes/admin/_base/template/header.latte` — add `$TAT_BOOTSTRAP` /
  `$ADMINTOOLS_ADMIN_TOOLBAR`.
- `src/Piwigo/Core/DeviceService.php` (new) — if not already present,
  needed by smartpocket and modus. Replaces upstream `get_device()`.
- Possible new event classes if any upstream hook turns out to not
  have a typed event yet — add one PHP file per missing event under
  `src/Piwigo/Event/<Bucket>/`.

These cross-cutting bits should land **with the first extension that
needs them**, not as a standalone "infra" commit. The slot-addition
pattern means the same commit ships both the producer (plugin) and the
consumer (core template hook).

## Critical files to read before each extension

- `src/Piwigo/Plugin/PluginInterface.php`, `PluginManifest.php`,
  `PluginRegistry.php`, `Plugin/Testing/PluginTestCase.php`
- `src/Piwigo/Theme/ThemeInterface.php`, `ThemeManifest.php`,
  `ThemeRegistry.php`, `TemplateResolver.php`
- `docs/schemas/plugin.schema.json`, `docs/schemas/theme.schema.json`
- `src/Piwigo/Event/<bucket>/<EventName>.php` for each event the
  extension subscribes to (read shape before writing the handler)
- `tools/plugin-lint.php` (the rules every commit must clear)
- `tools/smarty-to-latte/Converter.php` (run on each `template/` dir)
- `tools/i18n/convert-ext-languages.php` (or call its helpers
  directly on the per-plugin language dir)

## Verification

### Per-extension (each commit)

1. `composer dump-autoload && composer lint:php && composer lint:latte
   && composer piwigo:lint && composer analyse && composer test` —
   all green.
2. **Manual smoke test** in the running app:
   - Plugin admin install/activate via the Extensions UI.
   - For each `subscribedEvents` entry, exercise the relevant flow:
     - **language_switch**: visit `?lang=fr_FR`, confirm UI switches;
       confirm flags render on the homepage.
     - **elegant** / **modus** / **smartpocket**: activate via the
       Themes UI, confirm a default user lands on the right
       templates, click through index / picture / search.
     - **AdminTools**: log in as admin, visit a public page, confirm
       the floating toolbar shows; impersonate a non-admin user; on
       a photo page click the quick-edit affordance and edit a tag.
     - **LocalFilesEditor**: open the plugin admin page, edit a
       local config value, save, confirm the change persisted and
       rendered (no PHP fatal). Open CSS editor → confirm CodeMirror
       6 mounts with syntax highlighting.
     - **TakeATour**: launch the `first_contact` tour from the admin
       Help page, step through every prompt — selectors must
       resolve (no "step skipped — element not found" warnings).
3. **Browser-driven check** for at least one extension per category
   (one plugin + one theme) via Playwright:
   ```text
   npx playwright test  # existing test config
   ```
   Extend the smoke suite to navigate the extension's flow.
4. `git status` clean; commit message follows the recent
   `feat(ws):` / `feat(ext):` style.

### End-to-end (after all 7 commits)

- `composer piwigo:lint plugins/AdminTools plugins/LocalFilesEditor
  plugins/TakeATour plugins/language_switch` → zero violations.
- `find plugins themes -name 'plugin.json' -o -name 'theme.json'` →
  9 files (4 plugins + 5 themes including the existing `_base`,
  `standard_pages`, `admin/*`).
- Fresh-install smoke (`bin/piwigo install`) with all four plugins
  activated and `elegant`/`modus`/`smartpocket` available — no fatal
  errors, no warnings in the Symfony log.
- Run `composer test:parallel` — every suite green
  (Unit / IntegrationParallelDb / IntegrationParallelHttp /
  IntegrationSerial).
- Spot-check the rendered output of each theme against the upstream
  16.3.0 screenshots committed under each upstream extension's
  `screenshot.png` — pixel-perfect parity is not required, but
  layout parity is.

## Out of scope

- The 405 third-party PEM plugins and 113 PEM themes — those are
  externally-owned packages that get rewritten by their authors.
- Stylelint cleanup of skin CSS (`!important` purge) — that's §3.1
  step 8 / 9.
- Open Sans webfont migration to `@fontsource` — that's §2.4.
- Tests beyond the smoke-level `PluginTestCase` per extension —
  fuller coverage lands when §1.8 (Pest + coverage + Infection)
  ships.
- Documenting these extensions in `docs/EXTENSIONS.md` — that table
  is PEM-mirror-focused; adding "bundled in core" rows is a
  follow-up.
