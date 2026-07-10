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

// Bootstrap globals. $page is set by admin.php before including this
// panel; $conf/$template by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $conf, $page, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

trigger_notify('loc_begin_cat_list');

if (! empty($_POST) or isset($_GET['delete'])) {
    check_pwg_token();
}

$sort_orders = [
    'name ASC' => l10n('Album name, A &rarr; Z'),
    'name DESC' => l10n('Album name, Z &rarr; A'),
    'date_creation DESC' => l10n('Date created, new &rarr; old') . ' ' . l10n('(determined from photos)'),
    'date_creation ASC' => l10n('Date created, old &rarr; new') . ' ' . l10n('(determined from photos)'),
    'date_available DESC' => l10n('Date posted, new &rarr; old') . ' ' . l10n('(determined from photos)'),
    'date_available ASC' => l10n('Date posted, old &rarr; new') . ' ' . l10n('(determined from photos)'),
];

// +-----------------------------------------------------------------------+
// |                               functions                               |
// +-----------------------------------------------------------------------+
/**
 * @param array<int|string> $ids
 * @return array<int|string, mixed>
 */
function get_categories_ref_date(array $ids, string $field = 'date_available', string $minmax = 'max'): array
{
    // we need to work on the whole tree under each category, even if we don't
    // want to sort sub categories
    $category_ids = get_subcat_ids($ids);

    // search for the reference date of each album
    $query = '
SELECT
    category_id,
    ' . $minmax . '(' . $field . ') as ref_date
  FROM ' . IMAGE_CATEGORY_TABLE . '
    JOIN ' . IMAGES_TABLE . ' ON image_id = id
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
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    $uppercats_of = query2array($query, 'id', 'uppercats');

    foreach (array_keys($uppercats_of) as $cat_id) {
        // find the subcats
        $subcat_ids = [];

        foreach ($uppercats_of as $id => $uppercats) {
            if (! is_string($uppercats)) {
                continue;
            }
            if ((bool) preg_match('/(^|,)' . $cat_id . '(,|$)/', $uppercats)) {
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
    foreach ($ids as $id) {
        $return[$id] = $ref_dates[$id];
    }

    return $return;
}

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

// check_input_parameter() already validated (or killed the request) that
// $_GET['parent_id'], when present, matches PATTERN_ID (digits only) -- but
// it doesn't retype the superglobal, so we still narrow it once here and
// reuse this variable everywhere below instead of the raw mixed value.
$parent_id = null;
if (isset($_GET['parent_id']) and is_numeric($_GET['parent_id'])) {
    $parent_id = (int) $_GET['parent_id'];
}

$categories = [];

$base_url = get_root_url() . 'admin.php?page=cat_list';
$navigation = '<a href="' . $base_url . '">';
$navigation .= l10n('Home');
$navigation .= '</a>';

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'list';
include PHPWG_ROOT_PATH . 'admin/include/albums_tab.inc.php';

// +-----------------------------------------------------------------------+
// |                    virtual categories management                      |
// +-----------------------------------------------------------------------+
// request to delete a virtual category
if (isset($_GET['delete']) and is_numeric($_GET['delete'])) {
    $photo_deletion_mode = 'no_delete';
    if (isset($_GET['photo_deletion_mode']) and is_string($_GET['photo_deletion_mode'])) {
        $photo_deletion_mode = $_GET['photo_deletion_mode'];
    }
    delete_categories([(int) $_GET['delete']], $photo_deletion_mode);

    $_SESSION['page_infos'] = [l10n('Virtual album deleted')];
    update_global_rank();
    invalidate_user_cache();

    $redirect_url = get_root_url() . 'admin.php?page=cat_list';
    if ($parent_id !== null) {
        $redirect_url .= '&parent_id=' . $parent_id;
    }
    redirect($redirect_url);
}
// request to add a virtual category
elseif (isset($_POST['submitAdd'])) {
    $virtual_name = is_string($_POST['virtual_name'] ?? null) ? $_POST['virtual_name'] : '';
    $output_create = create_virtual_category(
        $virtual_name,
        $parent_id
    );

    invalidate_user_cache();
    // $page['errors']/$page['infos'] are always initialized to an array by
    // include/common.inc.php; re-assert it here so PHPStan can prove the
    // pushes below are array-like (same pattern as
    // admin/include/functions.php).
    $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
    $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];
    if (isset($output_create['error'])) {
        $page['errors'][] = $output_create['error'];
    } else {
        $edit_url = get_root_url() . 'admin.php?page=album-' . $output_create['id'];
        $page['infos'][] = $output_create['info'] . ' <a class="icon-pencil" href="' . $edit_url . '">' . l10n('Edit album') . '</a>';
    }
}
// +-----------------------------------------------------------------------+
// |                            Navigation path                            |
// +-----------------------------------------------------------------------+

if ($parent_id !== null) {
    // same fallback default as include/config_default.inc.php's
    // $conf['level_separator'] (' / '); see the identical pattern in
    // include/section_init.inc.php.
    $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';
    $navigation .= $level_separator;

    $navigation .= get_cat_display_name_from_id(
        $parent_id,
        $base_url . '&amp;parent_id='
    );
}
// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('categories', 'cat_list.tpl');

$form_action = PHPWG_ROOT_PATH . 'admin.php?page=cat_list';
if ($parent_id !== null) {
    $form_action .= '&amp;parent_id=' . $parent_id;
}
$sort_orders_checked = array_keys($sort_orders);

$template->assign([
    'ADMIN_PAGE_TITLE' => l10n('Album list management'),
    'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
    'F_ACTION' => $form_action,
    'PWG_TOKEN' => get_pwg_token(),
    'sort_orders' => $sort_orders,
    'sort_order_checked' => array_shift($sort_orders_checked),
]);

// +-----------------------------------------------------------------------+
// |                          Categories display                           |
// +-----------------------------------------------------------------------+

$categories = [];

$query = '
SELECT id, name, permalink, dir, `rank`, status
  FROM ' . CATEGORIES_TABLE;
if ($parent_id === null) {
    $query .= '
  WHERE id_uppercat IS NULL';
} else {
    $query .= '
  WHERE id_uppercat = ' . $parent_id;
}
$query .= '
  ORDER BY `rank` ASC
;';
$categories = hash_from_query($query, 'id');
/** @var array<int|string, array<string, string|null>> $categories */

// get the categories containing images directly
$categories_with_images = [];
if ((bool) count($categories)) {
    $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . IMAGE_CATEGORY_TABLE . '
  GROUP BY category_id
;';
    // WHERE category_id IN ('.implode(',', array_keys($categories)).')

    $nb_photos_in = query2array($query, 'category_id', 'nb_photos');

    $query = '
SELECT
    id,
    uppercats
  FROM ' . CATEGORIES_TABLE . '
;';
    $all_categories = query2array($query, 'id', 'uppercats');
    $subcats_of = [];

    foreach ($all_categories as $id => $uppercats) {
        if (! is_string($uppercats)) {
            continue;
        }
        foreach (array_slice(explode(',', $uppercats), 0, -1) as $uppercat_id) {
            $subcats_of[(int) $uppercat_id][] = $id;
        }
    }

    $nb_sub_photos = [];
    foreach ($subcats_of as $cat_id => $subcat_ids) {
        $nb_photos = 0;
        foreach ($subcat_ids as $id) {
            if (isset($nb_photos_in[$id]) and is_numeric($nb_photos_in[$id])) {
                $nb_photos += (int) $nb_photos_in[$id];
            }
        }

        $nb_sub_photos[$cat_id] = $nb_photos;
    }
}

$template->assign('categories', []);
$base_url = get_root_url() . 'admin.php?page=';

if ($parent_id !== null) {
    $template->assign(
        'PARENT_EDIT',
        $base_url . 'album-' . $parent_id
    );
}

foreach ($categories as $category) {
    // 'id' is the CATEGORIES_TABLE primary key (NOT NULL, auto-increment) --
    // it is always a numeric string here; this is a real guard, not dead
    // code, since query2array()'s return type is generically string|null
    // for every column.
    if (! is_numeric($category['id'])) {
        continue;
    }
    $cat_id = (int) $category['id'];

    $cat_list_url = $base_url . 'cat_list';

    $self_url = $cat_list_url;
    if ($parent_id !== null) {
        $self_url .= '&amp;parent_id=' . $parent_id;
    }

    $tpl_cat =
      [
          'NAME' => trigger_change(
              'render_category_name',
              $category['name'],
              'admin_cat_list'
          ),
          'NB_PHOTOS' => $nb_photos_in[$cat_id] ?? 0,
          'NB_SUB_PHOTOS' => $nb_sub_photos[$cat_id] ?? 0,
          'NB_SUB_ALBUMS' => isset($subcats_of[$cat_id]) ? count($subcats_of[$cat_id]) : 0,
          'ID' => $cat_id,
          'RANK' => is_numeric($category['rank']) ? ((int) $category['rank']) * 10 : 0,

          'U_JUMPTO' => make_index_url(
              [
                  'category' => $category,
              ]
          ),

          'U_CHILDREN' => $cat_list_url . '&amp;parent_id=' . $cat_id,
          'U_EDIT' => $base_url . 'album-' . $cat_id,
          'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . $cat_id,
          'U_MOVE' => $base_url . 'albums#cat-' . $cat_id,

          'IS_VIRTUAL' => empty($category['dir']),
          'CAT_ADMIN_ACCESS' => cat_admin_access($cat_id),
      ];

    if (empty($category['dir'])) {
        $tpl_cat['U_DELETE'] = $self_url . '&amp;delete=' . $cat_id;
        $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . get_pwg_token();
    } else {
        if ((bool) $conf['enable_synchronization']) {
            $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . $cat_id;
        }
    }

    $template->append('categories', $tpl_cat);
}

trigger_notify('loc_end_cat_list');

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'categories');
