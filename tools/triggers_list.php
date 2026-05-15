<?php
$core = array(
array(
  'name' => 'allow_increment_element_hit_count',
  'type' => 'dispatch',
  'vars' => array('bool', 'content_not_set'),
  'files' => array('src/Piwigo/Controller/PictureController.php'),
),
array(
  'name' => 'batch_manager_perform_filters',
  'type' => 'dispatch',
  'vars' => array('array', 'filter_sets', 'array', 'bulk_manager_filter'),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
  'infos' => 'New in 2.7',
),
array(
  'name' => 'batch_manager_register_filters',
  'type' => 'dispatch',
  'vars' => array('array', 'bulk_manager_filter'),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
  'infos' => 'New in 2.7',
),
array(
  'name' => 'batch_manager_url_filter',
  'type' => 'dispatch',
  'vars' => array('array', 'bulk_manager_filter', 'string', 'filter'),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
  'infos' => 'New in 2.7.',
),
array(
  'name' => 'before_parse_mail_template',
  'type' => 'notify',
  'vars' => array('string', 'cache_key', 'string', 'content_type'),
  'files' => array('src/Piwigo/Mail/MailService.php'),
),
array(
  'name' => 'before_send_mail',
  'type' => 'dispatch',
  'vars' => array('bool', 'result', 'mixed', 'to', 'array', 'arguments', 'PHPMailer', 'mail'),
  'files' => array('src/Piwigo/Mail/MailService.php'),
),
array(
  'name' => 'begin_delete_elements',
  'type' => 'notify',
  'vars' => array('array', 'ids'),
  'files' => array('src/Piwigo/Admin/Image/ImageAdminService.php'),
),
array(
  'name' => 'blockmanager_apply',
  'type' => 'notify',
  'vars' => array('object', 'menublock'),
  'files' => array('src/Piwigo/Menu/BlockManager.php'),
  'infos' => 'use this trigger to modify existing menu blocks',
),
array(
  'name' => 'blockmanager_prepare_display',
  'type' => 'notify',
  'vars' => array('object', 'this'),
  'files' => array('src/Piwigo/Menu/BlockManager.php (BlockManager::prepareDisplay)'),
),
array(
  'name' => 'blockmanager_register_blocks',
  'type' => 'notify',
  'vars' => array('object', 'menu'),
  'files' => array('src/Piwigo/Menu/BlockManager.php (BlockManager::loadRegisteredBlocks)'),
  'infos' => 'use this trigger to add menu block',
),
array(
  'name' => 'clean_iptc_value',
  'type' => 'dispatch',
  'vars' => array('string', 'value'),
  'files' => array('src/Piwigo/Metadata/MetadataService.php'),
),
array(
  'name' => 'combined_css',
  'type' => 'dispatch',
  'vars' => array('string', 'href', 'Combinable', '$combinable'),
  'files' => array('src/Piwigo/Template/Template.php'),
),
array(
  'name' => 'combined_css_postfilter',
  'type' => 'dispatch',
  'vars' => array('string', 'css'),
  'files' => array('src/Piwigo/Template/FileCombiner.php (FileCombiner::processCss)'),
),
array(
  'name' => 'combined_script',
  'type' => 'dispatch',
  'vars' => array('string', 'ret', 'string', 'script'),
  'files' => array('src/Piwigo/Template/Template.php (Template::makeScriptSrc)'),
),
array(
  'name' => 'combinable_preparse',
  'type' => 'notify',
  'vars' => array('Template', 'template', 'Combinable', '$combinable', 'FileCombiner', '$combiner'),
  'files' => array('src/Piwigo/Template/FileCombiner.php (FileCombiner::processCombinable)'),
  'infos' => 'New in 2.6.',
),
array(
  'name' => 'create_virtual_category',
  'type' => 'notify',
  'vars' => array('array', 'category'),
  'files' => array('src/Piwigo/Admin/Category/CategoryAdminService.php'),
),
array(
  'name' => 'delete_categories',
  'type' => 'notify',
  'vars' => array('array', 'ids'),
  'files' => array('src/Piwigo/Admin/Category/CategoryAdminService.php'),
),
array(
  'name' => 'delete_elements',
  'type' => 'notify',
  'vars' => array('array', 'ids'),
  'files' => array('src/Piwigo/Admin/Image/ImageAdminService.php'),
),
array(
  'name' => 'delete_group',
  'type' => 'notify',
  'vars' => array('array', 'groupids'),
  'files' => array('src/Piwigo/Admin/Users/UserAdminService.php'),
),
array(
  'name' => 'delete_tags',
  'type' => 'notify',
  'vars' => array('array', 'tag_ids'),
  'files' => array('src/Piwigo/Admin/Tag/TagAdminService.php'),
),
array(
  'name' => 'delete_user',
  'type' => 'notify',
  'vars' => array('int', 'user_id'),
  'files' => array('src/Piwigo/Admin/Users/UserAdminService.php'),
),
array(
  'name' => 'derivative_params_get',
  'type' => 'dispatch',
  'vars' => array('DerivativeParams', 'params'),
  'files' => array('src/Piwigo/Controller/ImageDerivativeController.php'),
),
array(
  'name' => 'element_set_global_action',
  'type' => 'notify',
  'vars' => array('string', 'action', 'array', 'collection'),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'empty_lounge',
  'type' => 'notify',
  'vars' => array('array', 'rows'),
  'files' => array('src/Piwigo/Admin/Category/CategoryAdminService.php'),
  'infos' => 'New in 12',
),
array(
  'name' => 'finalize_login',
  'type' => 'dispatch',
  'vars' => array('array', 'state', 'bool', 'user_found', 'bool', 'remember_me'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
),
array(
  'name' => 'format_exif_data',
  'type' => 'dispatch',
  'vars' => array('array', 'exif', 'string', 'filename', 'array', 'map'),
  'files' => array('src/Piwigo/Metadata/MetadataService.php'),
),
array(
  'name' => 'get_admin_advanced_features_links',
  'type' => 'dispatch',
  'vars' => array('array', 'advanced_features'),
  'files' => array('src/Piwigo/Controller/Admin/MaintenanceController.php'),
),
array(
  'name' => 'get_admin_plugin_menu_links',
  'type' => 'dispatch',
  'vars' => array('array', null),
  'files' => array('src/Piwigo/Controller/Admin/ExtensionsController.php'),
  'infos' => 'use this trigger to add links into admin plugins menu',
),
array(
  'name' => 'get_admins_site_links',
  'type' => 'dispatch',
  'vars' => array('array', 'plugin_links', 'int', 'site_id', 'bool', 'is_remote'),
  'files' => array('src/Piwigo/Controller/Admin/MaintenanceController.php'),
),
array(
  'name' => 'get_batch_manager_prefilters',
  'type' => 'dispatch',
  'vars' => array('array', 'prefilters'),
  'files' => array('src/Piwigo/Admin/BatchManager/FilterResolver.php'),
  'infos' => 'use this trigger to add prefilters into batch manager global',
),
array(
  'name' => 'get_categories_menu_sql_where',
  'type' => 'dispatch',
  'vars' => array('string', 'where', 'bool', 'user_expand', 'bool', 'filter_enabled'),
  'files' => array('src/Piwigo/Category/CategoryService.php'),
),
array(
  'name' => 'get_category_preferred_image_orders',
  'type' => 'dispatch',
  'vars' => array('array', null),
  'files' => array('src/Piwigo/Category/CategoryService.php'),
),
array(
  'name' => 'get_comments_derivative_params',
  'type' => 'dispatch',
  'vars' => array('DerivativeParams', null),
  'files' => array('src/Piwigo/Controller/CommentsController.php'),
  'infos' => 'New in 2.4',
),
array(
  'name' => 'get_derivative_url',
  'type' => 'dispatch',
  'vars' => array('string', 'url', 'DerivativeParams', null, 'SrcImage', 'this', 'string', 'rel_url'),
  'files' => array('src/Piwigo/Image/DerivativeImage.php'),
  'infos' => 'New in 2.4',
),
array(
  'name' => 'get_element_url',
  'type' => 'dispatch',
  'vars' => array('string', 'url', 'array', 'element_info'),
  'files' => array('src/Piwigo/Url/UrlService.php'),
),
array(
  'name' => 'get_index_album_derivative_params',
  'type' => 'dispatch',
  'vars' => array('DerivativeParams', null),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php'),
  'infos' => 'New in 2.4',
),
array(
  'name' => 'get_index_derivative_params',
  'type' => 'dispatch',
  'vars' => array('DerivativeParams', null),
  'files' => array('src/Piwigo/Category/CategoryDefaultRenderer.php'),
),
array(
  'name' => 'get_mimetype_location',
  'type' => 'dispatch',
  'vars' => array('string', 'url', 'string', 'ext'),
  'files' => array('src/Piwigo/Image/SrcImage.php'),
),
array(
  'name' => 'get_popup_help_content',
  'type' => 'dispatch',
  'vars' => array('string', 'help_content', 'string', 'page'),
  'files' => array('src/Piwigo/Controller/Admin/MiscController.php', 'src/Piwigo/Controller/PopuphelpController.php'),
),
array(
  'name' => 'get_pwg_themes',
  'type' => 'dispatch',
  'vars' => array('array', 'themes'),
  'files' => array('src/Piwigo/Core/Util.php'),
),
array(
  'name' => 'get_src_image_url',
  'type' => 'dispatch',
  'vars' => array('string', 'url', 'SrcImage', 'this'),
  'files' => array('src/Piwigo/Image/SrcImage.php'),
  'infos' => 'New in 2.4',
),
array(
  'name' => 'get_tag_alt_names',
  'type' => 'dispatch',
  'vars' => array('array', null, 'string', 'raw_name'),
  'files' => array('src/Piwigo/Admin/Tag/TagAdminService.php'),
  'infos' => 'New in 2.4',
),
array(
  'name' => 'get_tag_name_like_where',
  'type' => 'dispatch',
  'vars' => array('array', null, 'string', 'tag_name'),
  'files' => array('src/Piwigo/Admin/Tag/TagAdminService.php'),
  'infos' => 'New in 2.7',
),
array(
  'name' => 'get_thumbnail_title',
  'type' => 'dispatch',
  'vars' => array('string', 'title', 'array', 'info'),
  'files' => array('src/Piwigo/Html/HtmlService.php'),
),
array(
  'name' => 'get_webmaster_mail_address',
  'type' => 'dispatch',
  'vars' => array('string', 'email'),
  'files' => array('src/Piwigo/Core/Util.php'),
  'infos' => 'New in 2.6',
),
array(
  'name' => 'init',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Bootstrap/CommonBootstrap.php'),
  'infos' => 'fired just after common initialization; $conf, $user and $page (partial) are available',
),
array(
  'name' => 'invalidate_user_cache',
  'type' => 'notify',
  'vars' => array('bool', 'full'),
  'files' => array('src/Piwigo/Admin/Users/UserAdminService.php'),
),
array(
  'name' => 'list_check_integrity',
  'type' => 'notify',
  'vars' => array('object', 'this'),
  'files' => array('src/Piwigo/Admin/Integrity/CheckIntegrity.php (CheckIntegrity::check)'),
),
array(
  'name' => 'load_conf',
  'type' => 'notify',
  'vars' => array('string', 'condition'),
  'files' => array('src/Piwigo/Config/ConfigService.php'),
  'infos' => 'New in 2.6. <b>Warning:</b> you can\'t trigger the first call done in common.inc.php. Use <i>init</i> instead.',
),
array(
  'name' => 'load_image_library',
  'type' => 'notify',
  'vars' => array('object', 'this'),
  'files' => array('src/Piwigo/Admin/Image/PwgImage.php (PwgImage::__construct)'),
),
array(
  'name' => 'load_profile_in_template',
  'type' => 'notify',
  'vars' => array('array', 'userdata'),
  'files' => array('src/Piwigo/Users/ProfileService.php'),
),
array(
  'name' => 'loading_lang',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Bootstrap/CommonBootstrap.php', 'src/Piwigo/Core/Util.php', 'src/Piwigo/Mail/MailService.php'),
),
array(
  'name' => 'loc_action_before_http_headers',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/ActionController.php'),
),
array(
  'name' => 'loc_after_page_header',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/PageHeaderRenderer.php'),
),
array(
  'name' => 'loc_begin_about',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/AboutController.php'),
),
array(
  'name' => 'loc_begin_admin',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AdminController.php'),
),
array(
  'name' => 'loc_begin_admin_page',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AdminController.php'),
),
array(
  'name' => 'loc_begin_cat_list',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AlbumController.php'),
),
array(
  'name' => 'loc_begin_cat_modify',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AlbumController.php'),
),
array(
  'name' => 'loc_begin_comments',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/CommentsController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_element_set_global',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'loc_begin_element_set_unit',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'loc_begin_identification',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/IdentificationController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_index',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/GalleryController.php'),
),
array(
  'name' => 'loc_begin_index_category_thumbnails',
  'type' => 'notify',
  'vars' => array('array', 'categories'),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php'),
),
array(
  'name' => 'loc_begin_index_category_thumbnails_query',
  'type' => 'dispatch',
  'vars' => array('string', 'query'),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php'),
),
array(
  'name' => 'loc_begin_index_thumbnails',
  'type' => 'notify',
  'vars' => array('array', 'pictures'),
  'files' => array('src/Piwigo/Category/CategoryDefaultRenderer.php'),
),
array(
  'name' => 'loc_begin_notification',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/NotificationController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_page_header',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/PageHeaderRenderer.php'),
),
array(
  'name' => 'loc_begin_page_tail',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/PageTailRenderer.php'),
),
array(
  'name' => 'loc_begin_password',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/PasswordController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_picture',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/PictureController.php'),
),
array(
  'name' => 'loc_begin_profile',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/ProfileController.php'),
),
array(
  'name' => 'loc_begin_register',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/RegisterController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_search',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/SearchController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_begin_tags',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/TagsController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_about',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/AboutController.php'),
),
array(
  'name' => 'loc_end_add_uploaded_file',
  'type' => 'notify',
  'vars' => array('array', 'image_infos'),
  'files' => array('src/Piwigo/Admin/Upload/UploadService.php'),
  'infos' => 'New in 2.11',
),
array(
  'name' => 'loc_end_add_format',
  'type' => 'notify',
  'vars' => array('array', 'format_infos'),
  'files' => array('src/Piwigo/Admin/Upload/UploadService.php'),
),
array(
  'name' => 'loc_end_admin',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AdminController.php'),
),
array(
  'name' => 'loc_end_cat_list',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AlbumController.php'),
),
array(
  'name' => 'loc_end_cat_modify',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/AlbumController.php'),
),
array(
  'name' => 'loc_end_comments',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/CommentsController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_element_set_global',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'loc_end_element_set_unit',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'loc_end_help',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/MiscController.php'),
),
array(
  'name' => 'loc_end_identification',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/IdentificationController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_index',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/GalleryController.php'),
),
array(
  'name' => 'loc_end_index_category_thumbnails',
  'type' => 'dispatch',
  'vars' => array('array', 'tpl_thumbnails_var'),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php'),
),
array(
  'name' => 'loc_end_index_thumbnails',
  'type' => 'dispatch',
  'vars' => array('array', 'tpl_thumbnails_var', 'array', 'pictures'),
  'files' => array('src/Piwigo/Category/CategoryDefaultRenderer.php'),
),
array(
  'name' => 'loc_end_no_photo_yet',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/NoPhotoYetRenderer.php'),
),
array(
  'name' => 'loc_end_notification',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/NotificationController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_page_header',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/PageHeaderRenderer.php'),
),
array(
  'name' => 'loc_end_page_tail',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Page/PageTailRenderer.php'),
),
array(
  'name' => 'loc_end_password',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/PasswordController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_photo_add_direct',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/PhotoController.php'),
),
array(
  'name' => 'loc_end_picture',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/PictureController.php'),
),
array(
  'name' => 'loc_end_picture_modify',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/PhotoController.php'),
  'infos' => 'New in 2.6.3',
),
array(
  'name' => 'loc_end_profile',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/ProfileController.php'),
),
array(
  'name' => 'loc_end_register',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/RegisterController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_section_init',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Section/SectionInitializer.php'),
  'infos' => 'fired after section initialization; $page variable is fully defined',
),
array(
  'name' => 'loc_end_tags',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/TagsController.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'loc_end_themes_installed',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/ExtensionsController.php'),
  'infos' => 'New in 2.6.3',
),
array(
  'name' => 'loc_visible_user_list',
  'type' => 'dispatch',
  'vars' => array('array', 'visible_user_list'),
  'files' => array('src/Piwigo/Controller/Admin/UsersController.php'),
),
array(
  'name' => 'login_failure',
  'type' => 'notify',
  'vars' => array('string', 'username'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
),
array(
  'name' => 'login_failure_before_log_user',
  'type' => 'notify',
  'vars' => array('string', 'username'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
),
array(
  'name' => 'login_success',
  'type' => 'notify',
  'vars' => array('string', 'username'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
),
array(
  'name' => 'merge_tags',
  'type' => 'notify',
  'vars' => array('int', 'destination_tag_id', 'array', 'merge_tag'),
  'files' => array('src/Piwigo/Ws/Method/TagsEndpoints.php'),
),
array(
  'name' => 'nbm_event_handler_added',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Controller/Admin/MiscController.php'),
),
array(
  'name' => 'nbm_render_global_customize_mail_content',
  'type' => 'dispatch',
  'vars' => array('string', 'customize_mail_content'),
  'files' => array('src/Piwigo/Controller/Admin/MiscController.php'),
),
array(
  'name' => 'nbm_render_user_customize_mail_content',
  'type' => 'dispatch',
  'vars' => array('string', 'customize_mail_content', 'string', 'nbm_user'),
  'files' => array('src/Piwigo/Controller/Admin/MiscController.php'),
),
array(
  'name' => 'perform_batch_manager_prefilters',
  'type' => 'dispatch',
  'vars' => array('array', 'filter_sets', 'string', 'prefilter'),
  'files' => array('src/Piwigo/Controller/Admin/BatchManagerController.php'),
),
array(
  'name' => 'picture_modify_before_update',
  'type' => 'dispatch',
  'vars' => array('array', 'data'),
  'files' => array('src/Piwigo/Controller/Admin/PhotoController.php'),
  'infos' => 'New in 2.6.2.',
),
array(
  'name' => 'picture_pictures_data',
  'type' => 'dispatch',
  'vars' => array('array', 'picture'),
  'files' => array('src/Piwigo/Controller/PictureController.php'),
),
array(
  'name' => 'plugin_install_errors',
  'type' => 'dispatch',
  'vars' => array('array', 'errors'),
  'files' => array('src/Piwigo/Admin/Plugins.php'),
),
array(
  'name' => 'plugins_loaded',
  'type' => 'notify',
  'vars' => array(),
  'files' => array('src/Piwigo/Plugin/PluginService.php'),
),
array(
  'name' => 'pwg_log_allowed',
  'type' => 'dispatch',
  'vars' => array('bool', 'do_log', 'int', 'image_id', 'string', 'image_type'),
  'files' => array('src/Piwigo/Core/Util.php'),
),
array(
  'name' => 'pwg_log_update_last_visit',
  'type' => 'dispatch',
  'vars' => array('bool', 'update'),
  'files' => array('src/Piwigo/Core/Util.php'),
),
array(
  'name' => 'qsearch_before_eval',
  'type' => 'notify',
  'vars' => array('QExpression', 'expression', 'object', 'qsr'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'qsearch_expression_parsed',
  'type' => 'notify',
  'vars' => array('QExpression', 'expression'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'qsearch_get_images_sql_scopes',
  'type' => 'dispatch',
  'vars' => array('array', 'clauses', 'QSingleToken', 'token', 'QExpression', 'expr'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'qsearch_get_scopes',
  'type' => 'dispatch',
  'vars' => array('array', 'scopes'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'qsearch_pre',
  'type' => 'dispatch',
  'vars' => array('string', 'query'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'qsearch_results',
  'type' => 'dispatch',
  'vars' => array('array', 'results', 'QExpression', 'expression', 'object', 'qsr'),
  'files' => array('src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'register_user',
  'type' => 'notify',
  'vars' => array('array', 'user'),
  'files' => array('src/Piwigo/Users/UserService.php'),
),
array(
  'name' => 'register_user_check',
  'type' => 'dispatch',
  'vars' => array('array', 'errors', 'array', 'user'),
  'files' => array('src/Piwigo/Users/UserService.php'),
),
array(
  'name' => 'render_category_description',
  'type' => 'dispatch',
  'vars' => array('string', 'category_description', 'string', 'action'),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php', 'src/Piwigo/Section/SectionInitializer.php', 'src/Piwigo/Ws/Method/CategoriesEndpoints.php'),
),
array(
  'name' => 'render_category_literal_description',
  'type' => 'dispatch',
  'vars' => array('string', 'category_description'),
  'files' => array('src/Piwigo/Category/CategoryCatsRenderer.php'),
),
array(
  'name' => 'render_category_name',
  'type' => 'dispatch',
  'vars' => array('string', 'category_name', 'string', 'location'),
  'files' => array('src/Piwigo/Category/CategoryService.php', 'src/Piwigo/Controller/Admin/AlbumController.php', 'src/Piwigo/Ws/Method/CategoriesEndpoints.php'),
),
array(
  'name' => 'render_comment_author',
  'type' => 'dispatch',
  'vars' => array('string', 'comment_author'),
  'files' => array('src/Piwigo/Controller/CommentsController.php', 'src/Piwigo/Picture/PictureCommentRenderer.php'),
),
array(
  'name' => 'render_comment_content',
  'type' => 'dispatch',
  'vars' => array('string', 'comment_content'),
  'files' => array('src/Piwigo/Controller/CommentsController.php', 'src/Piwigo/Picture/PictureCommentRenderer.php'),
),
array(
  'name' => 'render_element_content',
  'type' => 'dispatch',
  'vars' => array('string', 'content', 'array', 'current_picture'),
  'files' => array('src/Piwigo/Controller/PictureController.php'),
),
array(
  'name' => 'render_element_description',
  'type' => 'dispatch',
  'vars' => array('string', 'element_description', 'string', 'action'),
  'files' => array('src/Piwigo/Html/HtmlService.php', 'src/Piwigo/Controller/PictureController.php', 'src/Piwigo/Ws/Method/CategoriesEndpoints.php'),
),
array(
  'name' => 'render_element_name',
  'type' => 'dispatch',
  'vars' => array('string', 'element_name', 'array', 'info'),
  'files' => array('src/Piwigo/Html/HtmlService.php', 'src/Piwigo/Ws/Method/ImagesEndpoints.php'),
),
array(
  'name' => 'render_lost_password_mail_content',
  'type' => 'dispatch',
  'vars' => array('string', 'message'),
  'files' => array('src/Piwigo/Mail/MailService.php'),
),
array(
  'name' => 'render_page_banner',
  'type' => 'dispatch',
  'vars' => array('string', 'gallery_title'),
  'files' => array('src/Piwigo/Page/PageHeaderRenderer.php'),
),
array(
  'name' => 'render_tag_name',
  'type' => 'dispatch',
  'vars' => array('string', 'tag_name', 'array', 'tag'),
  'files' => array('src/Piwigo/Admin/Tag/TagAdminService.php', 'src/Piwigo/Tag/TagService.php', 'src/Piwigo/Controller/Admin/MiscController.php', 'src/Piwigo/Search/SearchService.php'),
),
array(
  'name' => 'render_tag_url',
  'type' => 'dispatch',
  'vars' => array('string', 'tag_name'),
  'files' => array('src/Piwigo/Admin/Tag/TagAdminService.php', 'src/Piwigo/Ws/Method/TagsEndpoints.php'),
),
array(
  'name' => 'save_profile_from_post',
  'type' => 'notify',
  'vars' => array('int', 'user_id'),
  'files' => array('src/Piwigo/Users/ProfileService.php'),
),
array(
  'name' => 'sendResponse',
  'type' => 'notify',
  'vars' => array('string', 'encodedResponse'),
  'files' => array('src/Piwigo/Ws/PwgServer.php'),
),
array(
  'name' => 'set_status_header',
  'type' => 'notify',
  'vars' => array('int', 'code', 'string', 'text'),
  'files' => array('src/Piwigo/Html/HtmlService.php'),
),
array(
  'name' => 'tabsheet_before_select',
  'type' => 'dispatch',
  'vars' => array('array', 'sheets', 'string', 'tabsheet_id'),
  'files' => array('src/Piwigo/Admin/Tabsheet.php (Tabsheet::select)'),
  'infos' => 'New in 2.4, use this trigger to add tabs to a tabsheet',
),
array(
  'name' => 'theme_activate_errors',
  'type' => 'dispatch',
  'vars' => array('array', 'errors'),
  'files' => array('src/Piwigo/Admin/Themes.php'),
),
array(
  'name' => 'trigger',
  'type' => 'notify',
  'vars' => array('array', null),
  'files' => array('src/Piwigo/Plugins/EventDispatcher.php (dispatch, notify)'),
),
array(
  'name' => 'try_log_user',
  'type' => 'dispatch',
  'vars' => array('boolean', 'success', 'string', 'username', 'string', 'password', 'bool', 'remember_me'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
  'infos' => 'New in 2.5. Used by identification form to check user credentials. If <i>success</i> is <i>true</i>, another login method already succeeded. Return <i>true</i> if your method succeeds.',
),
array(
  'name' => 'update_rating_score',
  'type' => 'dispatch',
  'vars' => array('boolean', 'done', 'int', 'element_id'),
  'files' => array('src/Piwigo/Rate/RateService.php'),
  'infos' => 'New in 2.6.',
),
array(
  'name' => 'upload_file',
  'type' => 'dispatch',
  'vars' => array('string', 'representative_ext', 'string', 'file_path'),
  'files' => array('src/Piwigo/Admin/Upload/UploadService.php'),
),
array(
  'name' => 'user_comment_check',
  'type' => 'dispatch',
  'vars' => array('string', 'comment_action', 'array', 'comm'),
  'files' => array('src/Piwigo/Comment/CommentService.php'),
  'infos' => 'use this trigger to add conditions on comment validation',
),
array(
  'name' => 'user_comment_deletion',
  'type' => 'notify',
  'vars' => array('mixed', 'comment_id'),
  'files' => array('src/Piwigo/Comment/CommentService.php'),
  'infos' => '$comment_id is an int or an array of int',
),
array(
  'name' => 'user_comment_insertion',
  'type' => 'notify',
  'vars' => array('array', 'comm'),
  'files' => array('src/Piwigo/Picture/PictureCommentRenderer.php'),
),
array(
  'name' => 'user_comment_validation',
  'type' => 'notify',
  'vars' => array('mixed', 'comment_id'),
  'files' => array('src/Piwigo/Comment/CommentService.php'),
  'infos' => '$comment_id is an int or an array of int',
),
array(
  'name' => 'user_init',
  'type' => 'notify',
  'vars' => array('array', 'user'),
  'files' => array('src/Piwigo/Users/UserBootstrap.php'),
),
array(
  'name' => 'user_login',
  'type' => 'notify',
  'vars' => array('int', 'user_id'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'user_logout',
  'type' => 'notify',
  'vars' => array('int', 'user_id'),
  'files' => array('src/Piwigo/Users/AuthService.php'),
  'infos' => 'New in 2.5',
),
array(
  'name' => 'ws_add_methods',
  'type' => 'notify',
  'vars' => array('object', 'this'),
  'files' => array('src/Piwigo/Ws/PwgServer.php'),
),
array(
  'name' => 'ws_images_uploadCompleted',
  'type' => 'notify',
  'vars' => array('array', 'upload_data'),
  'files' => array('src/Piwigo/Ws/Method/ImagesEndpoints.php'),
  'infos' => 'New in 12',
),
array(
  'name' => 'ws_invoke_allowed',
  'type' => 'dispatch',
  'vars' => array('bool', null, 'string', 'methodName', 'array', 'params'),
  'files' => array('src/Piwigo/Ws/PwgServer.php'),
),
array(
  'name' => 'ws_users_getList',
  'type' => 'dispatch',
  'vars' => array('array', 'users'),
  'files' => array('src/Piwigo/Ws/Method/UsersEndpoints.php'),
  'infos' => 'New in 2.6.2.',
),
);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <title>Piwigo Core Triggers</title>

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

  <style>
  html, body, div, span, h1, h2, h3, h4, p, a, table, caption, tbody, tfoot, thead, tr, th, td
  { margin:0; padding:0; border:0; font-size:100%; vertical-align:baseline; }
  body { line-height:1.1; }
  table { border-collapse:collapse; border-spacing:0; }

  html { font-family:"Segoe UI","Helvetica Neue","Verdana",sans-serif; color:#222; font-size:13px; }
  a { color:#247EBF; text-decoration:none; }
  a:hover { color:#EB9C39; border-bottom:1px dotted; }

  h1 { color:#fff; font-size:24px; padding:10px 15px;
    background: linear-gradient(to bottom, #45484d 0%, #333 100%); }

  #the_header { border-bottom:1px solid #cdcdcd; margin-bottom:1px; }
  #the_footer { background:#EAEAEA; border-top:1px solid #cdcdcd; padding:10px; clear:both; }
  #the_page   { padding:20px; }

  tfoot input, tfoot select { width:90%; }
  tfoot .search_input { color:#999; }
  tfoot select.search_input option:not(:first-child) { color:#222; }
  </style>
</head>

<body>

<div id="the_header">
  <h1>Piwigo Core Triggers</h1>
</div>

<div id="the_page">
  <table id="list">
  <thead>
    <tr>
      <th>Name</th>
      <th>Type</th>
      <th>Variables</th>
      <th>Usage in the core</th>
      <th>Commentary</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($core as $trigger): ?>
    <tr>
      <td><?= $trigger['name'] ?></td>
      <td><?= $trigger['type'] ?></td>
      <td><?php
        for ($i = 0; $i < count($trigger['vars']); $i += 2) {
            if ($i > 0) {
                echo ', ';
            }
            echo $trigger['vars'][$i];
            if (!empty($trigger['vars'][$i + 1])) {
                echo ' <i>$' . $trigger['vars'][$i + 1] . '</i>';
            }
        }
      ?></td>
      <td><?php
        $f = true;
      foreach ($trigger['files'] as $file) {
          if (!$f) {
              echo '<br>';
          }
          $f = false;
          echo preg_replace('#\((.+)\)#', '(<i>$1</i>)', htmlspecialchars($file));
      }
      ?></td>
      <td><?= $trigger['infos'] ?? '' ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot>
    <tr>
      <td><input type="text" value="Name" class="search_input"></td>
      <td>
        <select class="search_input">
          <option value="">Type</option>
          <option value="notify">notify</option>
          <option value="dispatch">dispatch</option>
        </select>
      </td>
      <td><input type="text" value="Variables" class="search_input"></td>
      <td><input type="text" value="Usage" class="search_input"></td>
      <td><input type="text" value="Commentary" class="search_input"></td>
    </tr>
  </tfoot>
  </table>
</div>

<div id="the_footer">
  Copyright &copy; 2002-2026 <a href="https://piwigo.org">Piwigo Team</a>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
var oTable = $('#list').dataTable({
  "aaSorting": [[0, 'asc']],
  "sPaginationType": "full_numbers",
  "aLengthMenu": [[10, 30, 50, 70, 90, -1], [10, 30, 50, 70, 90, "All"]],
  "iDisplayLength": 30,
  "oLanguage": { "sSearch": "Search all columns:" }
});

$("tfoot td").each(function(i) {
  $('select', this).on('change', function() { oTable.fnFilter($(this).val(), i); });
  $('input', this).on('keyup', function() { oTable.fnFilter($(this).val(), i); });
});

var asInitVals = [];
$("tfoot input").each(function(i) { asInitVals[i] = $(this).val(); });
$("tfoot input").on('focus', function() {
  if (this.className === "search_input") { $(this).removeClass("search_input").val(""); }
}).on('blur', function() {
  if ($(this).val() === "") {
    $(this).addClass("search_input").val(asInitVals[$("tfoot input").index(this)]);
  }
});
$("tfoot select").on('change', function() {
  $(this).toggleClass("search_input", $(this).val() === "");
});
</script>

</body>
</html>
