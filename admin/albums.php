<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $page is set by admin.php before including this page;
// the rest by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

$query = '
SELECT
    COUNT(*)
  FROM ' . Tables::categories() . '
;';
$row = pwg_db_fetch_row(pwg_query($query));
assert($row !== null);
[$albums_counter] = $row;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Administrator);

check_input_parameter('parent_id', $_GET, false, ValidationPattern::ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'list';
include PHPWG_ROOT_PATH . 'admin/include/albums_tab.inc.php';

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

    $post_order = $_POST['order'] ?? null;
    if (! is_string($post_order) || ! in_array($post_order, $sort_orders)) {
        die('Invalid sort order');
    }
    check_input_parameter('id', $_POST, false, '/^-?\d+$/');

    // check_input_parameter() above fatal_error()s on a non-scalar or
    // non-matching value, but only narrows the type on its own end; $_POST
    // itself is still mixed to PHPStan, so re-derive the validated string here.
    $post_id = $_POST['id'] ?? null;
    $post_id = is_scalar($post_id) ? (string) $post_id : '';

    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' .
      (($post_id === '-1') ? 'IS NULL' : '= ' . $post_id) . '
;';
    $category_ids = array_from_query($query, 'id');
    // 'id' is Tables::categories()'s primary key column, always a numeric
    // string per this driver's string|null fetch convention -- filter out
    // the (never-actually-occurring) null case so downstream implode()/
    // get_subcat_ids() calls get values castable to string.
    $category_ids = array_values(array_filter($category_ids, is_string(...)));

    if (isset($_POST['recursiveAutoOrder'])) {
        $category_ids = get_subcat_ids($category_ids);
    }

    $categories = [];
    $sort = [];

    [$order_by_field, $order_by_asc] = explode(' ', $post_order);

    $order_by_date = false;
    if (str_starts_with($order_by_field, 'date_')) {
        $order_by_date = true;

        $ref_dates = get_categories_ref_date(
            $category_ids,
            $order_by_field,
            $order_by_asc == 'ASC' ? 'min' : 'max'
        );
    }

    $query = '
SELECT id, name, id_uppercat
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        /** @var array<string, string|null> $row */
        $rendered_name = trigger_change('render_category_name', $row['name'], 'admin_cat_list');
        $row['name'] = is_string($rendered_name) ? $rendered_name : $row['name'];

        if ($order_by_date) {
            // id is Tables::categories()'s NOT NULL primary key.
            assert($row['id'] !== null);
            $sort[] = $ref_dates[$row['id']];
        } else {
            $sort[] = remove_accents((string) $row['name']);
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

    save_categories_order($categories);

    $open_cat = $_POST['id'];
}

$template->assign('open_cat', $open_cat);

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('albums', 'albums.tpl');

$template->assign(
    [
        'F_ACTION' => get_root_url() . 'admin.php?page=albums',
    ]
);

$template->assign('delay_before_autoOpen', $conf['album_move_delay_before_auto_opening']);

$template->assign('POS_PREF', $conf['newcat_default_position']); // TODO use user pref if it exists

// +-----------------------------------------------------------------------+
// |                          Album display                                |
// +-----------------------------------------------------------------------+

// Get all albums
$query = '
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM ' . Tables::categories() . '
;';

$allAlbum = query2array($query);

// Make an id tree
$associatedTree = [];

foreach ($allAlbum as $album) {
    // Read every raw column (still string|null, per this driver's
    // fetch convention) before any reassignment below -- writing a mixed
    // value (trigger_change()'s return) into one offset of a generic
    // array<string, string|null> would otherwise widen every other key's
    // inferred type to mixed for the rest of this iteration.
    $parents = explode(',', (string) $album['uppercats']);
    $the_place = &$associatedTree[strval($parents[0])];
    if (! is_array($the_place)) {
        // Matches PHP's own null-to-array autovivification on the offset
        // write below -- made explicit so PHPStan can prove $the_place is
        // array-like at every depth of this dynamically built tree.
        $the_place = [];
    }
    /** @var array<string, mixed> $the_place */
    for ($i = 1; $i < count($parents); $i++) {
        $child_key = strval($parents[$i]);
        if (! is_array($the_place['children'] ?? null)) {
            $the_place['children'] = [];
        }
        if (! is_array($the_place['children'][$child_key] ?? null)) {
            $the_place['children'][$child_key] = [];
        }
        $the_place = &$the_place['children'][$child_key];
        /** @var array<string, mixed> $the_place */
    }

    $rendered_name = trigger_change('render_category_name', $album['name'], 'admin_cat_list');
    $album['name'] = is_string($rendered_name) ? $rendered_name : $album['name'];
    $album['lastmodified'] = time_since((string) $album['lastmodified'], 'year');

    $the_place['cat'] = $album;
}

// WARNING $user['forbidden_categories'] is 100% reliable only on gallery side because
// it's a cache variable. On administration side, if you modify public/private status
// of an album or change permissions, this variable is reset and not recalculated until
// you open the gallery. As this situation doesn't occur each time you use the
// administration, it's quite reliable but not as much as on gallery side.
// $user is array<string, mixed> -- re-derive a real string the same way
// $_POST values are validated above, rather than casting a still-mixed
// offset value directly (array/object-without-__toString would fatal).
$forbidden_categories = $user['forbidden_categories'] ?? null;
$forbidden_categories = is_scalar($forbidden_categories) ? (string) $forbidden_categories : '';
$is_forbidden = array_fill_keys(@explode(',', $forbidden_categories), 1);

// Make an ordered tree
/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function cmpCat(array $a, array $b): int
{
    return $a['rank'] <=> $b['rank'];
}

/**
 * @param array<int|string, mixed> $assocT
 * @return array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed}[]|array{rank: mixed, name: mixed, status: mixed, id: mixed, visible: mixed, uppercats: mixed, nb_images: mixed, last_updates: mixed, has_not_access: bool, nb_sub_photos: mixed, nb_subcats: int<0, max>, children: mixed}[]
 */
function assocToOrderedTree(array $assocT): array
{
    /**
     * @var array<int|string, string|null> $nb_photos_in
     * @var array<int|string, int> $nb_sub_photos
     * @var array<int|string, int> $is_forbidden
     */
    global $nb_photos_in, $nb_sub_photos, $is_forbidden;

    $orderedTree = [];

    foreach ($assocT as $cat) {
        if (! is_array($cat) || ! isset($cat['cat']) || ! is_array($cat['cat'])) {
            // Every reachable node of $associatedTree gets its 'cat' key
            // populated from its own category row while the tree is built
            // above (uppercats always ends with the category's own id, and
            // $allAlbum holds every category row) -- but that's an
            // algorithmic invariant, not something guaranteed by the type
            // system, so skip defensively instead of trusting it blindly.
            continue;
        }
        /** @var array<string, string|null> $cat_row */
        $cat_row = $cat['cat'];
        // 'id' is the category primary key (NOT NULL in schema); narrow
        // once here since it's reused below as an array key, which
        // requires a non-null type.
        $cat_id = is_string($cat_row['id']) ? $cat_row['id'] : '';

        $orderedCat = [];
        $orderedCat['rank'] = $cat_row['rank'];
        $orderedCat['name'] = $cat_row['name'];
        $orderedCat['status'] = $cat_row['status'];
        $orderedCat['id'] = $cat_row['id'];
        $orderedCat['visible'] = $cat_row['visible'];
        $orderedCat['uppercats'] = $cat_row['uppercats'];
        $orderedCat['nb_images'] = $nb_photos_in[$cat_id] ?? 0;
        $orderedCat['last_updates'] = $cat_row['lastmodified'];
        $orderedCat['has_not_access'] = isset($is_forbidden[$cat_id]);
        $orderedCat['nb_sub_photos'] = $nb_sub_photos[$cat_id] ?? 0;
        if (isset($cat['children']) && is_array($cat['children'])) {
            // Does not update when moving a node
            $orderedCat['nb_subcats'] = count($cat['children']);
            $orderedCat['children'] = assocToOrderedTree($cat['children']);
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
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';

$nb_photos_in = query2array($query, 'category_id', 'nb_photos');

$query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;';
$all_categories = query2array($query, 'id', 'uppercats');

$subcats_of = [];

foreach ($all_categories as $id => $uppercats) {
    foreach (array_slice(explode(',', (string) $uppercats), 0, -1) as $uppercat_id) {
        @$subcats_of[$uppercat_id][] = $id;
    }
}

$nb_sub_photos = [];
foreach ($subcats_of as $cat_id => $subcat_ids) {
    $nb_photos = 0;
    foreach ($subcat_ids as $id) {
        if (isset($nb_photos_in[$id])) {
            // COUNT(*) always yields a numeric string per this driver's
            // string|null fetch convention (see query2array()); cast so the
            // accumulator stays a provably-int running total.
            $nb_photos += (int) $nb_photos_in[$id];
        }
    }

    $nb_sub_photos[$cat_id] = $nb_photos;
}

$template->assign(
    [
        'album_data' => assocToOrderedTree($associatedTree),
        'PWG_TOKEN' => get_pwg_token(),
        'nb_albums' => count($allAlbum),
        'ADMIN_PAGE_TITLE' => l10n('Albums'),
        'light_album_manager' => ($albums_counter > $conf['light_album_manager_threshold']) ? 1 : 0,
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
 * @param array<int, mixed> $ids
 * @return array<int|string, mixed>
 */
function get_categories_ref_date(array $ids, string $field = 'date_available', string $minmax = 'max'): array
{
    // $ids is documented as array<int, mixed> because callers pass an
    // already-mixed source ($_POST/array_from_query() cascades); filter to
    // the int|string values get_subcat_ids() and the final lookup below
    // actually need, rather than trusting every element's shape.
    $numeric_ids = [];
    foreach ($ids as $id) {
        if (is_int($id) || is_string($id)) {
            $numeric_ids[] = $id;
        }
    }

    // we need to work on the whole tree under each category, even if we don't
    // want to sort sub categories
    $category_ids = get_subcat_ids($numeric_ids);

    // search for the reference date of each album
    $query = '
SELECT
    category_id,
    ' . $minmax . '(' . $field . ') as ref_date
  FROM ' . Tables::imageCategory() . '
    JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (' . implode(',', $category_ids) . ')
  GROUP BY category_id
;';
    $ref_dates = query2array($query, 'category_id', 'ref_date');

    // the iterate on all albums (having a ref_date or not) to find the
    // reference_date, with a search on sub-albums
    $query = '
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    $uppercats_of = query2array($query, 'id', 'uppercats');

    foreach (array_keys($uppercats_of) as $cat_id) {
        // find the subcats
        $subcat_ids = [];

        foreach ($uppercats_of as $id => $uppercats) {
            if ((bool) preg_match('/(^|,)' . $cat_id . '(,|$)/', (string) $uppercats)) {
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
            $ref_dates[$cat_id] = $minmax == 'max' ? max($to_compare) : min($to_compare);
        } else {
            $ref_dates[$cat_id] = null;
        }
    }

    // only return the list of $ids, not the sub-categories
    $return = [];
    foreach ($numeric_ids as $id) {
        $return[$id] = $ref_dates[$id];
    }

    return $return;
}
