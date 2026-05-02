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
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


// +-----------------------------------------------------------------------+
// | Basic checks                                                          |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

$cat_id_str = is_scalar($_GET['cat_id']) ? (string) $_GET['cat_id'] : '';
$admin_album_base_url = get_root_url().'admin.php?page=album-'.$cat_id_str;

$query = '
SELECT *
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$cat_id_str.'
;';
$category = pwg_db_fetch_assoc(pwg_query($query));

if (!isset($category['id'])) {
    die('unknown album');
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'properties';

if (isset($_GET['tab'])) {
    $page['tab'] = is_scalar($_GET['tab']) ? (string) $_GET['tab'] : 'properties';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('album');
$tabsheet->select((string) $page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Load the tab                                                          |
// +-----------------------------------------------------------------------+

$category_name = trigger_change(
    'render_category_name',
    $category['name'],
    'get_cat_display_name_cache'
);
$template->assign([
  'ADMIN_PAGE_TITLE' => l10n('Edit album').' <strong>'.(string) $category_name.'</strong>',
  'ADMIN_PAGE_OBJECT_ID' => '#'.(string) $category['id'],
]);

if ('properties' == $page['tab']) {
    include(PHPWG_ROOT_PATH.'admin/cat_modify.php');
} elseif ('sort_order' == $page['tab']) {
    include(PHPWG_ROOT_PATH.'admin/element_set_ranks.php');
} elseif ('permissions' == $page['tab']) {
    $_GET['cat'] = $_GET['cat_id'];
    include(PHPWG_ROOT_PATH.'admin/cat_perm.php');
} else {
    include(PHPWG_ROOT_PATH.'admin/album_'.(string) $page['tab'].'.php');
}
