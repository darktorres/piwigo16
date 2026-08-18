# Legacy event hook map

Maps every legacy Piwigo `trigger_change()`/`trigger_notify()` hook name
this fork's event system derives from to its current typed PSR-14 event
class — the reference for anyone porting a plugin's `add_event_handler()`
call or trying to find where a familiar hook name landed after P32 Stage
A5's catalogue/rename/co-location pass. See `docs/REFERENCE.md`'s
"Plugin/theme contract" section for how `subscribedEvents()` and
`ExtensionContext::dispatch()` actually work; this file is the name
lookup, not the mechanism doc.

**Kind** is `filter` (the legacy hook's `trigger_change()` — a handler can
mutate the event's own field(s) and later handlers/the caller see the
result) or `notify` (`trigger_notify()` — fire-and-forget, no return value
is ever read). No back-compat obligation exists with upstream Piwigo or
pre-17.x extensions (`AppInfo::VERSION` is the real compatibility gate) —
this map exists for readability and porting convenience, not a wire
contract.

**A `filter` handler must mutate the event's own field in place and
return `void` — it must not `return` a replacement value.** `dispatch()`
(P32 Stage A2) is the single verb for both kinds and never reads a
handler's return value, unlike the legacy `trigger_change()`/pre-A2
`dispatchChange()`, both of which chained each handler's *returned* value
into the next. A handler written the old way (`return $newValue;` instead
of `$event->field = $newValue;`) fails **silently** — it runs, throws
nothing, and simply has no effect — not loudly. This bit several of this
fork's own tests during A2 (see `we-made-lots-and-recursive-valiant.md`'s
own A2 entry for the full list); a plugin ported from a pre-17.x hook
using the old idiom will hit the exact same silent no-op, so check every
migrated handler mutates its event in place.

## Current classes

| Legacy hook | Kind | Current class |
| --- | --- | --- |
| `allow_increment_element_hit_count` | filter | `Piwigo\Controller\Event\AllowIncrementElementHitCount` |
| `batch_manager_perform_filters` | filter | `Piwigo\Controller\Admin\Event\BatchManagerPerformFilters` |
| `batch_manager_register_filters` | filter | `Piwigo\Controller\Admin\Event\BatchManagerRegisterFilters` |
| `before_parse_mail_template` | notify | `Piwigo\Mail\Event\BeforeParseMailTemplate` |
| `begin_delete_elements` | notify | `Piwigo\Image\Event\BeginDeleteElements` |
| `blockmanager_apply` | notify | `Piwigo\Menu\Event\BlockManagerApply` |
| `blockmanager_prepare_display` | notify | `Piwigo\Menu\Event\BlockManagerPrepareDisplay` |
| `blockmanager_register_blocks` | notify | `Piwigo\Menu\Event\BlockManagerRegisterBlocks` |
| `clean_iptc_value` | filter | `Piwigo\Metadata\Event\CleanIptcValue` |
| `combinable_preparse` | notify | `Piwigo\Template\Event\CombinablePreparse` |
| `combined_css_postfilter` | filter | `Piwigo\Template\Event\CombinedCssPostfilter` |
| `combined_script` | filter | `Piwigo\Template\Event\CombinedScript` |
| `create_virtual_category` | notify | `Piwigo\Category\Event\CreateVirtualCategory` |
| `delete_categories` | notify | `Piwigo\Category\Event\DeleteCategories` |
| `delete_elements` | notify | `Piwigo\Image\Event\DeleteElements` |
| `delete_tags` | notify | `Piwigo\Tag\Event\DeleteTags` |
| `delete_user` | notify | `Piwigo\Users\Event\DeleteUser` |
| `element_set_global_action` | notify | `Piwigo\Admin\Event\ElementSetGlobalAction` |
| `format_exif_data` | filter | `Piwigo\Metadata\Event\FormatExifData` |
| `get_admin_advanced_features_links` | filter | `Piwigo\Admin\Event\GetAdminAdvancedFeaturesLinks` |
| `get_admin_plugin_menu_links` | filter | `Piwigo\Admin\Event\GetAdminPluginMenuLinks` |
| `get_admins_site_links` | filter | `Piwigo\Controller\Admin\Event\GetAdminsSiteLinks` |
| `get_batch_manager_prefilters` | filter | `Piwigo\Admin\BatchManager\Event\GetBatchManagerPrefilters` |
| `get_categories_menu_sql_where` | filter | `Piwigo\Category\Event\GetCategoriesMenuRows` (payload changed — see below) |
| `get_category_preferred_image_orders` | filter | `Piwigo\Category\Event\GetCategoryPreferredImageOrders` |
| `get_derivative_url` | filter | `Piwigo\Image\Event\GetDerivativeUrl` |
| `get_element_metadata_available` | filter | `Piwigo\Controller\Event\GetElementMetadataAvailable` |
| `get_element_url` | filter | `Piwigo\Picture\Event\GetElementUrl` |
| `get_index_derivative_params` | filter | `Piwigo\Image\Event\GetIndexDerivativeParams` |
| `get_mimetype_location` | filter | `Piwigo\Image\Event\GetMimetypeLocation` |
| `get_popup_help_content` | filter | `Piwigo\Controller\Event\GetPopupHelpContent` |
| `get_src_image_url` | filter | `Piwigo\Image\Event\GetSrcImageUrl` |
| `get_tag_alt_names` | filter | `Piwigo\Tag\Event\GetTagAltNames` |
| `get_tag_name_like_where` | filter | `Piwigo\Tag\Event\GetTagNameLikeWhere` |
| `get_webmaster_mail_address` | filter | `Piwigo\Users\Event\GetWebmasterMailAddress` |
| `init` | notify | `Piwigo\Bootstrap\Event\Init` |
| `invalidate_user_cache` | notify | `Piwigo\Cache\Event\InvalidateUserCache` (payload dropped — see below) |
| `list_check_integrity` | notify | `Piwigo\Admin\Integrity\Event\ListCheckIntegrity` |
| `loading_lang` | notify | `Piwigo\Lang\Event\LoadingLang` |
| `load_profile_in_template` | notify | `Piwigo\Controller\Event\LoadProfileInTemplate` |
| `loc_after_page_header` | notify | `Piwigo\Page\Event\PageHeaderRendered` |
| `loc_begin_about` | notify | `Piwigo\Controller\Event\AboutPageRendering` |
| `loc_begin_admin` | notify | `Piwigo\Admin\Event\AdminShellDispatching` |
| `loc_begin_admin_page` | notify | `Piwigo\Admin\Event\AdminPageRendering` |
| `loc_begin_cat_list` | notify | `Piwigo\Admin\Event\CatListPageRendering` |
| `loc_begin_cat_modify` | notify | `Piwigo\Admin\Event\CatModifyPageRendering` |
| `loc_begin_element_set_global` | notify | `Piwigo\Admin\Event\BatchManagerGlobalRendering` |
| `loc_begin_element_set_unit` | notify | `Piwigo\Admin\Event\BatchManagerUnitRendering` |
| `loc_begin_identification` | notify | `Piwigo\Controller\Event\IdentificationPageRendering` |
| `loc_begin_index` | notify | `Piwigo\Controller\Event\IndexRendering` |
| `loc_begin_index_category_thumbnails` | notify | `Piwigo\Category\Event\IndexCategoryThumbnailsRendering` |
| `loc_begin_index_thumbnails` | notify | `Piwigo\Category\Event\IndexThumbnailsRendering` |
| `loc_begin_page_header` | notify | `Piwigo\Page\Event\PageHeaderRendering` |
| `loc_begin_page_tail` | notify | `Piwigo\Page\Event\PageTailRendering` |
| `loc_begin_password` | notify | `Piwigo\Controller\Event\PasswordPageRendering` |
| `loc_begin_picture` | notify | `Piwigo\Controller\Event\PicturePageRendering` |
| `loc_begin_profile` | notify | `Piwigo\Controller\Event\ProfilePageRendering` |
| `loc_begin_register` | notify | `Piwigo\Controller\Event\RegisterPageRendering` |
| `loc_begin_search` | notify | `Piwigo\Controller\Event\SearchPageRendering` |
| `loc_begin_tags` | notify | `Piwigo\Controller\Event\TagsPageRendering` |
| `loc_end_add_uploaded_file` | notify | `Piwigo\Admin\Upload\Event\UploadedFileAdded` |
| `loc_end_admin` | notify | `Piwigo\Admin\Event\AdminShellRendered` |
| `loc_end_cat_modify` | notify | `Piwigo\Admin\Event\CatModifyPageRendered` |
| `loc_end_comments` | notify | `Piwigo\Controller\Event\CommentsPageRendered` |
| `loc_end_element_set_global` | notify | `Piwigo\Admin\Event\BatchManagerGlobalRendered` |
| `loc_end_element_set_unit` | notify | `Piwigo\Admin\Event\BatchManagerUnitRendered` |
| `loc_end_help` | notify | `Piwigo\Admin\Event\HelpPageRendered` |
| `loc_end_identification` | notify | `Piwigo\Controller\Event\IdentificationPageRendered` |
| `loc_end_index` | notify | `Piwigo\Controller\Event\IndexRendered` |
| `loc_end_index_category_thumbnails` | filter | `Piwigo\Category\Event\IndexCategoryThumbnailsRendered` |
| `loc_end_index_thumbnails` | filter | `Piwigo\Category\Event\IndexThumbnailsRendered` |
| `loc_end_intro` | notify | `Piwigo\Controller\Admin\Event\IntroPageRendered` |
| `loc_end_no_photo_yet` | notify | `Piwigo\Page\Event\NoPhotoYetRendered` |
| `loc_end_page_header` | notify | `Piwigo\Page\Event\PageHeaderContextFinalized` |
| `loc_end_page_tail` | notify | `Piwigo\Page\Event\PageTailRendered` |
| `loc_end_password` | notify | `Piwigo\Controller\Event\PasswordPageRendered` |
| `loc_end_photo_add_direct` | notify | `Piwigo\Admin\Event\PhotosAddDirectPageRendered` |
| `loc_end_picture` | notify | `Piwigo\Controller\Event\PicturePageRendered` |
| `loc_end_picture_modify` | notify | `Piwigo\Admin\Event\PictureModifyPageRendered` |
| `loc_end_profile` | notify | `Piwigo\Controller\Event\ProfilePageRendered` |
| `loc_end_register` | notify | `Piwigo\Controller\Event\RegisterPageRendered` |
| `loc_end_section_init` | notify | `Piwigo\Section\Event\SectionInitialized` |
| `loc_end_tags` | notify | `Piwigo\Controller\Event\TagsPageRendered` |
| `loc_end_themes_installed` | notify | `Piwigo\Admin\Event\ThemesInstalledPageRendered` |
| `loc_index_thumbnails_selection` | filter | `Piwigo\Category\Event\IndexThumbnailsSelected` |
| `login_failure` | notify | `Piwigo\Auth\Event\LoginFailure` |
| `login_success` | notify | `Piwigo\Auth\Event\LoginSuccess` |
| `merge_tags` | notify | `Piwigo\Tag\Event\MergeTags` |
| `nbm_render_global_customize_mail_content` | notify | `Piwigo\Mail\Event\NbmRenderGlobalCustomizeMailContent` |
| `nbm_render_user_customize_mail_content` | notify | `Piwigo\Notification\Event\NbmRenderUserCustomizeMailContent` |
| `perform_batch_manager_prefilters` | filter | `Piwigo\Controller\Admin\Event\PerformBatchManagerPrefilters` |
| `picture_modify_before_update` | filter | `Piwigo\Admin\Event\PictureModifyBeforeUpdate` |
| `picture_pictures_data` | filter | `Piwigo\Controller\Event\PicturePicturesData` |
| `plugins_loaded` | notify | `Piwigo\PluginConfig\Event\PluginsLoaded` |
| `pwg_log_allowed` | filter | `Piwigo\History\Event\LogAllowed` |
| `pwg_log_update_last_visit` | filter | `Piwigo\History\Event\LogUpdateLastVisit` |
| `qsearch_expression_parsed` | notify | `Piwigo\Search\Event\QsearchExpressionParsed` |
| `qsearch_get_images_sql_scopes` | filter | `Piwigo\Search\Event\QsearchGetImagesSqlScopes` |
| `qsearch_get_scopes` | filter | `Piwigo\Event\Search\QsearchGetScopes` (deliberately not co-located — see its own docblock) |
| `qsearch_pre` | filter | `Piwigo\Search\Event\QsearchPre` |
| `qsearch_results` | filter | `Piwigo\Search\Event\QsearchResults` |
| `register_user` | notify | `Piwigo\Users\Event\RegisterUser` |
| `register_user_check` | filter | `Piwigo\Users\Event\RegisterUserCheck` |
| `render_category_description` | filter | `Piwigo\Category\Event\RenderCategoryDescription` |
| `render_category_literal_description` | filter | `Piwigo\Category\Event\RenderCategoryLiteralDescription` |
| `render_category_name` | filter | `Piwigo\Category\Event\RenderCategoryName` |
| `render_comment_author` | filter | `Piwigo\Picture\Event\RenderCommentAuthor` |
| `render_comment_content` | filter | `Piwigo\Html\Event\RenderCommentContent` |
| `render_element_content` | filter | `Piwigo\Controller\Event\RenderElementContent` |
| `render_element_description` | filter | `Piwigo\Html\Event\RenderElementDescription` |
| `render_element_name` | filter | `Piwigo\Html\Event\RenderElementName` |
| `render_lost_password_mail_content` | filter | `Piwigo\Mail\Event\RenderLostPasswordMailContent` |
| `render_page_banner` | filter | `Piwigo\Page\Event\RenderPageBanner` |
| `render_tag_name` | filter | `Piwigo\Tag\Event\RenderTagName` |
| `render_tag_url` | filter | `Piwigo\Tag\Event\RenderTagUrl` |
| `save_profile_from_post` | notify | `Piwigo\Controller\Event\SaveProfileFromPost` |
| `tabsheet_before_select` | filter | `Piwigo\Admin\Event\TabsheetBeforeSelect` |
| `try_log_user` | filter | `Piwigo\Auth\Event\TryLogUser` |
| `upload_file` | filter | `Piwigo\Admin\Upload\Event\UploadFile` |
| `user_comment_check` | filter | `Piwigo\Comment\Event\UserCommentCheck` |
| `user_comment_insertion` | notify | `Piwigo\Picture\Event\UserCommentInsertion` |
| `user_comment_validation` | notify | `Piwigo\Comment\Event\UserCommentValidation` |
| `user_init` | notify | `Piwigo\Bootstrap\Event\UserInit` |
| `user_logout` | notify | `Piwigo\Auth\Event\UserLogout` |

Two payload notes, both from P32 Stage A5's "6 missing core hooks" pass
(neither is a straight port — see `we-made-lots-and-recursive-valiant.md`
for the full reasoning):

- **`get_categories_menu_sql_where`** used to filter a raw SQL WHERE-clause
  string. `CategoryService::getCategoriesMenu()` no longer builds one —
  `GetCategoriesMenuRows` filters the already-resolved category row array
  instead, the mechanism that actually exists today.
- **`invalidate_user_cache`** used to carry a `bool $full` (truncate vs.
  partial `UPDATE`). `PermissionCacheInvalidator::invalidate()` always does
  a full PSR-6 pool clear now, so `InvalidateUserCache` carries no payload.

## Not a legacy port

Two classes exist with no legacy hook behind them at all — new PSR-14-era
mechanisms this rewrite introduced, not ports:

| Class | Why it exists |
| --- | --- |
| `Piwigo\Category\Event\DeleteSite` | Legacy `delete_site()` never dispatched anything — it deleted the `sites` row inline. This rewrite's `CategoryService::deleteSite()` dispatches instead, purely to avoid `Category` (`L2aCoreDomain`) depending directly on `Site` (`L2bExtendedDomain`) — see the class's own docblock. |
| `Piwigo\Menu\Event\CheckMenuLinkVisibility` | SEC-49 — a new, deliberate extension point, not a legacy port. |

## Investigated, not ported

Four hooks turned up in the wild plugin corpus (`../piwigo16-plugins`,
`../piwigo16-themes`) with no typed class, but have zero dispatch site
anywhere in this project's own legacy reference (`../piwigo16`) and no
matching mechanism in this rewrite — porting them would mean inventing an
extension point that was never real in the lineage this fork is built
from:

| Legacy hook | Why not ported |
| --- | --- |
| `user_list_columns` | Filtered a server-rendered admin DataTables column list. The admin user list is `/api/v1/users` + client-JS now (P27) — no server-side row-filtering hook exists in that flow; `ws_users_getList` was the last real equivalent capability and was itself removed along with the WS layer (see "Removed" below). |
| `after_render_user_list` | Same DataTables mechanism as `user_list_columns`, same reason. |
| `get_high_url` | A binary "high definition available" toggle with no analogue in this rewrite's derivative/multi-size image system. |
| `add_elements` | A legacy batch-insert notify (`$inserts`, an array of raw file rows from an old directory-scan flow) with no current counterpart. |

## Removed

28 classes were deleted during P32 Stage A5's catalogue pass — either
genuinely dead (zero dispatch site and zero listener anywhere), a
mechanism-testing-only test (the class under test *was* the deleted
capability), or a plugin-event system hijacked as first-party test
infrastructure (replaced by a real DI seam — see e.g. `MailService`'s
`?TransportInterface $transportOverride`, `AuthService`'s
`?FinalizeLoginDecision $finalizeLoginOverride`). Full reasoning per class
is in `we-made-lots-and-recursive-valiant.md`; commits
`59992e37fb`/`814d8c6b14`/`3fb0c86be9`.

`batch_manager_url_filter` · `before_send_mail` · `combined_css` ·
`delete_group` · `empty_lounge` · `finalize_login` ·
`get_comments_derivative_params` · `get_index_album_derivative_params` ·
`get_pwg_themes` · `get_thumbnail_title` · `load_conf` ·
`load_image_library` · `loc_action_before_http_headers` ·
`loc_begin_comments` · `loc_begin_notification` · `loc_end_add_format` ·
`loc_end_cat_list` · `loc_end_notification` ·
`login_failure_before_log_user` · `nbm_event_handler_added` ·
`qsearch_before_eval` · `set_status_header` · `update_rating_score` ·
`upload_image_resize` · `upload_thumbnail_resize` ·
`user_comment_deletion` · `user_login` · `ws_images_uploadCompleted`

5 more were removed separately, when the WS layer itself was deleted
outright (P27, not the P32 catalogue pass above): their dispatch sites
were WS-protocol-internal code (response serialization, method
registration, invoke-authorization, and 2 admin-page listing filters)
with no REST equivalent concept, not events that migrated elsewhere.

`get_history` · `sendResponse` · `ws_add_methods` · `ws_invoke_allowed` ·
`ws_users_getList`

`ws_add_methods` specifically has a real replacement, just not an
event: `PluginConfig\ApiRouteProviderInterface` (P29.6), a manifest-declared
(`hasApiRoutes: true`) capability a plugin implements to register its own
`/api/v1/plugin-routes/{id}/...` routes -- see that interface's own
docblock for the full mechanism. The other 4 hooks above genuinely have
no replacement of any kind.
