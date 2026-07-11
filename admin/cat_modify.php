<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $admin_album_base_url is set by admin/album.php
// before including this panel; the rest by include/common.inc.php.
/**
 * @var string $admin_album_base_url
 * @var array<string, mixed> $conf
 * @var Template $template
 */
global $admin_album_base_url, $conf, $template;

include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

// get_complete_dir returns the concatenation of get_site_url and
// get_local_dir
// Example : "pets > rex > 1_year_old" is on the the same site as the
// Piwigo files and this category has 22 for identifier
// get_complete_dir(22) returns "./galleries/pets/rex/1_year_old/"
function get_complete_dir(int|string $category_id): string
{
    return get_site_url($category_id) . get_local_dir($category_id);
}

// get_local_dir returns an array with complete path without the site url
// Example : "pets > rex > 1_year_old" is on the the same site as the
// Piwigo files and this category has 22 for identifier
// get_local_dir(22) returns "pets/rex/1_year_old/"
function get_local_dir(int|string $category_id): string
{
    /** @var array<string, mixed> $page */
    global $page;

    $uppercats = '';
    $local_dir = '';

    // $page['plain_structure'] is a legacy category-structure cache key;
    // grepped the whole codebase and nothing ever populates it, so this
    // branch is dead in practice. Kept (and typed defensively rather than
    // assumed) in case a future cache layer starts filling it in.
    $plain_structure = $page['plain_structure'] ?? null;
    $structure_entry = is_array($plain_structure) ? ($plain_structure[$category_id] ?? null) : null;
    $structure_uppercats = is_array($structure_entry) ? ($structure_entry['uppercats'] ?? null) : null;

    if (is_string($structure_uppercats)) {
        $uppercats = $structure_uppercats;
    } else {
        $query = 'SELECT uppercats';
        $query .= ' FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $category_id;
        $query .= ';';
        $row = pwg_db_fetch_assoc(pwg_query($query));
        if (! is_array($row)) {
            throw new Exception(__FUNCTION__ . "(): category #{$category_id} not found");
        }
        $uppercats = is_string($row['uppercats']) ? $row['uppercats'] : '';
    }

    $upper_array = explode(',', $uppercats);

    $database_dirs = [];
    $query = 'SELECT id,dir';
    $query .= ' FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . $uppercats . ')';
    $query .= ';';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row_id = $row['id'];
        if (is_string($row_id)) {
            $database_dirs[$row_id] = $row['dir'];
        }
    }
    foreach ($upper_array as $id) {
        $local_dir .= $database_dirs[$id] . '/';
    }

    return $local_dir;
}

// retrieving the site url : "http://domain.com/gallery/" or
// simply "./galleries/"
function get_site_url(int|string $category_id): string
{
    global $page;

    $query = '
SELECT galleries_url
  FROM ' . SITES_TABLE . ' AS s,' . CATEGORIES_TABLE . ' AS c
  WHERE s.id = c.site_id
    AND c.id = ' . $category_id . '
;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    if (! is_array($row)) {
        throw new Exception(__FUNCTION__ . "(): category #{$category_id} not found");
    }
    $galleries_url = $row['galleries_url'];
    return is_string($galleries_url) ? $galleries_url : '';
}

function get_min_local_dir(?string $local_dir): ?string
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

// ---------------------------------------------------------------- verification
if (! isset($_GET['cat_id']) || ! is_numeric($_GET['cat_id'])) {
    trigger_error('missing cat_id param', E_USER_ERROR);
}

// --------------------------------------------------------- form criteria check

if (isset($redirect)) {
    redirect($admin_album_base_url . '-properties');
}

// nullable fields
foreach (['comment', 'dir', 'site_id', 'id_uppercat'] as $nullable) {
    /** @var array<string, mixed> $category */
    if (! isset($category[$nullable])) {
        $category[$nullable] = '';
    }
}

/** @var array<string, mixed> $category */
$category['is_virtual'] = empty($category['dir']) ? true : false;

$query = 'SELECT DISTINCT category_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE category_id = ' . $_GET['cat_id'] . '
  LIMIT 1';
$result = pwg_query($query);
$category['has_images'] = pwg_db_num_rows($result) > 0 ? true : false;

// number of sub-categories
// 'id' is the categories table primary key (NOT NULL); pwg_db_fetch_assoc()
// in admin/album.php (one file over the include boundary PHPStan can't see
// into) always returns it as a numeric string. Narrow once here and reuse
// throughout the rest of this file's many uses of the category id.
$category_id = is_numeric($category['id']) ? (int) $category['id'] : 0;
$subcat_ids = get_subcat_ids([$category_id]);

$category['nb_subcats'] = count($subcat_ids) - 1;

// Navigation path
$category_uppercats = is_string($category['uppercats']) ? $category['uppercats'] : '';
$navigation = get_cat_display_name_cache(
    $category_uppercats,
    get_root_url() . 'admin.php?page=album-'
);

// Parent navigation path
$uppercats_array = explode(',', $category_uppercats);
if (count($uppercats_array) > 1) {
    array_pop($uppercats_array);
    $parent_navigation = get_cat_display_name_cache(
        implode(',', $uppercats_array),
        get_root_url() . 'admin.php?page=album-'
    );
} else {
    $parent_navigation = l10n('Root');
}

// ----------------------------------------------------- template initialization
$template->set_filename('album_properties', 'cat_modify.tpl');

$base_url = get_root_url() . 'admin.php?page=';
$cat_list_url = $base_url . 'albums';

// 'id_uppercat' is one of the nullable fields normalized to '' above;
// otherwise it is the parent category id, a numeric string from the DB.
$category_id_uppercat = is_string($category['id_uppercat']) ? $category['id_uppercat'] : '';

$self_url = $cat_list_url;
if (! empty($category_id_uppercat)) {
    $self_url .= '&amp;parent_id=' . $category_id_uppercat;
}

// We show or hide this warning in JS
/** @var array<string, mixed> $page */
if (! is_array($page['warnings'] ?? null)) {
    $page['warnings'] = [];
}
$page['warnings'][] = l10n('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . l10n('Unlock it') . '</span>';

$template->assign(
    [
        'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
        'CATEGORIES_PARENT_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation)),
        'PARENT_CAT_ID' => ! empty($category_id_uppercat) ? $category_id_uppercat : 0,
        'CAT_ID' => $category_id,
        'CAT_NAME' => @htmlspecialchars(is_string($category['name']) ? $category['name'] : ''),
        'CAT_COMMENT' => @htmlspecialchars(is_string($category['comment']) ? $category['comment'] : ''),
        'IS_VISIBLE' => boolean_to_string($category['visible']),
        'CAT_ADMIN_ACCESS' => cat_admin_access($category_id),

        'U_DELETE' => $base_url . 'albums',

        'U_JUMPTO' => make_index_url(
            [
                'category' => $category,
            ]
        ),

        'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . $category_id,
        'U_CHILDREN' => $cat_list_url . '&amp;parent_id=' . $category_id,
        'U_MOVE' => $base_url . 'albums&amp;parent_id=' . $category_id,
        'U_ACTIVITY' => get_root_url() . 'admin.php?page=user_activity&album=' . $category_id,
    ]
);

if ((bool) $conf['activate_comments']) {
    $template->assign('CAT_COMMENTABLE', boolean_to_string($category['commentable']));
}

// manage album elements link
$image_count = 0;
$info_title = '';
if ($category['has_images']) {
    $template->assign(
        'U_MANAGE_ELEMENTS',
        $base_url . 'batch_manager&amp;filter=album-' . $category_id
    );

    $query = '
SELECT
    COUNT(image_id),
    MIN(DATE(date_available)),
    MAX(DATE(date_available))
  FROM ' . IMAGES_TABLE . '
    JOIN ' . IMAGE_CATEGORY_TABLE . ' ON image_id = id
  WHERE category_id = ' . $category_id . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    if (! is_array($row)) {
        throw new Exception("cat_modify.php: aggregate photo count/date query returned no row for category #{$category_id}");
    }
    [$image_count, $min_date, $max_date] = $row;
    // date_available is a NOT NULL column but the driver still types every
    // fetched value as string|null; format_date()'s phpDoc param forbids
    // null, so fall back to false (its "no date" sentinel) if that ever
    // isn't the case.
    $min_date = is_string($min_date) ? $min_date : false;
    $max_date = is_string($max_date) ? $max_date : false;

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
    ' . IMAGE_CATEGORY_TABLE . '
  WHERE
    category_id IN (' . implode(',', $subcat_ids) . ')
  ;';
$image_ids_recursive = query2array($query, null, 'image_id');

$category['nb_images_recursive'] = count($image_ids_recursive);

// date creation
$query = '
SELECT occured_on
  FROM `' . ACTIVITY_TABLE . '`
  WHERE object_id = ' . $category_id . '
    AND object = "album"
    AND action = "add"
';
$result = query2array($query);

if (count($result) > 0) {
    // occured_on is a nullable timestamp column; the driver always types
    // fetched values as string|null, so narrow with real fallbacks rather
    // than assuming a value is present.
    $occured_on = $result[0]['occured_on'];
    $template->assign(
        [
            'INFO_CREATION_SINCE' => time_since(is_string($occured_on) ? $occured_on : '', 'day', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
            'INFO_CREATION' => format_date(is_string($occured_on) ? $occured_on : false, ['day', 'month', 'year']),
        ]
    );
}

// Sub Albums
$query = '
SELECT COUNT(*)
  FROM `' . CATEGORIES_TABLE . '`
  WHERE id_uppercat = ' . $category_id . '
';
$result = query2array($query);

$template->assign(
    [
        'INFO_DIRECT_SUB' => l10n(
            '%d sub-albums',
            $result[0]['COUNT(*)']
        ),
    ]
);

// lastmodified is a NOT NULL DATETIME column, but the driver still types
// every fetched value as string|null; narrow with real fallbacks (matching
// the min_date/max_date/occured_on pattern above) rather than assuming.
$category_lastmodified = is_string($category['lastmodified']) ? $category['lastmodified'] : null;
$template->assign(
    [
        'INFO_ID' => l10n('Numeric identifier : %d', $category_id),
        'INFO_LAST_MODIFIED_SINCE' => time_since($category_lastmodified ?? '', 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
        'INFO_LAST_MODIFIED' => format_date($category_lastmodified ?? false, ['day', 'month', 'year']),
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
    'U_MANAGE_RANKS' => $base_url . 'element_set_ranks&amp;cat_id=' . $category_id,
    'CACHE_KEYS' => get_admin_client_cache_keys(['categories']),
]);

if (! (bool) $category['is_virtual']) {
    $category['cat_full_dir'] = get_complete_dir((int) $_GET['cat_id']);
    $category_full_dir = preg_replace('/\/$/', '', $category['cat_full_dir']);
    $template->assign(
        [
            'CAT_FULL_DIR' => $category_full_dir,
        ]
    );
    $template->assign('CAT_DIR_NAME', basename((string) $category_full_dir));
    $template->assign('CAT_MIN_DIR', get_min_local_dir($category_full_dir));

    if ((bool) $conf['enable_synchronization']) {
        $template->assign(
            'U_SYNC',
            $base_url . 'site_update&amp;site=' . (is_string($category['site_id']) ? $category['site_id'] : '') . '&amp;cat_id=' . $category_id
        );
    }

}

// representant management
// 'representative_picture_id' is a nullable FK column; the driver types it
// string|null (never int, even though the referenced images.id is an int
// PK), so narrow with a real fallback rather than assuming.
$category_representative_picture_id = is_string($category['representative_picture_id']) ? $category['representative_picture_id'] : 0;
if ($category['has_images'] or ! empty($category_representative_picture_id)) {
    $tpl_representant = [];

    // picture to display : the identified representant or the generic random
    // representant ?
    if (! empty($category_representative_picture_id)) {
        $tpl_representant['picture'] = get_category_representant_properties($category_representative_picture_id, IMG_MEDIUM);
    }

    // can the admin choose to set a new random representant ?
    $tpl_representant['ALLOW_SET_RANDOM'] = ($category['has_images'] ? true : false);

    // can the admin delete the current representant ?
    // the outer `if` above already guarantees
    // !empty($category_representative_picture_id) whenever
    // !$category['has_images'], since that's the only way its own
    // has_images-or-!empty(...) condition could be true here.
    if (
        ($category['has_images']
         and (bool) $conf['allow_random_representative'])
        or ! $category['has_images']) {
        $tpl_representant['ALLOW_DELETE'] = true;
    }
    $template->assign('representant', $tpl_representant);
}

if ((bool) $category['is_virtual']) {
    $template->assign('parent_category', empty($category_id_uppercat) ? [] : [$category_id_uppercat]);
}

$template->assign('PWG_TOKEN', get_pwg_token());

trigger_notify('loc_end_cat_modify');

// ----------------------------------------------------------- sending html code
$template->assign_var_from_handle('ADMIN_CONTENT', 'album_properties');
