<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// P21: the tab-dispatch body this file used to hold is now
// Piwigo\Controller\Admin\PhotosAddSubController (page slug "photos_add"
// in config/admin_pages.php) -- this file is include_once'd by that
// sub-controller purely to define PHOTOS_ADD_BASE_URL from legacy code
// (an arch test forbids define() calls under src/Piwigo/), since
// admin/include/add_core_tabs.inc.php's add_core_tabs() (a
// 'tabsheet_before_select' event handler, so it only runs once
// $tabsheet->select() is called further down the sub-controller) and the
// 3 photos_add_*.php tab bodies all still read this constant.

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

if (! defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
}
