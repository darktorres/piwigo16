# Sibling extension repos

Inventory of every entry in each sibling repo's `manifest.json`.

| Repo                                        | Entries |
| ------------------------------------------- | ------: |
| [`piwigo16-plugins`](#piwigo16-plugins)     |     405 |
| [`piwigo16-themes`](#piwigo16-themes)       |     136 |
| [`piwigo16-languages`](#piwigo16-languages) |      62 |
| [`piwigo16-tools`](#piwigo16-tools)         |      33 |

The **Compatible with** column lists every Piwigo version upstream `piwigo.org/ext` marks the mirrored revision as compatible with, newest-first. It mirrors the `piwigo_compat` field on each entry in the sibling-repo `manifest.json`, which was backfilled once by querying `get_revision_list.php?category_id=X&version=Y` across all 22 known Piwigo versions and inverting the result. The data is a snapshot — see [`piwigo16-ext/README.md`](../../piwigo16-ext/README.md) for refresh instructions.

Of the 636 entries here, only 321 (~50%) are actually compatible with Piwigo 16; the rest target older Piwigo versions (some as old as 1.5). The mirror keeps whatever revision was retrievable upstream regardless of declared compatibility, so `piwigo16-tools` in particular is largely a historical archive (only 1 of 33 entries works on Piwigo 16). Filter on the column when looking for currently-supported extensions.

## Inter-extension dependencies

Some plugins include from another plugin's path (`PHPWG_PLUGINS_PATH . 'OtherPlugin/...'`) — an implicit hard dependency. The new fork plugin contract (ROADMAP §1.4 "Plugin dependencies") replaces this with a typed `require` field in `plugin.json`; this section enumerates the graph the converter has to preserve.

**Library plugins** (one-to-many fan-in):

| Library plugin              | Required by                                                                                                                                                | Count |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ----: |
| `GrumPluginClasses`         | `AMenuManager`, `AMetaData`, `ASearchEngine`, `AStat`, `ColorStat`, `EStat`, `GMaps`, `Histogram`, `lmt`, `UserStat`; legacy aliases `grum_plugins_classes` (`FormattedDescription`) and `grum_plugins_classes-2` (`mypolls`, `translator`) |    13 |
| `IndexManager`              | `ComOnIndex_17j`, `nbc_EditoOnIndex_1.3.e`, `nbc_LogonOnIndex1.4.f`, `nbc_TagsOnIndex_1.1.b`                                                                |     4 |

**Bilateral edges** (long tail):

| Dependent                       | Depends on               |
| ------------------------------- | ------------------------ |
| `AlbumPilot_1.4.0`              | `piwigo-videojs`         |
| `EasyRotate_0.7`                | `rotateImage`            |
| `HistoryIPExcluder_12.a`        | `nbc_HistoryIPExcluder`  |
| `icy_picture_modify-v2.4.6`     | `community`              |
| `LocalFilesEditor_16.3.0`       | `PersonalPlugin`         |
| `piwigo-cas_users_16.d`         | `cas_users`              |
| `PluginsManager_17l`            | `trash`                  |
| `PWG_Stuffs_15.a`               | `piclens`                |
| `SocialConnect_0.0.3`           | `oAuth`                  |
| `UserAdvManager_2.80.0`         | `NBC_UserAdvManager`     |

**Theme parent inheritance** — the upstream root theme `default` is renamed to `_base` in the fork, mirroring the bundled `themes/_base/` directory. The PEM mirror's `themeconf.inc.php` files declare these parents:

| Parent theme            | Child count |
| ----------------------- | ----------: |
| `_base` (was `default`) |          47 |
| `Pure_default`          |           9 |
| `gally-default`         |           6 |
| `OS_default`            |           4 |
| `stripped`              |           3 |
| `PwgCarbon_dft`         |           3 |
| `stripped_black_bloc`   |           2 |
| `simple`                |           2 |
| `elegant`               |           2 |
| `Sylvia`                |           2 |

Inheritance chains of depth ≥ 3 exist (e.g. a child theme → `Pure_default` → `_base`). 30 themes declare no parent at all (root themes that aren't `_base`).

Regeneration: `tools/audit-extension-deps.php` walks the sibling repos and re-emits this section (not yet built — produced by hand during the initial audit on 2026-05-10).

## piwigo16-plugins

405 entries.

| Key                                         | Filename                                        | Extension Name                                  | Compatible with                                                      |
| ------------------------------------------- | ----------------------------------------------- | ----------------------------------------------- | -------------------------------------------------------------------- |
| `3dhop_1.2`                                 | `3dhop_1.2.zip`                                 | 3dhop                                           | 2.10                                                                 |
| `About1menu_13.0.a`                         | `About1menu_13.0.a.zip`                         | About1menu                                      | 16, 15, 14, 13                                                       |
| `add_head_element_13.0.a`                   | `add_head_element_13.0.a.zip`                   | Add < head > Element                            | 16, 15, 14, 13                                                       |
| `add_index_12.0.a`                          | `add_index_12.0.a.zip`                          | Add Index                                       | 16, 15, 14, 13, 12                                                   |
| `Add_tags_mass_15.a`                        | `Add_tags_mass_15.a.zip`                        | Add tags Mass                                   | 16, 15                                                               |
| `AddInfo_2.7.c`                             | `AddInfo_2.7.c.zip`                             | AddInfo                                         | 2.7                                                                  |
| `AddInfousers_2.6.a`                        | `AddInfousers_2.6.a.zip`                        | Add Info Users                                  | 2.6                                                                  |
| `AdditionalPages_14.a`                      | `AdditionalPages_14.a.zip`                      | Additional Pages                                | 16, 15, 14, 13                                                       |
| `addjquery_13.0.a`                          | `addjquery_13.0.a.zip`                          | Add jQuery                                      | 16, 15, 14, 13                                                       |
| `addThis_1.0.0`                             | `addThis_1.0.0.zip`                             | AddThis                                         | 2.1, 2.0                                                             |
| `AddUsersNotes_15.a`                        | `AddUsersNotes_15.a.zip`                        | AddUsersNotes                                   | 16, 15                                                               |
| `admin_advices_2.0.2`                       | `admin_advices_2.0.2.zip`                       | Admin Advices                                   | 2.0                                                                  |
| `Admin_Messages_16.b`                       | `Admin_Messages_16.b.zip`                       | Admin Messages                                  | 16, 15                                                               |
| `admin_multi_view_2.6.1`                    | `admin_multi_view_2.6.1.zip`                    | Multi view                                      | 2.6                                                                  |
| `admin_ws_api`                              | `admin_ws_api.zip`                              | admin_ws_api                                    | 2.5                                                                  |
| `AdminTools_16.3.0`                         | `AdminTools_16.3.0.zip`                         | Admin Tools                                     | 16                                                                   |
| `adult_content_2.4.6`                       | `adult_content_2.4.6.zip`                       | Adult_content                                   | 16, 15, 14, 13, 12, 11                                               |
| `AdvancedSynchro-v1.0.3-beta`               | `AdvancedSynchro-v1.0.3-beta.zip`               | AdvancedSynchro                                 | 1.7                                                                  |
| `Ajax_Thumbnailer_2.2.c`                    | `Ajax_Thumbnailer_2.2.c.zip`                    | Ajax Thumbnailer                                | 2.2                                                                  |
| `AlbumPilot_1.4.0`                          | `AlbumPilot_1.4.0.zip`                          | AlbumPilot                                      | 16                                                                   |
| `albums_2.1.i`                              | `albums_2.1.i.zip`                              | Albums                                          | 2.1                                                                  |
| `AlwaysShowMetadata_12.0.a`                 | `AlwaysShowMetadata_12.0.a.zip`                 | AlwaysShowMetadata                              | 16, 15, 14, 13, 12                                                   |
| `AMenuManager_3.2.16`                       | `AMenuManager_3.2.16.zip`                       | Advanced Menu Manager                           | 16, 15, 14, 13                                                       |
| `AMetaData_0.7.1`                           | `AMetaData_0.7.1.zip`                           | Advanced Metadata                               | 2.4                                                                  |
| `AntiAspi_12.a`                             | `AntiAspi_12.a.zip`                             | AntiAspi                                        | 16, 15, 14, 13, 12, 11                                               |
| `ASearchEngine_1.2.0`                       | `ASearchEngine_1.2.0.zip`                       | Advanced Search Engine                          | 2.4                                                                  |
| `AStat_2.4.9`                               | `AStat_2.4.9.zip`                               | AStat                                           | 16, 15, 14                                                           |
| `autocorrect_filename_1.1.1`                | `autocorrect_filename_1.1.1.zip`                | Autocorrect Filename                            | 11, 2.10, 2.9                                                        |
| `automatic_size_13.a`                       | `automatic_size_13.a.zip`                       | Automatic Size                                  | 16, 15, 14, 13                                                       |
| `autoname`                                  | `autoname.zip`                                  | Autoname                                        | 1.7                                                                  |
| `Autosize_3.1.4`                            | `Autosize_3.1.4.zip`                            | Autosize                                        | 2.5                                                                  |
| `autoupdate_2.2.e`                          | `autoupdate_2.2.e.zip`                          | Piwigo Auto Upgrade                             | 2.2                                                                  |
| `Babar_V1.9.2`                              | `Babar_V1.9.2.zip`                              | Babar                                           | 16                                                                   |
| `Back2Front_1.3.5`                          | `Back2Front_1.3.5.zip`                          | Back2Front                                      | 16, 15, 14, 13, 12, 11                                               |
| `BanIP_13.0.a`                              | `BanIP_13.0.a.zip`                              | BanIP                                           | 16, 15, 14, 13                                                       |
| `batch_manager_added_by_12.a`               | `batch_manager_added_by_12.a.zip`               | Batch Manager, Added by                         | 16, 15, 14, 13, 12, 11                                               |
| `batch_manager_prefilters_14.a`             | `batch_manager_prefilters_14.a.zip`             | Batch Manager Prefilters                        | 16, 15, 14                                                           |
| `batch_manager_prefilters_ratio_11.0.a`     | `batch_manager_prefilters_ratio_11.0.a.zip`     | batch_manager_prefilters_ratio                  | 11                                                                   |
| `BatchCustomDerivatives_0.3.0`              | `BatchCustomDerivatives_0.3.0.zip`              | BatchCustomDerivatives                          | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                          |
| `BatchDownloader_16.a`                      | `BatchDownloader_16.a.zip`                      | Batch Downloader                                | 16                                                                   |
| `battle_V1.0`                               | `battle_V1.0.zip`                               | Battle                                          | 16, 15                                                               |
| `bbcode_bar_14.a`                           | `bbcode_bar_14.a.zip`                           | BBCode Bar                                      | 16, 15, 14                                                           |
| `betterrightClick`                          | `betterrightClick.zip`                          | Better No Right Click                           | 2.6                                                                  |
| `birthdate_13.a`                            | `birthdate_13.a.zip`                            | Birthdate                                       | 16, 15, 14, 13                                                       |
| `blob_thumbnails`                           | `blob_thumbnails.zip`                           | BlobThumbnails                                  | 2.0                                                                  |
| `block_search_13.0.a`                       | `block_search_13.0.a.zip`                       | Block search                                    | 16, 15, 14, 13                                                       |
| `BlocMenuAdd_1.7.g`                         | `BlocMenuAdd_1.7.g.zip`                         | BlocMenuAdd                                     | 1.7                                                                  |
| `bmp_description_15.a`                      | `bmp_description_15.a.zip`                      | Batch Manager, Photo Description                | 16, 15                                                               |
| `bmp_name`                                  | `bmp_name.zip`                                  | Batch Manager - Picture Name selector           | 16, 15                                                               |
| `BootstrapDefaultLanguageSwitch_2.7.2.1`    | `BootstrapDefaultLanguageSwitch_2.7.2.1.zip`    | Bootstrap Default Language Switch               | 11, 2.10, 2.9, 2.8, 2.7                                              |
| `bot_protection`                            | `bot_protection.zip`                            | BYB | Bot Protection                            | 16, 15                                                               |
| `bulk_manager_2.1b`                         | `bulk_manager_2.1b.zip`                         | Bulk Manager                                    | 2.1                                                                  |
| `c13y_upgrade_2.6.1`                        | `c13y_upgrade_2.6.1.zip`                        | Check upgrades                                  | 2.6                                                                  |
| `Calendrier`                                | `Calendrier.zip`                                | Calendrier                                      | 2.6                                                                  |
| `captcha_2.5.a`                             | `captcha_2.5.a.zip`                             | Captcha                                         | 2.5                                                                  |
| `castor`                                    | `castor.zip`                                    | Castor Chromecast                               | 16, 15                                                               |
| `cat_add_fav`                               | `cat_add_fav.zip`                               | CatAddFav                                       | 2.1                                                                  |
| `cdn_0.1`                                   | `cdn_0.1.zip`                                   | CDN                                             | 2.10, 2.9, 2.8, 2.7                                                  |
| `centralAdmin-3.3.0`                        | `centralAdmin-3.3.0.zip`                        | centralAdmin                                    | 16                                                                   |
| `change_who_added_photo_13.0.a`             | `change_who_added_photo_13.0.a.zip`             | Change who added photo                          | 16, 15, 14, 13                                                       |
| `charlies-3.2.4-2`                          | `charlies-3.2.4-2.zip`                          | Charlies\' content                              | 16, 15, 14, 13, 12, 11                                               |
| `check_files_integrity_16.d`                | `check_files_integrity_16.d.zip`                | Check Files Integrity                           | 16                                                                   |
| `check_uploads_15.a`                        | `check_uploads_15.a.zip`                        | Check Uploads                                   | 16, 15                                                               |
| `chromecast_0.1.2`                          | `chromecast_0.1.2.zip`                          | ChromeCast                                      | 2.6                                                                  |
| `cl_conflit_1.1.2`                          | `cl_conflit_1.1.2.zip`                          | cl_conflit                                      | 2.2, 2.1                                                             |
| `ColorPalette_15.a`                         | `ColorPalette_15.a.zip`                         | Color Palette                                   | 16, 15                                                               |
| `ColorStat_1.2.0`                           | `ColorStat_1.2.0.zip`                           | ColorStat                                       | 2.4                                                                  |
| `CommentEditor_1.0.p`                       | `CommentEditor_1.0.p.zip`                       | CommentEditor                                   | 2.0                                                                  |
| `Comments_Access_Manager_2.7.3`             | `Comments_Access_Manager_2.7.3.zip`             | Comments Access Manager                         | 2.10, 2.9, 2.8, 2.7                                                  |
| `comments_blacklist_1.3`                    | `comments_blacklist_1.3.zip`                    | Comments Blacklist                              | 16, 15, 14, 13, 12, 11, 2.10                                         |
| `Comments_on_Albums_14.a`                   | `Comments_on_Albums_14.a.zip`                   | Comments on Albums                              | 16, 15, 14                                                           |
| `community_16.f`                            | `community_16.f.zip`                            | Community                                       | 16                                                                   |
| `ComOnIndex_17j`                            | `ComOnIndex_17j.zip`                            | ComOnIndex                                      | 1.7                                                                  |
| `ConcoursPhoto_12.0.0`                      | `ConcoursPhoto_12.0.0.zip`                      | Concours Photo                                  | 16, 15, 14, 13, 12, 11                                               |
| `Contact1menu_13.0.a`                       | `Contact1menu_13.0.a.zip`                       | Contact1menu                                    | 16, 15, 14, 13                                                       |
| `ContactForm_16.a`                          | `ContactForm_16.a.zip`                          | ContactForm                                     | 16                                                                   |
| `ContestResults_1.3.c`                      | `ContestResults_1.3.c.zip`                      | ContestResults                                  | 2.2                                                                  |
| `contribute_to_demo_1.0.3`                  | `contribute_to_demo_1.0.3.zip`                  | Contribute to Demo                              | 2.8                                                                  |
| `cookieconsent_12.0.0c`                     | `cookieconsent_12.0.0c.zip`                     | Cookie Consent                                  | 16, 15, 14, 13, 12                                                   |
| `copy_thumbnails`                           | `copy_thumbnails.zip`                           | CopyThumbnails                                  | 2.0                                                                  |
| `Copyrights_15.a`                           | `Copyrights_15.a.zip`                           | Copyrights                                      | 16, 15                                                               |
| `Crop_Image_15.b`                           | `Crop_Image_15.b.zip`                           | Crop Image                                      | 16, 15                                                               |
| `CryptograPHP_15.a`                         | `CryptograPHP_15.a.zip`                         | Crypto Captcha                                  | 16, 15                                                               |
| `custom_contact_link_12.0.a`                | `custom_contact_link_12.0.a.zip`                | custom contact link on photo page               | 16, 15, 14, 13, 12                                                   |
| `custom_download_link_14.a`                 | `custom_download_link_14.a.zip`                 | Custom Download Link                            | 16, 15, 14                                                           |
| `CustomUsersFields v1.6`                    | `CustomUsersFields v1.6.zip`                    | Custom Users Fields                             | 2.10, 2.9, 2.8, 2.7                                                  |
| `db_backup1.7.1`                            | `db_backup1.7.1.zip`                            | DB Backup                                       | 1.7                                                                  |
| `de_activate_all_languages_13.0.a`          | `de_activate_all_languages_13.0.a.zip`          | de_activate all languages                       | 16, 15, 14, 13                                                       |
| `DefaultCreationDateToToday_1`              | `DefaultCreationDateToToday_1.zip`              | Default Creation Date To Today                  | 2.10, 2.9                                                            |
| `delete_hd_2.3.a`                           | `delete_hd_2.3.a.zip`                           | Delete HD                                       | 2.3                                                                  |
| `delete_hit_2.1.b`                          | `delete_hit_2.1.b.zip`                          | Delete Hit                                      | 2.1                                                                  |
| `delete_hit_rate_14.0.a`                    | `delete_hit_rate_14.0.a.zip`                    | Delete Hit/Rate                                 | 16, 15, 14                                                           |
| `delete_rate_2.1.c`                         | `delete_rate_2.1.c.zip`                         | Delete Rate                                     | 2.1                                                                  |
| `dew-1.0.0.3`                               | `dew-1.0.0.3.zip`                               | Diaporama everywhere                            | 1.7                                                                  |
| `dotcleareasy_1.0.8653`                     | `dotcleareasy_1.0.8653.zip`                     | Dotclear Easy                                   | 2.3, 2.2, 2.1                                                        |
| `download_by_size_12.b`                     | `download_by_size_12.b.zip`                     | Download by Size                                | 16, 15, 14, 13, 12                                                   |
| `download_counter_12.a`                     | `download_counter_12.a.zip`                     | Download Counter                                | 16, 15, 14, 13, 12, 11                                               |
| `download_formats_buttons_16.a`             | `download_formats_buttons_16.a.zip`             | Download formats buttons                        | 16                                                                   |
| `download_limits_12.a`                      | `download_limits_12.a.zip`                      | Download Limits                                 | 16, 15, 14, 13, 12                                                   |
| `download_multi`                            | `download_multi.zip`                            | Download Multi                                  | 2.3                                                                  |
| `download_permissions_12.a`                 | `download_permissions_12.a.zip`                 | Download Permissions                            | 16, 15, 14, 13, 12, 11                                               |
| `DynamicResize_0.2`                         | `DynamicResize_0.2.zip`                         | DynamicResize                                   | 2.2                                                                  |
| `DynamicResize_0.3`                         | `DynamicResize_0.3.zip`                         | DynamicResize                                   | 2.2                                                                  |
| `dynareceperio_2.7.a`                       | `dynareceperio_2.7.a.zip`                       | Dynamic Recent Period                           | 2.10, 2.9, 2.8, 2.7                                                  |
| `EasyCaptcha_1.2.0`                         | `EasyCaptcha_1.2.0.zip`                         | Easy Captcha                                    | 2.10, 2.9, 2.8, 2.7                                                  |
| `EasyRotate_0.7`                            | `EasyRotate_0.7.zip`                            | EasyRotate                                      | 2.10, 2.9, 2.8                                                       |
| `edit_filename_12.a`                        | `edit_filename_12.a.zip`                        | Edit Filename                                   | 16, 15, 14, 13, 12, 11                                               |
| `edit_gmaps_2.3.2`                          | `edit_gmaps_2.3.2.zip`                          | edit_gmaps                                      | 2.5                                                                  |
| `editorplus_15.b`                           | `editorplus_15.b.zip`                           | EditorPlus                                      | 16, 15                                                               |
| `EK_Calendar`                               | `EK_Calendar.zip`                               | EK_Calendar                                     | 2.4                                                                  |
| `EK_Galleria`                               | `EK_Galleria.zip`                               | EK_Galleria                                     | 2.4                                                                  |
| `elo0029b`                                  | `elo0029b.zip`                                  | Elo                                             | 16, 15                                                               |
| `EStat_0.1.0b`                              | `EStat_0.1.0b.zip`                              | EStat                                           | 2.4                                                                  |
| `event_cats_1.2.8`                          | `event_cats_1.2.8.zip`                          | Event Cats                                      | 2.6                                                                  |
| `event_tracer_12.a`                         | `event_tracer_12.a.zip`                         | Event tracer                                    | 16, 15, 14, 13, 12, 11                                               |
| `Evil_Blog_1.2.4`                           | `Evil_Blog_1.2.4.zip`                           | Evil_Blog                                       | 16, 15, 14, 13, 12                                                   |
| `exif_view_16.a`                            | `exif_view_16.a.zip`                            | EXIF View                                       | 16, 15                                                               |
| `exiftool_gps_0.8`                          | `exiftool_gps_0.8.zip`                          | Exiftool GPS                                    | 16, 15, 14, 13, 12, 11                                               |
| `exiftool_keywords_15.a`                    | `exiftool_keywords_15.a.zip`                    | Exiftool Keywords                               | 16, 15                                                               |
| `Expiry_Date_16.c`                          | `Expiry_Date_16.c.zip`                          | Expiry Date                                     | 16                                                                   |
| `export_data_13.b`                          | `export_data_13.b.zip`                          | Export Data                                     | 16, 15, 14, 13                                                       |
| `Extended_author_1.0.3`                     | `Extended_author_1.0.3.zip`                     | Extended_author                                 | 2.3, 2.2                                                             |
| `ExtendedDescription_16.c`                  | `ExtendedDescription_16.c.zip`                  | Extended Description                            | 16                                                                   |
| `external_ImageMagick_2.2.b`                | `external_ImageMagick_2.2.b.zip`                | External ImageMagick                            | 2.2                                                                  |
| `external_reference_2.9.a`                  | `external_reference_2.9.a.zip`                  | External Reference                              | 16, 15, 14, 13, 12, 11, 2.10, 2.9                                    |
| `ExternalAuth_0.5.0`                        | `ExternalAuth_0.5.0.zip`                        | ExternalAuth                                    | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                          |
| `extra_special_functions`                   | `extra_special_functions.zip`                   | Extra special functions                         | 2.1                                                                  |
| `face_tag`                                  | `face_tag.zip`                                  | Face Tag                                        | 16, 15                                                               |
| `face_tag_editor`                           | `face_tag_editor.zip`                           | Face Tag Editor                                 | 16, 15                                                               |
| `Facebook_Integration_1.0.0`                | `Facebook_Integration_1.0.0.zip`                | Facebook Integration                            | 2.1                                                                  |
| `FacebookPlug_2.7.29995`                    | `FacebookPlug_2.7.29995.zip`                    | FacebookPlug                                    | 11, 2.10, 2.9, 2.8, 2.7                                              |
| `Fancybox_1.5.3`                            | `Fancybox_1.5.3.zip`                            | Fancybox For Piwigo                             | 2.4, 2.3                                                             |
| `FancyFooter_1.0.5`                         | `FancyFooter_1.0.5.zip`                         | Fancy Footer                                    | 2.10, 2.9, 2.8                                                       |
| `FCKEditor_14.a`                            | `FCKEditor_14.a.zip`                            | FCK Editor                                      | 16, 15, 14                                                           |
| `File_Uploader_2.7.a`                       | `File_Uploader_2.7.a.zip`                       | File Uploader                                   | 2.10, 2.9, 2.8, 2.7                                                  |
| `Flash_Gallery_0.0.2`                       | `Flash_Gallery_0.0.2.zip`                       | Flash Gallery                                   | 2.0                                                                  |
| `flickr2piwigo_2-0-2`                       | `flickr2piwigo_2-0-2.zip`                       | Flickr2Piwigo                                   | 16                                                                   |
| `footer_count_17e`                          | `footer_count_17e.zip`                          | Footer count                                    | 1.7                                                                  |
| `Force_HTTPS_12.1`                          | `Force_HTTPS_12.1.zip`                          | Force HTTPS                                     | 16, 15, 14, 13, 12, 11                                               |
| `FormattedDescription-v1.0.0`               | `FormattedDescription-v1.0.0.zip`               | FormattedDescription                            | 1.7                                                                  |
| `Fotorama_14.b`                             | `Fotorama_14.b.zip`                             | Fotorama                                        | 16, 15, 14                                                           |
| `free_mail`                                 | `free_mail.zip`                                 | free_mail                                       | 2.0, 1.7                                                             |
| `Front2Back_2.3`                            | `Front2Back_2.3.zip`                            | Front2Back                                      | 2.3, 2.2                                                             |
| `fsrmp_1.2.4`                               | `fsrmp_1.2.4.zip`                               | Filtre fsrmp                                    | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                               |
| `fstats-1.0.1.0`                            | `fstats-1.0.1.0.zip`                            | Files statistics                                | 1.7                                                                  |
| `fun_citation_2.0.c`                        | `fun_citation_2.0.c.zip`                        | Fun Citation                                    | 2.5, 2.4, 2.3, 2.2, 2.1, 2.0, 1.7                                    |
| `fun_snow_2.3.12799`                        | `fun_snow_2.3.12799.zip`                        | Fun Snow                                        | 2.5, 2.4, 2.3                                                        |
| `GDThumb_1.0.27`                            | `GDThumb_1.0.27.zip`                            | gdThumb                                         | 16, 15, 14, 13, 12, 11                                               |
| `geo_tag_editor`                            | `geo_tag_editor.zip`                            | Geo Tag Editor                                  | 16, 15                                                               |
| `getFullMissingDerivatives-2.6.a`           | `getFullMissingDerivatives-2.6.a.zip`           | getFullMissingDerivatives                       | 2.10, 2.9, 2.8, 2.7, 2.6                                             |
| `GMaps_1.4.1`                               | `GMaps_1.4.1.zip`                               | GMaps                                           | 2.4                                                                  |
| `Google2Piwigo_1.2.0-beta`                  | `Google2Piwigo_1.2.0-beta.zip`                  | Google2Piwigo                                   | 2.6                                                                  |
| `GrumPluginClasses_3.5.15`                  | `GrumPluginClasses_3.5.15.zip`                  | Grum Plugin Classes                             | 16, 15, 14, 13                                                       |
| `GThumb_12.a`                               | `GThumb_12.a.zip`                               | GThumb+                                         | 16, 15, 14, 13, 12, 11                                               |
| `guest_view_thumb_only_2.5.b`               | `guest_view_thumb_only_2.5.b.zip`               | Guest view thumb only                           | 2.5                                                                  |
| `GuestBook_14.a`                            | `GuestBook_14.a.zip`                            | GuestBook                                       | 16, 15, 14                                                           |
| `gvideo_14.a`                               | `gvideo_14.a.zip`                               | Embedded Videos                                 | 16, 15, 14                                                           |
| `HasHigh_2.2.a`                             | `HasHigh_2.2.a.zip`                             | Has High                                        | 2.3, 2.2                                                             |
| `HDavecPrefixe`                             | `HDavecPrefixe.zip`                             | HDavecPrefixe                                   | 2.0                                                                  |
| `header_manager_14.b`                       | `header_manager_14.b.zip`                       | Header Manager                                  | 16, 15, 14                                                           |
| `hide_title_on_browse_path_12.a`            | `hide_title_on_browse_path_12.a.zip`            | Hide Title on Browse Path                       | 16, 15, 14, 13, 12, 11                                               |
| `Histogram_0.2.0`                           | `Histogram_0.2.0.zip`                           | histogram                                       | 2.4                                                                  |
| `History_cleanup_2.1.b`                     | `History_cleanup_2.1.b.zip`                     | History cleanup                                 | 2.2, 2.1                                                             |
| `HistoryIPExcluder_12.a`                    | `HistoryIPExcluder_12.a.zip`                    | History IP Excluder                             | 16, 15, 14, 13, 12, 11                                               |
| `hotblockerv2.0`                            | `hotblockerv2.0.zip`                            | HotBlocker                                      | 2.0                                                                  |
| `hotlink_compatibility_1.0.2`               | `hotlink_compatibility_1.0.2.zip`               | Hotlink Compatibility                           | 2.4                                                                  |
| `Icons_Set_1.2.1`                           | `Icons_Set_1.2.1.zip`                           | Icons Set                                       | 16, 15, 14, 13, 12, 11                                               |
| `icy_picture_modify-v2.4.6`                 | `icy_picture_modify-v2.4.6.zip`                 | Icy Picture Modify                              | 2.6, 2.5, 2.4                                                        |
| `ID_switch_2.2.c`                           | `ID_switch_2.2.c.zip`                           | ID Switch                                       | 2.2                                                                  |
| `Image_For_All20b`                          | `Image_For_All20b.zip`                          | ImageForAll                                     | 2.0                                                                  |
| `ImageMagick_GPS_1.6`                       | `ImageMagick_GPS_1.6.zip`                       | ImageMagick GPS                                 | 2.10, 2.9                                                            |
| `imgpreview_1.3.13`                         | `imgpreview_1.3.13.zip`                         | Image Preview                                   | 16, 15, 14, 13, 12                                                   |
| `ImportStat-v1.0`                           | `ImportStat-v1.0.zip`                           | ImportStat                                      | 1.7                                                                  |
| `instagram2piwigo_1.1.1`                    | `instagram2piwigo_1.1.1.zip`                    | Instagram2Piwigo                                | 2.6                                                                  |
| `ip_location`                               | `ip_location.zip`                               | IP Location                                     | 16, 15                                                               |
| `iptc_from_mac_2.8.a`                       | `iptc_from_mac_2.8.a.zip`                       | IPTC from Mac                                   | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                               |
| `language_switch_16.3.0`                    | `language_switch_16.3.0.zip`                    | Language Switch                                 | 16                                                                   |
| `language_switch_menubar_13.0.a`            | `language_switch_menubar_13.0.a.zip`            | Language Switch Menubar                         | 16, 15, 14, 13                                                       |
| `LCAS_2.3.1`                                | `LCAS_2.3.1.zip`                                | LCAS                                            | 2.6                                                                  |
| `Ldap_Login_16.1.0-1`                       | `Ldap_Login_16.1.0-1.zip`                       | Ldap Login                                      | 16                                                                   |
| `light_update_17b`                          | `light_update_17b.zip`                          | light_update                                    | 1.7                                                                  |
| `lightbox_14.a`                             | `lightbox_14.a.zip`                             | Lightbox                                        | 16, 15, 14                                                           |
| `like_dislike`                              | `like_dislike.zip`                              | Like / Dislike                                  | 16, 15                                                               |
| `linked_pages_12.a`                         | `linked_pages_12.a.zip`                         | Linked Pages                                    | 16, 15, 14, 13, 12, 11                                               |
| `LinkRoot_v2.0`                             | `LinkRoot_v2.0.zip`                             | LinkRoot                                        | 2.0                                                                  |
| `lmt_1.4.1`                                 | `lmt_1.4.1.zip`                                 | LMT                                             | 2.4                                                                  |
| `LocalFilesEditor_16.3.0`                   | `LocalFilesEditor_16.3.0.zip`                   | LocalFiles Editor                               | 16                                                                   |
| `log_failed_logins`                         | `log_failed_logins.zip`                         | Log Failed Logins                               | 2.10, 2.9, 2.8, 2.7                                                  |
| `look_like_gbo2_V151`                       | `look_like_gbo2_V151.zip`                       | Look_like_Gbo 2                                 | 16, 15, 14                                                           |
| `m365connect_15.c`                          | `m365connect_15.c.zip`                          | Microsoft 365 Connect                           | 16, 15                                                               |
| `Mail_supervisor_2.0.1`                     | `Mail_supervisor_2.0.1.zip`                     | Mail_supervisor                                 | 2.5                                                                  |
| `manage_properties_photos_15.0.b`           | `manage_properties_photos_15.0.b.zip`           | Manage Properties Photos                        | 16, 15, 14                                                           |
| `Media_Icon_16.a`                           | `Media_Icon_16.a.zip`                           | Media Icon                                      | 16                                                                   |
| `memories_12.0.a`                           | `memories_12.0.a.zip`                           | Memories                                        | 16, 15, 14, 13, 12                                                   |
| `menalto2piwigo_13.a-beta`                  | `menalto2piwigo_13.a-beta.zip`                  | Menalto2Piwigo                                  | 16, 15, 14, 13, 12                                                   |
| `MenubarManager_17e`                        | `MenubarManager_17e.zip`                        | Menubar Manager                                 | 1.7                                                                  |
| `MenuRandomPhoto_14.b`                      | `MenuRandomPhoto_14.b.zip`                      | Menu Random Photo                               | 16, 15, 14                                                           |
| `MenuTags_1.1.1`                            | `MenuTags_1.1.1.zip`                            | Menu Tags                                       | 16, 15, 14                                                           |
| `meta_16.a`                                 | `meta_16.a.zip`                                 | Meta                                            | 16                                                                   |
| `meta_og_14.0.f`                            | `meta_og_14.0.f.zip`                            | Metadata Open Graph                             | 16, 15, 14                                                           |
| `metadata_display`                          | `metadata_display.zip`                          | Metadata Display                                | 16, 15                                                               |
| `metasimple_2.1.d`                          | `metasimple_2.1.d.zip`                          | Metasimple                                      | 2.1                                                                  |
| `miro_v1.6`                                 | `miro_v1.6.zip`                                 | Miro                                            | 16, 15                                                               |
| `MM_View_CompMetaPict-1.1`                  | `MM_View_CompMetaPict-1.1.zip`                  | MM View CompMetaPict                            | 1.7                                                                  |
| `Mobile_Theme_for_Tablets_12.a`             | `Mobile_Theme_for_Tablets_12.a.zip`             | Mobile Theme for Tablets                        | 16, 15, 14, 13, 12                                                   |
| `most_downloaded_12.0.a`                    | `most_downloaded_12.0.a.zip`                    | Most Downloaded                                 | 16, 15, 14, 13, 12                                                   |
| `MostCommented_11.0.a`                      | `MostCommented_11.0.a.zip`                      | Most Commented                                  | 16, 15, 14, 13, 12, 11                                               |
| `MPVC-v1.3`                                 | `MPVC-v1.3.zip`                                 | MPVC                                            | 1.7                                                                  |
| `MugShot`                                   | `MugShot.zip`                                   | MugShot                                         | 16, 15, 14, 13, 12, 11                                               |
| `multiselect_whatsapp`                      | `multiselect_whatsapp.zip`                      | MultiselectBeta                                 | 1.3                                                                  |
| `music_player_2.3.5`                        | `music_player_2.3.5.zip`                        | Music_player                                    | 13, 12, 11                                                           |
| `MyPiwiShop_1.0.1`                          | `MyPiwiShop_1.0.1.zip`                          | MyPiwiShop                                      | 2.6                                                                  |
| `mypolls_2.1.0-a2`                          | `mypolls_2.1.0-a2.zip`                          | MyPolls                                         | 2.0                                                                  |
| `nbc_EditoOnIndex_1.3.e`                    | `nbc_EditoOnIndex_1.3.e.zip`                    | nbc EditoOnIndex                                | 1.7                                                                  |
| `nbc_LinkUser2PhpBB_1.0.c`                  | `nbc_LinkUser2PhpBB_1.0.c.zip`                  | nbc LinkUser2PhpBB                              | 1.7                                                                  |
| `nbc_LinkUser2PunBB_2.2.f`                  | `nbc_LinkUser2PunBB_2.2.f.zip`                  | nbc LinkUser2PunBB                              | 1.7                                                                  |
| `nbc_LogonOnIndex1.4.f`                     | `nbc_LogonOnIndex1.4.f.zip`                     | nbc LogonOnIndex                                | 1.7                                                                  |
| `nbc_News`                                  | `nbc_News.zip`                                  | News                                            | 1.7                                                                  |
| `nbc_TagsOnIndex_1.1.b`                     | `nbc_TagsOnIndex_1.1.b.zip`                     | nbc TagsOnIndex                                 | 1.7                                                                  |
| `nbc_ThemeChanger_11.0.a`                   | `nbc_ThemeChanger_11.0.a.zip`                   | nbc ThemeChanger                                | 16, 15, 14, 13, 12, 11                                               |
| `NBM_Subscriber_2.7.3`                      | `NBM_Subscriber_2.7.3.zip`                      | NBM_Subscriber                                  | 2.10, 2.9, 2.8, 2.7                                                  |
| `no_stats_for_robots_2.10.a`                | `no_stats_for_robots_2.10.a.zip`                | No Stats For Robots                             | 16, 15, 14, 13, 12, 11, 2.10                                         |
| `NoPassword`                                | `NoPassword.zip`                                | NoPassword                                      | 2.10, 2.9, 2.8, 2.7                                                  |
| `oAuth_15.a`                                | `oAuth_15.a.zip`                                | Social Connect                                  | 16, 15                                                               |
| `offset_creation_date_1.1.0`                | `offset_creation_date_1.1.0.zip`                | Offset Creation Date                            | 16, 15, 14, 13, 12                                                   |
| `okta_connect_15.a`                         | `okta_connect_15.a.zip`                         | Okta Connect                                    | 16, 15                                                               |
| `online_users_16.a`                         | `online_users_16.a.zip`                         | Online users                                    | 16                                                                   |
| `OpenIdConnect`                             | `OpenIdConnect.zip`                             | OpenID Connect Next                             | 16, 15                                                               |
| `optipic-1.21.0`                            | `optipic-1.21.0.zip`                            | OptiPic images optimization and WebP convertion | 11                                                                   |
| `paMOOramics_2.2`                           | `paMOOramics_2.2.zip`                           | paMOOramics                                     | 2.3, 2.2                                                             |
| `pAnchor_0.5b`                              | `pAnchor_0.5b.zip`                              | pAnchor                                         | 2.1                                                                  |
| `Panoramas_14.a`                            | `Panoramas_14.a.zip`                            | Panoramas                                       | 16, 15, 14                                                           |
| `Password_Policy_16.a`                      | `Password_Policy_16.a.zip`                      | Password Policy                                 | 16                                                                   |
| `PayPalShoppingCart_12.a`                   | `PayPalShoppingCart_12.a.zip`                   | PayPal Shopping Cart                            | 16, 15, 14, 13, 12                                                   |
| `pbase2piwigo_1.1.1`                        | `pbase2piwigo_1.1.1.zip`                        | PBase2Piwigo                                    | 2.6                                                                  |
| `pdf2tab_12.0.a`                            | `pdf2tab_12.0.a.zip`                            | PDF2Tab                                         | 16, 15, 14, 13, 12                                                   |
| `permalink_generator_12.a`                  | `permalink_generator_12.a.zip`                  | Permalink Generator                             | 16, 15, 14, 13, 12                                                   |
| `PersoAbout_13.0.a`                         | `PersoAbout_13.0.a.zip`                         | PersoAbout                                      | 16, 15, 14, 13                                                       |
| `PersoFavicon_13.0.a`                       | `PersoFavicon_13.0.a.zip`                       | PersoFavicon                                    | 16, 15, 14, 13                                                       |
| `PersoFooter_13.0.a`                        | `PersoFooter_13.0.a.zip`                        | Perso Footer                                    | 16, 15, 14, 13                                                       |
| `PEUoverlay`                                | `PEUoverlay.zip`                                | PEU Overlay                                     | 16, 15, 14, 13, 12, 11                                               |
| `Photo_add_by_16.a`                         | `Photo_add_by_16.a.zip`                         | Photo Added by                                  | 16                                                                   |
| `photo_from_email_11.a`                     | `photo_from_email_11.a.zip`                     | Photo from Email                                | 11                                                                   |
| `photo_update_15.a`                         | `photo_update_15.a.zip`                         | Photo Update                                    | 16, 15                                                               |
| `Photos_2.1.d`                              | `Photos_2.1.d.zip`                              | Photos                                          | 2.1                                                                  |
| `PhotoSphere_0.3.0`                         | `PhotoSphere_0.3.0.zip`                         | PhotoSphere                                     | 16, 15, 14, 13                                                       |
| `photoWidget-0.5.1`                         | `photoWidget-0.5.1.zip`                         | photoWidget                                     | 2.6                                                                  |
| `phpcaptchapiwigo-1.1.1`                    | `phpcaptchapiwigo-1.1.1.zip`                    | PHP Captcha for Piwigo                          | 16, 15, 14, 13, 12                                                   |
| `physical2virtual_2.7.k`                    | `physical2virtual_2.7.k.zip`                    | physical2virtual                                | 2.10, 2.9, 2.8, 2.7, 2.6                                             |
| `physical_photo_move_2.30`                  | `physical_photo_move_2.30.zip`                  | Physical Photo Move                             | 16, 15, 14, 13, 12, 11                                               |
| `piclens-0.0.1.0`                           | `piclens-0.0.1.0.zip`                           | PicLens                                         | 1.7                                                                  |
| `piclens_2.7.a`                             | `piclens_2.7.a.zip`                             | Cooliris/Piclens                                | 2.10, 2.9, 2.8, 2.7                                                  |
| `picture_wall_17a`                          | `picture_wall_17a.zip`                          | Build a wall                                    | 1.7                                                                  |
| `Piwecard_16.a`                             | `Piwecard_16.a.zip`                             | Piwecard                                        | 16, 15                                                               |
| `PiwiBar`                                   | `PiwiBar.zip`                                   | Piwi Bar                                        | 2.10, 2.9, 2.8                                                       |
| `piwigo-ai_0.0.3-beta`                      | `piwigo-ai_0.0.3-beta.zip`                      | Piwigo AI                                       | 16                                                                   |
| `piwigo-cas_users_16.d`                     | `piwigo-cas_users_16.d.zip`                     | CAS users                                       | 16                                                                   |
| `piwigo-cdnplus_2.8.a`                      | `piwigo-cdnplus_2.8.a.zip`                      | CDNPlus                                         | 2.10, 2.9, 2.8                                                       |
| `piwigo-custom_missing_derivatives_0.0.1`   | `piwigo-custom_missing_derivatives_0.0.1.zip`   | custom_missing_derivatives                      | 2.10, 2.9, 2.8                                                       |
| `piwigo-easy-config_1.2`                    | `piwigo-easy-config_1.2.zip`                    | EasyConfig                                      | 16, 15                                                               |
| `piwigo-export_formats_16.c`                | `piwigo-export_formats_16.c.zip`                | Export Formats                                  | 16                                                                   |
| `piwigo-facetag_0.0.3`                      | `piwigo-facetag_0.0.3.zip`                      | facetag                                         | 2.10, 2.9, 2.8                                                       |
| `piwigo-familink_V1.0.0`                    | `piwigo-familink_V1.0.0.zip`                    | Piwigo Familink Prints                          | 16                                                                   |
| `piwigo-forecast_2.8.b`                     | `piwigo-forecast_2.8.b.zip`                     | Forecast                                        | 2.10, 2.9, 2.8                                                       |
| `piwigo-jplayer-0.6`                        | `piwigo-jplayer-0.6.zip`                        | jplayer                                         | 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5                                    |
| `piwigo-openstreetmap_16.b`                 | `piwigo-openstreetmap_16.b.zip`                 | piwigo-openstreetmap                            | 16                                                                   |
| `piwigo-panorama_1.0`                       | `piwigo-panorama_1.0.zip`                       | Piwigo Panorama                                 | 2.10                                                                 |
| `piwigo-photoswipe-download-button`         | `piwigo-photoswipe-download-button.zip`         | PhotoSwipe Download Button                      | 16, 15                                                               |
| `piwigo-register-codes_1.5`                 | `piwigo-register-codes_1.5.zip`                 | Register Codes                                  | 16, 15, 14, 13, 12, 11                                               |
| `piwigo-two_factor_16.c`                    | `piwigo-two_factor_16.c.zip`                    | Two Factor Authentication (2FA)                 | 16                                                                   |
| `piwigo-videojs_16.b`                       | `piwigo-videojs_16.b.zip`                       | piwigo-videojs                                  | 16, 15, 14                                                           |
| `Piwigo-VirtualizeAlbumById_0.2.d`          | `Piwigo-VirtualizeAlbumById_0.2.d.zip`          | VirtualizeAlbumById                             | 16, 15, 14, 13, 2.10, 2.9                                            |
| `piwigo4blog-0.1.0-beta3`                   | `piwigo4blog-0.1.0-beta3.zip`                   | Piwigo4blog                                     | 2.10                                                                 |
| `piwigo_failed_logins_1.1.1`                | `piwigo_failed_logins_1.1.1.zip`                | Failed logins                                   | 16, 15, 14, 13                                                       |
| `piwigo_masonry_grid_2.2`                   | `piwigo_masonry_grid_2.2.zip`                   | Masonry grid                                    | 16, 15, 14, 13, 12, 11                                               |
| `piwigo_privacy_1.0.1`                      | `piwigo_privacy_1.0.1.zip`                      | piwigo_privacy                                  | 11, 2.10, 2.9, 2.8                                                   |
| `piwigo_pst-1-0a`                           | `piwigo_pst-1-0a.zip`                           | Protect Search and Tags                         | 2.10, 2.9, 2.8, 2.7                                                  |
| `PiwigoClientWsExts_1.0.19`                 | `PiwigoClientWsExts_1.0.19.zip`                 | PiwigoClientWsExts                              | 16, 15, 14, 13, 12, 11, 2.10                                         |
| `Piwigodonate_13.0.a`                       | `Piwigodonate_13.0.a.zip`                       | Piwigo Donate                                   | 13                                                                   |
| `piwigoplugin_ldap_login_2.10d`             | `piwigoplugin_ldap_login_2.10d.zip`             | ldap_login for v11                              | 11                                                                   |
| `piwishack_12.a`                            | `piwishack_12.a.zip`                            | PiwiShack                                       | 16, 15, 14, 13, 12                                                   |
| `plugin_analyzer-v1.0`                      | `plugin_analyzer-v1.0.zip`                      | Plugin Analyzer                                 | 1.7                                                                  |
| `plugin_lang_analysis_1.2.0`                | `plugin_lang_analysis_1.2.0.zip`                | Language Analysis                               | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                          |
| `Plugin_Register_Punbb-1_3a`                | `Plugin_Register_Punbb-1_3a.zip`                | Register_PunBB                                  | 1.7                                                                  |
| `PluginsManager_17l`                        | `PluginsManager_17l.zip`                        | Plugins Manager                                 | 1.7                                                                  |
| `polaroid_2.7.a`                            | `polaroid_2.7.a.zip`                            | Polaroid                                        | 11, 2.10, 2.9, 2.8, 2.7                                              |
| `posted_date_changer_11.0.a`                | `posted_date_changer_11.0.a.zip`                | Posted Date Changer                             | 16, 15, 14, 13, 12, 11                                               |
| `Preload`                                   | `Preload.zip`                                   | Preload                                         | 2.10, 2.9, 2.8, 2.7                                                  |
| `prepaid_credits_15.a`                      | `prepaid_credits_15.a.zip`                      | Prepaid Credits                                 | 16, 15                                                               |
| `PresyncAutoRename_12.1`                    | `PresyncAutoRename_12.1.zip`                    | PresyncAutoRename                               | 16, 15, 14, 13, 12, 11                                               |
| `prevnext_0.4`                              | `prevnext_0.4.zip`                              | PrevNext                                        | 2.10, 2.9, 2.8                                                       |
| `private_share_14.a`                        | `private_share_14.a.zip`                        | Private Share                                   | 16, 15, 14                                                           |
| `properties_mass_update_14.a`               | `properties_mass_update_14.a.zip`               | Properties Mass Update                          | 16, 15, 14                                                           |
| `protect_notification_2.9.a`                | `protect_notification_2.9.a.zip`                | Protect Notification                            | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                               |
| `ProtectedAlbums_0.5.b`                     | `ProtectedAlbums_0.5.b.zip`                     | Protected Albums                                | 2.10, 2.9, 2.8, 2.7                                                  |
| `Prune_History_12.a`                        | `Prune_History_12.a.zip`                        | Prune History                                   | 16, 15, 14, 13, 12, 11                                               |
| `pwg_images_addSimple_2.1f`                 | `pwg_images_addSimple_2.1f.zip`                 | pwg.images.addSimple                            | 2.1                                                                  |
| `PWG_Stuffs_15.a`                           | `PWG_Stuffs_15.a.zip`                           | PWG Stuffs                                      | 16, 15                                                               |
| `pwgCumulus-0.8.1`                          | `pwgCumulus-0.8.1.zip`                          | Cumulus Tags Cloud                              | 2.6                                                                  |
| `quick_fav_14.b`                            | `quick_fav_14.b.zip`                            | QuickFav                                        | 16, 15, 14                                                           |
| `quick_star_V1.4`                           | `quick_star_V1.4.zip`                           | quick_star                                      | 16                                                                   |
| `Random_Header_2.2`                         | `Random_Header_2.2.zip`                         | Random Header                                   | 2.4, 2.3, 2.2                                                        |
| `random_quote_0.2`                          | `random_quote_0.2.zip`                          | Random Quote                                    | 2.5                                                                  |
| `read_metadata_14.0.a`                      | `read_metadata_14.0.a.zip`                      | Read Metadata                                   | 16, 15, 14                                                           |
| `regenerateThumbnails_2.2.g`                | `regenerateThumbnails_2.2.g.zip`                | Thumbnails Regeneration                         | 2.2                                                                  |
| `regenerateWebsize_2.2.d`                   | `regenerateWebsize_2.2.d.zip`                   | Websize Regeneration                            | 2.2                                                                  |
| `Register_FluxBB_2.8.0`                     | `Register_FluxBB_2.8.0.zip`                     | Register_FluxBB                                 | 2.10, 2.9, 2.8, 2.7                                                  |
| `Register_PhpBB_2.5.0`                      | `Register_PhpBB_2.5.0.zip`                      | Register_PhpBB                                  | 2.5                                                                  |
| `Reisishot-Login-Security-for-PIWIGO_1.0.2` | `Reisishot-Login-Security-for-PIWIGO_1.0.2.zip` | Reisishot Login Security                        | 2.10, 2.9                                                            |
| `Reisishot-Visual-Editor_1.1.1`             | `Reisishot-Visual-Editor_1.1.1.zip`             | Reisishot Visual Editor                         | 2.10, 2.9                                                            |
| `RemoveMbHeader_20a`                        | `RemoveMbHeader_20a.zip`                        | Remove MB Header                                | 2.0                                                                  |
| `reply_to_12.a`                             | `reply_to_12.a.zip`                             | Reply To                                        | 16, 15, 14, 13, 12                                                   |
| `reset_level_13.0.a`                        | `reset_level_13.0.a.zip`                        | Reset Level                                     | 16, 15, 14, 13                                                       |
| `reset_manual_order_12.a`                   | `reset_manual_order_12.a.zip`                   | Reset manual order                              | 16, 15, 14, 13, 12, 11                                               |
| `resize_excluder_15.a`                      | `resize_excluder_15.a.zip`                      | Resize excluder                                 | 16, 15                                                               |
| `rightClick_16.a`                           | `rightClick_16.a.zip`                           | rightClick                                      | 16                                                                   |
| `rotateImage_11.0.a`                        | `rotateImage_11.0.a.zip`                        | rotateImage                                     | 16, 15, 14, 13, 12, 11                                               |
| `rv_akismet_12.a`                           | `rv_akismet_12.a.zip`                           | RV Akismet                                      | 16, 15, 14, 13, 12                                                   |
| `rv_autocomplete_12.a`                      | `rv_autocomplete_12.a.zip`                      | RV Autocomplete                                 | 16, 15, 14, 13, 12, 11                                               |
| `rv_db_integrity_12.a`                      | `rv_db_integrity_12.a.zip`                      | RV DB Integrity                                 | 16, 15, 14, 13, 12, 11                                               |
| `rv_gmaps_2.10.b`                           | `rv_gmaps_2.10.b.zip`                           | RV Maps & Earth                                 | 11, 2.10, 2.9                                                        |
| `rv_menutree_2.9.a`                         | `rv_menutree_2.9.a.zip`                         | RV Menutree                                     | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                               |
| `rv_sitemap_2.9.a`                          | `rv_sitemap_2.9.a.zip`                          | RV Sitemap                                      | 16, 15, 14, 13, 12, 11, 2.10, 2.9                                    |
| `rv_thumbs_2.1.a`                           | `rv_thumbs_2.1.a.zip`                           | RV Thumbs                                       | 2.1                                                                  |
| `rv_tscroller_12.a`                         | `rv_tscroller_12.a.zip`                         | RV Thumb Scroller                               | 16, 15, 14, 13, 12, 11                                               |
| `s3upload`                                  | `s3upload.zip`                                  | S3Upload                                        | 2.5                                                                  |
| `scheduler_2.6.a`                           | `scheduler_2.6.a.zip`                           | Scheduler                                       | 2.6, 2.5, 2.4                                                        |
| `search_links_14.a`                         | `search_links_14.a.zip`                         | Search Links                                    | 16, 15, 14                                                           |
| `searcht1menu_13.0.a`                       | `searcht1menu_13.0.a.zip`                       | Search 1 menu                                   | 16, 15, 14, 13                                                       |
| `secureImages_0.5.0-beta`                   | `secureImages_0.5.0-beta.zip`                   | Secure Images                                   | 1.7                                                                  |
| `SecurityHeaders_1.1.0`                     | `SecurityHeaders_1.1.0.zip`                     | Security Headers                                | 16, 15, 14, 13                                                       |
| `see_my_photos_13.0.a`                      | `see_my_photos_13.0.a.zip`                      | See my photos                                   | 16, 15, 14, 13                                                       |
| `see_photos_by_user_14.0.a`                 | `see_photos_by_user_14.0.a.zip`                 | See photos by user                              | 16, 15, 14                                                           |
| `set_plugins_1.1.4`                         | `set_plugins_1.1.4.zip`                         | set_plugins                                     | 2.3                                                                  |
| `Shadogo_0.2.0`                             | `Shadogo_0.2.0.zip`                             | Shadogo                                         | 2.3, 2.2, 2.1                                                        |
| `ShareAlbum_16.1`                           | `ShareAlbum_16.1.zip`                           | Share Album                                     | 16, 15                                                               |
| `ShareThis_2.10.a`                          | `ShareThis_2.10.a.zip`                          | ShareThis                                       | 2.10                                                                 |
| `show_photo_identifier_14.a`                | `show_photo_identifier_14.a.zip`                | Show Photo Identifier                           | 16, 15, 14                                                           |
| `showcase_subscribe_2.7.a`                  | `showcase_subscribe_2.7.a.zip`                  | Showcase Register                               | 2.6                                                                  |
| `simple_sort_orders`                        | `simple_sort_orders.zip`                        | Simple Sort Orders                              | 2.3, 2.2                                                             |
| `SimpleCopyright`                           | `SimpleCopyright.zip`                           | Simple Copyright                                | 16, 15, 14, 13, 12                                                   |
| `skeleton_12.a`                             | `skeleton_12.a.zip`                             | Skeleton                                        | 16, 15, 14, 13, 12, 11                                               |
| `SmartAlbums_16.c`                          | `SmartAlbums_16.c.zip`                          | SmartAlbums                                     | 16                                                                   |
| `SmartTooltip_002`                          | `SmartTooltip_002.zip`                          | SmartTooltip                                    | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                               |
| `smileys_votes`                             | `smileys_votes.zip`                             | Smileys & Votes                                 | 16, 15                                                               |
| `SmiliesSupport_2.7`                        | `SmiliesSupport_2.7.zip`                        | Smilies Support                                 | 16, 15, 14, 13, 12, 11, 2.10                                         |
| `SocialButtons_14.e`                        | `SocialButtons_14.e.zip`                        | Social Buttons                                  | 16, 15, 14                                                           |
| `SocialConnect_0.0.3`                       | `SocialConnect_0.0.3.zip`                       | Social Connect (forked)                         | 16, 15, 14, 13                                                       |
| `SortOrders_1.3.1`                          | `SortOrders_1.3.1.zip`                          | SortOrders                                      | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                          |
| `spread_menus_2.1.d`                        | `spread_menus_2.1.d.zip`                        | Spread menus                                    | 2.1                                                                  |
| `square_thumbnails_2.2.c`                   | `square_thumbnails_2.2.c.zip`                   | Square Thumbnails                               | 2.2                                                                  |
| `Statistics_11.0.a`                         | `Statistics_11.0.a.zip`                         | Statistics                                      | 16, 15, 14, 13, 12, 11                                               |
| `Stats_IP_Excluder_V2_0`                    | `Stats_IP_Excluder_V2_0.zip`                    | Stats IP Excluder                               | 1.7                                                                  |
| `Stereo.git_0.3.3`                          | `Stereo.git_0.3.3.zip`                          | Stereo                                          | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4           |
| `Stereo_2.0.b`                              | `Stereo_2.0.b.zip`                              | Stereo                                          | 2.0                                                                  |
| `stereoZoom_1.2.17`                         | `stereoZoom_1.2.17.zip`                         | Stéréo Zoom                                     | 16, 15, 14, 13, 12, 11, 2.10, 2.9                                    |
| `stop_spammers_14.a`                        | `stop_spammers_14.a.zip`                        | Stop Spammers                                   | 16, 15, 14, 13                                                       |
| `Subscribe_to_Comments_14.a`                | `Subscribe_to_Comments_14.a.zip`                | Subscribe to Comments                           | 16, 15, 14                                                           |
| `super_zoom`                                | `super_zoom.zip`                                | super_zoom                                      | 16, 15                                                               |
| `Synchronize_local_directory_1.0beta`       | `Synchronize_local_directory_1.0beta.zip`       | Synchronize local directory                     | 2.3                                                                  |
| `tag2keyword(v2.5.a)`                       | `tag2keyword(v2.5.a).zip`                       | Tag To Keyword                                  | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4, 2.3, 2.2 |
| `tag_groups_14.f`                           | `tag_groups_14.f.zip`                           | Tag Groups                                      | 16, 15, 14                                                           |
| `tag_recognition_16.a`                      | `tag_recognition_16.a.zip`                      | Tag Recognition                                 | 16                                                                   |
| `tag_to_name 0.0.0.2`                       | `tag_to_name 0.0.0.2.zip`                       | Tag To Name (Tag2name)                          | 2.1                                                                  |
| `Tags2File`                                 | `Tags2File.zip`                                 | Tags2File / Export Image Metadata               | 2.0                                                                  |
| `TakeATour_16.3.0`                          | `TakeATour_16.3.0.zip`                          | Take A Tour                                     | 16                                                                   |
| `tam_0.80`                                  | `tam_0.80.zip`                                  | Tag Access Management                           | 16, 15, 14, 13                                                       |
| `TFA`                                       | `TFA.zip`                                       | Two-factor Authentication                       | 15, 14, 13, 12                                                       |
| `theme_switch_11.0.a`                       | `theme_switch_11.0.a.zip`                       | Theme switch                                    | 16, 15, 14, 13, 12, 11                                               |
| `ThreeD_2.9`                                | `ThreeD_2.9.zip`                                | ThreeD                                          | 2.10, 2.9, 2.8, 2.7                                                  |
| `thumb_size`                                | `thumb_size.zip`                                | Thumb Batch Size                                | 16, 15                                                               |
| `thumbCropper_0.4`                          | `thumbCropper_0.4.zip`                          | thumbCropper                                    | 2.3                                                                  |
| `ThumbnailTooltip_14.b`                     | `ThumbnailTooltip_14.b.zip`                     | Thumbnail Tooltip                               | 16, 15, 14                                                           |
| `tinymce_17a`                               | `tinymce_17a.zip`                               | TinyMCE Editor                                  | 1.7                                                                  |
| `title_16.a`                                | `title_16.a.zip`                                | Title                                           | 16                                                                   |
| `titlesimple_2.1.a`                         | `titlesimple_2.1.a.zip`                         | Title Simple                                    | 2.1                                                                  |
| `ToolTeeps_1.1.0`                           | `ToolTeeps_1.1.0.zip`                           | ToolTeeps                                       | 16, 15, 14, 13, 12, 11, 2.10                                         |
| `translator-2.0.0`                          | `translator-2.0.0.zip`                          | Translator                                      | 2.0                                                                  |
| `TravelMap_0.1.3`                           | `TravelMap_0.1.3.zip`                           | Travel Map                                      | 2.10, 2.9, 2.8                                                       |
| `typetags_14.a`                             | `typetags_14.a.zip`                             | Colored Tags                                    | 16, 15, 14                                                           |
| `unTagged_2.1.a`                            | `unTagged_2.1.a.zip`                            | unTagged                                        | 2.1                                                                  |
| `UpdateAlbum_1.3.d`                         | `UpdateAlbum_1.3.d.zip`                         | Update Album                                    | 15, 14, 13, 12                                                       |
| `upload_form_2.0.j`                         | `upload_form_2.0.j.zip`                         | Upload Form                                     | 2.0                                                                  |
| `uploadAsync_2.10.a`                        | `uploadAsync_2.10.a.zip`                        | uploadAsync                                     | 2.10                                                                 |
| `uploader_plus`                             | `uploader_plus.zip`                             | Uploader +                                      | 2.0                                                                  |
| `uploadt1menu_14.0.a`                       | `uploadt1menu_14.0.a.zip`                       | Upload1menu                                     | 16, 15, 14                                                           |
| `url_uploader_15.a`                         | `url_uploader_15.a.zip`                         | URL Uploader                                    | 16, 15                                                               |
| `user_custom_fields_16.b`                   | `user_custom_fields_16.b.zip`                   | User Custom Fields                              | 16                                                                   |
| `user_delete_photo_14.0.a`                  | `user_delete_photo_14.0.a.zip`                  | user delete photo                               | 16, 15, 14                                                           |
| `user_info_tracking`                        | `user_info_tracking.zip`                        | User Info Tracking                              | 2.4                                                                  |
| `user_mass_register_15.b`                   | `user_mass_register_15.b.zip`                   | User Mass Register                              | 16, 15                                                               |
| `user_tags-1.0.5`                           | `user_tags-1.0.5.zip`                           | User Tags                                       | 16, 15, 14, 13                                                       |
| `UserAdvManager_2.80.0`                     | `UserAdvManager_2.80.0.zip`                     | UserAdvManager                                  | 2.10, 2.9, 2.8, 2.7                                                  |
| `UserCollections_16.a`                      | `UserCollections_16.a.zip`                      | User Collections                                | 16                                                                   |
| `UserDir`                                   | `UserDir.zip`                                   | UserDir                                         | 1.7                                                                  |
| `UserStat_1.2.1`                            | `UserStat_1.2.1.zip`                            | UserStat                                        | 2.4                                                                  |
| `virtualAutoGrant_2.2.a`                    | `virtualAutoGrant_2.2.a.zip`                    | Virtual AutoGrant                               | 2.2                                                                  |
| `virtualize_15.c`                           | `virtualize_15.c.zip`                           | Virtualize                                      | 16, 15                                                               |
| `vkbutton`                                  | `vkbutton.zip`                                  | vkbutton                                        | 2.4                                                                  |
| `voyage_V1.1`                               | `voyage_V1.1.zip`                               | Voyage                                          | 16                                                                   |
| `whois_online_12.b`                         | `whois_online_12.b.zip`                         | Whois Online                                    | 13, 12, 11                                                           |
| `whois_online_menu_12.0.a`                  | `whois_online_menu_12.0.a.zip`                  | Whois Online Menu                               | 15, 14, 13, 12                                                       |
| `WiredForSound_2.7.a`                       | `WiredForSound_2.7.a.zip`                       | Wired For Sound                                 | 2.10, 2.9, 2.8, 2.7                                                  |
| `write_metadata_15.b`                       | `write_metadata_15.b.zip`                       | Write Metadata                                  | 16, 15                                                               |
| `wsstats-1.0.2.1`                           | `wsstats-1.0.2.1.zip`                           | Web services statistics                         | 2.5, 2.4, 2.3, 2.2, 2.1, 2.0, 1.7                                    |

## piwigo16-themes

136 entries.

| Key                             | Filename                            | Extension Name                    | Compatible with                                            |
| ------------------------------- | ----------------------------------- | --------------------------------- | ---------------------------------------------------------- |
| `aqua-1-1-2`                    | `aqua-1-1-2.zip`                    | Aquarelle                         | 2.1                                                        |
| `bakary`                        | `bakary.zip`                        | Yoga-Bakary Theme                 | 1.7                                                        |
| `blacknblue`                    | `blacknblue.zip`                    | BlacknBlue                        | 1.6                                                        |
| `blancmontxl_15.a`              | `blancmontxl_15.a.zip`              | BlancMont XL                      | 16, 15                                                     |
| `bootstrap_darkroom_16.d`       | `bootstrap_darkroom_16.d.zip`       | Bootstrap Darkroom                | 16                                                         |
| `bootstrapdefault-1.0.7`        | `bootstrapdefault-1.0.7.zip`        | Bootstrap Default                 | 2.9, 2.8                                                   |
| `Borealis_1.0.1`                | `Borealis_1.0.1.zip`                | Borealis                          | 2.0                                                        |
| `Bubble_1.0.5`                  | `Bubble_1.0.5.zip`                  | Bubble                            | 2.0                                                        |
| `clear_12.a`                    | `clear_12.a.zip`                    | Clear                             | 16, 15, 14, 13, 12                                         |
| `clear_20a`                     | `clear_20a.zip`                     | goto-clear (admin theme)          | 2.0                                                        |
| `Csn_1.0.2`                     | `Csn_1.0.2.zip`                     | Csn                               | 2.0                                                        |
| `cuise`                         | `cuise.zip`                         | cuise                             | 2.0                                                        |
| `dark_2.9.5`                    | `dark_2.9.5.zip`                    | dark                              | 16, 15, 14, 13, 12, 11, 2.10, 2.9                          |
| `darkblack`                     | `darkblack.zip`                     | yoga-darkblack                    | 1.7                                                        |
| `darkbrown`                     | `darkbrown.zip`                     | dark brown                        | 1.6                                                        |
| `elegant_16.3.0`                | `elegant_16.3.0.zip`                | elegant                           | 16                                                         |
| `elegant_slick_1.0`             | `elegant_slick_1.0.zip`             | Elegant Slick                     | 2.9, 2.8                                                   |
| `expo-1.0.1`                    | `expo-1.0.1.zip`                    | Exposition                        | 2.3, 2.2, 2.1                                              |
| `Float_1.4`                     | `Float_1.4.zip`                     | Float                             | 2.6                                                        |
| `floOS_v1_1_2`                  | `floOS_v1_1_2.zip`                  | floOS                             | 2.0                                                        |
| `flop_mauve_3.3.1`              | `flop_mauve_3.3.1.zip`              | Theme flop_mauve                  | 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4, 2.3                |
| `floPure_v2_1_1`                | `floPure_v2_1_1.zip`                | floPure                           | 2.0                                                        |
| `Full_Background_2.5.1`         | `Full_Background_2.5.1.zip`         | Full_Background                   | 2.6                                                        |
| `gally-black-graphite_1.5.0`    | `gally-black-graphite_1.5.0.zip`    | Gally/Black-graphite              | 2.4                                                        |
| `gally-default_1.5.1`           | `gally-default_1.5.1.zip`           | Gally/Default                     | 2.4                                                        |
| `gally-graphite_1.5.0`          | `gally-graphite_1.5.0.zip`          | Gally/Graphite                    | 2.4                                                        |
| `gally-grum-dark-II_1.5.0`      | `gally-grum-dark-II_1.5.0.zip`      | Gally/Grum dark II                | 2.4                                                        |
| `gally-lapis-lazuli_1.5.0`      | `gally-lapis-lazuli_1.5.0.zip`      | Gally/Lapis-lazuli                | 2.4                                                        |
| `gally-minimalist_1.5.0`        | `gally-minimalist_1.5.0.zip`        | Gally/Minimalist                  | 2.4                                                        |
| `gally-v1.2.0`                  | `gally-v1.2.0.zip`                  | Gally                             | 2.0                                                        |
| `gally_cuise_2.0.7`             | `gally_cuise_2.0.7.zip`             | gally-cuise                       | 2.4                                                        |
| `gpa`                           | `gpa.zip`                           | Theme gpa                         | 2.0                                                        |
| `Greenpixel_1.0.1`              | `Greenpixel_1.0.1.zip`              | Greenpixel                        | 2.3, 2.2                                                   |
| `greydragon_1.4.3`              | `greydragon_1.4.3.zip`              | GreyDragon Theme                  | 16, 15, 14, 13, 12, 11                                     |
| `grum-dark-II_2.4.a`            | `grum-dark-II_2.4.a.zip`            | Grum dark II                      | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `heritage-1.0.0`                | `heritage-1.0.0.zip`                | Heritage                          | 2.1                                                        |
| `hk-3_1.2`                      | `hk-3_1.2.zip`                      | yoga-hk-3                         | 1.6                                                        |
| `hk-darkblue-left_1.0`          | `hk-darkblue-left_1.0.zip`          | yoga-darkblue-left                | 1.6                                                        |
| `hr_glass_xl_2.4.3`             | `hr_glass_xl_2.4.3.zip`             | hr_glass_xl                       | 16, 15, 14, 13, 12, 11                                     |
| `hr_os_2.5.3`                   | `hr_os_2.5.3.zip`                   | hr_os                             | 16, 15, 14, 13, 12, 11                                     |
| `hr_os_xl_2.5.3`                | `hr_os_xl_2.5.3.zip`                | hr_os_xl                          | 16, 15, 14, 13, 12, 11                                     |
| `Junk_1.0.1`                    | `Junk_1.0.1.zip`                    | Junk                              | 2.6                                                        |
| `kardon_2.7.a`                  | `kardon_2.7.a.zip`                  | Kardon                            | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `Keaihui_1.0`                   | `Keaihui_1.0.zip`                   | Keaihui Theme                     | 2.1                                                        |
| `KoffeeTux_1.0.7`               | `KoffeeTux_1.0.7.zip`               | KoffeeTux                         | 2.0                                                        |
| `Lineaments`                    | `Lineaments.zip`                    | Lineaments                        | 2.0                                                        |
| `luciano_14.c`                  | `luciano_14.c.zip`                  | Luciano Amodio                    | 16, 15, 14                                                 |
| `marine-1.0.0`                  | `marine-1.0.0.zip`                  | Marine                            | 2.1                                                        |
| `Metal_1.2`                     | `Metal_1.2.zip`                     | Metal                             | 2.3                                                        |
| `mixmax-1.0.0`                  | `mixmax-1.0.0.zip`                  | mixmax                            | 1.7                                                        |
| `modus_16.3.0.1`                | `modus_16.3.0.1.zip`                | modus                             | 16                                                         |
| `Moewp`                         | `Moewp.zip`                         | MoeWP Theme                       | 2.2                                                        |
| `montblanc_v1.0.4`              | `montblanc_v1.0.4.zip`              | MontBlanc Theme                   | 1.7                                                        |
| `montblancxl-fun`               | `montblancxl-fun.zip`               | montblancxl-fun                   | 2.0                                                        |
| `montblancxl_15.a`              | `montblancxl_15.a.zip`              | MontBlanc XL                      | 16, 15                                                     |
| `naive-0.6`                     | `naive-0.6.zip`                     | naive                             | 2.4                                                        |
| `Orange`                        | `Orange.zip`                        | Orange                            | 2.0                                                        |
| `OS_default_2.6.3`              | `OS_default_2.6.3.zip`              | OS_default                        | 16, 15, 14, 13, 12, 11                                     |
| `OS_glass_2.4.0`                | `OS_glass_2.4.0.zip`                | OS_glass                          | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `OS_glass_clear_2.4.0`          | `OS_glass_clear_2.4.0.zip`          | OS_glass_clear                    | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `OS_glass_dark_2.4.0`           | `OS_glass_dark_2.4.0.zip`           | OS_glass_dark                     | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `OS_glass_dark_2_2.4.0`         | `OS_glass_dark_2_2.4.0.zip`         | OS_glass_dark_2                   | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `p0w0_3.2`                      | `p0w0_3.2.zip`                      | p0w0                              | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `Pack_blue_v2_0_5`              | `Pack_blue_v2_0_5.zip`              | Pack_blue ( for floPure)          | 2.0                                                        |
| `Pack_D1_1_0_4`                 | `Pack_D1_1_0_4.zip`                 | Pack D1 ( for floPure )           | 2.0                                                        |
| `Pack_grey_v2_0_5`              | `Pack_grey_v2_0_5.zip`              | Pack grey ( for floPure )         | 2.0                                                        |
| `PaysonsPlaces_11459`           | `PaysonsPlaces_11459.zip`           | PaysonsPlaces                     | 2.2                                                        |
| `Pepito`                        | `Pepito.zip`                        | Pepito                            | 2.2                                                        |
| `Pesme-Black-Purple-Blue`       | `Pesme-Black-Purple-Blue.zip`       | Theme Pesme-Black-Purple-Blue     | 1.6                                                        |
| `phpwebgallery-jillij-v7`       | `phpwebgallery-jillij-v7.zip`       | jillij                            | 1.5                                                        |
| `Pure_autumn_1.5.0`             | `Pure_autumn_1.5.0.zip`             | Pure_autumn                       | 16, 15, 14, 13, 12, 11                                     |
| `Pure_clear_blue_1.3.0`         | `Pure_clear_blue_1.3.0.zip`         | Pure_clear_blue                   | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `Pure_default_1.5.1`            | `Pure_default_1.5.1.zip`            | Pure_default                      | 16, 15, 14, 13, 12, 11                                     |
| `Pure_freaky_1.3.0`             | `Pure_freaky_1.3.0.zip`             | Pure_freaky                       | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `Pure_green_nature_1.4.0`       | `Pure_green_nature_1.4.0.zip`       | Pure_green_nature                 | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                     |
| `Pure_grey_1.4.0`               | `Pure_grey_1.4.0.zip`               | Pure_grey                         | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                     |
| `Pure_grey_plastic_1.4.0`       | `Pure_grey_plastic_1.4.0.zip`       | Pure_grey_plastic                 | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                     |
| `Pure_sky_1.4.0`                | `Pure_sky_1.4.0.zip`                | Pure_sky                          | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                     |
| `Pure_tr_clear_blue_1.3.0`      | `Pure_tr_clear_blue_1.3.0.zip`      | Pure_tr_clear_blue                | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `Pure_tr_green_nature_1.4`      | `Pure_tr_green_nature_1.4.zip`      | Pure_tr_green_nature              | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8                     |
| `pwg-JoesYoga-v1.0`             | `pwg-JoesYoga-v1.0.zip`             | Joe's Yoga                        | 1.5                                                        |
| `pwg_template_hpsam-beige-2`    | `pwg_template_hpsam-beige-2.zip`    | Template Beige                    | 1.5                                                        |
| `pwg_template_hpsam-bleu-2`     | `pwg_template_hpsam-bleu-2.zip`     | Template blue                     | 1.5                                                        |
| `pwg_template_hpsam_1.7.c`      | `pwg_template_hpsam_1.7.c.zip`      | hpsam / beige, blue, wood         | 1.7                                                        |
| `pwg_template_phpBB-1`          | `pwg_template_phpBB-1.zip`          | phpBB                             | 1.5                                                        |
| `pwg_template_wood-4`           | `pwg_template_wood-4.zip`           | template wood                     | 1.5                                                        |
| `pwg_theme_hpsam-beige-3`       | `pwg_theme_hpsam-beige-3.zip`       | Theme Hpsam Beige                 | 1.6                                                        |
| `pwg_theme_hpsam-bleu-3`        | `pwg_theme_hpsam-bleu-3.zip`        | Theme Hpsam Blue                  | 1.6                                                        |
| `pwg_theme_hpsam-wood-5`        | `pwg_theme_hpsam-wood-5.zip`        | Theme Hpsam Wood                  | 1.6                                                        |
| `PwgCarbon_1.4`                 | `PwgCarbon_1.4.zip`                 | PwgCarbon                         | 2.6                                                        |
| `PwgCarbon_dft_1.6`             | `PwgCarbon_dft_1.6.zip`             | PwgCarbon_dft                     | 2.6                                                        |
| `rainbow_beta2`                 | `rainbow_beta2.zip`                 | rainbow theme                     | 1.7                                                        |
| `Sable`                         | `Sable.zip`                         | Sable                             | 2.0                                                        |
| `sakurabw`                      | `sakurabw.zip`                      | Sakura BW                         | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `silver`                        | `silver.zip`                        | Silver                            | 1.6                                                        |
| `simple-black_2.4.a`            | `simple-black_2.4.a.zip`            | Simple Black                      | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4 |
| `simple_15.a`                   | `simple_15.a.zip`                   | Simple Grey                       | 16, 15                                                     |
| `simpleng_15.b`                 | `simpleng_15.b.zip`                 | SimpleNG                          | 16, 15                                                     |
| `Slide_2.8`                     | `Slide_2.8.zip`                     | Slide                             | 2.6                                                        |
| `Slim_2.0`                      | `Slim_2.0.zip`                      | Slim                              | 2.6, 2.5, 2.4                                              |
| `Slimi_2.0`                     | `Slimi_2.0.zip`                     | Slimi                             | 2.6, 2.5, 2.4                                              |
| `smartpocket_16.3.0`            | `smartpocket_16.3.0.zip`            | Smart Pocket (mobile theme)       | 16                                                         |
| `smartpocket_clear_2.4.b`       | `smartpocket_clear_2.4.b.zip`       | Smart Pocket Clear (mobile theme) | 2.5, 2.4                                                   |
| `sobre_2.1.2`                   | `sobre_2.1.2.zip`                   | Sobre                             | 2.2, 2.1                                                   |
| `stripped-galleria_1.5.0`       | `stripped-galleria_1.5.0.zip`       | stripped-galleria                 | 2.6, 2.5, 2.4                                              |
| `stripped-slide_1.2.0`          | `stripped-slide_1.2.0.zip`          | stripped-slide                    | 2.4                                                        |
| `stripped_15.h`                 | `stripped_15.h.zip`                 | stripped                          | 16, 15                                                     |
| `stripped_black_bloc_2.6.8`     | `stripped_black_bloc_2.6.8.zip`     | Stripped & Columns                | 16, 15, 14, 13, 12, 11                                     |
| `stripped_cuise_bloc_1.0.1`     | `stripped_cuise_bloc_1.0.1.zip`     | stripped_cuise_bloc               | 2.4                                                        |
| `stripped_responsive_15.b`      | `stripped_responsive_15.b.zip`      | stripped-responsive               | 16, 15                                                     |
| `stripped_white_bloc_0.40`      | `stripped_white_bloc_0.40.zip`      | White Stripped & Columns          | 2.4                                                        |
| `strippedbage`                  | `strippedbage.zip`                  | StrippedBage                      | 2.2                                                        |
| `strippedblue`                  | `strippedblue.zip`                  | Stripped Blue                     | 2.2                                                        |
| `strippedred`                   | `strippedred.zip`                   | strippedred                       | 2.2                                                        |
| `strippedwhite`                 | `strippedwhite.zip`                 | strippedwhite                     | 2.2                                                        |
| `Sylvia_2.9.6`                  | `Sylvia_2.9.6.zip`                  | Sylvia                            | 16, 15, 14, 13, 12, 11                                     |
| `Sylvia_Modern_Transparent_1.0` | `Sylvia_Modern_Transparent_1.0.zip` | Sylvia Modern Transparent         | 2.4                                                        |
| `SylviaSigisGreen_v0.1`         | `SylviaSigisGreen_v0.1.zip`         | SylviaSigisGreen                  | 2.4                                                        |
| `Terra_1.0.4`                   | `Terra_1.0.4.zip`                   | Terra                             | 2.0                                                        |
| `Versa_0.7`                     | `Versa_0.7.zip`                     | Versa                             | 2.9                                                        |
| `VerticalWhite_2.7.a`           | `VerticalWhite_2.7.a.zip`           | VerticalWhite                     | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `vimages_gray_2_flat`           | `vimages_gray_2_flat.zip`           | [yoga] vimages_gray_1             | 1.7                                                        |
| `vimages_sport1`                | `vimages_sport1.zip`                | theme vimages_sport1              | 1.6                                                        |
| `vimages_SweetCandy`            | `vimages_SweetCandy.zip`            | vimages SweetCandy theme          | 1.6                                                        |
| `vimages_white_2`               | `vimages_white_2.zip`               | [yoga] vimages_white_2            | 1.7                                                        |
| `white`                         | `white.zip`                         | White                             | 1.5                                                        |
| `white_0.2`                     | `white_0.2.zip`                     | yoga-white                        | 1.6                                                        |
| `white_2.7.a`                   | `white_2.7.a.zip`                   | Simple White                      | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7                |
| `WinterForest_1.0.2`            | `WinterForest_1.0.2.zip`            | WinterForest                      | 2.0                                                        |
| `wipi_14.a`                     | `wipi_14.a.zip`                     | wipi                              | 16, 15, 14                                                 |
| `Wood_1.1`                      | `Wood_1.1.zip`                      | Wood                              | 2.6                                                        |
| `yoga-black`                    | `yoga-black.zip`                    | yoga-black                        | 1.7, 1.6                                                   |
| `yoga-grey`                     | `yoga-grey.zip`                     | Yoga-grey                         | 1.7, 1.6                                                   |
| `yoga-roomy-rev31`              | `yoga-roomy-rev31.zip`              | yoga-roomy                        | 1.5                                                        |
| `yoga_os_2`                     | `yoga_os_2.zip`                     | Pack yoga_OS                      | 2.0                                                        |
| `zouz`                          | `zouz.zip`                          | Theme zouz                        | 2.0                                                        |

## piwigo16-languages

62 entries.

| Key            | Filename           | Extension Name         | Compatible with |
| -------------- | ------------------ | ---------------------- | --------------- |
| `af_ZA_16.3.0` | `af_ZA_16.3.0.zip` | Afrikaans [ZA]         | 16              |
| `ar_EG_16.3.0` | `ar_EG_16.3.0.zip` | العربية (مصر) [EG]     | 16              |
| `ar_SA_16.3.0` | `ar_SA_16.3.0.zip` | العربية [AR]           | 16              |
| `bg_BG_16.3.0` | `bg_BG_16.3.0.zip` | Български [BG]         | 16              |
| `br_FR_16.3.0` | `br_FR_16.3.0.zip` | Brezhoneg [FR]         | 16              |
| `ca_ES_16.3.0` | `ca_ES_16.3.0.zip` | Catalan [CA]           | 16              |
| `cs_CZ_16.3.0` | `cs_CZ_16.3.0.zip` | Česky [CZ]             | 16              |
| `da_DK_16.3.0` | `da_DK_16.3.0.zip` | Dansk [DK]             | 16              |
| `de_DE_16.3.0` | `de_DE_16.3.0.zip` | Deutsch [DE]           | 16              |
| `el_GR_16.3.0` | `el_GR_16.3.0.zip` | Ελληνικά [GR]          | 16              |
| `en_GB_16.3.0` | `en_GB_16.3.0.zip` | English [GB]           | 16              |
| `en_UK_16.3.0` | `en_UK_16.3.0.zip` | English [UK]           | 16              |
| `en_US_16.3.0` | `en_US_16.3.0.zip` | English [US]           | 16              |
| `eo_EO_16.3.0` | `eo_EO_16.3.0.zip` | Esperanto [EO]         | 16              |
| `es_AR_16.3.0` | `es_AR_16.3.0.zip` | Argentina [AR]         | 16              |
| `es_ES_16.3.0` | `es_ES_16.3.0.zip` | Español [ES]           | 16              |
| `es_MX_16.3.0` | `es_MX_16.3.0.zip` | México [MX]            | 16              |
| `et_EE_16.3.0` | `et_EE_16.3.0.zip` | Estonian [EE]          | 16              |
| `eu_ES_16.3.0` | `eu_ES_16.3.0.zip` | Euskara [ES]           | 16              |
| `fa_IR_16.3.0` | `fa_IR_16.3.0.zip` | پارسی [IR]             | 16              |
| `fi_FI_16.3.0` | `fi_FI_16.3.0.zip` | Finnish [FI]           | 16              |
| `fr_CA_16.3.0` | `fr_CA_16.3.0.zip` | Français [QC]          | 16              |
| `fr_FR_16.3.0` | `fr_FR_16.3.0.zip` | Français [FR]          | 16              |
| `gl_ES_16.3.0` | `gl_ES_16.3.0.zip` | Galego [ES]            | 16              |
| `he_IL_16.3.0` | `he_IL_16.3.0.zip` | עברית [IL]             | 16              |
| `hr_HR_16.3.0` | `hr_HR_16.3.0.zip` | Hrvatski [HR]          | 16              |
| `hu_HU_16.3.0` | `hu_HU_16.3.0.zip` | Magyar [HU]            | 16              |
| `hy_AM_16.3.0` | `hy_AM_16.3.0.zip` | Հայերեն (Hayerēn) [AM] | 16              |
| `id_ID_16.3.0` | `id_ID_16.3.0.zip` | Bahasa Indonesia [ID]  | 16              |
| `is_IS_16.3.0` | `is_IS_16.3.0.zip` | Íslenska [IS]          | 16              |
| `it_IT_16.3.0` | `it_IT_16.3.0.zip` | Italiano [IT]          | 16              |
| `ja_JP_16.3.0` | `ja_JP_16.3.0.zip` | 日本語 [JP]               | 16              |
| `ka_GE_16.3.0` | `ka_GE_16.3.0.zip` | ქართული [GE]           | 16              |
| `km_KH_16.3.0` | `km_KH_16.3.0.zip` | ខ្មែរ [KH]             | 16              |
| `kn_IN_16.3.0` | `kn_IN_16.3.0.zip` | ಕನ್ನಡ [IN]             | 16              |
| `ko_KR_16.3.0` | `ko_KR_16.3.0.zip` | 한국어 [KR]               | 16              |
| `lb_LU_16.3.0` | `lb_LU_16.3.0.zip` | Lëtzebuergesch [LU]    | 16              |
| `lt_LT_16.3.0` | `lt_LT_16.3.0.zip` | Lietuvių [LT]          | 16              |
| `lv_LV_16.3.0` | `lv_LV_16.3.0.zip` | Latviešu [LV]          | 16              |
| `mk_MK_16.3.0` | `mk_MK_16.3.0.zip` | Македонски [MK]        | 16              |
| `mn_MN_16.3.0` | `mn_MN_16.3.0.zip` | Монгол [MN]            | 16              |
| `nb_NO_16.3.0` | `nb_NO_16.3.0.zip` | Norsk bokmål [NO]      | 16              |
| `nl_NL_16.3.0` | `nl_NL_16.3.0.zip` | Nederlands [NL]        | 16              |
| `nn_NO_16.3.0` | `nn_NO_16.3.0.zip` | Norwegian nynorsk [NO] | 16              |
| `pl_PL_16.3.0` | `pl_PL_16.3.0.zip` | Polski [PL]            | 16              |
| `pt_BR_16.3.0` | `pt_BR_16.3.0.zip` | Brasil [BR]            | 16              |
| `pt_PT_16.3.0` | `pt_PT_16.3.0.zip` | Português [PT]         | 16              |
| `ro_RO_16.3.0` | `ro_RO_16.3.0.zip` | Română [RO]            | 16              |
| `ru_RU_16.3.0` | `ru_RU_16.3.0.zip` | Русский [RU]           | 16              |
| `sh_RS_16.3.0` | `sh_RS_16.3.0.zip` | Srpski [SR]            | 16              |
| `sk_SK_16.3.0` | `sk_SK_16.3.0.zip` | Slovensky [SK]         | 16              |
| `sl_SI_16.3.0` | `sl_SI_16.3.0.zip` | Slovenšcina [SL]       | 16              |
| `sr_RS_16.3.0` | `sr_RS_16.3.0.zip` | Српски [SR]            | 16              |
| `sv_SE_16.3.0` | `sv_SE_16.3.0.zip` | Svenska [SE]           | 16              |
| `ta_IN_16.3.0` | `ta_IN_16.3.0.zip` | தமிழ் [IN]             | 16              |
| `th_TH_16.3.0` | `th_TH_16.3.0.zip` | ภาษาไทย [TH]           | 16              |
| `tr_TR_16.3.0` | `tr_TR_16.3.0.zip` | Türkçe [TR]            | 16              |
| `uk_UA_16.3.0` | `uk_UA_16.3.0.zip` | Українська [UA]        | 16              |
| `vi_VN_16.3.0` | `vi_VN_16.3.0.zip` | Tiếng Việt [VN]        | 16              |
| `zh_CN_16.3.0` | `zh_CN_16.3.0.zip` | 简体中文 [CN]              | 16              |
| `zh_HK_16.3.0` | `zh_HK_16.3.0.zip` | 中文 (香港) [HK]           | 16              |
| `zh_TW_16.3.0` | `zh_TW_16.3.0.zip` | 中文 (繁體) [TW]           | 16              |

## piwigo16-tools

33 entries.

| Key                                                      | Filename                                                     | Extension Name                              | Compatible with                                  |
| -------------------------------------------------------- | ------------------------------------------------------------ | ------------------------------------------- | ------------------------------------------------ |
| `add_index_1.0.2.0`                                      | `add_index_1.0.2.0.zip`                                      | add_index                                   | 1.6                                              |
| `albumSync.sh`                                           | `albumSync.sh.zip`                                           | AlbumsSync                                  | 2.2                                              |
| `ApertureToPiwigo.pkg`                                   | `ApertureToPiwigo.pkg.zip`                                   | ApertureToPiwigo                            | 2.5                                              |
| `batch_all`                                              | `batch_all.zip`                                              | batch_all                                   | 1.7                                              |
| `BATCH_OPTIMISATEUR_installation`                        | `BATCH_OPTIMISATEUR_installation.zip`                        | Batch Optimizer                             | 2.1, 2.0, 1.7, 1.6, 1.5, 1.4, 1.3                |
| `BuildPWGPicture`                                        | `BuildPWGPicture.zip`                                        | BuildPWGPicture                             | 1.6, 1.5, 1.4, 1.3                               |
| `crea_arbo_piwigo.v2.bat`                                | `crea_arbo_piwigo.v2.bat.zip`                                | Créarbo_piwigo                              | 2.3                                              |
| `encadre_image-0.7`                                      | `encadre_image-0.7.zip`                                      | Frame_Image                                 | 1.6, 1.5, 1.4                                    |
| `flop_style_2.3`                                         | `flop_style_2.3.zip`                                         | flop_style                                  | 2.6, 2.5, 2.4                                    |
| `iPhotoToPiwigo.pkg`                                     | `iPhotoToPiwigo.pkg.zip`                                     | iPhotoToPiwigo                              | 2.10, 2.9, 2.8, 2.7, 2.6, 2.5                    |
| `MacShareToPiwigo.pkg`                                   | `MacShareToPiwigo.pkg.zip`                                   | MacShareToPiwigo                            | 2.10, 2.9, 2.8, 2.7                              |
| `media-pump`                                             | `media-pump.zip`                                             | Media Pump - [Windows/Linux/Mac]            | 2.10, 2.9, 2.8, 2.7                              |
| `mod_Rvm_2_0`                                            | `mod_Rvm_2_0.zip`                                            | MOD RVM                                     | 1.5                                              |
| `PHP_Optimisateur_1.4.2`                                 | `PHP_Optimisateur_1.4.2.zip`                                 | PHP Optimizer                               | 2.3, 2.2, 2.1, 2.0                               |
| `PhpWebGallery_create_v3.0.1`                            | `PhpWebGallery_create_v3.0.1.zip`                            | PhpWebGallery_create                        | 1.7, 1.6                                         |
| `PHPWG-tools`                                            | `PHPWG-tools.zip`                                            | PHPWG-tools                                 | 1.6, 1.5                                         |
| `Picasa2Piwigo - version 1.4 installer - multi language` | `Picasa2Piwigo - version 1.4 installer - multi language.zip` | Picasa2Piwigo                               | 2.10, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4               |
| `PicasaFaceTagExtraction1_0.msi`                         | `PicasaFaceTagExtraction1_0.msi.zip`                         | Google Picasa Face Tag Extraction To Piwigo | 2.4                                              |
| `piwigo-kodi.0.3`                                        | `piwigo-kodi.0.3.zip`                                        | Piwigo Kodi (XBMC) Browser                  | 2.6, 2.5, 2.4                                    |
| `piwigo_import_tree.pl`                                  | `piwigo_import_tree.pl.zip`                                  | Piwigo Import Tree                          | 16, 15, 14, 13, 12, 11, 2.10, 2.9, 2.8, 2.7, 2.6 |
| `piwigo_refresh`                                         | `piwigo_refresh.zip`                                         | Quick Sync                                  | 2.10, 2.9, 2.8                                   |
| `PiwigoBook_1.2_Intel.mpkg`                              | `PiwigoBook_1.2_Intel.mpkg.zip`                              | PiwigoBook for MAC Intel                    | 2.1                                              |
| `PiwigoBook_1.2_Linux`                                   | `PiwigoBook_1.2_Linux.zip`                                   | PiwigoBook for Linux                        | 2.1                                              |
| `PiwigoBook_1.2_Universel.mpkg`                          | `PiwigoBook_1.2_Universel.mpkg.zip`                          | PiwigoBook for MAC Universal                | 2.1                                              |
| `PiwigoBook_1_2_setup.exe`                               | `PiwigoBook_1_2_setup.exe.zip`                               | PiwigoBook for Windows                      | 2.1                                              |
| `piwigomedia.0.9.6`                                      | `piwigomedia.0.9.6.zip`                                      | PiwigoMedia                                 | 2.1                                              |
| `piwigopress.2.31`                                       | `piwigopress.2.31.zip`                                       | PiwigoPress                                 | 2.10, 2.9, 2.8, 2.7, 2.6                         |
| `prepare21upgrade_1`                                     | `prepare21upgrade_1.zip`                                     | Prepare 2.1 Upgrade                         | 2.0, 1.7, 1.6, 1.5, 1.4                          |
| `pywiUpload-0.8`                                         | `pywiUpload-0.8.zip`                                         | pywiUpload                                  | 2.1                                              |
| `Rep2Thumb_0.5`                                          | `Rep2Thumb_0.5.zip`                                          | Rep2Thumb                                   | 2.0, 1.7, 1.6, 1.5                               |
| `Stats_IP_Excluder_1_1`                                  | `Stats_IP_Excluder_1_1.zip`                                  | Stats IP Excluder                           | 1.6                                              |
| `tools_bar_0.2`                                          | `tools_bar_0.2.zip`                                          | Tools bar for News mod                      | 1.5                                              |
| `tools_bar_cat`                                          | `tools_bar_cat.zip`                                          | Tools Bar Cat                               | 1.5                                              |
