<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

$admin_album_base_url = functions_url::get_root_url() . 'admin.php?page=album-' . $_GET['cat_id'];

$query = '
SELECT *
  FROM categories
  WHERE id = ' . $_GET['cat_id'] . '
;';
$category = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

if (! isset($category['id'])) {
    die('unknown album');
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'properties';

if (isset($_GET['tab'])) {
    $page['tab'] = $_GET['tab'];
}

$tabsheet = new tabsheet();
$tabsheet->set_id('album');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

$category_name = functions_plugins::trigger_change(
    'render_category_name',
    $category['name'],
    '\Piwigo\inc\functions_html::get_cat_display_name_cache'
);
$template->assign([
    'ADMIN_PAGE_TITLE' => functions::l10n('Edit album') . ' <strong>' . $category_name . '</strong>',
    'ADMIN_PAGE_OBJECT_ID' => '#' . $category['id'],
]);

if ($page['tab'] == 'properties') {
    include(PHPWG_ROOT_PATH . 'admin/cat_modify.php');
} elseif ($page['tab'] == 'sort_order') {
    include(PHPWG_ROOT_PATH . 'admin/element_set_ranks.php');
} elseif ($page['tab'] == 'permissions') {
    $_GET['cat'] = $_GET['cat_id'];
    include(PHPWG_ROOT_PATH . 'admin/cat_perm.php');
} else {
    include(PHPWG_ROOT_PATH . 'admin/album_' . $page['tab'] . '.php');
}
