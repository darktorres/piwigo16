# Default plugins

Snapshot of the plugins shipped under `plugins/` on `16.x-rewrite` before the directory is wiped. Recorded so the bundled set can be re-acquired or re-implemented later if needed.

All five plugins live as untracked source trees alongside Piwigo core (none are PHP-Composer packages, none are git submodules). Each ships its own `main.inc.php` header and registers handlers through `EventDispatcher::addListener`. Where an upstream commit pin is recorded in `pem_metadata.txt`, that revision is the exact version present in this checkout.

| Plugin dir                | Header version | Settings   | Has admin UI | Upstream pin                                                   |
| ------------------------- | -------------- | ---------- | ------------ | -------------------------------------------------------------- |
| `LocalFilesEditor`        | `16.3.0`       | webmaster  | yes          | github.com/Piwigo/LocalFilesEditor @ `24d9457d` (2026-01-31)   |
| `nbc_ThemeChanger`        | `11.0.a`       | true       | yes          | piwigo.org SVN `extensions/nbc_ThemeChanger` @ rev `32434`     |
| `piwigo-openstreetmap`    | `16.b`         | webmaster  | yes          | github.com/Piwigo/piwigo-openstreetmap @ `d218b73f` (2026-03-03) |
| `piwigo-videojs`          | `16.b`         | true       | yes          | github.com/Piwigo/piwigo-videojs @ `38caa4f8` (2026-01-24)     |
| `user_tags`               | `1.0.5`        | true       | yes          | github.com/nikrou/user_tags (extension eid=441)                |

Also present at the top level:

- `plugins/index.php` — directory-listing guard (`<?php // not generally used`).
- `plugins/user_tags.dat` — runtime-generated serialized config for the `user_tags` plugin (created on first boot of that plugin via `userTags\Config::save_config`). Untracked, regenerable; safe to delete.

---

## LocalFilesEditor (v16.3.0)

- **Plugin URI:** http://piwigo.org/ext/extension_view.php?eid=144
- **Author:** Piwigo team
- **Permission:** webmaster only

In-admin editor for Piwigo's local-override files (`local/config/*.php`, theme CSS overrides, etc.) from the **Plugins → LocalFilesEditor** screen, with on-disk `.bak` backups on save. Defines `LOCALEDIT_PATH`, includes `include/<tab>.inc.php` per tab (`config`, `css`, `tpl`, `lang`, `plugin`), and uses CodeMirror (bundled in `codemirror/`) for syntax highlighting.

Also injects a per-theme "Customize CSS" link on the **Themes** admin screen via a Smarty prefilter:

- Listener: `loc_end_themes_installed` → `localfiles_css_link()` → `$template->setPrefilter('themes', 'localfiles_css_link_prefilter')` (`main.inc.php:40-56`).

Many third-party plugins (notably `piwigo-videojs`) rely on this for one-time configuration tweaks, which is why it's in the default set.

## nbc_ThemeChanger (v11.0.a)

- **Plugin URI:** http://piwigo.org/ext/extension_view.php?eid=214
- **Author:** Datajulien
- **Permission:** any user (per-category)

Per-category theme override. Configuration is a list of `category_id,theme_name` pairs maintained from the plugin's admin tab. On every page that hits `loc_end_section_init`, the listener `change_category_theme()` (`main.inc.php:40-69`):

1. Loads the category→theme map via `\Piwigo\Plugins\NbcThemeChanger\Config::themes()`.
2. If the current page is inside a mapped category and the theme directory exists under `themes/`, it swaps `$user['theme']` and re-instantiates `$template` with the new theme.
3. Special-cases `'stripped'` by calling `set_config_values()`.

The plugin instance is parked in `LoadedPluginRegistry`. Note: this is the only one of the five whose upstream is still on Piwigo's old SVN — there is no first-party Git mirror.

## piwigo-openstreetmap (v16.b)

- **Plugin URI:** https://piwigo.org/ext/extension_view.php?eid=701
- **Author:** xbgmsharp
- **Permission:** webmaster (admin), public (rendering)

OpenStreetMap integration — turns lat/lon EXIF metadata into Leaflet maps on the gallery side. Bundled assets: `leaflet/`, `fontello/`, custom mimetype icons.

Boot wiring (`main.inc.php`):

- Defines `OSM_PATH` and DB table constant `osm_place_table` (= `<prefix>osm_places`) — schema is created in `maintain.class.php` on plugin activation.
- Includes `gpx.inc.php` (GPX overlay handling).
- Page-conditional includes: `picture.inc.php` on photo pages, `category.inc.php` + `menu.inc.php` on index pages.
- Listeners:
  - `blockmanager_apply` → `osm_blockmanager_apply` (left-menu "World map" link, opt-in via config).
  - `loc_begin_index_category_thumbnails` → `osm_index_cat_thumbs_displayed`.
  - `loc_end_index` → `osm_end_index` (adds the **Display on map** action button on category/tag/search/recent results when items have lat/lon).
  - `begin_delete_elements` → `osm_begin_delete_elements` (clears the "displayed GPX" pointer if the file is deleted).
  - `ws_invoke_allowed` → `osm_ws_images_setInfo` (REST hook, lazy-loads `include/ws_functions.inc.php`).
- If `IN_ADMIN`, includes `admin/admin_boot.php`.
- If the `community` plugin is active, includes `admin/admin_batchmanager.php` and registers two extra `community_*` listeners.
- Config is auto-deserialized lazily via `\Piwigo\Plugins\PiwigoOpenstreetmap\Config` (no boot-time `safe_unserialize` needed, per the comment in `main.inc.php:25-27`).

`osmmap.php` / `osmmap2.php` / `osmmap3.php` / `osmmap4.php` are alternative full-page map views (different layouts) selected by the `&v=` query parameter built by `osm_make_map_index_url`.

## piwigo-videojs (v16.b)

- **Plugin URI:** https://piwigo.org/ext/extension_view.php?eid=610
- **Author:** xbgmsharp
- **Permission:** webmaster (admin), public (player)

Video.js player + video metadata pipeline. Adds first-class video support to a gallery that otherwise only handles still images. Bundles **four** complete Video.js player versions (`video-js-4/`, `video-js-5/`, `video-js-6/`, `video-js-7/`) — the active one is selectable from the plugin's admin screen.

Boot wiring (`main.inc.php:1-60+`):

- Defines `VIDEOJS_PATH`.
- Loads plugin language on `loading_lang`.
- Registers extra file extensions on the global `file_ext` config (lowercase + uppercase): `ogg`, `ogv`, `mp4`, `m4v`, `webm`, `webmv`, `strm` — via `\Piwigo\Config\Config::override('file_ext', …)` so the sync importer picks them up.
- Listeners (selection):
  - `render_element_content` → `vjs_render_media` (replaces the default image renderer with a `<video>`/Video.js block when the element is one of the registered extensions).
  - `get_mimetype_location` → `vjs_get_mimetype_icon` (fallback thumbnail when no poster is set).
  - `format_exif_data` → `vjs_format_exif_data` (folds video metadata into the EXIF panel).

Server-side dependency: an external metadata extractor — `ExifTool`, `FFmpeg`, or `MediaInfo` — must be installed on the host (per upstream README). Posters and Video.js thumbnails are regenerated from the **Plugins → VideoJS** screen, **Edit Photo**, or **Batch Manager**.

Config (player choice + sync settings) is auto-deserialized lazily by `\Piwigo\Plugins\PiwigoVideojs\Config`.

## user_tags (v1.0.5)

- **Plugin URI:** http://piwigo.org/ext/extension_view.php?eid=441
- **Author:** nikrou (Nicolas Roudaire) — independent of Piwigo team, fork-friendly GPLv2.
- **Permission:** any user (subject to plugin permission map)

Lets non-admin visitors add tags to images on the public photo page. Behaves more like a moderation feature than a UI tweak: the admin page (`admin.php`) configures three permission knobs persisted into the plugin's serialized config:

- `add` — minimum user status that can add tags.
- `delete` — minimum user status that can remove tags.
- `existing_tags_only` — when set, users may only attach pre-existing tags (no new-tag creation).

Boot wiring:

- `init.php` defines `T4U_PLUGIN_ROOT`, includes `include/constants.inc.php` and `include/autoload.inc.php`, then constructs the singleton `userTags\Config` and calls `load_config()`.
- In admin: registers `get_admin_plugin_menu_links` → `Config::plugin_admin_menu` and `get_popup_help_content` → `Config::get_admin_help`.
- On the public side: includes `public.php` which adds the "add tag" UI (`template/add_tags.tpl`, `js/jquery.addtags.js`, `css/style.css`) on the picture page.
- Exposes a JSON web service `user_tags.tags.list` (handler in `src/userTags/Ws.php`).

The serialized config file lives at `<root>/plugins/<plugin_dir>.dat` (i.e. `plugins/user_tags.dat`) — see the top-of-page note. This is the only one of the five plugins that writes runtime state directly into `plugins/`; the rest persist through the normal config table or the `_data/` tree.

---

## Re-acquiring after deletion

For each plugin you keep using, the cleanest re-source is:

```sh
# inside plugins/
git clone https://github.com/Piwigo/LocalFilesEditor.git    && (cd LocalFilesEditor    && git checkout 24d9457d)
git clone https://github.com/Piwigo/piwigo-openstreetmap.git && (cd piwigo-openstreetmap && git checkout d218b73f)
git clone https://github.com/Piwigo/piwigo-videojs.git       && (cd piwigo-videojs       && git checkout 38caa4f8)
git clone https://github.com/nikrou/user_tags.git            # tag/release v1.0.5
```

`nbc_ThemeChanger` has no first-party Git mirror — re-fetch from the Piwigo extension page (eid=214) or the SVN URL recorded in `pem_metadata.txt`.

For the corresponding `manifest.json` keys in the sibling `piwigo16-plugins` repo (when fetching prebuilt zips), see `docs/EXTENSIONS.md`.
