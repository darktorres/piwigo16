<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$core = [
    [
        'name' => 'allow_increment_element_hit_count',
        'type' => 'trigger_change',
        'vars' => ['bool', 'content_not_set'],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'batch_manager_perform_filters',
        'type' => 'trigger_change',
        'vars' => ['array', 'filter_sets', 'array', 'bulk_manager_filter'],
        'files' => ['admin\batch_manager.php'],
        'infos' => 'New in 2.7',
    ],
    [
        'name' => 'batch_manager_register_filters',
        'type' => 'trigger_change',
        'vars' => ['array', 'bulk_manager_filter'],
        'files' => ['admin\batch_manager.php'],
        'infos' => 'New in 2.7',
    ],
    [
        'name' => 'batch_manager_url_filter',
        'type' => 'trigger_change',
        'vars' => ['array', 'bulk_manager_filter', 'string', 'filter'],
        'files' => ['admin\batch_manager.php'],
        'infos' => 'New in 2.7.',
    ],
    [
        'name' => 'begin_delete_elements',
        'type' => 'trigger_notify',
        'vars' => ['array', 'ids'],
        'files' => ['admin\include\functions.inc.php (delete_elements)'],
    ],
    [
        'name' => 'blockmanager_apply',
        'type' => 'trigger_notify',
        'vars' => ['object', 'menublock'],
        'files' => ['include\block.class.php (BlockManager::apply)'],
        'infos' => 'use this trigger to modify existing menu blocks',
    ],
    [
        'name' => 'blockmanager_prepare_display',
        'type' => 'trigger_notify',
        'vars' => ['object', 'this'],
        'files' => ['include\block.class.php (BlockManager::prepare_display)'],
    ],
    [
        'name' => 'blockmanager_register_blocks',
        'type' => 'trigger_notify',
        'vars' => ['object', 'menu'],
        'files' => ['include\block.class.php (BlockManager::load_registered_blocks)'],
        'infos' => 'use this trigger to add menu block',
    ],
    [
        'name' => 'clean_iptc_value',
        'type' => 'trigger_change',
        'vars' => ['string', 'value'],
        'files' => ['include\functions_metadata.inc.php (clean_iptc_value)'],
    ],
    [
        'name' => 'combined_css',
        'type' => 'trigger_change',
        'vars' => ['string', 'href', 'Combinable', '$combinable'],
        'files' => ['include\template.class.php (Template::flush)'],
    ],
    [
        'name' => 'combined_css_postfilter',
        'type' => 'trigger_change',
        'vars' => ['string', 'css'],
        'files' => ['include\template.class.php (Template::process_css)'],
    ],
    [
        'name' => 'combined_script',
        'type' => 'trigger_change',
        'vars' => ['string', 'ret', 'string', 'script'],
        'files' => ['include\template.class.php (Template::make_script_src)'],
    ],
    [
        'name' => 'delete_categories',
        'type' => 'trigger_notify',
        'vars' => ['array', 'ids'],
        'files' => ['admin\include\functions.inc.php (delete_categories)'],
    ],
    [
        'name' => 'delete_group',
        'type' => 'trigger_notify',
        'vars' => ['array', 'groupids'],
        'files' => ['admin\group_list.php', 'admin\include\functions.inc.php (delete_group)'],
    ],
    [
        'name' => 'delete_elements',
        'type' => 'trigger_notify',
        'vars' => ['array', 'ids'],
        'files' => ['admin\include\functions.inc.php (delete_elements)'],
    ],
    [
        'name' => 'delete_tags',
        'type' => 'trigger_notify',
        'vars' => ['array', 'tag_ids'],
        'files' => ['admin\include\functions.inc.php (delete_tags)'],
    ],
    [
        'name' => 'merge_tags',
        'type' => 'trigger_notify',
        'vars' => ['array', 'destination_tag_id', 'array', 'merge_tag'],
        'files' => ['admin\include\ws_functions/pwg.tags.php (merge_tags)'],
    ],
    [
        'name' => 'delete_user',
        'type' => 'trigger_notify',
        'vars' => ['int', 'user_id'],
        'files' => ['admin\include\functions.inc.php (delete_user)'],
    ],
    [
        'name' => 'element_set_global_action',
        'type' => 'trigger_notify',
        'vars' => ['string', 'action', 'array', 'collection'],
        'files' => ['admin\batch_manager_global.php'],
    ],
    [
        'name' => 'format_exif_data',
        'type' => 'trigger_change',
        'vars' => ['array', 'exif', 'string', 'filename', 'array', 'map'],
        'files' => ['include\functions_metadata.inc.php (get_exif_data)'],
    ],
    [
        'name' => 'functions_history_included',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\include\functions_history.inc.php'],
    ],
    [
        'name' => 'functions_mail_included',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\functions_mail.inc.php'],
    ],
    [
        'name' => 'get_admin_advanced_features_links',
        'type' => 'trigger_change',
        'vars' => ['array', 'advanced_features'],
        'files' => ['admin\maintenance.php'],
    ],
    [
        'name' => 'get_admin_plugin_menu_links',
        'type' => 'trigger_change',
        'vars' => ['array', null],
        'files' => ['admin.php'],
        'infos' => 'use this trigger to add links into admin plugins menu',
    ],
    [
        'name' => 'get_admins_site_links',
        'type' => 'trigger_change',
        'vars' => ['array', 'plugin_links', 'int', 'site_id', 'bool', 'is_remote'],
        'files' => ['admin\site_manager.php'],
    ],
    [
        'name' => 'get_batch_manager_prefilters',
        'type' => 'trigger_change',
        'vars' => ['array', 'prefilters'],
        'files' => ['admin\batch_manager_global.php'],
        'infos' => 'use this trigger to add prefilters into batch manager global',
    ],
    [
        'name' => 'get_categories_menu_sql_where',
        'type' => 'trigger_change',
        'vars' => ['string', 'where', 'bool', 'user_expand', 'bool', 'filter_enabled'],
        'files' => ['include\functions_category.inc.php (get_categories_menu)'],
    ],
    [
        'name' => 'get_category_preferred_image_orders',
        'type' => 'trigger_change',
        'vars' => ['array', null],
        'files' => ['include\functions_category.inc.php (get_category_preferred_image_orders)'],
    ],
    [
        'name' => 'get_download_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'url', 'array', 'element_info'],
        'files' => ['include\functions_picture.inc.php (get_download_url)'],
    ],
    [
        'name' => 'get_element_metadata_available',
        'type' => 'trigger_change',
        'vars' => ['bool', null, 'string', 'element_path'],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'get_element_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'url', 'array', 'element_info'],
        'files' => ['include\functions_picture.inc.php (get_element_url)'],
    ],
    [
        'name' => 'get_high_location',
        'type' => 'trigger_change',
        'vars' => ['string', 'location', 'array', 'element_info'],
        'files' => ['include\functions_picture.inc.php (get_high_location)'],
    ],
    [
        'name' => 'get_high_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'url', 'array', 'element_info'],
        'files' => ['include\functions_picture.inc.php (get_high_url)'],
    ],
    [
        'name' => 'get_history',
        'type' => 'trigger_change',
        'vars' => ['array', null, 'array', 'page_search', 'array', 'types'],
        'files' => ['admin\history.php'],
    ],
    [
        'name' => 'get_image_location',
        'type' => 'trigger_change',
        'vars' => ['string', 'path', 'array', 'element_info'],
        'files' => ['include\functions_picture.inc.php (get_image_location)'],
    ],
    [
        'name' => 'get_popup_help_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'help_content', 'string', 'page'],
        'files' => ['admin\popuphelp.php', 'popuphelp.php'],
    ],
    [
        'name' => 'get_pwg_themes',
        'type' => 'trigger_change',
        'vars' => ['array', 'themes'],
        'files' => ['include\functions.inc.php (get_pwg_themes)'],
    ],
    [
        'name' => 'get_thumbnail_title',
        'type' => 'trigger_change',
        'vars' => ['string', 'title', 'array', 'info'],
        'files' => ['include\functions.inc.php (get_thumbnail_title)'],
    ],
    [
        'name' => 'get_comments_derivative_params',
        'type' => 'trigger_change',
        'vars' => ['ImageStdParams', null],
        'files' => ['comments.php'],
        'infos' => 'New in 2.4',
    ],
    [
        'name' => 'get_index_album_derivative_params',
        'type' => 'trigger_change',
        'vars' => ['ImageStdParams', null],
        'files' => ['includecategory_cats.php', 'include\category_default.inc.php'],
        'infos' => 'New in 2.4',
    ],
    [
        'name' => 'get_src_image_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'url', 'SrcImage', 'this'],
        'files' => ['include\derivative.inc.php (SrcImage::__construct)'],
        'infos' => 'New in 2.4',
    ],
    [
        'name' => 'get_derivative_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'url', 'ImageStdParams', null, 'SrcImage', 'this', 'string', 'rel_url'],
        'files' => ['include\derivative.inc.php (SrcImage::url, SrcImage::get_url)'],
        'infos' => 'New in 2.4',
    ],
    [
        'name' => 'get_tag_alt_names',
        'type' => 'trigger_change',
        'vars' => ['array', null, 'string', 'raw_name'],
        'files' => ['admin\tags.php', 'admin\include\functions.php (get_taglist)'],
        'infos' => 'New in 2.4',
    ],
    [
        'name' => 'get_tag_name_like_where',
        'type' => 'trigger_change',
        'vars' => ['array', null, 'string', 'tag_name'],
        'files' => ['admin\include\functions.php (tag_id_from_tag_name)'],
        'infos' => 'New in 2.7',
    ],
    [
        'name' => 'get_webmaster_mail_address',
        'type' => 'trigger_change',
        'vars' => ['string', 'email'],
        'files' => ['include\functions.inc.php (get_webmaster_mail_address)'],
        'infos' => 'New in 2.6',
    ],
    [
        'name' => 'init',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\common.inc.php'],
        'infos' => 'this action is called just after the common initialization, $conf, $user and $page (partial) variables are availables',
    ],
    [
        'name' => 'invalidate_user_cache',
        'type' => 'trigger_notify',
        'vars' => ['bool', 'full'],
        'files' => ['admin\include\functions.inc.php (invalidate_user_cache)'],
    ],
    [
        'name' => 'load_conf',
        'type' => 'trigger_notify',
        'vars' => ['string', 'condition'],
        'files' => ['include\functions.inc.php (load_conf_from_db)'],
        'infos' => 'New in 2.6. <b>Warning:</b> you can\'t trigger the first call done une common.inc.php. Use <i>init</i> instead.',
    ],
    [
        'name' => 'list_check_integrity',
        'type' => 'trigger_notify',
        'vars' => ['object', 'this'],
        'files' => ['admin\include\check_integrity.class.php (check_integrity::check)'],
    ],
    [
        'name' => 'load_image_library',
        'type' => 'trigger_notify',
        'vars' => ['object', 'this'],
        'files' => ['admin\include\image.class.php (pwg_image::__construct)'],
    ],
    [
        'name' => 'load_profile_in_template',
        'type' => 'trigger_notify',
        'vars' => ['array', 'userdata'],
        'files' => ['profile.php (load_profile_in_template)'],
    ],
    [
        'name' => 'loading_lang',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\common.inc.php', 'include\functions.inc.php (redirect_html)', 'include\functions_mail.inc.php (switch_lang_to)', 'nbm.php'],
    ],
    [
        'name' => 'loc_after_page_header',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\page_header.php'],
    ],
    [
        'name' => 'loc_begin_about',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['about.php'],
    ],
    [
        'name' => 'loc_begin_admin',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin.php'],
    ],
    [
        'name' => 'loc_begin_admin_page',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin.php'],
    ],
    [
        'name' => 'loc_begin_cat_list',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\cat_list.php'],
    ],
    [
        'name' => 'loc_begin_cat_modify',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\cat_modify.php'],
    ],
    [
        'name' => 'loc_begin_element_set_global',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\batch_manager_global.php'],
    ],
    [
        'name' => 'loc_begin_element_set_unit',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\batch_manager_unit.php'],
    ],
    [
        'name' => 'loc_begin_index',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['index.php'],
    ],
    [
        'name' => 'loc_begin_index_category_thumbnails',
        'type' => 'trigger_notify',
        'vars' => ['array', 'categories'],
        'files' => ['include\category_cats.inc.php'],
    ],
    [
        'name' => 'loc_begin_index_thumbnails',
        'type' => 'trigger_notify',
        'vars' => ['array', 'pictures'],
        'files' => ['include\category_default.inc.php'],
    ],
    [
        'name' => 'loc_begin_page_header',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\page_header.php'],
    ],
    [
        'name' => 'loc_begin_page_tail',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\page_tail.php'],
    ],
    [
        'name' => 'loc_begin_picture',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'loc_begin_profile',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['profile.php'],
    ],
    [
        'name' => 'loc_begin_password',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['password.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_register',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['register.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_search',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['search.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_tags',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['tags.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_comments',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['comments.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_identification',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['identification.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_begin_notification',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['notification.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_add_uploaded_file',
        'type' => 'trigger_notify',
        'vars' => ['array', 'image_infos'],
        'files' => ['admin\include\functions_upload.inc.php (add_uploaded_file)'],
        'infos' => 'New in 2.11',
    ],
    [
        'name' => 'empty_lounge',
        'type' => 'trigger_notify',
        'vars' => ['array', 'rows'],
        'files' => ['admin\include\functions.php (empty_lounge)'],
        'infos' => 'New in 12',
    ],
    [
        'name' => 'ws_images_uploadCompleted',
        'type' => 'trigger_notify',
        'vars' => ['array', 'upload_data'],
        'files' => ['include\ws_functions\pwg.images.php (ws_images_uploadCompleted)'],
        'infos' => 'New in 12',
    ],
    [
        'name' => 'loc_end_password',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['password.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_register',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['register.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_search',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['search.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_tags',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['tags.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_comments',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['comments.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_identification',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['identification.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_notification',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['notification.php'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'loc_end_admin',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin.php'],
    ],
    [
        'name' => 'loc_end_cat_list',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\cat_list.php'],
    ],
    [
        'name' => 'loc_end_cat_modify',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\cat_modify.php'],
    ],
    [
        'name' => 'loc_end_element_set_global',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\batch_manager_global.php'],
    ],
    [
        'name' => 'loc_end_element_set_unit',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\batch_manager_unit.php'],
    ],
    [
        'name' => 'loc_end_help',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\help.php'],
    ],
    [
        'name' => 'loc_end_index',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['index.php'],
    ],
    [
        'name' => 'loc_end_index_category_thumbnails',
        'type' => 'trigger_change',
        'vars' => ['array', 'tpl_thumbnails_var'],
        'files' => ['include\category_cats.inc.php'],
    ],
    [
        'name' => 'loc_end_index_thumbnails',
        'type' => 'trigger_change',
        'vars' => ['array', 'tpl_thumbnails_var', 'array', 'pictures'],
        'files' => ['include\category_default.inc.php'],
    ],
    [
        'name' => 'loc_end_no_photo_yet',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\no_photo_yet.inc.php'],
    ],
    [
        'name' => 'loc_end_page_header',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\page_header.php'],
    ],
    [
        'name' => 'loc_end_page_tail',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\page_tail.php'],
    ],
    [
        'name' => 'loc_end_photo_add_direct',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\photo_add_direct.php'],
    ],
    [
        'name' => 'loc_end_picture',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'loc_end_picture_modify',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\picture_modify.php'],
        'infos' => 'New in 2.6.3',
    ],
    [
        'name' => 'loc_end_profile',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['profile.php'],
    ],
    [
        'name' => 'loc_end_section_init',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\section_init.inc.php'],
        'infos' => 'this action is called after section initilization, $page variable is fully defined',
    ],
    [
        'name' => 'loc_end_themes_installed',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\themes_installed.php'],
        'infos' => 'New in 2.6.3',
    ],
    [
        'name' => 'loc_visible_user_list',
        'type' => 'trigger_change',
        'vars' => ['array', 'visible_user_list'],
        'files' => ['admin\user_list.php'],
    ],
    [
        'name' => 'login_failure',
        'type' => 'trigger_notify',
        'vars' => ['string', 'username'],
        'files' => ['include\functions_user.inc.php (try_log_user)'],
    ],
    [
        'name' => 'login_success',
        'type' => 'trigger_notify',
        'vars' => ['string', 'username'],
        'files' => ['include\functions_user.inc.php (auto_login, try_log_user)'],
    ],
    [
        'name' => 'nbm_event_handler_added',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['admin\notification_by_mail.php'],
    ],
    [
        'name' => 'nbm_render_global_customize_mail_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'customize_mail_content'],
        'files' => ['admin\notification_by_mail.php (do_action_send_mail_notification)'],
    ],
    [
        'name' => 'nbm_render_user_customize_mail_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'customize_mail_content', 'string', 'nbm_user'],
        'files' => ['admin\notification_by_mail.php (do_action_send_mail_notification)'],
    ],
    [
        'name' => 'perform_batch_manager_prefilters',
        'type' => 'trigger_change',
        'vars' => ['array', 'filter_sets', 'string', 'prefilter'],
        'files' => ['admin\batch_manager.php'],
    ],
    [
        'name' => 'picture_pictures_data',
        'type' => 'trigger_change',
        'vars' => ['array', 'picture'],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'plugins_loaded',
        'type' => 'trigger_notify',
        'vars' => [],
        'files' => ['include\functions_plugins.inc.php (load_plugins)'],
    ],
    [
        'name' => 'pwg_log_allowed',
        'type' => 'trigger_change',
        'vars' => ['bool', 'do_log', 'int', 'image_id', 'string', 'image_type'],
        'files' => ['include\functions.inc.php (pwg_log)'],
    ],
    [
        'name' => 'register_user',
        'type' => 'trigger_notify',
        'vars' => ['array', 'user'],
        'files' => ['include\functions_user.inc.php (register_user)'],
    ],
    [
        'name' => 'register_user_check',
        'type' => 'trigger_change',
        'vars' => ['array', 'errors', 'array', 'user'],
        'files' => ['include\functions_user.inc.php (register_user)'],
    ],
    [
        'name' => 'render_category_description',
        'type' => 'trigger_change',
        'vars' => ['string', 'category_description', 'string', 'action'],
        'files' => ['include\category_cats.inc.php', 'include\section_init.inc.php', 'include\ws_functions.inc.php (ws_categories_getList, ws_categories_getAdminList)'],
    ],
    [
        'name' => 'render_category_literal_description',
        'type' => 'trigger_change',
        'vars' => ['string', 'category_description'],
        'files' => ['include\category_cats.inc.php'],
    ],
    [
        'name' => 'render_category_name',
        'type' => 'trigger_change',
        'vars' => ['string', 'category_name', 'string', 'location'],
        'files' => ['admin\cat_list.php', 'include\ws_functions.inc.php (ws_categories_getList, ws_categories_getAdminList, ws_categories_move)'],
    ],
    [
        'name' => 'render_comment_author',
        'type' => 'trigger_change',
        'vars' => ['string', 'comment_author'],
        'files' => ['admin\comments.php', 'comments.php', 'include\picture_comment.inc.php'],
    ],
    [
        'name' => 'render_comment_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'comment_content'],
        'files' => ['admin\comments.php', 'comments.php', 'include\picture_comment.inc.php'],
    ],
    [
        'name' => 'render_element_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'content', 'array', 'current_picture'],
        'files' => ['picture.php'],
    ],
    [
        'name' => 'render_element_name',
        'type' => 'trigger_change',
        'vars' => ['string', 'element_name', 'array', 'info'],
        'files' => ['include\functions_html.inc.php (render_element_name)'],
    ],
    [
        'name' => 'render_element_description',
        'type' => 'trigger_change',
        'vars' => ['string', 'element_description', 'string', 'action'],
        'files' => ['picture.php', 'include\functions_html.inc.php (render_element_description)'],
    ],
    [
        'name' => 'render_lost_password_mail_content',
        'type' => 'trigger_change',
        'vars' => ['string', 'message'],
        'files' => ['password.php (process_password_request)'],
    ],
    [
        'name' => 'render_page_banner',
        'type' => 'trigger_change',
        'vars' => ['string', 'gallery_title'],
        'files' => ['include\page_header.php'],
    ],
    [
        'name' => 'render_tag_name',
        'type' => 'trigger_change',
        'vars' => ['string', 'tag_name', 'array', 'tag'],
        'files' => ['admin\include\functions.php (get_taglist)', 'admin\tags.php', 'admin\history.php', 'include\functions_tag.inc.php (get_available_tags, get_all_tags, get_common_tags)', 'include\functions_html.inc.php (get_tags_content_title)', 'include\functions_search.inc.php (get_qsearch_tags)'],
    ],
    [
        'name' => 'render_tag_url',
        'type' => 'trigger_change',
        'vars' => ['string', 'tag_name'],
        'files' => ['admin\include\functions.php (tag_id_from_tag_name, create_tag)', 'admin\tags.php'],
    ],
    [
        'name' => 'save_profile_from_post',
        'type' => 'trigger_notify',
        'vars' => ['int', 'user_id'],
        'files' => ['profile.php (save_profile_from_post)'],
    ],
    [
        'name' => 'before_send_mail',
        'type' => 'trigger_change',
        'vars' => ['bool', 'result', 'mixed', 'to', 'array', 'arguments', \Symfony\Component\Mime\Email::class, 'mail'],
        'files' => ['include\functions_mail.inc.php (pwg_mail)'],
    ],
    [
        'name' => 'before_parse_mail_template',
        'type' => 'trigger_notify',
        'vars' => ['string', 'cache_key', 'string', 'content_type'],
        'files' => ['include\functions_mail.inc.php (pwg_mail)'],
    ],
    [
        'name' => 'sendResponse',
        'type' => 'trigger_notify',
        'vars' => ['string', 'encodedResponse'],
        'files' => ['include\ws_core.inc.php (PwgServer::sendResponse)'],
    ],
    [
        'name' => 'set_status_header',
        'type' => 'trigger_notify',
        'vars' => ['int', 'code', 'string', 'text'],
        'files' => ['include\functions_html.inc.php (set_status_header)'],
    ],
    [
        'name' => 'tabsheet_before_select',
        'type' => 'trigger_change',
        'vars' => ['array', 'sheets', 'string', 'tabsheet_id'],
        'files' => ['include\tabsheet.class.php (tabsheet::select)'],
        'infos' => 'New in 2.4, use this trigger to add tabs to a tabsheets',
    ],
    [
        'name' => 'trigger',
        'type' => 'trigger_notify',
        'vars' => ['array', null],
        'files' => ['include\functions_plugins.inc.php (trigger_change, trigger_notify)'],
    ],
    [
        'name' => 'user_comment_check',
        'type' => 'trigger_change',
        'vars' => ['string', 'comment_action', 'array', 'comm'],
        'files' => ['include\functions_comment.inc.php (insert_user_comment, update_user_comment)'],
        'infos' => 'use this trigger to add conditions on comment validation',
    ],
    [
        'name' => 'user_comment_deletion',
        'type' => 'trigger_notify',
        'vars' => ['mixed', 'comment_id'],
        'files' => ['include\functions_comment.inc.php (delete_user_comment)'],
        'infos' => '$comment_id is and int or an array of int',
    ],
    [
        'name' => 'user_comment_insertion',
        'type' => 'trigger_notify',
        'vars' => ['array', 'comm'],
        'files' => ['include\picture_comment.inc.php'],
    ],
    [
        'name' => 'user_comment_validation',
        'type' => 'trigger_notify',
        'vars' => ['mixed', 'comment_id'],
        'files' => ['include\functions_comment.inc.php (validate_user_comment)'],
        'infos' => '$comment_id is and int or an array of int',
    ],
    [
        'name' => 'user_init',
        'type' => 'trigger_notify',
        'vars' => ['array', 'user'],
        'files' => ['include\user.inc.php'],
    ],
    [
        'name' => 'ws_add_methods',
        'type' => 'trigger_notify',
        'vars' => ['object', 'this'],
        'files' => ['include\ws_core.inc.php (PwgServer::run)'],
    ],
    [
        'name' => 'ws_invoke_allowed',
        'type' => 'trigger_change',
        'vars' => ['bool', null, 'string', 'methodName', 'array', 'params'],
        'files' => ['include\ws_core.inc.php (PwgServer::invoke)'],
    ],
    [
        'name' => 'user_logout',
        'type' => 'trigger_notify',
        'vars' => ['int', 'user_id'],
        'files' => ['include\functions_user.inc.php (logout_user)'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'user_login',
        'type' => 'trigger_notify',
        'vars' => ['int', 'user_id'],
        'files' => ['include\functions_user.inc.php (log_user)'],
        'infos' => 'New in 2.5',
    ],
    [
        'name' => 'try_log_user',
        'type' => 'trigger_change',
        'vars' => ['boolean', 'success', 'string', 'username', 'string', 'password', 'bool', 'remember_me'],
        'files' => ['include\functions_user.inc.php (try_log_user)'],
        'infos' => 'New in 2.5. Used by identification form to check user credentials and log user. If <i>success</i> is <i>true</i>, another login method already succeed. Return <i>true</i> if your method succeed.',
    ],
    [
        'name' => 'combinable_preparse',
        'type' => 'trigger_notify',
        'vars' => ['Template', 'template', 'Combinable', '$combinable', 'FileCombiner', '$combiner'],
        'files' => ['include\template.class.php (FileCombiner::process_combinable)'],
        'infos' => 'New in 2.6.',
    ],
    [
        'name' => 'update_rating_score',
        'type' => 'trigger_change',
        'vars' => ['boolean', 'done', 'int', 'element_id'],
        'files' => ['include\functions_rate.inc.php'],
        'infos' => 'New in 2.6.',
    ],
    [
        'name' => 'picture_modify_before_update',
        'type' => 'trigger_change',
        'vars' => ['array', 'data'],
        'files' => ['admin\picture_modify.php'],
        'infos' => 'New in 2.6.2.',
    ],
    [
        'name' => 'ws_users_getList',
        'type' => 'trigger_change',
        'vars' => ['array', 'users'],
        'files' => ['include\ws_functions\pwg.users.php'],
        'infos' => 'New in 2.6.2.',
    ],
];
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" dir="ltr">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>Piwigo Core Triggers</title>

  <link rel="stylesheet" type="text/css" href="//code.jquery.com/ui/1.9.2/themes/base/jquery-ui.css">
  <link rel="stylesheet" type="text/css" href="//ajax.aspnetcdn.com/ajax/jquery.dataTables/1.9.4/css/jquery.dataTables_themeroller.css">

  <style type="text/css">
  /* BEGIN CSS RESET
    http://meyerweb.com/eric/tools/css/reset
    v2.0 | 20110126 | License: none (public domain) */
  html, body, div, span, applet, object, iframe, h1, h2, h3, h4, h5, h6, p, blockquote, pre, a, abbr, acronym, address, big, cite, code,
  del, dfn, em, img, ins, kbd, q, s, samp, small, strike, strong, sub, sup, tt, var, b, u, i, center, dl, dt, dd, ol, ul, li,
  fieldset, form, label, legend, table, caption, tbody, tfoot, thead, tr, th, td, article, aside, canvas, details, embed,
  figure, figcaption, footer, header, hgroup, menu, nav, output, ruby, section, summary, time, mark, audio, video
  {margin:0;padding:0;border:0;font-size:100%;vertical-align:baseline;}

  article, aside, details, figcaption, figure, footer, header, hgroup, menu, nav, section {display:block;}
  body {line-height:1.1;}
  blockquote, q {quotes:none;}
  blockquote:before, blockquote:after, q:before, q:after {content:'';content:none;}
  table {border-collapse:collapse;border-spacing:0;}
  /* END CSS RESET */

  html {font-family:"Corbel","Lucida Grande","Verdana",sans-serif;color:#222;font-size:13px;}

  a {color:#247EBF;text-decoration:none;}
  a:hover {color:#EB9C39;border-bottom-width:1px;border-style:dotted;text-shadow:1px 1px 0 #ddd;}

  h1 {color:#fff;font-size:26px;padding:10px 15px;text-shadow:1px 1px 0 #999;
    background:#45484d;background:linear-gradient(to bottom, #45484d 0%,#333333 100%);
  }

  #the_header {border-bottom:1px solid #cdcdcd;margin-bottom:1px;}
  #the_footer {background:#EAEAEA;border-top:1px solid #cdcdcd;padding:10px;clear:both;}

  #the_page {padding:20px;background-image:url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAMAAAADCAIAAADZSiLoAAAAH0lEQVQImSXHMQEAMAwCMOrfK0jIjuVL2gLBzyHJtgd7wBdU3Vt/7AAAAABJRU5ErkJggg==);}

  tfoot input {width:80%;}
  tfoot .search_input {color:#999;}
  tfoot select.search_input option:not(:first-child) {color:#222;}
  </style>
</head>

<body>

<div id="the_header">
  <h1>Piwigo Core Triggers</h1>
</div> <!-- the_header -->

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

  <?php
    foreach ($core as $trigger) {
        echo '
    <tr>
      <td>' . $trigger['name'] . '</td>
      <td>' . $trigger['type'] . '</td>
      <td>';
        for ($i = 0; $i < count($trigger['vars']); $i += 2) {
            if ($i > 0) {
                echo ', ';
            }
            echo $trigger['vars'][$i] . ' ' . (! empty($trigger['vars'][$i + 1]) ? '<i>$' . $trigger['vars'][$i + 1] . '</i>' : null);
        }
        echo '
      </td>
      <td>';
        $f = 1;
        foreach ($trigger['files'] as $file) {
            if (! $f) {
                echo '<br>';
            } $f = 0;
            echo preg_replace('#\((.+)\)#', '(<i>$1</i>)', (string) $file);
        }
        echo '
      </td>
      <td>' . @$trigger['infos'] . '</td>
    </tr>';
    }
?>

  </tbody>
  <tfoot>
    <tr>
      <td><input type="text" value="Name" class="search_input"></td>
      <td>
        <select class="search_input">
          <option value="">Type</option>
          <option value="trigger_notify">trigger_notify</option>
          <option value="trigger_change">trigger_change</option>
        </select>
      </td>
      <td><input type="text" value="Variables" class="search_input"></td>
      <td><input type="text" value="Usage" class="search_input"></td>
      <td><input type="text" value="Commentary" class="search_input"></td>
    </tr>
  </tfoot>
  </table>
</div> <!-- the_page -->

<div id="the_footer">
  Copyright &copy; 2002-2016 <a href="http://piwigo.org">Piwigo Team</a>
</div> <!-- the_footer -->


<script type="text/javascript" src="//code.jquery.com/jquery-1.9.1.min.js"></script>
<script type="text/javascript" src="//ajax.aspnetcdn.com/ajax/jquery.dataTables/1.9.4/jquery.dataTables.min.js"></script>

<script type="text/javascript">
var oTable = $('#list').dataTable({
  "bJQueryUI": true,
  "aaSorting": [ [0,'asc'] ],
  "sPaginationType": "full_numbers",
  "aLengthMenu": [[10, 30, 50, 70, 90, -1], [10, 30, 50, 70, 90, "All"]],
  "iDisplayLength": 30,
  "oLanguage": {
      "sSearch": "Search all columns :"
  }
});

// search input
$("tfoot td").each(function (i) {
  $('select', this).change(function () {
    oTable.fnFilter($(this).val(), i);
  });
  $('input', this).keyup(function () {
    oTable.fnFilter($(this).val(), i);
  });
});

// search helpers
var asInitVals = new Array();
$("tfoot input").each(function (i) {
  asInitVals[i] = $(this).val();
});

$("tfoot input").focus(function () {
  if (this.className == "search_input") {
    $(this).removeClass("search_input");
    $(this).val("");
  }
});

$("tfoot input").blur(function (i) {
  if ($(this).val() == "") {
    $(this).addClass("search_input");
    $(this).val(asInitVals[$("tfoot input").index(this)]);
  }
});

$("tfoot select").change(function () {
  if ($(this).val() == "") {
    $(this).addClass("search_input");
  }
  else {
    $(this).removeClass("search_input");
  }
});
</script>

</body>
</html>
