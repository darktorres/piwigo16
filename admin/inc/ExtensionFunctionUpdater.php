<?php

namespace Piwigo\admin\inc;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Trait containing shared functionality for updating extension files
 * Used by plugins, themes, and languages to handle file renaming and function prefixing
 */
trait ExtensionFunctionUpdater
{
    /**
     * Updates function calls in PHP/TPL files to their namespaced versions
     *
     * @param string $path The directory containing files to process
     */
    protected function update_function_calls(string $path): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $function_map = [
            // Plugin functions
            'add_event_handler' => '\Piwigo\inc\functions_plugins::add_event_handler',
            'autoupdate_plugin' => '\Piwigo\inc\functions_plugins::autoupdate_plugin',
            'get_admin_plugin_menu_link' => '\Piwigo\admin\inc\functions_plugins_admin::get_admin_plugin_menu_link',
            'get_db_plugins' => '\Piwigo\inc\functions_plugins::get_db_plugins',
            'get_event_handlers' => '\Piwigo\inc\functions_plugins::get_event_handlers',
            'get_inactive_plugins' => '\Piwigo\inc\functions_plugins::get_inactive_plugins',
            'get_incompatible_plugins' => '\Piwigo\inc\functions_plugins::get_incompatible_plugins',
            'get_missing_plugins' => '\Piwigo\inc\functions_plugins::get_missing_plugins',
            'get_plugin_data' => '\Piwigo\inc\functions_plugins::get_plugin_data',
            'load_plugin' => '\Piwigo\inc\functions_plugins::load_plugin',
            'load_plugins' => '\Piwigo\inc\functions_plugins::load_plugins',
            'plugin_is_active' => '\Piwigo\inc\functions_plugins::plugin_is_active',
            'plugin_is_installed' => '\Piwigo\inc\functions_plugins::plugin_is_installed',
            'remove_event_handler' => '\Piwigo\inc\functions_plugins::remove_event_handler',
            'set_plugin_data' => '\Piwigo\inc\functions_plugins::set_plugin_data',
            'trigger_change' => '\Piwigo\inc\functions_plugins::trigger_change',
            'trigger_notify' => '\Piwigo\inc\functions_plugins::trigger_notify',

            // Category functions
            'check_restrictions' => '\Piwigo\inc\functions_category::check_restrictions',
            'delete_cat_permalink' => '\Piwigo\admin\inc\functions_permalinks::delete_cat_permalink',
            'display_select_cat_wrapper' => '\Piwigo\inc\functions_category::display_select_cat_wrapper',
            'display_select_categories' => '\Piwigo\inc\functions_category::display_select_categories',
            'get_cat_id_from_old_permalink' => '\Piwigo\admin\inc\functions_permalinks::get_cat_id_from_old_permalink',
            'get_cat_id_from_permalink' => '\Piwigo\admin\inc\functions_permalinks::get_cat_id_from_permalink',
            'get_cat_id_from_permalinks' => '\Piwigo\inc\functions_category::get_cat_id_from_permalinks',
            'get_cat_info' => '\Piwigo\inc\functions_category::get_cat_info',
            'get_categories_menu' => '\Piwigo\inc\functions_category::get_categories_menu',
            'get_category_preferred_image_orders' => '\Piwigo\inc\functions_category::get_category_preferred_image_orders',
            'get_common_categories' => '\Piwigo\inc\functions_category::get_common_categories',
            'get_computed_categories' => '\Piwigo\inc\functions_category::get_computed_categories',
            'get_display_images_count' => '\Piwigo\inc\functions_category::get_display_images_count',
            'get_image_ids_for_categories' => '\Piwigo\inc\functions_category::get_image_ids_for_categories',
            'get_random_image_in_category' => '\Piwigo\inc\functions_category::get_random_image_in_category',
            'get_related_categories_menu' => '\Piwigo\inc\functions_category::get_related_categories_menu',
            'get_subcat_ids' => '\Piwigo\inc\functions_category::get_subcat_ids',
            'global_rank_compare' => '\Piwigo\inc\functions_category::global_rank_compare',
            'rank_compare' => '\Piwigo\inc\functions_category::rank_compare',
            'remove_computed_category' => '\Piwigo\inc\functions_category::remove_computed_category',
            'set_cat_permalink' => '\Piwigo\admin\inc\functions_permalinks::set_cat_permalink',

            // HTML functions
            'access_denied' => '\Piwigo\inc\functions_html::access_denied',
            'bad_request' => '\Piwigo\inc\functions_html::bad_request',
            'fatal_error' => '\Piwigo\inc\functions_html::fatal_error',
            'flush_page_messages' => '\Piwigo\inc\functions_html::flush_page_messages',
            'get_cat_display_name_cache' => '\Piwigo\inc\functions_html::get_cat_display_name_cache',
            'get_cat_display_name_from_id' => '\Piwigo\inc\functions_html::get_cat_display_name_from_id',
            'get_cat_display_name' => '\Piwigo\inc\functions_html::get_cat_display_name',
            'get_combined_categories_content_title' => '\Piwigo\inc\functions_html::get_combined_categories_content_title',
            'get_element_url_protection_handler' => '\Piwigo\inc\functions_html::get_element_url_protection_handler',
            'get_icon' => '\Piwigo\inc\functions_html::get_icon',
            'get_src_image_url_protection_handler' => '\Piwigo\inc\functions_html::get_src_image_url_protection_handler',
            'get_tags_content_title' => '\Piwigo\inc\functions_html::get_tags_content_title',
            'get_themeconf' => '\Piwigo\inc\functions::get_themeconf',
            'get_thumbnail_title' => '\Piwigo\inc\functions_html::get_thumbnail_title',
            'get_ws_root_url' => '\Piwigo\inc\functions_html::get_ws_root_url',
            'name_compare' => '\Piwigo\inc\functions_html::name_compare',
            'page_forbidden' => '\Piwigo\inc\functions_html::page_forbidden',
            'page_not_found' => '\Piwigo\inc\functions_html::page_not_found',
            'register_default_menubar_blocks' => '\Piwigo\inc\functions_html::register_default_menubar_blocks',
            'render_category_literal_description' => '\Piwigo\inc\functions_html::render_category_literal_description',
            'render_comment_content' => '\Piwigo\inc\functions_html::render_comment_content',
            'render_element_description' => '\Piwigo\inc\functions_html::render_element_description',
            'render_element_name' => '\Piwigo\inc\functions_html::render_element_name',
            'set_status_header' => '\Piwigo\inc\functions_html::set_status_header',
            'tag_alpha_compare' => '\Piwigo\inc\functions_html::tag_alpha_compare',
            'ws_invoke_allowed' => '\Piwigo\inc\functions_html::ws_invoke_allowed',
            'ws_invoke' => '\Piwigo\inc\functions_html::ws_invoke',

            // User functions
            'auto_login' => '\Piwigo\inc\functions_user::auto_login',
            'build_user' => '\Piwigo\inc\functions_user::build_user',
            'calculate_auto_login_key' => '\Piwigo\inc\functions_user::calculate_auto_login_key',
            'calculate_permissions' => '\Piwigo\inc\functions_user::calculate_permissions',
            'check_status' => '\Piwigo\inc\functions_user::check_status',
            'check_user_favorites' => '\Piwigo\inc\functions_user::check_user_favorites',
            'create_user_infos' => '\Piwigo\inc\functions_user::create_user_infos',
            'delete_user_cache' => '\Piwigo\inc\functions_user::delete_user_cache',
            'get_browser_language' => '\Piwigo\inc\functions_user::get_browser_language',
            'get_default_language' => '\Piwigo\inc\functions_user::get_default_language',
            'get_default_theme' => '\Piwigo\inc\functions_user::get_default_theme',
            'get_default_user_info' => '\Piwigo\inc\functions_user::get_default_user_info',
            'get_default_user_value' => '\Piwigo\inc\functions_user::get_default_user_value',
            'get_sql_condition_FandF' => '\Piwigo\inc\functions_user::get_sql_condition_FandF',
            'get_user_cache' => '\Piwigo\inc\functions_user::get_user_cache',
            'get_user_info' => '\Piwigo\inc\functions_user::get_user_info',
            'get_user_language' => '\Piwigo\inc\functions_user::get_user_language',
            'get_user_preferences' => '\Piwigo\inc\functions_user::get_user_preferences',
            'get_user_status' => '\Piwigo\inc\functions_user::get_user_status',
            'get_user_theme' => '\Piwigo\inc\functions_user::get_user_theme',
            'get_userid_by_email' => '\Piwigo\inc\functions_user::get_userid_by_email',
            'get_userid' => '\Piwigo\inc\functions_user::get_userid',
            'getuserdata' => '\Piwigo\inc\functions_user::getuserdata',
            'is_a_guest' => '\Piwigo\inc\functions_user::is_a_guest',
            'is_admin' => '\Piwigo\inc\functions_user::is_admin',
            'is_authorized_status' => '\Piwigo\inc\functions_user::is_authorized_status',
            'is_webmaster' => '\Piwigo\inc\functions_user::is_webmaster',
            'log_user' => '\Piwigo\inc\functions_user::log_user',
            'logout_user' => '\Piwigo\inc\functions_user::logout_user',
            'pwg_login' => '\Piwigo\inc\functions_user::pwg_login',
            'search_case_username' => '\Piwigo\inc\functions_user::search_case_username',
            'set_user_cache' => '\Piwigo\inc\functions_user::set_user_cache',
            'set_user_preferences' => '\Piwigo\inc\functions_user::set_user_preferences',
            'try_log_user' => '\Piwigo\inc\functions_user::try_log_user',
            'validate_enabled_high' => '\Piwigo\inc\functions_user::validate_enabled_high',
            'validate_expand' => '\Piwigo\inc\functions_user::validate_expand',
            'validate_generic' => '\Piwigo\inc\functions_user::validate_generic',
            'validate_language' => '\Piwigo\inc\functions_user::validate_language',
            'validate_level' => '\Piwigo\inc\functions_user::validate_level',
            'validate_login_case' => '\Piwigo\inc\functions_user::validate_login_case',
            'validate_login' => '\Piwigo\inc\functions_user::validate_login',
            'validate_mail_address' => '\Piwigo\inc\functions_user::validate_mail_address',
            'validate_mail_notification' => '\Piwigo\inc\functions_user::validate_mail_notification',
            'validate_password' => '\Piwigo\inc\functions_user::validate_password',
            'validate_privacy_level' => '\Piwigo\inc\functions_user::validate_privacy_level',
            'validate_recent_period' => '\Piwigo\inc\functions_user::validate_recent_period',
            'validate_show_nb_comments' => '\Piwigo\inc\functions_user::validate_show_nb_comments',
            'validate_show_nb_hits' => '\Piwigo\inc\functions_user::validate_show_nb_hits',
            'validate_status' => '\Piwigo\inc\functions_user::validate_status',
            'validate_theme' => '\Piwigo\inc\functions_user::validate_theme',
            'validate_websize' => '\Piwigo\inc\functions_user::validate_websize',

            // URL functions
            'add_url_params' => '\Piwigo\inc\functions_url::add_url_params',
            'duplicate_index_url' => '\Piwigo\inc\functions_url::duplicate_index_url',
            'embellish_url' => '\Piwigo\inc\functions_url::embellish_url',
            'get_absolute_root_url' => '\Piwigo\inc\functions_url::get_absolute_root_url',
            'get_action_url' => '\Piwigo\inc\functions_url::get_action_url',
            'get_element_url' => '\Piwigo\inc\functions_url::get_element_url',
            'get_extended_desc' => '\Piwigo\inc\functions_url::get_extended_desc',
            'get_gallery_home_url' => '\Piwigo\inc\functions_url::get_gallery_home_url',
            'get_query_string_diff' => '\Piwigo\inc\functions_url::get_query_string_diff',
            'get_root_url' => '\Piwigo\inc\functions_url::get_root_url',
            'make_index_url' => '\Piwigo\inc\functions_url::make_index_url',
            'make_picture_data_url' => '\Piwigo\inc\functions_url::make_picture_data_url',
            'make_picture_url' => '\Piwigo\inc\functions_url::make_picture_url',
            'parse_section_url' => '\Piwigo\inc\functions_url::parse_section_url',
            'parse_well_known_params_url' => '\Piwigo\inc\functions_url::parse_well_known_params_url',
            'set_make_full_url' => '\Piwigo\inc\functions_url::set_make_full_url',
            'unset_make_full_url' => '\Piwigo\inc\functions_url::unset_make_full_url',
            'url_is_remote' => '\Piwigo\inc\functions_url::url_is_remote',

            // Session functions
            'delete_session_data' => '\Piwigo\inc\functions_session::delete_session_data',
            'delete_user_sessions' => '\Piwigo\inc\functions_session::delete_user_sessions',
            'generate_key' => '\Piwigo\inc\functions_session::generate_key',
            'get_remote_addr_session_hash' => '\Piwigo\inc\functions_session::get_remote_addr_session_hash',
            'get_session_data' => '\Piwigo\inc\functions_session::get_session_data',
            'get_session_status' => '\Piwigo\inc\functions_session::get_session_status',
            'pwg_get_session_var' => '\Piwigo\inc\functions_session::pwg_get_session_var',
            'pwg_session_close' => '\Piwigo\inc\functions_session::pwg_session_close',
            'pwg_session_destroy' => '\Piwigo\inc\functions_session::pwg_session_destroy',
            'pwg_session_gc' => '\Piwigo\inc\functions_session::pwg_session_gc',
            'pwg_session_open' => '\Piwigo\inc\functions_session::pwg_session_open',
            'pwg_session_read' => '\Piwigo\inc\functions_session::pwg_session_read',
            'pwg_session_write' => '\Piwigo\inc\functions_session::pwg_session_write',
            'pwg_set_session_var' => '\Piwigo\inc\functions_session::pwg_set_session_var',
            'pwg_unset_session_var' => '\Piwigo\inc\functions_session::pwg_unset_session_var',
            'set_session_data' => '\Piwigo\inc\functions_session::set_session_data',
            'set_session_status' => '\Piwigo\inc\functions_session::set_session_status',

            // Tag functions
            'add_level_to_tags' => '\Piwigo\inc\functions_tag::add_level_to_tags',
            'add_tag_selection' => '\Piwigo\inc\functions_tag::add_tag_selection',
            'add_tag' => '\Piwigo\inc\functions_tag::add_tag',
            'clear_tag_selection' => '\Piwigo\inc\functions_tag::clear_tag_selection',
            'delete_tag_selection' => '\Piwigo\inc\functions_tag::delete_tag_selection',
            'delete_tag' => '\Piwigo\inc\functions_tag::delete_tag',
            'find_tags' => '\Piwigo\inc\functions_tag::find_tags',
            'get_all_tags' => '\Piwigo\inc\functions_tag::get_all_tags',
            'get_available_tags' => '\Piwigo\inc\functions_tag::get_available_tags',
            'get_common_tags' => '\Piwigo\inc\functions_tag::get_common_tags',
            'get_image_ids_for_tags' => '\Piwigo\inc\functions_tag::get_image_ids_for_tags',
            'get_nb_available_tags' => '\Piwigo\inc\functions_tag::get_nb_available_tags',
            'get_related_tags' => '\Piwigo\inc\functions_tag::get_related_tags',
            'get_tag_children' => '\Piwigo\inc\functions_tag::get_tag_children',
            'get_tag_cloud' => '\Piwigo\inc\functions_tag::get_tag_cloud',
            'get_tag_counter' => '\Piwigo\inc\functions_tag::get_tag_counter',
            'get_tag_id' => '\Piwigo\inc\functions_tag::get_tag_id',
            'get_tag_ids_for_images' => '\Piwigo\inc\functions_tag::get_tag_ids_for_images',
            'get_tag_ids' => '\Piwigo\inc\functions_tag::get_tag_ids',
            'get_tag_info' => '\Piwigo\inc\functions_tag::get_tag_info',
            'get_tag_name' => '\Piwigo\inc\functions_tag::get_tag_name',
            'get_tag_selection' => '\Piwigo\inc\functions_tag::get_tag_selection',
            'get_tags' => '\Piwigo\inc\functions_tag::get_tags',
            'merge_tags' => '\Piwigo\inc\functions_tag::merge_tags',
            'remove_tag_selection' => '\Piwigo\inc\functions_tag::remove_tag_selection',
            'set_tag_name' => '\Piwigo\inc\functions_tag::set_tag_name',
            'tag_exists' => '\Piwigo\inc\functions_tag::tag_exists',
            'tag_image' => '\Piwigo\inc\functions_tag::tag_image',
            'tag_images' => '\Piwigo\inc\functions_tag::tag_images',
            'tag_selection' => '\Piwigo\inc\functions_tag::tag_selection',
            'tags_counter_compare' => '\Piwigo\inc\functions_tag::tags_counter_compare',
            'tags_id_compare' => '\Piwigo\inc\functions_tag::tags_id_compare',
            'untag_image' => '\Piwigo\inc\functions_tag::untag_image',
            'untag_images' => '\Piwigo\inc\functions_tag::untag_images',

            // Search functions
            'get_available_search_uuid' => '\Piwigo\inc\functions_search::get_available_search_uuid',
            'get_quick_search_results_no_cache' => '\Piwigo\inc\functions_search::get_quick_search_results_no_cache',
            'get_quick_search_results' => '\Piwigo\inc\functions_search::get_quick_search_results',
            'get_regular_search_results' => '\Piwigo\inc\functions_search::get_regular_search_results',
            'get_search_array' => '\Piwigo\inc\functions_search::get_search_array',
            'get_search_id_pattern' => '\Piwigo\inc\functions_search::get_search_id_pattern',
            'get_search_info' => '\Piwigo\inc\functions_search::get_search_info',
            'get_search_results_count' => '\Piwigo\inc\functions_search::get_search_results_count',
            'get_search_results_page' => '\Piwigo\inc\functions_search::get_search_results_page',
            'get_search_results' => '\Piwigo\inc\functions_search::get_search_results',
            'get_sql_search_clause' => '\Piwigo\inc\functions_search::get_sql_search_clause',
            'qsearch_eval' => '\Piwigo\inc\functions_search::qsearch_eval',
            'qsearch_get_categories' => '\Piwigo\inc\functions_search::qsearch_get_categories',
            'qsearch_get_images' => '\Piwigo\inc\functions_search::qsearch_get_images',
            'qsearch_get_tags' => '\Piwigo\inc\functions_search::qsearch_get_tags',
            'qsearch_get_text_token_search_sql' => '\Piwigo\inc\functions_search::qsearch_get_text_token_search_sql',
            'save_search' => '\Piwigo\inc\functions_search::save_search',
            'split_allwords' => '\Piwigo\inc\functions_search::split_allwords',

            // Picture functions
            'correct_slideshow_params' => '\Piwigo\inc\functions_picture::correct_slideshow_params',
            'decode_slideshow_params' => '\Piwigo\inc\functions_picture::decode_slideshow_params',
            'encode_slideshow_params' => '\Piwigo\inc\functions_picture::encode_slideshow_params',
            'get_default_slideshow_params' => '\Piwigo\inc\functions_picture::get_default_slideshow_params',
            'get_picture_info' => '\Piwigo\inc\functions_picture::get_picture_info',
            'get_picture_url_protection_handler_thumb' => '\Piwigo\inc\functions_picture::get_picture_url_protection_handler_thumb',
            'get_picture_url_protection_handler' => '\Piwigo\inc\functions_picture::get_picture_url_protection_handler',
            'get_picture_url' => '\Piwigo\inc\functions_picture::get_picture_url',
            'increase_image_visit_counter' => '\Piwigo\inc\functions_picture::increase_image_visit_counter',

            // Rate functions
            'get_picture_rating' => '\Piwigo\inc\functions_rate::get_picture_rating',
            'get_rate_group' => '\Piwigo\inc\functions_rate::get_rate_group',
            'get_rating_score' => '\Piwigo\inc\functions_rate::get_rating_score',
            'get_user_picture_rates' => '\Piwigo\inc\functions_rate::get_user_picture_rates',
            'get_user_rating' => '\Piwigo\inc\functions_rate::get_user_rating',
            'is_rate_group' => '\Piwigo\inc\functions_rate::is_rate_group',
            'rate_picture' => '\Piwigo\inc\functions_rate::rate_picture',
            'update_rating_score' => '\Piwigo\inc\functions_rate::update_rating_score',

            // Comment functions
            'add_comment' => '\Piwigo\inc\functions_comment::add_comment',
            'delete_comment' => '\Piwigo\inc\functions_comment::delete_comment',
            'email_admin' => '\Piwigo\inc\functions_comment::email_admin',
            'get_comment_author_id' => '\Piwigo\inc\functions_comment::get_comment_author_id',
            'get_comments' => '\Piwigo\inc\functions_comment::get_comments',
            'insert_user_comment' => '\Piwigo\inc\functions_comment::insert_user_comment',
            'invalidate_user_cache_nb_comments' => '\Piwigo\inc\functions_comment::invalidate_user_cache_nb_comments',
            'update_user_comment' => '\Piwigo\inc\functions_comment::update_user_comment',
            'user_comment_check' => '\Piwigo\inc\functions_comment::user_comment_check',
            'validate_comment' => '\Piwigo\inc\functions_comment::validate_comment',
            'validate_user_comment' => '\Piwigo\inc\functions_comment::validate_user_comment',

            // Cookie functions
            'cookie_path' => '\Piwigo\inc\functions_cookie::cookie_path',
            'delete_cookie' => '\Piwigo\inc\functions_cookie::delete_cookie',
            'get_cookie' => '\Piwigo\inc\functions_cookie::get_cookie',
            'pwg_get_cookie_var' => '\Piwigo\inc\functions_cookie::pwg_get_cookie_var',
            'pwg_set_cookie_var' => '\Piwigo\inc\functions_cookie::pwg_set_cookie_var',
            'pwg_unset_cookie_var' => '\Piwigo\inc\functions_cookie::pwg_unset_cookie_var',
            'set_cookie' => '\Piwigo\inc\functions_cookie::set_cookie',

            // Mail functions
            'assign_vars_nbm_mail_content' => '\Piwigo\inc\functions_notification_by_mail::assign_vars_nbm_mail_content',
            'begin_users_env_nbm' => '\Piwigo\inc\functions_notification_by_mail::begin_users_env_nbm',
            'check_sendmail_timeout' => '\Piwigo\inc\functions_notification_by_mail::check_sendmail_timeout',
            'display_counter_info' => '\Piwigo\inc\functions_notification_by_mail::display_counter_info',
            'do_subscribe_unsubscribe_notification_by_mail' => '\Piwigo\inc\functions_notification_by_mail::do_subscribe_unsubscribe_notification_by_mail',
            'end_users_env_nbm' => '\Piwigo\inc\functions_notification_by_mail::end_users_env_nbm',
            'find_available_check_key' => '\Piwigo\inc\functions_notification_by_mail::find_available_check_key',
            'format_email' => '\Piwigo\inc\functions_mail::format_email',
            'get_clean_recipients_list' => '\Piwigo\inc\functions_mail::get_clean_recipients_list',
            'get_mail_configuration' => '\Piwigo\inc\functions_mail::get_mail_configuration',
            'get_mail_sender_email' => '\Piwigo\inc\functions_mail::get_mail_sender_email',
            'get_mail_sender_name' => '\Piwigo\inc\functions_mail::get_mail_sender_name',
            'get_mail_template' => '\Piwigo\inc\functions_mail::get_mail_template',
            'get_str_email_format' => '\Piwigo\inc\functions_mail::get_str_email_format',
            'get_strict_email_list' => '\Piwigo\inc\functions_mail::get_strict_email_list',
            'get_user_notifications' => '\Piwigo\inc\functions_notification_by_mail::get_user_notifications',
            'inc_mail_sent_failed' => '\Piwigo\inc\functions_notification_by_mail::inc_mail_sent_failed',
            'inc_mail_sent_success' => '\Piwigo\inc\functions_notification_by_mail::inc_mail_sent_success',
            'move_css_to_body' => '\Piwigo\inc\functions_mail::move_css_to_body',
            'pwg_mail_admins' => '\Piwigo\inc\functions_mail::pwg_mail_admins',
            'pwg_mail_group' => '\Piwigo\inc\functions_mail::pwg_mail_group',
            'pwg_mail_notification_admins' => '\Piwigo\inc\functions_mail::pwg_mail_notification_admins',
            'pwg_mail' => '\Piwigo\inc\functions_mail::pwg_mail',
            'pwg_send_mail_test' => '\Piwigo\inc\functions_mail::pwg_send_mail_test',
            'pwg_send_mail' => '\Piwigo\inc\functions_mail::pwg_send_mail',
            'quote_check_key_list' => '\Piwigo\inc\functions_notification_by_mail::quote_check_key_list',
            'send_mail_to_admin' => '\Piwigo\inc\functions_mail::send_mail_to_admin',
            'send_mail_to_user' => '\Piwigo\inc\functions_mail::send_mail_to_user',
            'send_mail' => '\Piwigo\inc\functions_mail::send_mail',
            'set_user_on_env_nbm' => '\Piwigo\inc\functions_notification_by_mail::set_user_on_env_nbm',
            'subscribe_notification_by_mail' => '\Piwigo\inc\functions_notification_by_mail::subscribe_notification_by_mail',
            'switch_lang_back' => '\Piwigo\inc\functions_mail::switch_lang_back',
            'switch_lang_to' => '\Piwigo\inc\functions_mail::switch_lang_to',
            'unformat_email' => '\Piwigo\inc\functions_mail::unformat_email',
            'unset_user_on_env_nbm' => '\Piwigo\inc\functions_notification_by_mail::unset_user_on_env_nbm',
            'unsubscribe_notification_by_mail' => '\Piwigo\inc\functions_notification_by_mail::unsubscribe_notification_by_mail',

            // Calendar functions
            'get_calendar_month' => '\Piwigo\inc\functions_calendar::get_calendar_month',
            'get_calendar_week' => '\Piwigo\inc\functions_calendar::get_calendar_week',
            'get_calendar' => '\Piwigo\inc\functions_calendar::get_calendar',
            'initialize_calendar' => '\Piwigo\inc\functions_calendar::initialize_calendar',

            // Metadata functions
            'clean_iptc_value' => '\Piwigo\inc\functions_metadata::clean_iptc_value',
            'delete_metadata' => '\Piwigo\inc\functions_metadata::delete_metadata',
            'get_exif_data' => '\Piwigo\inc\functions_metadata::get_exif_data',
            'get_filelist' => '\Piwigo\admin\inc\functions_metadata_admin::get_filelist',
            'get_iptc_data' => '\Piwigo\inc\functions_metadata::get_iptc_data',
            'get_metadata' => '\Piwigo\inc\functions_metadata::get_metadata',
            'get_sync_exif_data' => '\Piwigo\admin\inc\functions_metadata_admin::get_sync_exif_data',
            'get_sync_iptc_data' => '\Piwigo\admin\inc\functions_metadata_admin::get_sync_iptc_data',
            'get_sync_metadata_attributes' => '\Piwigo\admin\inc\functions_metadata_admin::get_sync_metadata_attributes',
            'get_sync_metadata' => '\Piwigo\admin\inc\functions_metadata_admin::get_sync_metadata',
            'metadata_normalize_keywords_string' => '\Piwigo\admin\inc\functions_metadata_admin::metadata_normalize_keywords_string',
            'parse_exif_gps_data' => '\Piwigo\inc\functions_metadata::parse_exif_gps_data',
            'set_metadata' => '\Piwigo\inc\functions_metadata::set_metadata',
            'strip_html_in_metadata' => '\Piwigo\inc\functions_metadata::strip_html_in_metadata',
            'sync_metadata' => '\Piwigo\admin\inc\functions_metadata_admin::sync_metadata',

            // Notification functions
            'add_news_line' => '\Piwigo\inc\functions_notification::add_news_line',
            'custom_notification_query' => '\Piwigo\inc\functions_notification::custom_notification_query',
            'get_html_description_recent_post_date' => '\Piwigo\inc\functions_notification::get_html_description_recent_post_date',
            'get_notifications' => '\Piwigo\inc\functions_notification::get_notifications',
            'get_recent_post_dates_array' => '\Piwigo\inc\functions_notification::get_recent_post_dates_array',
            'get_recent_post_dates' => '\Piwigo\inc\functions_notification::get_recent_post_dates',
            'get_std_sql_where_restrict_filter' => '\Piwigo\inc\functions_notification::get_std_sql_where_restrict_filter',
            'get_title_recent_post_date' => '\Piwigo\inc\functions_notification::get_title_recent_post_date',
            'mark_notification_read' => '\Piwigo\inc\functions_notification::mark_notification_read',
            'nb_new_comments' => '\Piwigo\inc\functions_notification::nb_new_comments',
            'nb_new_elements' => '\Piwigo\inc\functions_notification::nb_new_elements',
            'nb_new_users' => '\Piwigo\inc\functions_notification::nb_new_users',
            'nb_unvalidated_comments' => '\Piwigo\inc\functions_notification::nb_unvalidated_comments',
            'nb_updated_categories' => '\Piwigo\inc\functions_notification::nb_updated_categories',
            'new_comments' => '\Piwigo\inc\functions_notification::new_comments',
            'new_elements' => '\Piwigo\inc\functions_notification::new_elements',
            'new_users' => '\Piwigo\inc\functions_notification::new_users',
            'news_exists' => '\Piwigo\inc\functions_notification::news_exists',
            'news' => '\Piwigo\inc\functions_notification::news',
            'send_notification' => '\Piwigo\inc\functions_notification::send_notification',
            'updated_categories' => '\Piwigo\inc\functions_notification::updated_categories',

            // Filter functions
            'delete_filter' => '\Piwigo\inc\functions_filter::delete_filter',
            'get_filter' => '\Piwigo\inc\functions_filter::get_filter',
            'set_filter' => '\Piwigo\inc\functions_filter::set_filter',
            'update_cats_with_filtered_data' => '\Piwigo\inc\functions_filter::update_cats_with_filtered_data',

            // General functions
            'mobile_theme' => '\Piwigo\inc\functions::mobile_theme',
            'array_from_query' => '\Piwigo\inc\functions::array_from_query',
            'check_theme_installed' => '\Piwigo\inc\functions::check_theme_installed',
            'conf_delete_param' => '\Piwigo\inc\functions::conf_delete_param',
            'conf_get_param' => '\Piwigo\inc\functions::conf_get_param',
            'conf_update_param' => '\Piwigo\inc\functions::conf_update_param',
            'dateDiff' => '\Piwigo\inc\functions::dateDiff',
            'do_log' => '\Piwigo\inc\functions::do_log',
            'fill_caddie' => '\Piwigo\inc\functions::fill_caddie',
            'format_date' => '\Piwigo\inc\functions::format_date',
            'format_fromto' => '\Piwigo\inc\functions::format_fromto',
            'get_elapsed_time' => '\Piwigo\inc\functions::get_elapsed_time',
            'get_element_path' => '\Piwigo\inc\functions::get_element_path',
            'get_extension' => '\Piwigo\inc\functions::get_extension',
            'get_filename_wo_extension' => '\Piwigo\inc\functions::get_filename_wo_extension',
            'get_filter_page_value' => '\Piwigo\inc\functions::get_filter_page_value',
            'get_l10n_args' => '\Piwigo\inc\functions::get_l10n_args',
            'get_languages' => '\Piwigo\inc\functions::get_languages',
            'get_moment' => '\Piwigo\inc\functions::get_moment',
            'get_name_from_file' => '\Piwigo\inc\functions::get_name_from_file',
            'get_parent_language' => '\Piwigo\inc\functions::get_parent_language',
            'get_pwg_themes' => '\Piwigo\inc\functions::get_pwg_themes',
            'get_webmaster_mail_address' => '\Piwigo\inc\functions::get_webmaster_mail_address',
            'hash_from_query' => '\Piwigo\inc\functions::hash_from_query',
            'l10n_args' => '\Piwigo\inc\functions::l10n_args',
            'l10n_dec' => '\Piwigo\inc\functions::l10n_dec',
            'l10n' => '\Piwigo\inc\functions::l10n',
            'load_conf_from_db' => '\Piwigo\inc\functions::load_conf_from_db',
            'load_language' => '\Piwigo\inc\functions::load_language',
            'micro_seconds' => '\Piwigo\inc\functions::micro_seconds',
            'mkgetdir' => '\Piwigo\inc\functions::mkgetdir',
            'original_to_format' => '\Piwigo\inc\functions::original_to_format',
            'original_to_representative' => '\Piwigo\inc\functions::original_to_representative',
            'prepend_append_array_items' => '\Piwigo\inc\functions::prepend_append_array_items',
            'pwg_activity' => '\Piwigo\inc\functions::pwg_activity',
            'pwg_debug' => '\Piwigo\inc\functions::pwg_debug',
            'pwg_is_dbconf_writeable' => '\Piwigo\inc\functions::pwg_is_dbconf_writeable',
            'pwg_log' => '\Piwigo\inc\functions::pwg_log',
            'pwg_transliterate' => '\Piwigo\inc\functions::pwg_transliterate',
            'qualify_utf8' => '\Piwigo\inc\functions::qualify_utf8',
            'redirect_html' => '\Piwigo\inc\functions::redirect_html',
            'redirect_http' => '\Piwigo\inc\functions::redirect_http',
            'redirect' => '\Piwigo\inc\functions::redirect',
            'remove_accents' => '\Piwigo\inc\functions::remove_accents',
            'safe_json_decode' => '\Piwigo\inc\functions::safe_json_decode',
            'safe_unserialize' => '\Piwigo\inc\functions::safe_unserialize',
            'script_basename' => '\Piwigo\inc\functions::script_basename',
            'simple_hash_from_query' => '\Piwigo\inc\functions::simple_hash_from_query',
            'str2DateTime' => '\Piwigo\inc\functions::str2DateTime',
            'str2url' => '\Piwigo\inc\functions::str2url',
            'time_since' => '\Piwigo\inc\functions::time_since',
            'transform_date' => '\Piwigo\inc\functions::transform_date',

            // Database functions
            'boolean_to_string' => '\Piwigo\inc\dblayer\$conf->sql_backend::boolean_to_string',
            'do_maintenance_all_tables' => '\Piwigo\inc\dblayer\$conf->sql_backend::do_maintenance_all_tables',
            'get_boolean' => '\Piwigo\inc\dblayer\$conf->sql_backend::get_boolean',
            'get_enums' => '\Piwigo\inc\dblayer\$conf->sql_backend::get_enums',
            'mass_inserts' => '\Piwigo\inc\dblayer\$conf->sql_backend::mass_inserts',
            'mass_updates' => '\Piwigo\inc\dblayer\$conf->sql_backend::mass_updates',
            'my_error' => '\Piwigo\inc\dblayer\$conf->sql_backend::my_error',
            'pwg_db_cast_to_text' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_cast_to_text',
            'pwg_db_changes' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_changes',
            'pwg_db_check_version' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_check_version',
            'pwg_db_close' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_close',
            'pwg_db_concat_ws' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_concat_ws',
            'pwg_db_concat' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_concat',
            'pwg_db_connect' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_connect',
            'pwg_db_date_to_ts' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_date_to_ts',
            'pwg_db_errno' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_errno',
            'pwg_db_error' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_error',
            'pwg_db_fetch_assoc' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_fetch_assoc',
            'pwg_db_fetch_object' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_fetch_object',
            'pwg_db_fetch_row' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_fetch_row',
            'pwg_db_free_result' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_free_result',
            'pwg_db_get_date_MMDD' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_date_MMDD',
            'pwg_db_get_date_YYYYMM' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_date_YYYYMM',
            'pwg_db_get_dayofmonth' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_dayofmonth',
            'pwg_db_get_dayofweek' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_dayofweek',
            'pwg_db_get_flood_period_expression' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_flood_period_expression',
            'pwg_db_get_hour' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_hour',
            'pwg_db_get_month' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_month',
            'pwg_db_get_recent_period_expression' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_recent_period_expression',
            'pwg_db_get_recent_period' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_recent_period',
            'pwg_db_get_week' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_week',
            'pwg_db_get_weekday' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_weekday',
            'pwg_db_get_year' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_get_year',
            'pwg_db_insert_id' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_insert_id',
            'pwg_db_nextval' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_nextval',
            'pwg_db_num_rows' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_num_rows',
            'pwg_db_real_escape_string' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_db_real_escape_string',
            'pwg_get_db_version' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_get_db_version',
            'pwg_query' => '\Piwigo\inc\dblayer\$conf->sql_backend::pwg_query',
            'query2array' => '\Piwigo\inc\dblayer\$conf->sql_backend::query2array',
            'single_insert' => '\Piwigo\inc\dblayer\$conf->sql_backend::single_insert',
            'single_update' => '\Piwigo\inc\dblayer\$conf->sql_backend::single_update',

            // Admin functions
            'add_md5sum' => '\Piwigo\admin\inc\functions_admin::add_md5sum',
            'add_tags' => '\Piwigo\admin\inc\functions_admin::add_tags',
            'associate_images_to_categories' => '\Piwigo\admin\inc\functions_admin::associate_images_to_categories',
            'assocToOrderedTree' => '\Piwigo\admin\inc\functions_admin::assocToOrderedTree',
            'avg_compare' => '\Piwigo\admin\inc\functions_admin::avg_compare',
            'categories_integrity' => '\Piwigo\admin\inc\functions_admin::categories_integrity',
            'check_upgrade' => '\Piwigo\admin\inc\functions_admin::check_upgrade',
            'compare_image_tag_lists' => '\Piwigo\admin\inc\functions_admin::compare_image_tag_lists',
            'consensus_dev_compare' => '\Piwigo\admin\inc\functions_admin::consensus_dev_compare',
            'count_compare' => '\Piwigo\admin\inc\functions_admin::count_compare',
            'count_orphans' => '\Piwigo\admin\inc\functions_admin::count_orphans',
            'create_virtual_category' => '\Piwigo\admin\inc\functions_admin::create_virtual_category',
            'cv_compare' => '\Piwigo\admin\inc\functions_admin::cv_compare',
            'deactivate_non_standard_plugins' => '\Piwigo\admin\inc\functions_admin::deactivate_non_standard_plugins',
            'delete_categories' => '\Piwigo\admin\inc\functions_admin::delete_categories',
            'delete_element_files' => '\Piwigo\admin\inc\functions_admin::delete_element_files',
            'delete_elements' => '\Piwigo\admin\inc\functions_admin::delete_elements',
            'delete_orphan_tags' => '\Piwigo\admin\inc\functions_admin::delete_orphan_tags',
            'delete_site' => '\Piwigo\admin\inc\functions_admin::delete_site',
            'delete_tags' => '\Piwigo\admin\inc\functions_admin::delete_tags',
            'delete_user' => '\Piwigo\admin\inc\functions_admin::delete_user',
            'dissociate_images_from_category' => '\Piwigo\admin\inc\functions_admin::dissociate_images_from_category',
            'do_timeout_treatment' => '\Piwigo\admin\inc\functions_admin::do_timeout_treatment',
            'empty_lounge' => '\Piwigo\admin\inc\functions_admin::empty_lounge',
            'fill_lounge' => '\Piwigo\admin\inc\functions_admin::fill_lounge',
            'get_active_menu' => '\Piwigo\admin\inc\functions_admin::get_active_menu',
            'get_admin_client_cache_keys' => '\Piwigo\admin\inc\functions_admin::get_admin_client_cache_keys',
            'get_cache_size_derivatives' => '\Piwigo\admin\inc\functions_admin::get_cache_size_derivatives',
            'get_categories_ref_date' => '\Piwigo\admin\inc\functions_admin::get_categories_ref_date',
            'get_category_representative_properties' => '\Piwigo\admin\inc\functions_admin::get_category_representative_properties',
            'get_complete_dir' => '\Piwigo\admin\inc\functions_admin::get_complete_dir',
            'get_date_object' => '\Piwigo\admin\inc\functions_admin::get_date_object',
            'get_fs_directories' => '\Piwigo\admin\inc\functions_admin::get_fs_directories',
            'get_fs' => '\Piwigo\admin\inc\functions_admin::get_fs',
            'get_fulldirs' => '\Piwigo\admin\inc\functions_admin::get_fulldirs',
            'get_image_infos' => '\Piwigo\admin\inc\functions_admin::get_image_infos',
            'get_image_tag_ids' => '\Piwigo\admin\inc\functions_admin::get_image_tag_ids',
            'get_last' => '\Piwigo\admin\inc\functions_admin::get_last',
            'get_local_dir' => '\Piwigo\admin\inc\functions_admin::get_local_dir',
            'get_min_local_dir' => '\Piwigo\admin\inc\functions_admin::get_min_local_dir',
            'get_month_of_last_years' => '\Piwigo\admin\inc\functions_admin::get_month_of_last_years',
            'get_month_stats' => '\Piwigo\admin\inc\functions_admin::get_month_stats',
            'get_orphan_tags' => '\Piwigo\admin\inc\functions_admin::get_orphan_tags',
            'get_orphans' => '\Piwigo\admin\inc\functions_admin::get_orphans',
            'get_photos_no_md5sum' => '\Piwigo\admin\inc\functions_admin::get_photos_no_md5sum',
            'get_piwigo_news' => '\Piwigo\admin\inc\functions_admin::get_piwigo_news',
            'get_site_url' => '\Piwigo\admin\inc\functions_admin::get_site_url',
            'get_tab_status' => '\Piwigo\admin\inc\functions_admin::get_tab_status',
            'get_uppercat_ids' => '\Piwigo\admin\inc\functions_admin::get_uppercat_ids',
            'get_watermark_filename' => '\Piwigo\admin\inc\functions_admin::get_watermark_filename',
            'images_integrity' => '\Piwigo\admin\inc\functions_admin::images_integrity',
            'insert_new_data_user_mail_notification' => '\Piwigo\admin\inc\functions_admin::insert_new_data_user_mail_notification',
            'invalidate_user_cache' => '\Piwigo\admin\inc\functions_admin::invalidate_user_cache',
            'last_rate_compare' => '\Piwigo\admin\inc\functions_admin::last_rate_compare',
            'make_consecutive' => '\Piwigo\admin\inc\functions_admin::make_consecutive',
            'move_categories' => '\Piwigo\admin\inc\functions_admin::move_categories',
            'move_images_to_categories' => '\Piwigo\admin\inc\functions_admin::move_images_to_categories',
            'number_format_human_readable' => '\Piwigo\admin\inc\functions_admin::number_format_human_readable',
            'order_by_is_local' => '\Piwigo\admin\inc\functions_admin::order_by_is_local',
            'parse_sort_variables' => '\Piwigo\admin\inc\functions_admin::parse_sort_variables',
            'pwg_URL' => '\Piwigo\admin\inc\functions_admin::pwg_URL',
            'render_global_customize_mail_content' => '\Piwigo\admin\inc\functions_admin::render_global_customize_mail_content',
            'save_categories_order' => '\Piwigo\admin\inc\functions_admin::save_categories_order',
            'save_images_order' => '\Piwigo\admin\inc\functions_admin::save_images_order',
            'set_cat_status' => '\Piwigo\admin\inc\functions_admin::set_cat_status',
            'set_cat_visible' => '\Piwigo\admin\inc\functions_admin::set_cat_visible',
            'set_missing_values' => '\Piwigo\admin\inc\functions_admin::set_missing_values',
            'set_random_representative' => '\Piwigo\admin\inc\functions_admin::set_random_representative',
            'set_tags_of' => '\Piwigo\admin\inc\functions_admin::set_tags_of',
            'set_tags' => '\Piwigo\admin\inc\functions_admin::set_tags',
            'sync_users' => '\Piwigo\admin\inc\functions_admin::sync_users',
            'tag_id_from_tag_name' => '\Piwigo\admin\inc\functions_admin::tag_id_from_tag_name',
            'UC_name_compare' => '\Piwigo\admin\inc\functions_admin::UC_name_compare',
            'update_category' => '\Piwigo\admin\inc\functions_admin::update_category',
            'update_global_rank' => '\Piwigo\admin\inc\functions_admin::update_global_rank',
            'update_images_lastmodified' => '\Piwigo\admin\inc\functions_admin::update_images_lastmodified',
            'update_path' => '\Piwigo\admin\inc\functions_admin::update_path',
            'update_uppercats' => '\Piwigo\admin\inc\functions_admin::update_uppercats',

            // Upgrade functions
            'check_piwigo_upgrade' => '\Piwigo\admin\inc\updates::check_piwigo_upgrade',
            'check_upgrade_access_rights' => '\Piwigo\admin\inc\functions_upgrade::check_upgrade_access_rights',
            'check_upgrade_feed' => '\Piwigo\admin\inc\functions_upgrade::check_upgrade_feed',
            'deactivate_non_standard_themes' => '\Piwigo\admin\inc\functions_upgrade::deactivate_non_standard_themes',
            'deactivate_templates' => '\Piwigo\admin\inc\functions_upgrade::deactivate_templates',
            'get_available_upgrade_ids' => '\Piwigo\admin\inc\functions_upgrade::get_available_upgrade_ids',
            'process_obsolete_list' => '\Piwigo\admin\inc\updates::process_obsolete_list',
            'upgrade_db_connect' => '\Piwigo\admin\inc\functions_upgrade::upgrade_db_connect',
            'upgrade_to' => '\Piwigo\admin\inc\updates::upgrade_to',

            // History functions
            'get_history' => '\Piwigo\admin\inc\functions_history::get_history',
            'history_autopurge' => '\Piwigo\admin\inc\functions_history::history_autopurge',
            'history_compare' => '\Piwigo\admin\inc\functions_history::history_compare',
            'history_remove_summarized_column' => '\Piwigo\admin\inc\functions_history::history_remove_summarized_column',
            'history_summarize' => '\Piwigo\admin\inc\functions_history::history_summarize',
            'history_tabsheet' => '\Piwigo\admin\inc\functions_history::history_tabsheet',

            // Install functions
            'activate_core_plugins' => '\Piwigo\admin\inc\functions_install::activate_core_plugins',
            'activate_core_themes' => '\Piwigo\admin\inc\functions_install::activate_core_themes',
            'execute_sqlfile' => '\Piwigo\admin\inc\functions_install::execute_sqlfile',
            'install_db_connect' => '\Piwigo\admin\inc\functions_install::install_db_connect',

            // Image functions
            'get_library' => '\Piwigo\admin\inc\pwg_image::get_library',
            'get_resize_dimensions' => '\Piwigo\admin\inc\pwg_image::get_resize_dimensions',
            'get_rotation_angle_from_code' => '\Piwigo\admin\inc\pwg_image::get_rotation_angle_from_code',
            'get_rotation_angle' => '\Piwigo\admin\inc\pwg_image::get_rotation_angle',
            'get_rotation_code_from_angle' => '\Piwigo\admin\inc\pwg_image::get_rotation_code_from_angle',
            'get_sharpen_matrix' => '\Piwigo\admin\inc\pwg_image::get_sharpen_matrix',
            'is_ext_imagick' => '\Piwigo\admin\inc\pwg_image::is_ext_imagick',
            'is_gd' => '\Piwigo\admin\inc\pwg_image::is_gd',
            'is_imagick' => '\Piwigo\admin\inc\pwg_image::is_imagick',
            'is_vips' => '\Piwigo\admin\inc\pwg_image::is_vips',
            'webp_info' => '\Piwigo\admin\inc\pwg_image::webp_info',

            // Upload functions
            'add_format' => '\Piwigo\admin\inc\functions_upload::add_format',
            'add_upload_error' => '\Piwigo\admin\inc\functions_upload::add_upload_error',
            'add_uploaded_file_add_to_categories' => '\Piwigo\admin\inc\functions_upload::add_uploaded_file_add_to_categories',
            'add_uploaded_file' => '\Piwigo\admin\inc\functions_upload::add_uploaded_file',
            'convert_shorthand_notation_to_bytes' => '\Piwigo\admin\inc\functions_upload::convert_shorthand_notation_to_bytes',
            'file_upload_error_message' => '\Piwigo\admin\inc\functions_upload::file_upload_error_message',
            'get_ini_size' => '\Piwigo\admin\inc\functions_upload::get_ini_size',
            'get_optimal_dimensions_for_representative' => '\Piwigo\admin\inc\functions_upload::get_optimal_dimensions_for_representative',
            'get_upload_form_config' => '\Piwigo\admin\inc\functions_upload::get_upload_form_config',
            'is_valid_image_extension' => '\Piwigo\admin\inc\functions_upload::is_valid_image_extension',
            'need_resize' => '\Piwigo\admin\inc\functions_upload::need_resize',
            'prepare_directory' => '\Piwigo\admin\inc\functions_upload::prepare_directory',
            'pwg_image_infos' => '\Piwigo\admin\inc\functions_upload::pwg_image_infos',
            'ready_for_upload_message' => '\Piwigo\admin\inc\functions_upload::ready_for_upload_message',
            'save_upload_form_config' => '\Piwigo\admin\inc\functions_upload::save_upload_form_config',
            'upload_file_eps' => '\Piwigo\admin\inc\functions_upload::upload_file_eps',
            'upload_file_heic' => '\Piwigo\admin\inc\functions_upload::upload_file_heic',
            'upload_file_pdf' => '\Piwigo\admin\inc\functions_upload::upload_file_pdf',
            'upload_file_psd' => '\Piwigo\admin\inc\functions_upload::upload_file_psd',
            'upload_file_tiff' => '\Piwigo\admin\inc\functions_upload::upload_file_tiff',
            'upload_file_video' => '\Piwigo\admin\inc\functions_upload::upload_file_video',

            // Derivative functions
            'char_to_fraction' => '\Piwigo\inc\derivative_params::char_to_fraction',
            'derivative_to_url' => '\Piwigo\inc\derivative_params::derivative_to_url',
            'fraction_to_char' => '\Piwigo\inc\derivative_params::fraction_to_char',
            'get_all' => '\Piwigo\inc\DerivativeImage::get_all',
            'get_one' => '\Piwigo\inc\DerivativeImage::get_one',
            'size_equals' => '\Piwigo\inc\derivative_params::size_equals',
            'size_to_url' => '\Piwigo\inc\derivative_params::size_to_url',
            'thumb_url' => '\Piwigo\inc\DerivativeImage::thumb_url',
            'url' => '\Piwigo\inc\DerivativeImage::url',

            // FileCombiner functions
            'clear_combined_files' => '\Piwigo\inc\FileCombiner::clear_combined_files',
            'process_css_rec' => '\Piwigo\inc\FileCombiner::process_css_rec',
            'process_css' => '\Piwigo\inc\FileCombiner::process_css',
            'process_js' => '\Piwigo\inc\FileCombiner::process_js',

            // CssLoader functions
            'cmp_by_order' => '\Piwigo\inc\CssLoader::cmp_by_order',

            // Web Service core functions
            'ws_add_method' => '\Piwigo\inc\ws_core::ws_add_method',
            'ws_get_method' => '\Piwigo\inc\ws_core::ws_get_method',
            'ws_get_methods' => '\Piwigo\inc\ws_core::ws_get_methods',
            'ws_remove_method' => '\Piwigo\inc\ws_core::ws_remove_method',
            'ws_register_method' => '\Piwigo\inc\ws_core::ws_register_method',
            'ws_register_methods' => '\Piwigo\inc\ws_core::ws_register_methods',
            'ws_unregister_method' => '\Piwigo\inc\ws_core::ws_unregister_method',
            'ws_unregister_methods' => '\Piwigo\inc\ws_core::ws_unregister_methods',

            // Web Service protocol functions
            'ws_encode_xmlrpc' => '\Piwigo\inc\ws_protocols\PwgXmlRpcEncoder::ws_encode_xmlrpc',
            'ws_encode_json' => '\Piwigo\inc\ws_protocols\PwgJsonEncoder::ws_encode_json',
            'ws_encode_php' => '\Piwigo\inc\ws_protocols\PwgSerialPhpEncoder::ws_encode_php',
            'ws_encode_rest' => '\Piwigo\inc\ws_protocols\PwgRestEncoder::ws_encode_rest',
            'ws_handle_rest_request' => '\Piwigo\inc\ws_protocols\PwgRestRequestHandler::ws_handle_rest_request',
            'ws_write_xml' => '\Piwigo\inc\ws_protocols\PwgXmlWriter::ws_write_xml',

            // Web Service functions from pwg.php
            'ws_echo' => '\Piwigo\inc\ws_functions\pwg::ws_echo',
            'ws_get_states' => '\Piwigo\inc\ws_functions\pwg::ws_get_states',
            'ws_get_version' => '\Piwigo\inc\ws_functions\pwg::ws_get_version',
            'ws_reflect' => '\Piwigo\inc\ws_functions\pwg::ws_reflect',
            'ws_reflect_methods' => '\Piwigo\inc\ws_functions\pwg::ws_reflect_methods',
            'ws_reflect_parameters' => '\Piwigo\inc\ws_functions\pwg::ws_reflect_parameters',
            'ws_reflect_return' => '\Piwigo\inc\ws_functions\pwg::ws_reflect_return',
            'ws_reflect_summary' => '\Piwigo\inc\ws_functions\pwg::ws_reflect_summary',
            'ws_reflect_type' => '\Piwigo\inc\ws_functions\pwg::ws_reflect_type',

            // Web Service functions from pwg_categories.php
            'ws_categories_add' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_add',
            'ws_categories_delete' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_delete',
            'ws_categories_getAdminList' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_getAdminList',
            'ws_categories_getImages' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_getImages',
            'ws_categories_getInfo' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_getInfo',
            'ws_categories_getList' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_getList',
            'ws_categories_move' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_move',
            'ws_categories_setInfo' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_setInfo',
            'ws_categories_setRepresentative' => '\Piwigo\inc\ws_functions\pwg_categories::ws_categories_setRepresentative',

            // Web Service functions from pwg_images.php
            'ws_images_add' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_add',
            'ws_images_addChunk' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_addChunk',
            'ws_images_addSimple' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_addSimple',
            'ws_images_checkFiles' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_checkFiles',
            'ws_images_delete' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_delete',
            'ws_images_exist' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_exist',
            'ws_images_getInfo' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_getInfo',
            'ws_images_getList' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_getList',
            'ws_images_setInfo' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_setInfo',
            'ws_images_setRank' => '\Piwigo\inc\ws_functions\pwg_images::ws_images_setRank',

            // Web Service functions from pwg_tags.php
            'ws_tags_add' => '\Piwigo\inc\ws_functions\pwg_tags::ws_tags_add',
            'ws_tags_delete' => '\Piwigo\inc\ws_functions\pwg_tags::ws_tags_delete',
            'ws_tags_getList' => '\Piwigo\inc\ws_functions\pwg_tags::ws_tags_getList',
            'ws_tags_getImages' => '\Piwigo\inc\ws_functions\pwg_tags::ws_tags_getImages',
            'ws_tags_setInfo' => '\Piwigo\inc\ws_functions\pwg_tags::ws_tags_setInfo',

            // Web Service functions from pwg_users.php
            'ws_users_add' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_add',
            'ws_users_delete' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_delete',
            'ws_users_getList' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_getList',
            'ws_users_getInfo' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_getInfo',
            'ws_users_setInfo' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_setInfo',
            'ws_users_setPassword' => '\Piwigo\inc\ws_functions\pwg_users::ws_users_setPassword',

            // Web Service functions from pwg_groups.php
            'ws_groups_add' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_add',
            'ws_groups_delete' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_delete',
            'ws_groups_getList' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_getList',
            'ws_groups_getInfo' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_getInfo',
            'ws_groups_setInfo' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_setInfo',
            'ws_groups_addUser' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_addUser',
            'ws_groups_deleteUser' => '\Piwigo\inc\ws_functions\pwg_groups::ws_groups_deleteUser',

            // Web Service functions from pwg_permissions.php
            'ws_permissions_add' => '\Piwigo\inc\ws_functions\pwg_permissions::ws_permissions_add',
            'ws_permissions_delete' => '\Piwigo\inc\ws_functions\pwg_permissions::ws_permissions_delete',
            'ws_permissions_getList' => '\Piwigo\inc\ws_functions\pwg_permissions::ws_permissions_getList',
            'ws_permissions_getInfo' => '\Piwigo\inc\ws_functions\pwg_permissions::ws_permissions_getInfo',
            'ws_permissions_setInfo' => '\Piwigo\inc\ws_functions\pwg_permissions::ws_permissions_setInfo',

            // Web Service functions from pwg_extensions.php
            'ws_extensions_getList' => '\Piwigo\inc\ws_functions\pwg_extensions::ws_extensions_getList',
            'ws_extensions_getInfo' => '\Piwigo\inc\ws_functions\pwg_extensions::ws_extensions_getInfo',
            'ws_extensions_install' => '\Piwigo\inc\ws_functions\pwg_extensions::ws_extensions_install',
            'ws_extensions_uninstall' => '\Piwigo\inc\ws_functions\pwg_extensions::ws_extensions_uninstall',
            'ws_extensions_update' => '\Piwigo\inc\ws_functions\pwg_extensions::ws_extensions_update',

            'ImageStdParams::get_custom' => '\Piwigo\inc\ImageStdParams::get_custom',
        ];

        foreach ($files as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'tpl'])) {
                $content = file_get_contents($file->getPathname());

                // Replace function calls with their namespaced versions
                foreach ($function_map as $old_func => $new_func) {
                    // Match function calls that aren't method calls (not prefixed with ->)
                    $pattern = '/(?<![\w>]|->)\b' . preg_quote($old_func, '/') . '\s*\(/';
                    $content = preg_replace($pattern, $new_func . '(', $content);
                }

                // Replace jQuery UI strings (like 'jquery.ui.effect-blind' to 'jquery.ui')
                $content = preg_replace(
                    '/jquery\.ui\.[a-zA-Z0-9\-_]+/',
                    'jquery.ui',
                    $content
                );

                file_put_contents($file->getPathname(), $content);
            }
        }
    }

    /**
     * Renames files and folders according to the new naming convention
     *
     * @param string $path The directory to process
     */
    protected function rename_files_and_folders(string $path): void
    {
        // Process files to rename
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $filename = $file->getFilename();
                $path = $file->getPath();

                if ($filename === 'main.inc.php') {
                    rename($path . '/' . $filename, $path . '/main.php');
                }

                if ($filename === 'themeconf.inc.php') {
                    rename($path . '/' . $filename, $path . '/themeconf.php');
                }

                if ($filename === 'maintain.class.php') {
                    rename($path . '/' . $filename, $path . '/maintain_class.php');
                }

                if ($filename === 'admin.inc.php') {
                    rename($path . '/' . $filename, $path . '/admin.php');
                }
            }
        }

        // Updates SQL table constants to their string values
        $table_map = [
            'ACTIVITY_TABLE' => 'activity',
            'CADDIE_TABLE' => 'caddie',
            'CATEGORIES_TABLE' => 'categories',
            'COMMENTS_TABLE' => 'comments',
            'CONFIG_TABLE' => 'config',
            'FAVORITES_TABLE' => 'favorites',
            'GROUP_ACCESS_TABLE' => 'group_access',
            'GROUPS_TABLE' => 'groups',
            'HISTORY_SUMMARY_TABLE' => 'history_summary',
            'HISTORY_TABLE' => 'history',
            'IMAGE_CATEGORY_TABLE' => 'image_category',
            'IMAGE_FORMAT_TABLE' => 'image_format',
            'IMAGE_TAG_TABLE' => 'image_tag',
            'IMAGES_TABLE' => 'images',
            'LANGUAGES_TABLE' => 'languages',
            'LOUNGE_TABLE' => 'lounge',
            'OLD_PERMALINKS_TABLE' => 'old_permalinks',
            'PLUGINS_TABLE' => 'plugins',
            'RATE_TABLE' => 'rate',
            'SEARCH_TABLE' => 'search',
            'SESSIONS_TABLE' => 'sessions',
            'SITES_TABLE' => 'sites',
            'TAGS_TABLE' => 'tags',
            'THEMES_TABLE' => 'themes',
            'UPGRADE_TABLE' => 'upgrade',
            'USER_ACCESS_TABLE' => 'user_access',
            'USER_AUTH_KEYS_TABLE' => 'user_auth_keys',
            'USER_CACHE_CATEGORIES_TABLE' => 'user_cache_categories',
            'USER_CACHE_TABLE' => 'user_cache',
            'USER_FEED_TABLE' => 'user_feed',
            'USER_GROUP_TABLE' => 'user_group',
            'USER_INFOS_TABLE' => 'user_infos',
            'USER_MAIL_NOTIFICATION_TABLE' => 'user_mail_notification',
            'USERS_TABLE' => 'users',
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $modified = false;

                foreach ($table_map as $constant => $table_name) {
                    $pattern = '/\b' . $constant . '\b/';

                    if (preg_match($pattern, $content)) {
                        $content = preg_replace($pattern, "'" . $table_name . "'", $content);
                        $modified = true;
                    }
                }

                if ($modified) {
                    file_put_contents($file->getPathname(), $content);
                }
            }
        }
    }
}
