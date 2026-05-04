<?php

declare(strict_types=1);
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


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

$albums_counter = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
    ->countAll();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'list';
include(PHPWG_ROOT_PATH.'admin/include/albums_tab.inc.php');

// +-----------------------------------------------------------------------+
// |                         categories auto order                         |
// +-----------------------------------------------------------------------+

$raw_open_cat = $_GET['parent_id'] ?? -1;
$open_cat = is_scalar($raw_open_cat) ? (int) $raw_open_cat : -1;

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

    if (!in_array($_POST['order'], $sort_orders)) {
        throw new \Piwigo\Exception\ValidationException('Invalid sort order');
    }
    check_input_parameter('id', $_POST, false, '/^-?\d+$/');

    $post_id_str = is_scalar($_POST['id']) ? (string) $_POST['id'] : '';
    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id_uppercat '.
      (($post_id_str === '-1') ? 'IS NULL' : '= '.$post_id_str).'
;';
    $category_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');
    $category_ids = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $category_ids);

    if (isset($_POST['recursiveAutoOrder'])) {
        $category_ids = get_subcat_ids($category_ids);
    }

    $categories = [];
    $sort = [];

    [$order_by_field, $order_by_asc] = explode(' ', is_scalar($_POST['order']) ? (string) $_POST['order'] : '');

    $order_by_date = false;
    if (str_starts_with($order_by_field, 'date_')) {
        $order_by_date = true;

        $ref_dates = get_categories_ref_date(
            array_map('intval', $category_ids),
            $order_by_field,
            'ASC' == $order_by_asc ? 'min' : 'max'
        );
    }

    foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->findByIds(array_map('intval', $category_ids)) as $row) {
        $row['name'] = trigger_change('render_category_name', $row['name'], 'admin_cat_list');

        if ($order_by_date) {
            $rowId = is_scalar($row['id']) ? (string) $row['id'] : '';
            $sort[] = $ref_dates[$rowId] ?? null;
        } else {
            $sort[] = remove_accents(is_scalar($row['name']) ? (string) $row['name'] : '');
        }

        $categories[] = [
          'id' => $row['id'],
          'id_uppercat' => $row['id_uppercat'],
          ];
    }

    array_multisort(
        $sort,
        $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR,
        'ASC' == $order_by_asc ? SORT_ASC : SORT_DESC,
        $categories
    );

    save_categories_order($categories);

    $open_cat = is_scalar($_POST['id']) ? (string) $_POST['id'] : '-1';
}

$template->assign('open_cat', $open_cat);

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('albums', 'albums.tpl');

$template->assign(
    [
    'F_ACTION' => get_root_url().'admin.php?page=albums',
    ]
);

$template->assign('delay_before_autoOpen', \Piwigo\Config\Config::albumMoveDelayBeforeAutoOpening());

$template->assign('POS_PREF', \Piwigo\Config\Config::newcatDefaultPosition()); //TODO use user pref if it exists

// +-----------------------------------------------------------------------+
// |                          Album display                                |
// +-----------------------------------------------------------------------+

//Get all albums
$query = '
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM '.CATEGORIES_TABLE.'
;';

$allAlbum = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

//Make an id tree
$associatedTree = [];

foreach ($allAlbum as $album) {
    $album['name'] = trigger_change('render_category_name', $album['name'], 'admin_cat_list');
    $album['lastmodified'] = time_since(is_string($album['lastmodified']) || is_int($album['lastmodified']) ? $album['lastmodified'] : null, 'year');

    $parents = explode(',', is_scalar($album['uppercats']) ? (string) $album['uppercats'] : '');
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
$is_forbidden = array_fill_keys(explode(',', (string) ($user['forbidden_categories'] ?? '')), 1);

//Make an ordered tree
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function cmpCat(array $a, array $b): int
{
    return $a['rank'] <=> $b['rank'];
}

/**
 * @return array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed}[]|array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed, nb_subcats: int<0, max>, children: mixed}[]
 */
/**
 * @param array<mixed> $assocT
 * @return array<mixed>
 */
function assocToOrderedTree(array $assocT): array
{
    $nb_photos_in = is_array($GLOBALS['nb_photos_in'] ?? null) ? $GLOBALS['nb_photos_in'] : [];
    $nb_sub_photos = is_array($GLOBALS['nb_sub_photos'] ?? null) ? $GLOBALS['nb_sub_photos'] : [];
    $is_forbidden = is_array($GLOBALS['is_forbidden'] ?? null) ? $GLOBALS['is_forbidden'] : [];

    $orderedTree = [];

    foreach ($assocT as $cat) {
        if (!is_array($cat) || !is_array($cat['cat'] ?? null)) {
            continue;
        }
        /** @var array<string,mixed> $catData */
        $catData = $cat['cat'];
        $orderedCat = [];
        $orderedCat['rank'] = $catData['rank'];
        $orderedCat['name'] = $catData['name'];
        $orderedCat['status'] = $catData['status'];
        $orderedCat['id'] = $catData['id'];
        $orderedCat['visible'] = $catData['visible'];
        $orderedCat['uppercats'] = $catData['uppercats'];
        $catId = is_scalar($catData['id']) ? (string) $catData['id'] : '';
        $orderedCat['nb_images'] = $nb_photos_in[$catId] ?? 0;
        $orderedCat['last_updates'] = $catData['lastmodified'];
        $orderedCat['has_not_access'] = isset($is_forbidden[$catId]);
        $orderedCat['nb_sub_photos'] = $nb_sub_photos[$catId] ?? 0;
        if (isset($cat['children'])) {
            //Does not update when moving a node
            $children = is_array($cat['children']) ? $cat['children'] : [];
            $orderedCat['nb_subcats'] = count($children);
            $orderedCat['children'] = assocToOrderedTree($children);
        }
        array_push($orderedTree, $orderedCat);
    }
    usort($orderedTree, cmpCat(...));
    return $orderedTree;
}

$query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM '.IMAGE_CATEGORY_TABLE.'
  GROUP BY category_id
;';

$nb_photos_in = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'nb_photos', 'category_id');

$query = '
SELECT
    id,
    uppercats
  FROM '.CATEGORIES_TABLE.'
;';
$all_categories = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'uppercats', 'id');

$subcats_of = [];

foreach ($all_categories as $id => $uppercats) {
    foreach (array_slice(explode(',', is_scalar($uppercats) ? (string) $uppercats : ''), 0, -1) as $uppercat_id) {
        $subcats_of[$uppercat_id][] = $id;
    }
}

$nb_sub_photos = [];
foreach ($subcats_of as $cat_id => $subcat_ids) {
    $nb_photos = 0;
    foreach ($subcat_ids as $id) {
        if (isset($nb_photos_in[$id])) {
            $nb_photos += is_numeric($nb_photos_in[$id]) ? (int) $nb_photos_in[$id] : 0;
        }
    }

    $nb_sub_photos[$cat_id] = $nb_photos;
}

$nb_albums = count($allAlbum);
$light_album_manager = ($albums_counter > \Piwigo\Config\Config::lightAlbumManagerThreshold()) ? 1 : 0;
$album_tree = assocToOrderedTree($associatedTree);

$template->assign(
    [
    'album_data' => $album_tree,
    'PWG_TOKEN' => get_pwg_token(),
    'nb_albums' => $nb_albums,
    'ADMIN_PAGE_TITLE' => l10n('Albums'),
    'light_album_manager' => $light_album_manager,
    'page_data_json' => json_encode([
        'data'                       => $album_tree,
        'pwg_token'                  => get_pwg_token(),
        'openCat'                    => (int) $open_cat,
        'nb_albums'                  => $nb_albums,
        'light_album_manager'        => (bool) $light_album_manager,
        'delay_autoOpen'             => \Piwigo\Config\Config::albumMoveDelayBeforeAutoOpening(),
        'x_nb_subcats'               => l10n('%d sub-albums'),
        'x_nb_images'                => l10n('%d photos'),
        'x_nb_sub_photos'            => l10n('%d pictures in sub-albums'),
        'str_are_you_sure'           => l10n("The status of the album '%s' and its sub-albums will change to private. Are you sure?"),
        'str_yes_change_parent'      => l10n('Yes change parent anyway'),
        'str_no_change_parent'       => l10n("No, don't move this album here"),
        'str_albs_drag_drop'         => l10n('Drag and drop to reorder albums'),
        'delete_album_with_name'     => l10n('Delete album "%s".'),
        'delete_album_with_subs'     => l10n('Delete album "%s" and its %d sub-albums.'),
        'has_images_associated_outside' => l10n('delete album and all %d photos, even the %d associated to other albums'),
        'has_images_becomming_orphans'  => l10n('delete album and the %d orphan photos'),
        'rename_item'                => l10n('Rename "%s"'),
        'str_add_album'              => l10n('Add Album'),
        'str_edit_album'             => l10n('Edit album'),
        'str_add_photo'              => l10n('Add Photos'),
        'str_visit_gallery'          => l10n('Visit Gallery'),
        'str_sort_order'             => l10n('Automatic sort order'),
        'str_delete_album'           => l10n('Delete album'),
        'str_root_order'             => l10n('Apply to root albums'),
        'str_sub_album_order'        => l10n('Apply to direct sub-albums'),
        'str_album_name_empty'       => l10n('Album name must not be empty'),
        'add_album_root_title'       => l10n('Create a new album at root'),
        'add_sub_album_of'           => l10n('Create a sub-album of "%s"'),
        'tiptip_locked_album'        => l10n('Locked album'),
        'str_albums_found'           => l10n('<b>%d</b> albums found'),
        'str_album_found'            => l10n('<b>1</b> album found'),
        'str_result_limit'           => l10n('<b>%d+</b> albums found, try to refine the search'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
  ]
);

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'albums');

// +-----------------------------------------------------------------------+
// |                              functions                                |
// +-----------------------------------------------------------------------+
/**
 * @return mixed[]
 */
/**
 * @param int[]|int|string $ids
 * @return array<mixed>
 */
function get_categories_ref_date(array|int|string $ids, string $field = 'date_available', string $minmax = 'max'): array
{
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    // we need to work on the whole tree under each category, even if we don't
    // want to sort sub categories
    $category_ids = get_subcat_ids($ids);

    // search for the reference date of each album
    $query = '
SELECT
    category_id,
    '.$minmax.'('.$field.') as ref_date
  FROM '.IMAGE_CATEGORY_TABLE.'
    JOIN '.IMAGES_TABLE.' ON image_id = id
  WHERE category_id IN ('.implode(',', $category_ids).')
  GROUP BY category_id
;';
    $ref_dates = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'ref_date', 'category_id');

    // the iterate on all albums (having a ref_date or not) to find the
    // reference_date, with a search on sub-albums
    $query = '
SELECT
    id,
    uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $category_ids).')
;';
    $uppercats_of = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'uppercats', 'id');

    foreach (array_keys($uppercats_of) as $cat_id) {
        // find the subcats
        $subcat_ids = [];

        foreach ($uppercats_of as $id => $uppercats) {
            if (preg_match('/(^|,)'.$cat_id.'(,|$)/', is_scalar($uppercats) ? (string) $uppercats : '')) {
                $subcat_ids[] = $id;
            }
        }

        $to_compare = [];
        foreach ($subcat_ids as $id) {
            if (isset($ref_dates[$id])) {
                $to_compare[] = $ref_dates[$id];
            }
        }

        if (count($to_compare) > 0) {
            $ref_dates[$cat_id] = 'max' == $minmax ? max($to_compare) : min($to_compare);
        } else {
            $ref_dates[$cat_id] = null;
        }
    }

    // only return the list of $ids, not the sub-categories
    $return = [];
    foreach ($ids as $id) {
        $return[$id] = $ref_dates[$id] ?? null;
    }

    return $return;
}
