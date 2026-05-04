<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;
use Piwigo\Image\DerivativeImage;

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

check_input_parameter('display', $_GET, false, PATTERN_ID);

$tabsheet = new Tabsheet();
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

$page['user_filter'] = '';
if (isset($_GET['users'])) {
    if ($_GET['users'] == 'user') {
        $page['user_filter'] = ' AND r.user_id <> '.\Piwigo\Config\Config::guestId();
    } elseif ($_GET['users'] == 'guest') {
        $page['user_filter'] = ' AND r.user_id = '.\Piwigo\Config\Config::guestId();
    }
}

$page['cat_filter'] = '';
if (isset($_GET['cat']) and is_numeric($_GET['cat'])) {
    $cat_ids = get_subcat_ids([(int) $_GET['cat']]);

    if (count($cat_ids) > 0) {
        $page['cat_filter'] = ' AND ic.category_id IN ('.implode(',', $cat_ids).')';
    }
}

$userFields = \Piwigo\Config\Config::userFields();
$rawUsers = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
    ->findAllUserIdNameMap($userFields['id'], $userFields['username'], USERS_TABLE);
$users = [];
foreach ($rawUsers as $id => $username) {
    $users[$id] = stripslashes($username);
}


$query = '
SELECT
    COUNT(DISTINCT(r.element_id))
  FROM '.RATE_TABLE.' AS r';

if (!empty($page['cat_filter'])) {
    $query .= '
    JOIN '.IMAGES_TABLE.' AS i ON r.element_id = i.id
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id';
}

$query .= '
WHERE 1=1'. $page['user_filter'];
$nb_images = (int) \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
    ->executeQuery($query)->fetchOne();

$nb_elements = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)->countRatings();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filename('rating', 'rating.tpl');

$cache_keys = get_admin_client_cache_keys(['categories']);
$rating_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'str_create' => l10n('Create'),
  'nb_elements' => $nb_elements,
];

$template->assign(
    [
    'navbar' => create_navigation_bar(
        PHPWG_ROOT_PATH.'admin.php'.get_query_string_diff(['start','del']),
        $nb_images,
        $start,
        $elements_per_page
    ),
    'F_ACTION' => PHPWG_ROOT_PATH.'admin.php',
    'DISPLAY' => $elements_per_page,
    'NB_ELEMENTS' => $nb_elements,
    'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []),
    'CACHE_KEYS' => $cache_keys,
    'rating_page_data_json' => json_encode($rating_page_data),
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
  'all'   => l10n('all'),
  'user'  => l10n('Users'),
  'guest' => l10n('Guests'),
  ];

$template->assign('user_options', $user_options);
$template->assign('user_options_selected', [$_GET['users'] ?? null]);
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
  FROM '.RATE_TABLE.' AS r
    LEFT JOIN '.IMAGES_TABLE.' AS i ON r.element_id = i.id';

if (!empty($page['cat_filter'])) {
    $query .= '
    JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id';
}

$query .= '
  WHERE 1 = 1 ' . $page['user_filter'] . $page['cat_filter'] . '
  GROUP BY i.id,
        i.path,
        i.file,
        i.representative_ext,
        i.rating_score,
        r.element_id
  ORDER BY ' . $available_order_by[$order_by_index][1] .'
  LIMIT '.$elements_per_page.' OFFSET '.$start.'
;';

$images = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
    ->executeQuery($query)
    ->fetchAllAssociative();

$template->assign('images', []);
foreach ($images as $image) {
    $thumbnail_src = DerivativeImage::thumb_url($image);

    $image_url = get_root_url().'admin.php?page=photo-'.$image['id'];

    $all_rates = \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class)
        ->findByElementId((int) $image['id']);
    $nb_rates = count($all_rates);

    $tpl_image =
      [
        'id' => $image['id'],
        'U_THUMB' => $thumbnail_src,
        'U_URL' => $image_url,
        'SCORE_RATE' => $image['score'],
         'AVG_RATE' => $image['avg_rates'],
         'SUM_RATE' => $image['sum_rates'],
         'NB_RATES' => (int)$image['nb_rates'],
         'NB_RATES_TOTAL' => (int)$nb_rates,
         'FILE' => $image['file'],
         'rates'  => [],
     ];

    foreach ($all_rates as $row) {
        $user_id = is_numeric($row['user_id']) ? (int)$row['user_id'] : 0;
        if (isset($users[$user_id])) {
            $user_rate = $users[$user_id];
        } else {
            $user_rate = '? '. $user_id;
        }
        $anon_id_str = is_scalar($row['anonymous_id']) ? (string) $row['anonymous_id'] : '';
        if (strlen($anon_id_str) > 0) {
            $user_rate .= '('.$anon_id_str.')';
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
