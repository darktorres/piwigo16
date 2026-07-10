<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// --------------------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $user
 */
global $conf, $user;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

trigger_notify('loc_begin_search');

// +-----------------------------------------------------------------------+
// | Create a default search                                               |
// +-----------------------------------------------------------------------+

$search = [
    'mode' => 'AND',
    'fields' => [],
];

// list of filters in user preferences
$raw_filters_views = conf_get_param('filters_views', $conf['default_filters_views']);
$filters_views = (is_array($raw_filters_views) or is_string($raw_filters_views))
    ? safe_unserialize($raw_filters_views)
    : [];

// change the name of the keys so that they can be used with this part of the program
$filter_rename_for = [
    'words' => 'allwords',
    'post_date' => 'date_posted',
    'creation_date' => 'date_created',
    'album' => 'cat',
    'file_type' => 'filetypes',
    'ratio' => 'ratios',
    'rating' => 'ratings',
    'file_size' => 'filesize',
];

$filters_conf = [];
if (is_array($filters_views)) {
    foreach ($filters_views as $filter_name => $filter_value) {
        $key = $filter_rename_for[$filter_name] ?? $filter_name;

        $filters_conf[$key] = $filter_value;
    }
}

// get all default filters
$default_fields = [];
foreach ($filters_conf as $filt_name => $filt_conf) {
    if (! is_array($filt_conf)) {
        continue;
    }

    if (isset($filt_conf['default'])) {
        if ($filt_conf['default'] == true) {
            $default_fields[] = $filt_name;
        }
    }
}

if (is_a_guest() or is_generic() or $filters_conf['last_filters_conf'] == false) {
    $fields = $default_fields;
} else {
    $raw_fields = userprefs_get_param('gallery_search_filters', $default_fields);
    $fields = is_array($raw_fields) ? $raw_fields : $default_fields;
}

$words = [];
if (! empty($_GET['q']) and is_string($_GET['q'])) {
    $words = split_allwords($_GET['q']) ?? [];
}

if (count($words) > 0 or in_array('allwords', $fields)) {
    $search['fields']['allwords'] = [
        'words' => $words,
        'mode' => 'AND',
        'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
    ];
}

$cat_ids = [];
if (isset($_GET['cat_id'])) {
    check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

    $cat_id_value = $_GET['cat_id'];
    if (! is_scalar($cat_id_value)) {
        fatal_error('[Hacking attempt] the input parameter "cat_id" is not valid');
    }

    $cat_id = (string) $cat_id_value;

    // $user['id'] is always numeric here: build_user() (include/user.inc.php)
    // sets it to an int derived from $conf['guest_id'] or the session user
    // id; this is a defensive narrowing to satisfy the SQL query below,
    // matching the guest_id fallback pattern used in build_user()'s caller.
    $user_id_raw = $user['id'] ?? null;
    $guest_id = $conf['guest_id'];
    $guest_id_str = is_scalar($guest_id) ? (string) $guest_id : '2';
    $user_id = is_numeric($user_id_raw) ? (string) $user_id_raw : $guest_id_str;

    $query = '
SELECT
    *
  FROM ' . USER_CACHE_CATEGORIES_TABLE . '
  WHERE cat_id = ' . $cat_id . '
    AND user_id = ' . $user_id . '
;';
    $found_categories = query2array($query);
    if (empty($found_categories)) {
        page_not_found(l10n('Requested album does not exist'));
    }

    $cat_ids = [$cat_id];
}

if (count($cat_ids) > 0 or in_array('cat', $fields)) {
    $search['fields']['cat'] = [
        'words' => $cat_ids,
        'sub_inc' => true,
    ];
}

if (count(get_available_tags()) > 0) {
    $tag_ids = [];
    if (isset($_GET['tag_id'])) {
        check_input_parameter('tag_id', $_GET, false, '/^\d+(,\d+)*$/');

        $tag_id_value = $_GET['tag_id'];
        if (! is_scalar($tag_id_value)) {
            fatal_error('[Hacking attempt] the input parameter "tag_id" is not valid');
        }

        $tag_ids = explode(',', (string) $tag_id_value);
    }

    if (count($tag_ids) > 0 or in_array('tags', $fields)) {
        $search['fields']['tags'] = [
            'words' => $tag_ids,
            'mode' => 'AND',
        ];
    }
}

if (in_array('author', $fields)) {
    // does this Piwigo has authors for current user?
    $query = '
SELECT
    id
  FROM ' . IMAGES_TABLE . ' AS i
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id
  ' . get_sql_condition_FandF(
        [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ],
        ' WHERE '
    ) . '
    AND author IS NOT NULL
    LIMIT 1
;';
    $first_author = query2array($query);

    if (count($first_author) > 0) {
        $search['fields']['author'] = [
            'words' => [],
            'mode' => 'OR',
        ];
    }
}

foreach (['added_by', 'filetypes', 'ratios', 'ratings'] as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = [];
    }
}

foreach (['date_posted', 'date_created'] as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = [
            'preset' => '',
        ];
    }
}

foreach (['filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max'] as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = '';
    }
}

[$search_uuid, $search_url] = save_search($search);
redirect($search_url);
