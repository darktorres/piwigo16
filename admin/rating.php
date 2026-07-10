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

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var \Template $template
 */
global $conf, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('display', $_GET, false, PATTERN_ID);

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';
$tabsheet = new tabsheet();
$tabsheet->set_id('rating');
$tabsheet->select('rating');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+
if (isset($_GET['start']) and is_numeric($_GET['start'])) {
    $start = (int) $_GET['start'];
} else {
    $start = 0;
}

$elements_per_page = 10;
if (isset($_GET['display']) and is_numeric($_GET['display'])) {
    $elements_per_page = (int) $_GET['display'];
}

$order_by_index = 0;
if (isset($_GET['order_by']) and is_numeric($_GET['order_by'])) {
    $order_by_index = (int) $_GET['order_by'];
}

// $conf['guest_id'] is set as a PHP int literal in
// include/config_default.inc.php.
$conf_guest_id = $conf['guest_id'];
$guest_id = is_numeric($conf_guest_id) ? (int) $conf_guest_id : 0;

$user_filter = '';
if (isset($_GET['users'])) {
    if ($_GET['users'] == 'user') {
        $user_filter = ' AND r.user_id <> ' . $guest_id;
    } elseif ($_GET['users'] == 'guest') {
        $user_filter = ' AND r.user_id = ' . $guest_id;
    }
}

$cat_filter = '';
if (isset($_GET['cat']) and is_numeric($_GET['cat'])) {
    $cat_ids = get_subcat_ids([(int) $_GET['cat']]);

    if (count($cat_ids) > 0) {
        $cat_filter = ' AND ic.category_id IN (' . implode(',', $cat_ids) . ')';
    }
}

$users = [];
/** @var array<string, string> $user_fields */
$user_fields = $conf['user_fields'];
$query = '
SELECT ' . $user_fields['username'] . ' as username, ' . $user_fields['id'] . ' as id
  FROM ' . USERS_TABLE . '
;';
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    if (is_string($row['id'])) {
        $users[$row['id']] = stripslashes((string) $row['username']);
    }
}

$query = '
SELECT
    COUNT(DISTINCT(r.element_id))
  FROM ' . RATE_TABLE . ' AS r';

if (! empty($cat_filter)) {
    $query .= '
    JOIN ' . IMAGES_TABLE . ' AS i ON r.element_id = i.id
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id';
}

$query .= '
WHERE 1=1' . $user_filter;
$count_row = pwg_db_fetch_row(pwg_query($query));
assert($count_row !== null);
[$nb_images] = $count_row;
$nb_images = is_numeric($nb_images) ? (int) $nb_images : 0;

$query = '
SELECT
    COUNT(*)
  FROM ' . RATE_TABLE .
';';
$count_row = pwg_db_fetch_row(pwg_query($query));
assert($count_row !== null);
[$nb_elements] = $count_row;

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filename('rating', 'rating.tpl');

$template->assign(
    [
        'navbar' => create_navigation_bar(
            PHPWG_ROOT_PATH . 'admin.php' . get_query_string_diff(['start', 'del']),
            $nb_images,
            $start,
            $elements_per_page
        ),
        'F_ACTION' => PHPWG_ROOT_PATH . 'admin.php',
        'DISPLAY' => $elements_per_page,
        'NB_ELEMENTS' => $nb_elements,
        'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []),
        'CACHE_KEYS' => get_admin_client_cache_keys(['categories']),
    ]
);

$available_order_by = [
    [l10n('Rate date'), 'recently_rated DESC'],
    [l10n('Rating score'), 'score DESC'],
    [l10n('Average rate'), 'avg_rates DESC'],
    [l10n('Number of rates'), 'nb_rates DESC'],
    [l10n('Sum of rates'), 'sum_rates DESC'],
    [l10n('File name'), 'file DESC'],
    [l10n('Creation date'), 'date_creation DESC'],
    [l10n('Post date'), 'date_available DESC'],
];

for ($i = 0; $i < count($available_order_by); $i++) {
    $template->append(
        'order_by_options',
        $available_order_by[$i][0]
    );
}
$template->assign('order_by_options_selected', [$order_by_index]);

$user_options = [
    'all' => l10n('all'),
    'user' => l10n('Users'),
    'guest' => l10n('Guests'),
];

$template->assign('user_options', $user_options);
$template->assign('user_options_selected', [@$_GET['users']]);
$template->assign('ADMIN_PAGE_TITLE', l10n('Rating'));

$query = '
SELECT i.id,
    i.path,
    i.file,
    i.representative_ext,
    i.rating_score       AS score,
    MAX(r.date)          AS recently_rated,
    ROUND(AVG(r.rate),2) AS avg_rates,
    COUNT(r.rate)        AS nb_rates,
    SUM(r.rate)          AS sum_rates
  FROM ' . RATE_TABLE . ' AS r
    LEFT JOIN ' . IMAGES_TABLE . ' AS i ON r.element_id = i.id';

if (! empty($cat_filter)) {
    $query .= '
    JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic ON ic.image_id = i.id';
}

$query .= '
  WHERE 1 = 1 ' . $user_filter . $cat_filter . '
  GROUP BY i.id,
        i.path,
        i.file,
        i.representative_ext,
        i.rating_score,
        r.element_id
  ORDER BY ' . $available_order_by[$order_by_index][1] . '
  LIMIT ' . $elements_per_page . ' OFFSET ' . $start . '
;';

$images = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $images[] = $row;
}

$template->assign('images', []);
foreach ($images as $image) {
    $thumbnail_src = DerivativeImage::thumb_url($image);

    $image_url = get_root_url() . 'admin.php?page=photo-' . $image['id'];

    $query = 'SELECT *
FROM ' . RATE_TABLE . ' AS r
WHERE r.element_id=' . $image['id'] . '
ORDER BY date DESC;';
    $result = pwg_query($query);
    $nb_rates = pwg_db_num_rows($result);

    $tpl_image =
      [
          'id' => $image['id'],
          'U_THUMB' => $thumbnail_src,
          'U_URL' => $image_url,
          'SCORE_RATE' => $image['score'],
          'AVG_RATE' => $image['avg_rates'],
          'SUM_RATE' => $image['sum_rates'],
          'NB_RATES' => (int) $image['nb_rates'],
          'NB_RATES_TOTAL' => (int) $nb_rates,
          'FILE' => $image['file'],
          'rates' => [],
      ];

    while ($row = pwg_db_fetch_assoc($result)) {
        $row_user_id = is_string($row['user_id']) ? $row['user_id'] : '';
        if (isset($users[$row_user_id])) {
            $user_rate = $users[$row_user_id];
        } else {
            $user_rate = '? ' . $row_user_id;
        }
        if (strlen((string) $row['anonymous_id']) > 0) {
            $user_rate .= '(' . $row['anonymous_id'] . ')';
        }

        $row['USER'] = $user_rate;
        $tpl_image['rates'][] = $row;
    }
    $template->append('images', $tpl_image);
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
