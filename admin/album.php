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

// Bootstrap globals, set by include/common.inc.php.
global $template;

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

// check_input_parameter() only validates the format when 'cat_id' is
// present (mandatory=false above); fall back to 0 (no matching album,
// handled by the "unknown album" die() below) rather than risk building
// an invalid query from a missing/non-numeric value.
$cat_id = isset($_GET['cat_id']) && is_numeric($_GET['cat_id']) ? (int) $_GET['cat_id'] : 0;

$admin_album_base_url = get_root_url() . 'admin.php?page=album-' . $cat_id;

$query = '
SELECT *
  FROM ' . CATEGORIES_TABLE . '
  WHERE id = ' . $cat_id . '
;';
$category = pwg_db_fetch_assoc(pwg_query($query));

if (! isset($category['id'])) {
    die('unknown album');
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
$tabsheet->set_id('album');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

$category_name = trigger_change(
    'render_category_name',
    $category['name'],
    'get_cat_display_name_cache'
);
// trigger_change() is a generic plugin hook and legitimately returns
// mixed; fall back to the raw (unrendered) category name if no handler
// returned a string.
if (! is_string($category_name)) {
    $category_name = is_string($category['name']) ? $category['name'] : '';
}
$template->assign([
    'ADMIN_PAGE_TITLE' => l10n('Edit album') . ' <strong>' . $category_name . '</strong>',
    'ADMIN_PAGE_OBJECT_ID' => '#' . $category['id'],
]);

if ($page['tab'] == 'properties') {
    include PHPWG_ROOT_PATH . 'admin/cat_modify.php';
} elseif ($page['tab'] == 'sort_order') {
    include PHPWG_ROOT_PATH . 'admin/element_set_ranks.php';
} elseif ($page['tab'] == 'permissions') {
    $_GET['cat'] = $_GET['cat_id'];
    include PHPWG_ROOT_PATH . 'admin/cat_perm.php';
} else {
    include PHPWG_ROOT_PATH . 'admin/album_' . $page['tab'] . '.php';
}
