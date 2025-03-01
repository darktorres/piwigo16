<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Default settings
define('PHPWG_VERSION', '14.5.0');
define('PHPWG_DEFAULT_LANGUAGE', 'en_UK');

// this constant is only used in the upgrade process, the true default theme
// is the theme of user "guest", which is initialized with column user_infos.theme
// default value (see file install/piwigo_structure-mysql.sql)
define('PHPWG_DEFAULT_TEMPLATE', 'modus');

define('PHPWG_THEMES_PATH', $conf['themes_dir'] . '/');

if (! defined('PWG_COMBINED_DIR')) {
    define('PWG_COMBINED_DIR', $conf['data_location'] . 'combined/');
}

if (! defined('PWG_DERIVATIVE_DIR')) {
    define('PWG_DERIVATIVE_DIR', $conf['data_location'] . 'i/');
}

// Required versions
define('REQUIRED_PHP_VERSION', '8.4.6');

// Access codes
define('ACCESS_FREE', 0);
define('ACCESS_GUEST', 1);
define('ACCESS_CLASSIC', 2);
define('ACCESS_ADMINISTRATOR', 3);
define('ACCESS_WEBMASTER', 4);
define('ACCESS_CLOSED', 5);

// System activities
define('ACTIVITY_SYSTEM_CORE', 1);
define('ACTIVITY_SYSTEM_PLUGIN', 2);
define('ACTIVITY_SYSTEM_THEME', 3);

// Sanity checks
define('PATTERN_ID', '/^\d+$/');
define('PATTERN_ORDER', '/^(rand(om)?|[a-z_]+(\s+(asc|desc))?)(\s*,\s*(rand(om)?|[a-z_]+(\s+(asc|desc))?))*$/i');

// Table names
if (! defined('CATEGORIES_TABLE')) {
    define('CATEGORIES_TABLE', 'categories');
}

if (! defined('COMMENTS_TABLE')) {
    define('COMMENTS_TABLE', 'comments');
}

if (! defined('CONFIG_TABLE')) {
    define('CONFIG_TABLE', 'config');
}

if (! defined('FAVORITES_TABLE')) {
    define('FAVORITES_TABLE', 'favorites');
}

if (! defined('GROUP_ACCESS_TABLE')) {
    define('GROUP_ACCESS_TABLE', 'group_access');
}

if (! defined('GROUPS_TABLE')) {
    define('GROUPS_TABLE', 'groups');
}

if (! defined('HISTORY_TABLE')) {
    define('HISTORY_TABLE', 'history');
}

if (! defined('HISTORY_SUMMARY_TABLE')) {
    define('HISTORY_SUMMARY_TABLE', 'history_summary');
}

if (! defined('IMAGE_CATEGORY_TABLE')) {
    define('IMAGE_CATEGORY_TABLE', 'image_category');
}

if (! defined('IMAGES_TABLE')) {
    define('IMAGES_TABLE', 'images');
}

if (! defined('SESSIONS_TABLE')) {
    define('SESSIONS_TABLE', 'sessions');
}

if (! defined('SITES_TABLE')) {
    define('SITES_TABLE', 'sites');
}

if (! defined('USER_ACCESS_TABLE')) {
    define('USER_ACCESS_TABLE', 'user_access');
}

if (! defined('USER_GROUP_TABLE')) {
    define('USER_GROUP_TABLE', 'user_group');
}

if (! defined('USERS_TABLE')) {
    define('USERS_TABLE', isset($conf['users_table']) ? $conf['users_table'] : 'users');
}

if (! defined('USER_INFOS_TABLE')) {
    define('USER_INFOS_TABLE', 'user_infos');
}

if (! defined('USER_FEED_TABLE')) {
    define('USER_FEED_TABLE', 'user_feed');
}

if (! defined('RATE_TABLE')) {
    define('RATE_TABLE', 'rate');
}

if (! defined('USER_AUTH_KEYS_TABLE')) {
    define('USER_AUTH_KEYS_TABLE', 'user_auth_keys');
}

if (! defined('USER_CACHE_TABLE')) {
    define('USER_CACHE_TABLE', 'user_cache');
}

if (! defined('USER_CACHE_CATEGORIES_TABLE')) {
    define('USER_CACHE_CATEGORIES_TABLE', 'user_cache_categories');
}

if (! defined('CADDIE_TABLE')) {
    define('CADDIE_TABLE', 'caddie');
}

if (! defined('UPGRADE_TABLE')) {
    define('UPGRADE_TABLE', 'upgrade');
}

if (! defined('SEARCH_TABLE')) {
    define('SEARCH_TABLE', 'search');
}

if (! defined('USER_MAIL_NOTIFICATION_TABLE')) {
    define('USER_MAIL_NOTIFICATION_TABLE', 'user_mail_notification');
}

if (! defined('TAGS_TABLE')) {
    define('TAGS_TABLE', 'tags');
}

if (! defined('IMAGE_TAG_TABLE')) {
    define('IMAGE_TAG_TABLE', 'image_tag');
}

if (! defined('PLUGINS_TABLE')) {
    define('PLUGINS_TABLE', 'plugins');
}

if (! defined('OLD_PERMALINKS_TABLE')) {
    define('OLD_PERMALINKS_TABLE', 'old_permalinks');
}

if (! defined('THEMES_TABLE')) {
    define('THEMES_TABLE', 'themes');
}

if (! defined('LANGUAGES_TABLE')) {
    define('LANGUAGES_TABLE', 'languages');
}

if (! defined('IMAGE_FORMAT_TABLE')) {
    define('IMAGE_FORMAT_TABLE', 'image_format');
}

if (! defined('ACTIVITY_TABLE')) {
    define('ACTIVITY_TABLE', 'activity');
}

if (! defined('LOUNGE_TABLE')) {
    define('LOUNGE_TABLE', 'lounge');
}
