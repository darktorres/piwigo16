<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
check_input_parameter('image_id', $_GET, false, PATTERN_ID);

$image_id_str = is_scalar($_GET['image_id'] ?? null) ? (string)$_GET['image_id'] : '';
$admin_photo_base_url = get_root_url().'admin.php?page=photo-'.$image_id_str;

// retrieving direct information about picture
$page['image'] = get_image_infos($image_id_str, true);

if (isset($_GET['cat_id'])) {
    $cat_id_val = $_GET['cat_id'];
    $category = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->findCategoryById(is_scalar($cat_id_val) ? (int) $cat_id_val : 0);
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'properties';

if (isset($_GET['tab'])) {
    $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'properties';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$template->assign(
    [
    'ADMIN_PAGE_TITLE' => l10n('Edit photo').' <span class="image-id">#'.$image_id_str.'</span>',
    ]
);

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

if ('properties' == $page['tab']) {
    include(PHPWG_ROOT_PATH.'admin/picture_modify.php');
} elseif ('coi' == $page['tab']) {
    include(PHPWG_ROOT_PATH.'admin/picture_coi.php');
} elseif ('formats' == $page['tab'] && \Piwigo\Config\Config::isFormatsEnabled()) {
    include(PHPWG_ROOT_PATH.'admin/picture_formats.php');
} else {
    include(PHPWG_ROOT_PATH.'admin/photo_'.$page['tab'].'.php');
}
