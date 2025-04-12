<?php

declare(strict_types=1);

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

define('PHPWG_THEMES_PATH', $conf->themes_dir . '/');

if (! defined('PWG_COMBINED_DIR')) {
    define('PWG_COMBINED_DIR', $conf->data_location . 'combined/');
}

if (! defined('PWG_DERIVATIVE_DIR')) {
    define('PWG_DERIVATIVE_DIR', $conf->data_location . 'i/');
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
