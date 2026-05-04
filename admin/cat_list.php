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

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

trigger_notify('loc_begin_cat_list');

if (!empty($_POST) or isset($_GET['delete'])) {
    check_pwg_token();
}

$sort_orders = [
  'name ASC' => l10n('Album name, A &rarr; Z'),
  'name DESC' => l10n('Album name, Z &rarr; A'),
  'date_creation DESC' => l10n('Date created, new &rarr; old').' '.l10n('(determined from photos)'),
  'date_creation ASC' => l10n('Date created, old &rarr; new').' '.l10n('(determined from photos)'),
  'date_available DESC' => l10n('Date posted, new &rarr; old').' '.l10n('(determined from photos)'),
  'date_available ASC' => l10n('Date posted, old &rarr; new').' '.l10n('(determined from photos)'),
  ];

// +-----------------------------------------------------------------------+
// |                               functions                               |
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
            if (preg_match('/(^|,)'.$cat_id.'(,|$)/', is_scalar($uppercats) ? (string)$uppercats : '')) {
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

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

$categories = [];

$base_url = get_root_url().'admin.php?page=cat_list';
$navigation = '<a href="'.$base_url.'">';
$navigation .= l10n('Home');
$navigation .= '</a>';

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'list';
include(PHPWG_ROOT_PATH.'admin/include/albums_tab.inc.php');

// +-----------------------------------------------------------------------+
// |                    virtual categories management                      |
// +-----------------------------------------------------------------------+
// request to delete a virtual category
if (isset($_GET['delete']) and is_numeric($_GET['delete'])) {
    $photo_deletion_mode = 'no_delete';
    if (isset($_GET['photo_deletion_mode'])) {
        $photo_deletion_mode = $_GET['photo_deletion_mode'];
    }
    delete_categories([(int) $_GET['delete']], is_scalar($photo_deletion_mode) ? (string)$photo_deletion_mode : 'no_delete');

    $_SESSION['page_infos'] = [l10n('Virtual album deleted')];
    update_global_rank();
    invalidate_user_cache();

    $redirect_url = get_root_url().'admin.php?page=cat_list';
    if (isset($_GET['parent_id'])) {
        $redirect_url .= '&parent_id='.(is_scalar($_GET['parent_id']) ? (string)$_GET['parent_id'] : '');
    }
    redirect($redirect_url);
}
// request to add a virtual category
elseif (isset($_POST['submitAdd'])) {
    $output_create = create_virtual_category(
        is_scalar($_POST['virtual_name']) ? (string)$_POST['virtual_name'] : '',
        isset($_GET['parent_id']) ? (is_scalar($_GET['parent_id']) ? (string)$_GET['parent_id'] : null) : null
    );

    invalidate_user_cache();
    if (isset($output_create['error'])) {
        \Piwigo\Core\PageState::current()->addError(is_scalar($output_create['error']) ? (string)$output_create['error'] : '');
    } else {
        $edit_url = get_root_url().'admin.php?page=album-'.(is_scalar($output_create['id'] ?? '') ? (string)($output_create['id'] ?? '') : '');
        \Piwigo\Core\PageState::current()->addInfo((is_scalar($output_create['info'] ?? '') ? (string)($output_create['info'] ?? '') : '').' <a class="icon-pencil" href="'.$edit_url.'">'.l10n('Edit album').'</a>');
    }
}
// +-----------------------------------------------------------------------+
// |                            Navigation path                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['parent_id'])) {
    $navigation .= \Piwigo\Config\Config::levelSeparator();
    $raw_parent_id = $_GET['parent_id'];
    $navigation .= get_cat_display_name_from_id(
        is_scalar($raw_parent_id) ? (int) $raw_parent_id : 0,
        $base_url.'&amp;parent_id='
    );
}
// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('categories', 'cat_list.tpl');

$form_action = PHPWG_ROOT_PATH.'admin.php?page=cat_list';
if (isset($_GET['parent_id'])) {
    $form_action .= '&amp;parent_id='.(is_scalar($_GET['parent_id']) ? (string)$_GET['parent_id'] : '');
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
  FROM '.CATEGORIES_TABLE;
if (!isset($_GET['parent_id'])) {
    $query .= '
  WHERE id_uppercat IS NULL';
} else {
    $query .= '
  WHERE id_uppercat = '.(is_numeric($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0);
}
$query .= '
  ORDER BY `rank` ASC
;';
$categories = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'id');

// get the categories containing images directly
$categories_with_images = [];
if (count($categories)) {
    $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM '.IMAGE_CATEGORY_TABLE.'
  GROUP BY category_id
;';
    // WHERE category_id IN ('.implode(',', array_keys($categories)).')

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
        foreach (array_slice(explode(',', is_scalar($uppercats) ? (string)$uppercats : ''), 0, -1) as $uppercat_id) {
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
}

$template->assign('categories', []);
$base_url = get_root_url().'admin.php?page=';

if (isset($_GET['parent_id'])) {
    $template->assign(
        'PARENT_EDIT',
        $base_url.'album-'.(is_scalar($_GET['parent_id']) ? (string)$_GET['parent_id'] : '')
    );
}

foreach ($categories as $category) {
    $cat_list_url = $base_url.'cat_list';

    $self_url = $cat_list_url;
    if (isset($_GET['parent_id'])) {
        $self_url .= '&amp;parent_id='.(is_scalar($_GET['parent_id']) ? (string)$_GET['parent_id'] : '');
    }

    $tpl_cat =
      [
        'NAME'       =>
          trigger_change(
              'render_category_name',
              $category['name'],
              'admin_cat_list'
          ),
        'NB_PHOTOS' => $nb_photos_in[(string)(is_numeric($category['id']) ? (int)$category['id'] : 0)] ?? 0,
        'NB_SUB_PHOTOS' => $nb_sub_photos[(string)(is_numeric($category['id']) ? (int)$category['id'] : 0)] ?? 0,
        'NB_SUB_ALBUMS' => isset($subcats_of[(string)(is_numeric($category['id']) ? (int)$category['id'] : 0)]) ? count($subcats_of[(string)(is_numeric($category['id']) ? (int)$category['id'] : 0)]) : 0,
        'ID'         => $category['id'],
        'RANK'       => (is_numeric($category['rank'] ?? null) ? (int)($category['rank']) : 0) * 10,

        'U_JUMPTO'   => make_index_url(
            [
            'category' => $category,
            ]
        ),

        'U_CHILDREN' => $cat_list_url.'&amp;parent_id='.(is_scalar($category['id'] ?? null) ? (string)$category['id'] : ''),
        'U_EDIT'     => $base_url.'album-'.(is_scalar($category['id'] ?? null) ? (string)$category['id'] : ''),
        'U_ADD_PHOTOS_ALBUM' => $base_url.'photos_add&amp;album='.(is_scalar($category['id'] ?? null) ? (string)$category['id'] : ''),
        'U_MOVE' => $base_url.'albums#cat-'.(is_scalar($category['id'] ?? null) ? (string)$category['id'] : ''),

        'IS_VIRTUAL' => empty($category['dir']),
        'CAT_ADMIN_ACCESS' => cat_admin_access(is_numeric($category['id'] ?? null) ? (int)$category['id'] : 0),
      ];

    if (empty($category['dir'])) {
        $tpl_cat['U_DELETE'] = $self_url.'&amp;delete='.(is_scalar($category['id']) ? (string)$category['id'] : '');
        $tpl_cat['U_DELETE'] .= '&amp;pwg_token='.get_pwg_token();
    } else {
        if (\Piwigo\Config\Config::enableSynchronization()) {
            $tpl_cat['U_SYNC'] = $base_url.'site_update&amp;site=1&amp;cat_id='.(is_scalar($category['id']) ? (string)$category['id'] : '');
        }
    }

    $template->append('categories', $tpl_cat);
}

trigger_notify('loc_end_cat_list');

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'categories');
