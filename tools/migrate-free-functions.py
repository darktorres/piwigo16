"""
Replaces free function delegates in functions.inc.php with direct
ServiceLocator::get(Class::class)->method() calls across all PHP files.

Also handles special non-delegate cases:
  l10n() -> Lang::t()
  l10n_dec() -> Translator::get()->plural()
  get_branch_from_version() -> AppInfo static (has simple implementation)
"""
import re, os

BASE = r'C:\Apache24\htdocs\piwigo16'
SKIP = {'functions.inc.php', 'migrate-free-functions.py'}

# (free_function_name, replacement_expression)
# For ServiceLocator delegates we use the pattern:
#   ServiceLocator::get(X::class)->method
# For special cases, direct class calls
REPS = [
    # StringUtil delegates
    ('micro_seconds',               'ServiceLocator::get(StringUtil::class)->microSeconds'),
    ('get_moment',                  'ServiceLocator::get(StringUtil::class)->getMoment'),
    ('get_extension',               'ServiceLocator::get(StringUtil::class)->getExtension'),
    ('get_filename_wo_extension',   'ServiceLocator::get(StringUtil::class)->getFilenameWoExtension'),
    ('get_name_from_file',          'ServiceLocator::get(StringUtil::class)->getNameFromFile'),
    ('qualify_utf8',                'ServiceLocator::get(StringUtil::class)->qualifyUtf8'),
    ('remove_accents',              'ServiceLocator::get(StringUtil::class)->removeAccents'),
    ('pwg_transliterate',           'ServiceLocator::get(StringUtil::class)->pwgTransliterate'),
    ('str2url',                     'ServiceLocator::get(StringUtil::class)->str2url'),
    ('original_to_representative',  'ServiceLocator::get(StringUtil::class)->originalToRepresentative'),
    ('original_to_format',          'ServiceLocator::get(StringUtil::class)->originalToFormat'),
    ('get_element_path',            'ServiceLocator::get(StringUtil::class)->getElementPath'),
    ('convert_charset',             'ServiceLocator::get(StringUtil::class)->convertCharset'),
    ('secure_directory',            'ServiceLocator::get(StringUtil::class)->secureDirectory'),
    ('get_pwg_charset',             'ServiceLocator::get(StringUtil::class)->getPwgCharset'),
    ('url_check_format',            'ServiceLocator::get(StringUtil::class)->urlCheckFormat'),
    ('email_check_format',          'ServiceLocator::get(StringUtil::class)->emailCheckFormat'),
    ('safe_version_compare',        'ServiceLocator::get(StringUtil::class)->safeVersionCompare'),
    ('get_container_info',          'ServiceLocator::get(StringUtil::class)->getContainerInfo'),
    ('is_valid_mysql_datetime',     'ServiceLocator::get(StringUtil::class)->isValidMysqlDatetime'),
    ('pwg_safe_getimagesize',       'ServiceLocator::get(StringUtil::class)->pwgSafeGetimagesize'),
    ('pwg_safe_exif_read_data',     'ServiceLocator::get(StringUtil::class)->pwgSafeExifReadData'),
    ('safe_json_decode',            'ServiceLocator::get(StringUtil::class)->safeJsonDecode'),
    ('prepend_append_array_items',  'ServiceLocator::get(StringUtil::class)->prependAppendArrayItems'),
    # Util delegates
    ('do_log',                      'ServiceLocator::get(Util::class)->doLog'),
    ('pwg_log',                     'ServiceLocator::get(Util::class)->pwgLog'),
    ('pwg_activity',                'ServiceLocator::get(Util::class)->pwgActivity'),
    ('pwg_debug',                   'ServiceLocator::get(Util::class)->pwgDebug'),
    ('get_pwg_themes',              'ServiceLocator::get(Util::class)->getPwgThemes'),
    ('check_theme_installed',       'ServiceLocator::get(Util::class)->checkThemeInstalled'),
    ('fill_caddie',                 'ServiceLocator::get(Util::class)->fillCaddie'),
    ('get_themeconf',               'ServiceLocator::get(Util::class)->getThemeconf'),
    ('get_webmaster_mail_address',  'ServiceLocator::get(Util::class)->getWebmasterMailAddress'),
    ('pwg_is_dbconf_writeable',     'ServiceLocator::get(Util::class)->pwgIsDbconfWriteable'),  # wait, this is ConfigService
    ('get_filter_page_value',       'ServiceLocator::get(Util::class)->getFilterPageValue'),
    ('get_ephemeral_key',           'ServiceLocator::get(Util::class)->getEphemeralKey'),
    ('verify_ephemeral_key',        'ServiceLocator::get(Util::class)->verifyEphemeralKey'),
    ('create_navigation_bar',       'ServiceLocator::get(Util::class)->createNavigationBar'),
    ('get_icon',                    'ServiceLocator::get(Util::class)->getIcon'),
    ('check_pwg_token',             'ServiceLocator::get(Util::class)->checkPwgToken'),
    ('get_pwg_token',               'ServiceLocator::get(Util::class)->getPwgToken'),
    ('check_input_parameter',       'ServiceLocator::get(Util::class)->checkInputParameter'),
    ('get_privacy_level_options',   'ServiceLocator::get(Util::class)->getPrivacyLevelOptions'),
    ('get_device',                  'ServiceLocator::get(Util::class)->getDevice'),
    ('mobile_theme',                'ServiceLocator::get(Util::class)->mobileTheme'),
    ('get_nb_available_comments',   'ServiceLocator::get(Util::class)->getNbAvailableComments'),
    ('check_lounge',                'ServiceLocator::get(Util::class)->checkLounge'),
    ('send_piwigo_infos',           'ServiceLocator::get(Util::class)->sendPiwigoInfos'),
    ('send_piwigo_infos_retry_later','ServiceLocator::get(Util::class)->sendPiwigoInfosRetryLater'),
    ('pwg_unique_exec_begins',      'ServiceLocator::get(Util::class)->pwgUniqueExecBegins'),
    ('pwg_unique_exec_is_running',  'ServiceLocator::get(Util::class)->pwgUniqueExecIsRunning'),
    ('pwg_unique_exec_ends',        'ServiceLocator::get(Util::class)->pwgUniqueExecEnds'),
    # ConfigService delegates
    ('conf_update_param',           'ServiceLocator::get(ConfigService::class)->confUpdateParam'),
    ('conf_delete_param',           'ServiceLocator::get(ConfigService::class)->confDeleteParam'),
    ('conf_get_param',              'ServiceLocator::get(ConfigService::class)->confGetParam'),
    ('pwg_is_dbconf_writeable',     'ServiceLocator::get(ConfigService::class)->pwgIsDbconfWriteable'),
    # DateService delegates
    ('dateDiff',                    'ServiceLocator::get(DateService::class)->dateDiff'),
    ('str2DateTime',                'ServiceLocator::get(DateService::class)->str2DateTime'),
    ('format_date_legacy',          'ServiceLocator::get(DateService::class)->formatDateLegacy'),
    ('format_date',                 'ServiceLocator::get(DateService::class)->formatDate'),
    ('format_fromto',               'ServiceLocator::get(DateService::class)->formatFromto'),
    ('time_since',                  'ServiceLocator::get(DateService::class)->timeSince'),
    ('transform_date',              'ServiceLocator::get(DateService::class)->transformDate'),
    # QueryHelper delegates
    ('simple_hash_from_query',      'ServiceLocator::get(QueryHelper::class)->simpleHashFromQuery'),
    ('hash_from_query',             'ServiceLocator::get(QueryHelper::class)->hashFromQuery'),
    ('array_from_query',            'ServiceLocator::get(QueryHelper::class)->arrayFromQuery'),
    # SessionService delegates
    ('pwg_session_open',            'ServiceLocator::get(SessionService::class)->sessionOpen'),
    ('pwg_session_close',           'ServiceLocator::get(SessionService::class)->sessionClose'),
    ('get_remote_addr_session_hash','ServiceLocator::get(SessionService::class)->getRemoteAddrSessionHash'),
    ('pwg_session_read',            'ServiceLocator::get(SessionService::class)->sessionRead'),
    ('pwg_session_write',           'ServiceLocator::get(SessionService::class)->sessionWrite'),
    ('pwg_session_destroy',         'ServiceLocator::get(SessionService::class)->sessionDestroy'),
    ('pwg_session_gc',              'ServiceLocator::get(SessionService::class)->sessionGc'),
    ('pwg_set_session_var',         'ServiceLocator::get(SessionService::class)->setSessionVar'),
    ('pwg_get_session_var',         'ServiceLocator::get(SessionService::class)->getSessionVar'),
    ('pwg_unset_session_var',       'ServiceLocator::get(SessionService::class)->unsetSessionVar'),
    ('delete_user_sessions',        'ServiceLocator::get(SessionService::class)->deleteUserSessions'),
    # CategoryService delegates
    ('global_rank_compare',         'ServiceLocator::get(CategoryService::class)->globalRankCompare'),
    ('rank_compare',                'ServiceLocator::get(CategoryService::class)->rankCompare'),
    ('check_restrictions',          'ServiceLocator::get(CategoryService::class)->checkRestrictions'),
    ('get_categories_menu',         'ServiceLocator::get(CategoryService::class)->getCategoriesMenu'),
    ('get_cat_info',                'ServiceLocator::get(CategoryService::class)->getCatInfo'),
    ('get_category_preferred_image_orders','ServiceLocator::get(CategoryService::class)->getCategoryPreferredImageOrders'),
    ('display_select_categories',   'ServiceLocator::get(CategoryService::class)->displaySelectCategories'),
    ('display_select_cat_wrapper',  'ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper'),
    ('get_subcat_ids',              'ServiceLocator::get(CategoryService::class)->getSubcatIds'),
    ('get_cat_id_from_permalinks',  'ServiceLocator::get(CategoryService::class)->getCatIdFromPermalinks'),
    ('get_display_images_count',    'ServiceLocator::get(CategoryService::class)->getDisplayImagesCount'),
    ('get_random_image_in_category','ServiceLocator::get(CategoryService::class)->getRandomImageInCategory'),
    ('get_computed_categories',     'ServiceLocator::get(CategoryService::class)->getComputedCategories'),
    ('remove_computed_category',    'ServiceLocator::get(CategoryService::class)->removeComputedCategory'),
    ('get_image_ids_for_categories','ServiceLocator::get(CategoryService::class)->getImageIdsForCategories'),
    ('get_common_categories',       'ServiceLocator::get(CategoryService::class)->getCommonCategories'),
    ('get_related_categories_menu', 'ServiceLocator::get(CategoryService::class)->getRelatedCategoriesMenu'),
    # TagService delegates
    ('get_nb_available_tags',       'ServiceLocator::get(TagService::class)->getNbAvailableTags'),
    ('get_available_tags',          'ServiceLocator::get(TagService::class)->getAvailableTags'),
    ('get_all_tags',                'ServiceLocator::get(TagService::class)->getAllTags'),
    ('add_level_to_tags',           'ServiceLocator::get(TagService::class)->addLevelToTags'),
    ('get_image_ids_for_tags',      'ServiceLocator::get(TagService::class)->getImageIdsForTags'),
    ('get_common_tags',             'ServiceLocator::get(TagService::class)->getCommonTags'),
    ('find_tags',                   'ServiceLocator::get(TagService::class)->findTags'),
    ('tags_id_compare',             'ServiceLocator::get(TagService::class)->tagsIdCompare'),
    ('tags_counter_compare',        'ServiceLocator::get(TagService::class)->tagsCounterCompare'),
    # HtmlService delegates
    ('get_cat_display_name',            'ServiceLocator::get(HtmlService::class)->getCatDisplayName'),
    ('get_cat_display_name_cache',      'ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache'),
    ('get_cat_display_name_from_id',    'ServiceLocator::get(HtmlService::class)->getCatDisplayNameFromId'),
    ('render_comment_content',          'ServiceLocator::get(HtmlService::class)->renderCommentContent'),
    ('name_compare',                    'ServiceLocator::get(HtmlService::class)->nameCompare'),
    ('tag_alpha_compare',               'ServiceLocator::get(HtmlService::class)->tagAlphaCompare'),
    ('access_denied',                   'ServiceLocator::get(HtmlService::class)->accessDenied'),
    ('page_forbidden',                  'ServiceLocator::get(HtmlService::class)->pageForbidden'),
    ('bad_request',                     'ServiceLocator::get(HtmlService::class)->badRequest'),
    ('page_not_found',                  'ServiceLocator::get(HtmlService::class)->pageNotFound'),
    ('get_tags_content_title',          'ServiceLocator::get(HtmlService::class)->getTagsContentTitle'),
    ('get_combined_categories_content_title','ServiceLocator::get(HtmlService::class)->getCombinedCategoriesContentTitle'),
    ('set_status_header',               'ServiceLocator::get(HtmlService::class)->setStatusHeader'),
    ('render_category_literal_description','ServiceLocator::get(HtmlService::class)->renderCategoryLiteralDescription'),
    ('register_default_menubar_blocks', 'ServiceLocator::get(HtmlService::class)->registerDefaultMenubarBlocks'),
    ('render_element_name',             'ServiceLocator::get(HtmlService::class)->renderElementName'),
    ('render_element_description',      'ServiceLocator::get(HtmlService::class)->renderElementDescription'),
    ('get_thumbnail_title',             'ServiceLocator::get(HtmlService::class)->getThumbnailTitle'),
    ('get_src_image_url_protection_handler','ServiceLocator::get(HtmlService::class)->getSrcImageUrlProtectionHandler'),
    ('get_element_url_protection_handler','ServiceLocator::get(HtmlService::class)->getElementUrlProtectionHandler'),
    ('flush_page_messages',             'ServiceLocator::get(HtmlService::class)->flushPageMessages'),
    ('pwg_nl2br',                       'ServiceLocator::get(HtmlService::class)->pwgNl2br'),
    # PictureService delegates
    ('get_default_slideshow_params',    'ServiceLocator::get(PictureService::class)->getDefaultSlideshowParams'),
    ('correct_slideshow_params',        'ServiceLocator::get(PictureService::class)->correctSlideshowParams'),
    ('decode_slideshow_params',         'ServiceLocator::get(PictureService::class)->decodeSlideshowParams'),
    ('encode_slideshow_params',         'ServiceLocator::get(PictureService::class)->encodeSlideshowParams'),
    ('increase_image_visit_counter',    'ServiceLocator::get(PictureService::class)->increaseImageVisitCounter'),
    ('count_pdf_pages',                 'ServiceLocator::get(PictureService::class)->countPdfPages'),
    # RateService delegates
    ('rate_picture',                    'ServiceLocator::get(RateService::class)->ratePicture'),
    ('update_rating_score',             'ServiceLocator::get(RateService::class)->updateRatingScore'),
    # MetadataService delegates
    ('get_iptc_data',                   'ServiceLocator::get(MetadataService::class)->getIptcData'),
    ('clean_iptc_value',                'ServiceLocator::get(MetadataService::class)->cleanIptcValue'),
    ('get_exif_data',                   'ServiceLocator::get(MetadataService::class)->getExifData'),
    ('strip_html_in_metadata',          'ServiceLocator::get(MetadataService::class)->stripHtmlInMetadata'),
    ('parse_exif_gps_data',             'ServiceLocator::get(MetadataService::class)->parseExifGpsData'),
    # CommentService delegates
    ('user_comment_check',              'ServiceLocator::get(CommentService::class)->userCommentCheck'),
    ('insert_user_comment',             'ServiceLocator::get(CommentService::class)->insertUserComment'),
    ('delete_user_comment',             'ServiceLocator::get(CommentService::class)->deleteUserComment'),
    ('update_user_comment',             'ServiceLocator::get(CommentService::class)->updateUserComment'),
    ('email_admin',                     'ServiceLocator::get(CommentService::class)->emailAdmin'),
    ('get_comment_author_id',           'ServiceLocator::get(CommentService::class)->getCommentAuthorId'),
    ('validate_user_comment',           'ServiceLocator::get(CommentService::class)->validateUserComment'),
    ('invalidate_user_cache_nb_comments','ServiceLocator::get(CommentService::class)->invalidateUserCacheNbComments'),
    # NotificationService delegates
    ('get_std_sql_where_restrict_filter','ServiceLocator::get(NotificationService::class)->getStdSqlWhereRestrictFilter'),
    ('custom_notification_query',       'ServiceLocator::get(NotificationService::class)->customNotificationQuery'),
    ('nb_new_comments',                 'ServiceLocator::get(NotificationService::class)->nbNewComments'),
    ('new_comments',                    'ServiceLocator::get(NotificationService::class)->newComments'),
    ('nb_unvalidated_comments',         'ServiceLocator::get(NotificationService::class)->nbUnvalidatedComments'),
    ('nb_new_elements',                 'ServiceLocator::get(NotificationService::class)->nbNewElements'),
    ('new_elements',                    'ServiceLocator::get(NotificationService::class)->newElements'),
    ('nb_updated_categories',           'ServiceLocator::get(NotificationService::class)->nbUpdatedCategories'),
    ('updated_categories',              'ServiceLocator::get(NotificationService::class)->updatedCategories'),
    ('nb_new_users',                    'ServiceLocator::get(NotificationService::class)->nbNewUsers'),
    ('new_users',                       'ServiceLocator::get(NotificationService::class)->newUsers'),
    ('news_exists',                     'ServiceLocator::get(NotificationService::class)->newsExists'),
    ('add_news_line',                   'ServiceLocator::get(NotificationService::class)->addNewsLine'),
    ('news',                            'ServiceLocator::get(NotificationService::class)->news'),
    ('get_recent_post_dates',           'ServiceLocator::get(NotificationService::class)->getRecentPostDates'),
    ('get_recent_post_dates_array',     'ServiceLocator::get(NotificationService::class)->getRecentPostDatesArray'),
    ('get_html_description_recent_post_date','ServiceLocator::get(NotificationService::class)->getHtmlDescriptionRecentPostDate'),
    ('get_title_recent_post_date',      'ServiceLocator::get(NotificationService::class)->getTitleRecentPostDate'),
    # MailService delegates
    ('get_mail_sender_name',            'ServiceLocator::get(MailService::class)->getMailSenderName'),
    ('get_mail_sender_email',           'ServiceLocator::get(MailService::class)->getMailSenderEmail'),
    ('get_mail_configuration',          'ServiceLocator::get(MailService::class)->getMailConfiguration'),
    ('format_email',                    'ServiceLocator::get(MailService::class)->formatEmail'),
    ('unformat_email',                  'ServiceLocator::get(MailService::class)->unformatEmail'),
    ('get_clean_recipients_list',       'ServiceLocator::get(MailService::class)->getCleanRecipientsList'),
    ('get_strict_email_list',           'ServiceLocator::get(MailService::class)->getStrictEmailList'),
    ('get_mail_template',               'ServiceLocator::get(MailService::class)->getMailTemplate'),
    ('get_str_email_format',            'ServiceLocator::get(MailService::class)->getStrEmailFormat'),
    ('switch_lang_to',                  'ServiceLocator::get(MailService::class)->switchLangTo'),
    ('switch_lang_back',                'ServiceLocator::get(MailService::class)->switchLangBack'),
    ('pwg_mail_notification_admins',    'ServiceLocator::get(MailService::class)->pwgMailNotificationAdmins'),
    ('pwg_mail_admins',                 'ServiceLocator::get(MailService::class)->pwgMailAdmins'),
    ('pwg_mail_group',                  'ServiceLocator::get(MailService::class)->pwgMailGroup'),
    ('mail_function_is_usable',         'ServiceLocator::get(MailService::class)->mailFunctionIsUsable'),
    ('pwg_mail',                        'ServiceLocator::get(MailService::class)->pwgMail'),
    ('pwg_send_mail',                   'ServiceLocator::get(MailService::class)->pwgSendMail'),
    ('move_css_to_body',                'ServiceLocator::get(MailService::class)->moveCssToBody'),
    ('pwg_send_mail_test',              'ServiceLocator::get(MailService::class)->pwgSendMailTest'),
    ('pwg_generate_reset_password_mail','ServiceLocator::get(MailService::class)->pwgGenerateResetPasswordMail'),
    ('pwg_generate_set_password_mail',  'ServiceLocator::get(MailService::class)->pwgGenerateSetPasswordMail'),
    ('pwg_generate_code_verification_mail','ServiceLocator::get(MailService::class)->pwgGenerateCodeVerificationMail'),
    ('pwg_generate_success_reset_password_mail','ServiceLocator::get(MailService::class)->pwgGenerateSuccessResetPasswordMail'),
    # Special: l10n -> Lang::t (different pattern — handles null key and variadic args)
    # NOTE: l10n(null) becomes Lang::t('') — callers in src/ should never pass null
    # NOTE: l10n('key', $arg) becomes Lang::t('key', $arg) — variadic preserved
    ('l10n',                            'Lang::t'),
    # l10n_dec -> Translator
    ('l10n_dec',                        'Translator::get()->plural'),
    # get_branch_from_version — pure string operation, inline
    # Keeping as static to avoid complex inlining across 40+ call sites
]

# Use statements needed per class prefix
USES = {
    'ServiceLocator::get(StringUtil::class)': 'use Piwigo\\Core\\StringUtil;',
    'ServiceLocator::get(Util::class)': 'use Piwigo\\Core\\Util;',
    'ServiceLocator::get(ConfigService::class)': 'use Piwigo\\Config\\ConfigService;',
    'ServiceLocator::get(DateService::class)': 'use Piwigo\\Core\\DateService;',
    'ServiceLocator::get(QueryHelper::class)': 'use Piwigo\\Db\\QueryHelper;',
    'ServiceLocator::get(SessionService::class)': 'use Piwigo\\Session\\SessionService;',
    'ServiceLocator::get(CategoryService::class)': 'use Piwigo\\Category\\CategoryService;',
    'ServiceLocator::get(TagService::class)': 'use Piwigo\\Tag\\TagService;',
    'ServiceLocator::get(HtmlService::class)': 'use Piwigo\\Html\\HtmlService;',
    'ServiceLocator::get(PictureService::class)': 'use Piwigo\\Picture\\PictureService;',
    'ServiceLocator::get(RateService::class)': 'use Piwigo\\Rate\\RateService;',
    'ServiceLocator::get(MetadataService::class)': 'use Piwigo\\Metadata\\MetadataService;',
    'ServiceLocator::get(CommentService::class)': 'use Piwigo\\Comment\\CommentService;',
    'ServiceLocator::get(NotificationService::class)': 'use Piwigo\\Notification\\NotificationService;',
    'ServiceLocator::get(MailService::class)': 'use Piwigo\\Mail\\MailService;',
    'ServiceLocator::get(HtmlService::class)->accessDenied': '',  # already counted above
    'Lang::t': 'use Piwigo\\Core\\Lang;',
    'Translator::get()->plural': 'use Piwigo\\Lang\\Translator;',
}
SL_USE = 'use Piwigo\\Core\\ServiceLocator;'


def split_strings(code):
    return re.split(r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")""", code, flags=re.DOTALL)


def process(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    orig = content
    parts = split_strings(content)
    for old, new in REPS:
        parts = [re.sub(r'\b' + re.escape(old) + r'\b(?=\s*\()', new, p) if i % 2 == 0 else p
                 for i, p in enumerate(parts)]
    content = ''.join(parts)
    if content == orig:
        return False

    # Add missing use statements
    needed = set()
    for prefix, use_stmt in USES.items():
        if not use_stmt:
            continue
        # Check if the prefix appears in the modified content
        search = prefix.split('::')[0] + '::'
        if search in content and use_stmt not in content:
            needed.add(use_stmt)
    # ServiceLocator is needed if any ServiceLocator:: call was added
    if 'ServiceLocator::' in content and SL_USE not in content and 'namespace ' in content:
        needed.add(SL_USE)

    if needed:
        lines = content.split('\n')
        last = max((i for i, l in enumerate(lines) if re.match(r'^use [A-Z\\]', l)), default=-1)
        if last >= 0:
            insert = '\n'.join(sorted(needed))
            lines.insert(last + 1, insert)
            content = '\n'.join(lines)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    return True


changed = 0
for dp, _, fnames in os.walk(BASE):
    if 'vendor' in dp.split(os.sep):
        continue
    for fn in fnames:
        if fn.endswith('.php') and fn not in SKIP:
            if process(os.path.join(dp, fn)):
                changed += 1
                print(f'Updated: {os.path.relpath(os.path.join(dp, fn), BASE)}')
print(f'\nDone. {changed} files updated.')
