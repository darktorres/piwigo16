<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

//--------------------------------------------------------------------- include
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_search;
use Piwigo\inc\functions_tag;
use Piwigo\inc\functions_user;

const PHPWG_ROOT_PATH = './';
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

functions_plugins::trigger_notify('loc_begin_search');

// +-----------------------------------------------------------------------+
// | Create a default search                                               |
// +-----------------------------------------------------------------------+

$search = [
    'mode' => 'AND',
    'fields' => [],
];

// list of filters in user preferences
// allwords, cat, tags, author, added_by, filetypes, date_posted
$default_fields = ['allwords', 'cat', 'tags', 'author'];

if (functions_user::is_a_guest() ||
    functions_user::is_generic()
) {
    $fields = $default_fields;
} else {
    $fields = functions_user::userprefs_get_param('gallery_search_filters', $default_fields);
}

$words = [];

if (! empty($_GET['q'])) {
    $words = functions_search::split_allwords($_GET['q']);
}

if (count($words) > 0 ||
    in_array('allwords', $fields)
) {
    $search['fields']['allwords'] = [
        'words' => $words,
        'mode' => 'AND',
        'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
    ];
}

$cat_ids = [];

if (isset($_GET['cat_id'])) {
    functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
    $cat_ids = [$_GET['cat_id']];
}

if ($cat_ids !== [] ||
    in_array('cat', $fields)
) {
    $search['fields']['cat'] = [
        'words' => $cat_ids,
        'sub_inc' => true,
    ];
}

if (functions_tag::get_available_tags() !== []) {
    $tag_ids = [];

    if (isset($_GET['tag_id'])) {
        functions::check_input_parameter('tag_id', $_GET, false, '/^\d+(,\d+)*$/');
        $tag_ids = explode(',', $_GET['tag_id']);
    }

    if ($tag_ids !== [] ||
        in_array('tags', $fields)
    ) {
        $search['fields']['tags'] = [
            'words' => $tag_ids,
            'mode' => 'AND',
        ];
    }
}

if (in_array('author', $fields)) {
    // does this Piwigo has authors for current user?
    $sql_condition = functions_user::get_sql_condition_FandF(
        [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
        ],
        'WHERE'
    );

    $query = <<<SQL
        SELECT id
        FROM images AS i
        JOIN image_category AS ic ON ic.image_id = i.id
        {$sql_condition}
        AND author IS NOT NULL
        LIMIT 1;
        SQL;
    $first_author = functions_mysqli::query2array($query);

    if ($first_author !== []) {
        $search['fields']['author'] = [
            'words' => [],
            'mode' => 'OR',
        ];
    }
}

foreach (['added_by', 'filetypes', 'date_posted'] as $field) {
    if (in_array($field, $fields)) {
        $search['fields'][$field] = [];
    }
}

[$search_uuid, $search_url] = functions_search::save_search($search);
functions::redirect($search_url);
