<?php

declare(strict_types=1);

namespace Piwigo\Event;

/**
 * Static name→FQCN map used by the legacy static `EventDispatcher` to
 * fire matching typed events through the PSR-14 dispatcher in parallel
 * with its own listener loop.
 *
 * Lives only for the duration of §1.4 B4–B17. Once every
 * `EventDispatcher::dispatch()` / `notify()` call site in `src/` has
 * been migrated to typed dispatch (B5), the bridge is unused. It is
 * deleted alongside the legacy static dispatcher in B17.
 *
 * The map is hand-curated (not derived at runtime) because three
 * BlockManager events use a non-mechanical PascalCase form
 * (`BlockManagerApply`, etc.) that doesn't match the
 * `snake_case → PascalCase` rule of the other 150 entries.
 *
 * `loc_end_intro` (last entry under `loc_end_*`) was caught during B3's
 * reverse audit — it's dispatched in src/ but absent from
 * `tools/triggers_list.php`.
 */
final class LegacyEventBridge
{
    /** @var array<string, class-string> */
    public const array MAP = [
        'allow_increment_element_hit_count' => Picture\AllowIncrementElementHitCount::class,
        'batch_manager_perform_filters' => Admin\BatchManagerPerformFilters::class,
        'batch_manager_register_filters' => Admin\BatchManagerRegisterFilters::class,
        'batch_manager_url_filter' => Admin\BatchManagerUrlFilter::class,
        'before_parse_mail_template' => Mail\BeforeParseMailTemplate::class,
        'before_send_mail' => Mail\BeforeSendMail::class,
        'begin_delete_elements' => Picture\BeginDeleteElements::class,
        'blockmanager_apply' => BlockManager\BlockManagerApply::class,
        'blockmanager_prepare_display' => BlockManager\BlockManagerPrepareDisplay::class,
        'blockmanager_register_blocks' => BlockManager\BlockManagerRegisterBlocks::class,
        'clean_iptc_value' => Picture\CleanIptcValue::class,
        'combined_css' => Template\CombinedCss::class,
        'combined_script' => Template\CombinedScript::class,
        'create_virtual_category' => Album\CreateVirtualCategory::class,
        'delete_categories' => Album\DeleteCategories::class,
        'delete_elements' => Picture\DeleteElements::class,
        'delete_group' => User\DeleteGroup::class,
        'delete_tags' => Tag\DeleteTags::class,
        'delete_user' => User\DeleteUser::class,
        'derivative_params_get' => Picture\DerivativeParamsGet::class,
        'element_set_global_action' => Admin\ElementSetGlobalAction::class,
        'empty_lounge' => Album\EmptyLounge::class,
        'finalize_login' => User\FinalizeLogin::class,
        'format_exif_data' => Picture\FormatExifData::class,
        'get_admin_advanced_features_links' => Admin\GetAdminAdvancedFeaturesLinks::class,
        'get_admin_plugin_menu_links' => Admin\GetAdminPluginMenuLinks::class,
        'get_admins_site_links' => Album\GetAdminsSiteLinks::class,
        'get_batch_manager_prefilters' => Admin\GetBatchManagerPrefilters::class,
        'get_categories_menu_sql_where' => Album\GetCategoriesMenuSqlWhere::class,
        'get_category_preferred_image_orders' => Album\GetCategoryPreferredImageOrders::class,
        'get_comments_derivative_params' => Picture\GetCommentsDerivativeParams::class,
        'get_derivative_url' => Picture\GetDerivativeUrl::class,
        'get_element_url' => Picture\GetElementUrl::class,
        'get_index_album_derivative_params' => Picture\GetIndexAlbumDerivativeParams::class,
        'get_index_derivative_params' => Picture\GetIndexDerivativeParams::class,
        'get_mimetype_location' => Picture\GetMimetypeLocation::class,
        'get_popup_help_content' => Admin\GetPopupHelpContent::class,
        'get_pwg_themes' => Lifecycle\GetPwgThemes::class,
        'get_src_image_url' => Picture\GetSrcImageUrl::class,
        'get_tag_alt_names' => Tag\GetTagAltNames::class,
        'get_tag_name_like_where' => Tag\GetTagNameLikeWhere::class,
        'get_thumbnail_title' => Picture\GetThumbnailTitle::class,
        'get_webmaster_mail_address' => Mail\GetWebmasterMailAddress::class,
        'init' => Lifecycle\Init::class,
        'invalidate_user_cache' => User\InvalidateUserCache::class,
        'list_check_integrity' => Picture\ListCheckIntegrity::class,
        'load_conf' => Lifecycle\LoadConf::class,
        'load_image_library' => Lifecycle\LoadImageLibrary::class,
        'load_profile_in_template' => User\LoadProfileInTemplate::class,
        'loading_lang' => Lifecycle\LoadingLang::class,
        'loc_action_before_http_headers' => Lifecycle\LocActionBeforeHttpHeaders::class,
        'loc_after_page_header' => Lifecycle\LocAfterPageHeader::class,
        'loc_begin_about' => Location\LocBeginAbout::class,
        'loc_begin_admin' => Location\LocBeginAdmin::class,
        'loc_begin_admin_page' => Location\LocBeginAdminPage::class,
        'loc_begin_cat_list' => Location\LocBeginCatList::class,
        'loc_begin_cat_modify' => Location\LocBeginCatModify::class,
        'loc_begin_comments' => Location\LocBeginComments::class,
        'loc_begin_element_set_global' => Location\LocBeginElementSetGlobal::class,
        'loc_begin_element_set_unit' => Location\LocBeginElementSetUnit::class,
        'loc_begin_identification' => Location\LocBeginIdentification::class,
        'loc_begin_index' => Location\LocBeginIndex::class,
        'loc_begin_index_category_thumbnails' => Location\LocBeginIndexCategoryThumbnails::class,
        'loc_begin_index_category_thumbnails_query' => Location\LocBeginIndexCategoryThumbnailsQuery::class,
        'loc_begin_index_thumbnails' => Location\LocBeginIndexThumbnails::class,
        'loc_begin_notification' => Location\LocBeginNotification::class,
        'loc_begin_page_header' => Location\LocBeginPageHeader::class,
        'loc_begin_page_tail' => Location\LocBeginPageTail::class,
        'loc_begin_password' => Location\LocBeginPassword::class,
        'loc_begin_picture' => Location\LocBeginPicture::class,
        'loc_begin_profile' => Location\LocBeginProfile::class,
        'loc_begin_register' => Location\LocBeginRegister::class,
        'loc_begin_search' => Location\LocBeginSearch::class,
        'loc_begin_tags' => Location\LocBeginTags::class,
        'loc_end_about' => Location\LocEndAbout::class,
        'loc_end_add_format' => Location\LocEndAddFormat::class,
        'loc_end_add_uploaded_file' => Location\LocEndAddUploadedFile::class,
        'loc_end_admin' => Location\LocEndAdmin::class,
        'loc_end_cat_list' => Location\LocEndCatList::class,
        'loc_end_cat_modify' => Location\LocEndCatModify::class,
        'loc_end_comments' => Location\LocEndComments::class,
        'loc_end_element_set_global' => Location\LocEndElementSetGlobal::class,
        'loc_end_element_set_unit' => Location\LocEndElementSetUnit::class,
        'loc_end_help' => Location\LocEndHelp::class,
        'loc_end_identification' => Location\LocEndIdentification::class,
        'loc_end_index' => Location\LocEndIndex::class,
        'loc_end_index_category_thumbnails' => Location\LocEndIndexCategoryThumbnails::class,
        'loc_end_index_thumbnails' => Location\LocEndIndexThumbnails::class,
        'loc_end_intro' => Location\LocEndIntro::class,
        'loc_end_no_photo_yet' => Location\LocEndNoPhotoYet::class,
        'loc_end_notification' => Location\LocEndNotification::class,
        'loc_end_page_header' => Location\LocEndPageHeader::class,
        'loc_end_page_tail' => Location\LocEndPageTail::class,
        'loc_end_password' => Location\LocEndPassword::class,
        'loc_end_photo_add_direct' => Location\LocEndPhotoAddDirect::class,
        'loc_end_picture' => Location\LocEndPicture::class,
        'loc_end_picture_modify' => Location\LocEndPictureModify::class,
        'loc_end_profile' => Location\LocEndProfile::class,
        'loc_end_register' => Location\LocEndRegister::class,
        'loc_end_section_init' => Location\LocEndSectionInit::class,
        'loc_end_tags' => Location\LocEndTags::class,
        'loc_end_themes_installed' => Location\LocEndThemesInstalled::class,
        'login_failure' => User\LoginFailure::class,
        'login_failure_before_log_user' => User\LoginFailureBeforeLogUser::class,
        'login_success' => User\LoginSuccess::class,
        'merge_tags' => Album\MergeTags::class,
        'nbm_event_handler_added' => Lifecycle\NbmEventHandlerAdded::class,
        'nbm_render_global_customize_mail_content' => Mail\NbmRenderGlobalCustomizeMailContent::class,
        'nbm_render_user_customize_mail_content' => Mail\NbmRenderUserCustomizeMailContent::class,
        'perform_batch_manager_prefilters' => Admin\PerformBatchManagerPrefilters::class,
        'picture_modify_before_update' => Picture\PictureModifyBeforeUpdate::class,
        'picture_pictures_data' => Picture\PicturePicturesData::class,
        'plugin_install_errors' => Lifecycle\PluginInstallErrors::class,
        'plugins_loaded' => Lifecycle\PluginsLoaded::class,
        'pwg_log_allowed' => Picture\PwgLogAllowed::class,
        'pwg_log_update_last_visit' => Picture\PwgLogUpdateLastVisit::class,
        'qsearch_before_eval' => Search\QsearchBeforeEval::class,
        'qsearch_expression_parsed' => Search\QsearchExpressionParsed::class,
        'qsearch_get_images_sql_scopes' => Search\QsearchGetImagesSqlScopes::class,
        'qsearch_get_scopes' => Search\QsearchGetScopes::class,
        'qsearch_pre' => Search\QsearchPre::class,
        'qsearch_results' => Search\QsearchResults::class,
        'register_user' => User\RegisterUser::class,
        'register_user_check' => User\RegisterUserCheck::class,
        'render_category_description' => Template\RenderCategoryDescription::class,
        'render_category_name' => Template\RenderCategoryName::class,
        'render_comment_author' => Template\RenderCommentAuthor::class,
        'render_comment_content' => Template\RenderCommentContent::class,
        'render_element_content' => Picture\RenderElementContent::class,
        'render_element_description' => Picture\RenderElementDescription::class,
        'render_element_name' => Picture\RenderElementName::class,
        'render_lost_password_mail_content' => Mail\RenderLostPasswordMailContent::class,
        'render_tag_name' => Tag\RenderTagName::class,
        'render_tag_url' => Tag\RenderTagUrl::class,
        'save_profile_from_post' => User\SaveProfileFromPost::class,
        'sendResponse' => Ws\SendResponse::class,
        'set_status_header' => Template\SetStatusHeader::class,
        'tabsheet_before_select' => Admin\TabsheetBeforeSelect::class,
        'theme_activate_errors' => Lifecycle\ThemeActivateErrors::class,
        'try_log_user' => User\TryLogUser::class,
        'update_rating_score' => Picture\UpdateRatingScore::class,
        'upload_file' => Picture\UploadFile::class,
        'user_comment_check' => User\UserCommentCheck::class,
        'user_comment_deletion' => User\UserCommentDeletion::class,
        'user_comment_insertion' => User\UserCommentInsertion::class,
        'user_comment_validation' => User\UserCommentValidation::class,
        'user_init' => User\UserInit::class,
        'user_login' => User\UserLogin::class,
        'user_logout' => User\UserLogout::class,
        'ws_add_methods' => Ws\WsAddMethods::class,
        'ws_images_uploadCompleted' => Picture\WsImagesUploadCompleted::class,
        'ws_invoke_allowed' => Ws\WsInvokeAllowed::class,
        'ws_users_getList' => User\WsUsersGetList::class,
    ];

    /**
     * Return the typed event FQCN for a legacy string event name, or
     * `null` if the event is not mapped (dead, unscaffolded, or third-party).
     *
     * @return class-string|null
     */
    public static function classFor(string $eventName): ?string
    {
        return self::MAP[$eventName] ?? null;
    }
}
