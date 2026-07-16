<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Relocated from the now-deleted admin/photos_add.php (P23 batch 8a):
// Piwigo\Admin\CoreTabs::addCoreTabs() (formerly admin/include/
// add_core_tabs.inc.php's add_core_tabs(), P23 batch 8b-6) and
// Piwigo\Admin\PhotosAddDirectPageRenderer both read this constant, and
// this file is already include_once'd before either of them ever runs
// (PhotosAddSubController::handle() loads this file first) -- can't
// define() it in src/Piwigo/ itself (SEC-60 Arch rule).
//
// P23 batch 8d file 3 removed the last of this file's 18 Categories-domain
// functions (migrated to Piwigo\Category\CategoryService/
// Piwigo\Users\UserService) -- this constant-definition block is the only
// thing left. Whether to relocate it and delete this file entirely is
// P23 batch 9's scope (final legacy-file deletion sweep), not this one.
if (! defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
}
