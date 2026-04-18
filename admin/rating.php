<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

functions::check_input_parameter('display', $_GET, false, PATTERN_ID);

$tabsheet = new tabsheet();
$tabsheet->set_id('rating');
$tabsheet->select('rating');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+
$start = isset($_GET['start']) && is_numeric($_GET['start']) ? $_GET['start'] : 0;

$elements_per_page = 10;

if (isset($_GET['display']) &&
    is_numeric($_GET['display'])
) {
    $elements_per_page = $_GET['display'];
}

$order_by_index = 0;

if (isset($_GET['order_by']) &&
    is_numeric($_GET['order_by'])
) {
    $order_by_index = $_GET['order_by'];
}

$page['user_filter'] = '';

if (isset($_GET['users'])) {
    if ($_GET['users'] == 'user') {
        $page['user_filter'] = ' AND r.user_id != ' . $conf->guest_id;
    } elseif ($_GET['users'] == 'guest') {
        $page['user_filter'] = ' AND r.user_id = ' . $conf->guest_id;
    }
}

$page['cat_filter'] = '';

if (isset($_GET['cat']) &&
    is_numeric($_GET['cat'])
) {
    $cat_ids = functions_category::get_subcat_ids([$_GET['cat']]);

    if ($cat_ids !== []) {
        $page['cat_filter'] = ' AND ic.category_id IN (' . implode(', ', $cat_ids) . ')';
    }
}

$users = [];
$query = <<<SQL
    SELECT {$conf->user_fields['username']} AS username, {$conf->user_fields['id']} AS id
    FROM users;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $users[$row['id']] = stripslashes($row['username']);
}

$query = <<<SQL
    SELECT COUNT(DISTINCT r.element_id)
    FROM rate AS r

    SQL;

if (! empty($page['cat_filter'])) {
    $query .= <<<SQL
        JOIN images AS i ON r.element_id = i.id
        JOIN image_category AS ic ON ic.image_id = i.id

        SQL;
}

$query .= <<<SQL
    WHERE 1 = 1 {$page['user_filter']}
    SQL;
$query = trim($query) . ';';
[$nb_images] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM rate;
    SQL;
[$nb_elements] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filename('rating', 'rating.tpl');

$template->assign(
    [
        'navbar' => functions::create_navigation_bar(
            './admin.php' . functions_url::get_query_string_diff(['start', 'del']),
            $nb_images,
            $start,
            $elements_per_page
        ),
        'F_ACTION' => './admin.php',
        'DISPLAY' => $elements_per_page,
        'NB_ELEMENTS' => $nb_elements,
        'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []),
        'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['categories']),
    ]
);

$available_order_by = [
    [functions::l10n('Rate date'), 'recently_rated DESC'],
    [functions::l10n('Rating score'), 'score DESC'],
    [functions::l10n('Average rate'), 'avg_rates DESC'],
    [functions::l10n('Number of rates'), 'nb_rates DESC'],
    [functions::l10n('Sum of rates'), 'sum_rates DESC'],
    [functions::l10n('File name'), 'file DESC'],
    [functions::l10n('Creation date'), 'date_creation DESC'],
    [functions::l10n('Post date'), 'date_available DESC'],
];
$counter = count($available_order_by);

for ($i = 0; $i < $counter; $i++) {
    $template->append(
        'order_by_options',
        $available_order_by[$i][0]
    );
}

$template->assign('order_by_options_selected', [$order_by_index]);

$user_options = [
    'all' => functions::l10n('all'),
    'user' => functions::l10n('Users'),
    'guest' => functions::l10n('Guests'),
];

$template->assign('user_options', $user_options);
$template->assign('user_options_selected', [($_GET['users'] ?? null)]);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Rating'));

$query = <<<SQL
    SELECT i.id, i.path, i.file, i.representative_ext, i.rating_score AS score, MAX(r.date) AS recently_rated,
        ROUND(AVG(r.rate), 2) AS avg_rates, COUNT(r.rate) AS nb_rates, SUM(r.rate) AS sum_rates
    FROM rate AS r
    LEFT JOIN images AS i ON r.element_id = i.id

    SQL;

if (! empty($page['cat_filter'])) {
    $query .= <<<SQL
        JOIN image_category AS ic ON ic.image_id = i.id

        SQL;
}

$query .= <<<SQL
    WHERE 1 = 1 {$page['user_filter']} {$page['cat_filter']}
    GROUP BY i.id, i.path, i.file, i.representative_ext, i.rating_score, r.element_id
    ORDER BY {$available_order_by[$order_by_index][1]}
    LIMIT {$elements_per_page} OFFSET {$start};
    SQL;

$images = [];
$result = $conf->sql_backend::pwg_query($query);

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $images[] = $row;
}

$template->assign('images', []);

foreach ($images as $image) {
    $thumbnail_src = DerivativeImage::thumb_url($image);

    $image_url = functions_url::get_root_url() . 'admin.php?page=photo-' . $image['id'];

    $query = <<<SQL
        SELECT *
        FROM rate AS r
        WHERE r.element_id = {$image['id']}
        ORDER BY date DESC;
        SQL;
    $result = $conf->sql_backend::pwg_query($query);
    $nb_rates = $conf->sql_backend::pwg_db_num_rows($result);

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

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        $user_rate = $users[$row['user_id']] ?? '? ' . $row['user_id'];

        if (strlen($row['anonymous_id']) > 0) {
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

$cache_keys = functions_admin::get_admin_client_cache_keys(['categories']);
$page_data = [
    'categoriesServerKey' => $cache_keys['categories'],
    'categoriesServerId' => $cache_keys['_hash'],
    'rootUrl' => functions_url::get_root_url(),
    'nbElements' => $nb_elements,
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['rating']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
