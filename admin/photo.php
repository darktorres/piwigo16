<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $page is set by admin.php before including this page;
// $conf and $template by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $conf, $page, $template;

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
check_input_parameter('image_id', $_GET, false, PATTERN_ID);

// check_input_parameter() above already validated $_GET['image_id'] against
// PATTERN_ID (digits only) when present; $_GET values are always strings
// (or arrays, for foo[]=... params) so is_string() is the real narrowing.
$get_image_id = is_string($_GET['image_id'] ?? null) ? $_GET['image_id'] : '';

$admin_photo_base_url = get_root_url() . 'admin.php?page=photo-' . $get_image_id;

// retrieving direct information about picture
$page['image'] = get_image_infos($get_image_id, true);

if (isset($_GET['cat_id']) && is_string($_GET['cat_id'])) {
    $query = '
SELECT *
  FROM ' . CATEGORIES_TABLE . '
  WHERE id = ' . $_GET['cat_id'] . '
;';
    $category = pwg_db_fetch_assoc(pwg_query($query));
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

$page['tab'] = 'properties';

if (isset($_GET['tab']) && is_string($_GET['tab'])) {
    $page['tab'] = $_GET['tab'];
}

$tabsheet = new tabsheet();
$tabsheet->set_id('photo');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$template->assign(
    [
        'ADMIN_PAGE_TITLE' => l10n('Edit photo') . ' <span class="image-id">#' . $get_image_id . '</span>',
    ]
);

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

if ($page['tab'] == 'properties') {
    include PHPWG_ROOT_PATH . 'admin/picture_modify.php';
} elseif ($page['tab'] == 'coi') {
    include PHPWG_ROOT_PATH . 'admin/picture_coi.php';
} elseif ($page['tab'] == 'formats' && $conf['enable_formats']) {
    include PHPWG_ROOT_PATH . 'admin/picture_formats.php';
} else {
    include PHPWG_ROOT_PATH . 'admin/photo_' . $page['tab'] . '.php';
}
