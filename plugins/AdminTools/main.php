<?php

declare(strict_types=1);

/*
Plugin Name: Admin Tools
Version: 14.5.0
Description: Do some admin task from the public pages
Plugin URI: https://piwigo.org/ext/extension_view.php?eid=720
Author: Piwigo team
Author URI: http://piwigo.org
Has Settings: webmaster
*/

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\plugins\AdminTools\inc\MultiView;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

define('ADMINTOOLS_ID', basename(__DIR__));
const ADMINTOOLS_PATH = PHPWG_PLUGINS_PATH . ADMINTOOLS_ID . '/';
define('ADMINTOOLS_ADMIN', functions_url::get_root_url() . 'admin.php?page=plugin-' . ADMINTOOLS_ID);

require_once ADMINTOOLS_PATH . 'inc/events.php';

global $MultiView;
$MultiView = new MultiView();

functions_plugins::add_event_handler('init', admintools_init(...));

functions_plugins::add_event_handler('user_init', $MultiView->user_init(...));
functions_plugins::add_event_handler('init', $MultiView->init(...));

functions_plugins::add_event_handler('ws_add_methods', MultiView::register_ws(...));
functions_plugins::add_event_handler('delete_user', MultiView::invalidate_cache(...));
functions_plugins::add_event_handler('register_user', MultiView::invalidate_cache(...));

functions_plugins::add_event_handler('theme_installed', MultiView::invalidate_cache(...));
functions_plugins::add_event_handler('theme_deleted', MultiView::invalidate_cache(...));
functions_plugins::add_event_handler('theme_activated', MultiView::invalidate_cache(...));
functions_plugins::add_event_handler('theme_deactivated', MultiView::invalidate_cache(...));

if (! defined('IN_ADMIN')) {
    functions_plugins::add_event_handler('loc_after_page_header', admintools_add_public_controller(...));
    functions_plugins::add_event_handler('loc_begin_picture', admintools_save_picture(...));
    functions_plugins::add_event_handler('loc_begin_index', admintools_save_category(...));
} else {
    functions_plugins::add_event_handler('loc_begin_page_header', admintools_add_admin_controller_setprefilter(...));
    functions_plugins::add_event_handler('loc_after_page_header', admintools_add_admin_controller(...));
}

function admintools_init(): void
{
    global $conf;

    functions::load_language('plugin.lang', ADMINTOOLS_PATH);
}
