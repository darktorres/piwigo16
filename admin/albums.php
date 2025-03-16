<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

$query = '
SELECT
    COUNT(*)
  FROM ' . CATEGORIES_TABLE . '
;';
list($albums_counter) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'list';
include(PHPWG_ROOT_PATH . 'admin/inc/albums_tab.php');

// +-----------------------------------------------------------------------+
// |                         categories auto order                         |
// +-----------------------------------------------------------------------+

$open_cat = $_GET['parent_id'] ?? -1;

$sort_orders = [
    'name ASC',
    'name DESC',
    'date_creation DESC',
    'date_creation ASC',
    'date_available DESC',
    'date_available ASC',
    'natural_order DESC',
    'natural_order ASC',
];

if (isset($_POST['simpleAutoOrder']) || isset($_POST['recursiveAutoOrder'])) {

    if (! in_array($_POST['order'], $sort_orders)) {
        die('Invalid sort order');
    }
    functions::check_input_parameter('id', $_POST, false, '/^-?\d+$/');

    $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE id_uppercat ' .
      (($_POST['id'] === '-1') ? 'IS NULL' : '= ' . $_POST['id']) . '
;';
    $category_ids = functions::array_from_query($query, 'id');

    if (isset($_POST['recursiveAutoOrder'])) {
        $category_ids = functions_category::get_subcat_ids($category_ids);
    }

    $categories = [];
    $sort = [];

    list($order_by_field, $order_by_asc) = explode(' ', $_POST['order']);

    $order_by_date = false;
    if (strpos($order_by_field, 'date_') === 0) {
        $order_by_date = true;

        $ref_dates = functions_admin::get_categories_ref_date(
            $category_ids,
            $order_by_field,
            $order_by_asc == 'ASC' ? 'min' : 'max'
        );
    }

    $query = '
SELECT id, name, id_uppercat
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    $result = functions_mysqli::pwg_query($query);
    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $row['name'] = functions_plugins::trigger_change('render_category_name', $row['name'], 'admin_cat_list');

        if ($order_by_date) {
            $sort[] = $ref_dates[$row['id']];
        } else {
            $sort[] = functions::remove_accents($row['name']);
        }

        $categories[] = [
            'id' => $row['id'],
            'id_uppercat' => $row['id_uppercat'],
        ];
    }

    array_multisort(
        $sort,
        $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR,
        $order_by_asc == 'ASC' ? SORT_ASC : SORT_DESC,
        $categories
    );

    functions_admin::save_categories_order($categories);

    $open_cat = $_POST['id'];
}

$template->assign('open_cat', $open_cat);

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('albums', 'albums.tpl');

$template->assign(
    [
        'F_ACTION' => functions_url::get_root_url() . 'admin.php?page=albums',
    ]
);

$template->assign('delay_before_autoOpen', $conf['album_move_delay_before_auto_opening']);

$template->assign('POS_PREF', $conf['newcat_default_position']); //TODO use user pref if it exists

// +-----------------------------------------------------------------------+
// |                          Album display                                |
// +-----------------------------------------------------------------------+

//Get all albums
$query = '
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM ' . CATEGORIES_TABLE . '
;';

$allAlbum = functions_mysqli::query2array($query);

//Make an id tree
$associatedTree = [];

foreach ($allAlbum as $album) {
    $album['name'] = functions_plugins::trigger_change('render_category_name', $album['name'], 'admin_cat_list');
    $album['lastmodified'] = functions::time_since($album['lastmodified'], 'year');

    $parents = explode(',', $album['uppercats']);
    $the_place = &$associatedTree[strval($parents[0])];
    for ($i = 1; $i < count($parents); $i++) {
        $the_place = &$the_place['children'][strval($parents[$i])];
    }
    $the_place['cat'] = $album;
}

// WARNING $user['forbidden_categories'] is 100% reliable only on gallery side because
// it's a cache variable. On administration side, if you modify public/private status
// of an album or change permissions, this variable is reset and not recalculated until
// you open the gallery. As this situation doesn't occur each time you use the
// administration, it's quite reliable but not as much as on gallery side.
$is_forbidden = array_fill_keys(@explode(',', $user['forbidden_categories']), 1);

//Make an ordered tree
$query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . IMAGE_CATEGORY_TABLE . '
  GROUP BY category_id
;';

$nb_photos_in = functions_mysqli::query2array($query, 'category_id', 'nb_photos');

$query = '
SELECT
    id,
    uppercats
  FROM ' . CATEGORIES_TABLE . '
;';
$all_categories = functions_mysqli::query2array($query, 'id', 'uppercats');

$subcats_of = [];

foreach ($all_categories as $id => $uppercats) {
    foreach (array_slice(explode(',', $uppercats), 0, -1) as $uppercat_id) {
        @$subcats_of[$uppercat_id][] = $id;
    }
}

$nb_sub_photos = [];
foreach ($subcats_of as $cat_id => $subcat_ids) {
    $nb_photos = 0;
    foreach ($subcat_ids as $id) {
        if (isset($nb_photos_in[$id])) {
            $nb_photos += $nb_photos_in[$id];
        }
    }

    $nb_sub_photos[$cat_id] = $nb_photos;
}

$template->assign(
    [
        'album_data' => functions_admin::assocToOrderedTree($associatedTree),
        'PWG_TOKEN' => functions::get_pwg_token(),
        'nb_albums' => count($allAlbum),
        'ADMIN_PAGE_TITLE' => functions::l10n('Albums'),
        'light_album_manager' => ($albums_counter > $conf['light_album_manager_threshold']) ? 1 : 0,
    ]
);

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'albums');
