<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//--------------------------------------------------------------------- include
define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();
include_once(PHPWG_ROOT_PATH.'include/functions_search.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

trigger_notify('loc_begin_search');

// +-----------------------------------------------------------------------+
// | Create a default search                                               |
// +-----------------------------------------------------------------------+

$search = array(
  'mode' => 'AND',
  'fields' => array(),
);

// list of filters in user preferences
$filters_views_raw = conf_get_param('filters_views', \Piwigo\Config\Config::defaultFiltersViews());
$filters_views = safe_unserialize(is_scalar($filters_views_raw) ? (string) $filters_views_raw : '');

//change the name of the keys so that they can be used with this part of the program
$filter_rename_for = array(
  'words'          => 'allwords',
  'post_date'      => 'date_posted',
  'creation_date'  => 'date_created',
  'album'          => 'cat',
  'file_type'      => 'filetypes',
  'ratio'          => 'ratios',
  'rating'         => 'ratings',
  'file_size'      => 'filesize',
);

$filters_conf = array();
foreach ($filters_views as $filter_name => $filter_value) {
    $key = isset($filter_rename_for[$filter_name]) ? $filter_rename_for[$filter_name] : $filter_name;

    $filters_conf[$key] = $filter_value;
}

//get all default filters
$default_fields = array();
foreach ($filters_conf as $filt_name => $filt_conf) {
    if (is_array($filt_conf) && isset($filt_conf['default']) && $filt_conf['default'] == true) {
        $default_fields[] = $filt_name;
    }
}

if (is_a_guest() or is_generic() or $filters_conf['last_filters_conf'] == false) {
    $fields = $default_fields;
} else {
    $fields_raw = userprefs_get_param('gallery_search_filters', $default_fields);
    $fields = is_array($fields_raw) ? $fields_raw : $default_fields;
}

$words = array();
$q = input_string('q', null, $_GET);
if (!empty($q)) {
    $words = split_allwords($q);
}

if (count($words ?? []) > 0 or in_array('allwords', $fields)) {
    $search['fields']['allwords'] = array(
      'words' => $words,
      'mode' => 'AND',
      'fields' => array('file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'),
    );
}

$cat_ids = array();
$cat_id = input_int('cat_id', null, $_GET);
if ($cat_id !== null) {
    check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

    $query = '
SELECT
    *
  FROM '.USER_CACHE_CATEGORIES_TABLE.'
  WHERE cat_id = '.$cat_id.'
    AND user_id = '.$user['id'].'
;';
    $found_categories = \Piwigo\Db\QueryHelper::fetch($query);
    if (empty($found_categories)) {
        page_not_found(l10n('Requested album does not exist'));
    }

    $cat_ids = array($cat_id);
}

if (count($cat_ids) > 0 or in_array('cat', $fields)) {
    $search['fields']['cat'] = array(
      'words' => $cat_ids,
      'sub_inc' => true,
    );
}

if (count(get_available_tags()) > 0) {
    $tag_ids = array();
    $tag_id = input_string('tag_id', null, $_GET);
    if ($tag_id !== null) {
        check_input_parameter('tag_id', $_GET, false, '/^\d+(,\d+)*$/');
        $tag_ids = explode(',', $tag_id);
    }

    if (count($tag_ids) > 0 or in_array('tags', $fields)) {
        $search['fields']['tags'] = array(
          'words' => $tag_ids,
          'mode'  => 'AND',
        );
    }
}

if (in_array('author', $fields)) {
    // does this Piwigo has authors for current user?
    $query = '
SELECT
    id
  FROM '.IMAGES_TABLE.' AS i
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  '.get_sql_condition_FandF(
        array(
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
        ),
        ' WHERE '
    ).'
    AND author IS NOT NULL
    LIMIT 1
;';
    $first_author = \Piwigo\Db\QueryHelper::fetch($query);

    if (count($first_author) > 0) {
        $search['fields']['author'] = array(
          'words' => array(),
          'mode' => 'OR',
        );
    }
}

foreach (array('added_by', 'filetypes', 'ratios', 'ratings') as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = array();
    }
}

foreach (array('date_posted', 'date_created') as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = array(
          'preset' => '',
        );
    }
}

foreach (array('filesize_min', 'filesize_max', 'width_min', 'width_max', 'height_min', 'height_max') as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = '';
    }
}

list($search_uuid, $search_url) = save_search($search);
redirect(is_scalar($search_url) ? (string) $search_url : '');
