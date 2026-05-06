<?php

declare(strict_types=1);

use Piwigo\Url\UrlGenerator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $category, $admin_album_base_url;




// get_complete_dir returns the concatenation of get_site_url and
// get_local_dir
// Example : "pets > rex > 1_year_old" is on the the same site as the
// Piwigo files and this category has 22 for identifier
// get_complete_dir(22) returns "./galleries/pets/rex/1_year_old/"
function get_complete_dir(string $category_id): string
{
    return get_site_url($category_id).get_local_dir($category_id);
}

// get_local_dir returns an array with complete path without the site url
// Example : "pets > rex > 1_year_old" is on the the same site as the
// Piwigo files and this category has 22 for identifier
// get_local_dir(22) returns "pets/rex/1_year_old/"
function get_local_dir(string $category_id): string
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $uppercats = '';
    $local_dir = '';

    $plainStructure = is_array($page['plain_structure'] ?? null) ? $page['plain_structure'] : [];
    $catEntry = is_array($plainStructure[$category_id] ?? null) ? $plainStructure[$category_id] : [];
    if (isset($catEntry['uppercats']) && is_scalar($catEntry['uppercats'])) {
        $uppercats = (string) $catEntry['uppercats'];
    } else {
        $catRepo = ServiceLocator::get(CategoryRepository::class);
        $uppercats = $catRepo->findUppercatsStringById((int) $category_id) ?? '';
    }

    $upper_array = explode(',', $uppercats);

    $catRepo2 = ServiceLocator::get(CategoryRepository::class);
    $database_dirs = $catRepo2->findIdDirMap(array_map(intval(...), $upper_array));
    foreach ($upper_array as $id) {
        $local_dir .= $database_dirs[$id].'/';
    }

    return $local_dir;
}

// retrieving the site url : "http://domain.com/gallery/" or
// simply "./galleries/"
function get_site_url(string $category_id): string
{
    return ServiceLocator::get(CategoryRepository::class)
        ->findGalleriesUrlByCategoryId((int) $category_id) ?? '';
}

function get_min_local_dir(string $local_dir): string
{
    $full_dir = explode('/', (string) $local_dir);
    if (count($full_dir) <= 3) {
        return $local_dir;
    } else {
        $start = $full_dir[0] . '/' . $full_dir[1];
        $end = end($full_dir);
        $concat = $start . '/&hellip;/' . $end;
        return $concat;
    }
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

trigger_notify('loc_begin_cat_modify');

//---------------------------------------------------------------- verification
if (!isset($_GET['cat_id']) || !is_numeric($_GET['cat_id'])) {
    trigger_error('missing cat_id param', E_USER_ERROR);
}

//--------------------------------------------------------- form criteria check

if (isset($redirect)) {
    redirect($admin_album_base_url.'-properties');
}

// nullable fields
foreach (['comment','dir','site_id', 'id_uppercat'] as $nullable) {
    if (!isset($category[$nullable])) {
        $category[$nullable] = '';
    }
}

$category['is_virtual'] = empty($category['dir']) ? true : false;

$category['has_images'] = ServiceLocator::get(CategoryRepository::class)
    ->hasCategoryImages((int) $_GET['cat_id']);

// number of sub-categories
$subcat_ids = get_subcat_ids([$category['id']]);

$category['nb_subcats'] = count($subcat_ids) - 1;

// Navigation path
$navigation = get_cat_display_name_cache(
    $category['uppercats'],
    ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-'
);

// Parent navigation path
$uppercats_array = explode(',', (string) $category['uppercats']);
if (count($uppercats_array) > 1) {
    array_pop($uppercats_array);
    $parent_navigation = get_cat_display_name_cache(
        implode(',', $uppercats_array),
        ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-'
    );
} else {
    $parent_navigation = l10n('Root');
}

//----------------------------------------------------- template initialization
$template->set_filename('album_properties', 'cat_modify.tpl');

$base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
$cat_list_url = $base_url.'albums';

$self_url = $cat_list_url;
if (!empty($category['id_uppercat'])) {
    $self_url .= '&amp;parent_id='.$category['id_uppercat'];
}

// We show or hide this warning in JS
PageState::current()->addWarning(l10n('This album is currently locked, visible only to administrators.').'<span class="icon-cone unlock-album">'.l10n('Unlock it').'</span>');

$template->assign(
    [
    'CATEGORIES_NAV'     => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
    'CATEGORIES_PARENT_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation)),
    'PARENT_CAT_ID'      => !empty($category['id_uppercat']) ? $category['id_uppercat'] : 0,
    'CAT_ID'             => $category['id'],
    'CAT_NAME'           => htmlspecialchars((string) $category['name']),
    'CAT_COMMENT'        => htmlspecialchars((string) $category['comment']),
    'IS_VISIBLE'          => BoolUtil::toString($category['visible']),
    'CAT_ADMIN_ACCESS'   => cat_admin_access($category['id']),

    'U_DELETE' => $base_url.'albums',

    'U_JUMPTO' => make_index_url(
        [
        'category' => $category,
        ]
    ),

    'U_ADD_PHOTOS_ALBUM' => $base_url.'photos_add&amp;album='.$category['id'],
    'U_CHILDREN' => $cat_list_url.'&amp;parent_id='.$category['id'],
    'U_MOVE' => $base_url.'albums&amp;parent_id='.$category['id'],
    'U_ACTIVITY' => ServiceLocator::get(UrlGenerator::class)->admin('user_activity') . '&album='.$category['id'],
    ]
);

if (Config::activateComments()) {
    $template->assign('CAT_COMMENTABLE', BoolUtil::toString($category['commentable']));
}

// manage album elements link
$image_count = 0;
$info_title = '';
if ($category['has_images']) {
    $template->assign(
        'U_MANAGE_ELEMENTS',
        $base_url.'batch_manager&amp;filter=album-'.$category['id']
    );

    [$image_count, $min_date, $max_date] = ServiceLocator::get(CategoryRepository::class)
        ->findImageStats(is_numeric($category['id']) ? (int) $category['id'] : 0);
    $min_date = (string)$min_date;
    $max_date = (string)$max_date;

    if ($min_date == $max_date) {
        $info_title = l10n(
            'This album contains %d photos, added on %s.',
            $image_count,
            format_date($min_date)
        );
    } else {
        $info_title = l10n(
            'This album contains %d photos, added between %s and %s.',
            $image_count,
            format_date($min_date),
            format_date($max_date)
        );
    }

}
$info_photos = l10n('%d photos', $image_count);

$template->assign(
    [
    'INFO_PHOTO' => $info_photos,
    'INFO_TITLE' => $info_title,
    ]
);

// total number of images under this category (including sub-categories)
$query = '
SELECT DISTINCT
    (image_id)
  FROM 
    '.IMAGE_CATEGORY_TABLE.'
  WHERE 
    category_id IN ('.implode(',', $subcat_ids).')
  ;';
$image_ids_recursive = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');

$category['nb_images_recursive'] = count($image_ids_recursive);

// date creation
$query = '
SELECT occured_on
  FROM `'.ACTIVITY_TABLE.'`
  WHERE object_id = '.$category['id'].' 
    AND object = "album"
    AND action = "add"
';
$result = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

if (count($result) > 0) {
    $occurred_on = is_scalar($result[0]['occured_on']) ? (string)$result[0]['occured_on'] : '';
    $template->assign(
        [
        'INFO_CREATION_SINCE' => time_since($occurred_on, 'day', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
        'INFO_CREATION' => format_date($occurred_on, ['day', 'month','year']),
        ]
    );
}

// Sub Albums
$query = '
SELECT COUNT(*)
  FROM `'.CATEGORIES_TABLE.'`
  WHERE id_uppercat = '.$category['id'].'
';
$result = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();


$template->assign(
    [
    'INFO_DIRECT_SUB' => l10n(
        '%d sub-albums',
        $result[0]['COUNT(*)']
    ),
    ]
);

$template->assign(
    [
  'INFO_ID' => l10n('Numeric identifier : %d', $category['id']),
  'INFO_LAST_MODIFIED_SINCE' => time_since($category['lastmodified'], 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
  'INFO_LAST_MODIFIED' => format_date($category['lastmodified'], ['day', 'month','year']),
  'INFO_IMAGES_RECURSIVE' => l10n(
      '%d including sub-albums',
      $category['nb_images_recursive']
  ),
  'INFO_SUBCATS' => l10n(
      '%d in whole branch',
      $category['nb_subcats']
  ),

  'NB_SUBCATS' => $category['nb_subcats'],
  ]
);

$template->assign([
  'U_MANAGE_RANKS' => $base_url.'element_set_ranks&amp;cat_id='.$category['id'],
  'CACHE_KEYS' => get_admin_client_cache_keys(['categories']),
  ]);

if (!$category['is_virtual']) {
    $category['cat_full_dir'] = get_complete_dir((string) $_GET['cat_id']);
    $category_full_dir = preg_replace('/\/$/', '', (string) $category['cat_full_dir']);
    $template->assign(
        [
        'CAT_FULL_DIR' => $category_full_dir,
        ]
    );
    $template->assign('CAT_DIR_NAME', basename((string) $category_full_dir));
    $template->assign('CAT_MIN_DIR', get_min_local_dir($category_full_dir ?? ''));

    if (Config::enableSynchronization()) {
        $template->assign(
            'U_SYNC',
            $base_url.'site_update&amp;site='.$category['site_id'].'&amp;cat_id='.$category['id']
        );
    }

}

// representant management
if ($category['has_images'] or !empty($category['representative_picture_id'])) {
    $tpl_representant = [];

    // picture to display : the identified representant or the generic random
    // representant ?
    if (!empty($category['representative_picture_id'])) {
        $tpl_representant['picture'] = get_category_representant_properties($category['representative_picture_id'], IMG_MEDIUM);
    }

    // can the admin choose to set a new random representant ?
    $tpl_representant['ALLOW_SET_RANDOM'] = ($category['has_images'] ? true : false);

    // can the admin delete the current representant ?
    if (
        ($category['has_images']
         and Config::allowRandomRepresentative())
        or
        (!$category['has_images']
         and !empty($category['representative_picture_id']))) {
        $tpl_representant['ALLOW_DELETE'] = true;
    }
    $template->assign('representant', $tpl_representant);
}

if ($category['is_virtual']) {
    $template->assign('parent_category', empty($category['id_uppercat']) ? [] : [$category['id_uppercat']]);
}

$template->assign('PWG_TOKEN', get_pwg_token());

$pwg_token = get_pwg_token();
$parent_cat_id = !empty($category['id_uppercat']) ? (int) $category['id_uppercat'] : 0;
$template->assign('page_data_json', json_encode([
    'album_id'                            => (int) $category['id'],
    'album_name'                          => $category['name'] ?? '',
    'default_parent_album'                => $parent_cat_id,
    'is_visible'                          => BoolUtil::toString($category['visible']),
    'nb_sub_albums'                       => $category['nb_subcats'],
    'parent_album'                        => $parent_cat_id,
    'related_categories_ids'              => [(string) $category['id'], (string) $parent_cat_id],
    'u_delete'                            => $base_url.'albums',
    'pwg_token'                           => $pwg_token,
    'str_cancel'                          => l10n('No, I have changed my mind'),
    'str_delete_album'                    => l10n('Delete album'),
    'str_delete_album_and_his_x_subalbums' => l10n('Delete album "%s" and its %d sub-albums.'),
    'str_just_now'                        => l10n('Just now'),
    'str_dont_delete_photos'              => l10n('delete only album, not photos'),
    'str_delete_orphans'                  => l10n('delete album and the %d orphan photos'),
    'str_delete_all_photos'               => l10n('delete album and all %d photos, even the %d associated to other albums'),
    'str_album_comment_allow'             => l10n('Comments allowed for sub-albums'),
    'str_album_comment_disallow'          => l10n('Comments disallowed for sub-albums'),
    'str_modal_ab'                        => l10n('New parent album'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

trigger_notify('loc_end_cat_modify');

//----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'album_properties');
