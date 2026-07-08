<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

// Bootstrap globals, set by include/common.inc.php.
global $conf, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';
$tabsheet = new tabsheet();
$tabsheet->set_id('rating');
$tabsheet->select('rating_user');
$tabsheet->assign();

$filter_min_rates = 2;
if (isset($_GET['f_min_rates'])) {
    $filter_min_rates = (int) $_GET['f_min_rates'];
}

$consensus_top_number = $conf['top_number'];
if (isset($_GET['consensus_top_number'])) {
    $consensus_top_number = (int) $_GET['consensus_top_number'];
}

// build users
global $conf;
$query = 'SELECT DISTINCT
  u.' . $conf['user_fields']['id'] . ' AS id,
  u.' . $conf['user_fields']['username'] . ' AS name,
  ui.status
  FROM ' . USERS_TABLE . ' AS u INNER JOIN ' . USER_INFOS_TABLE . ' AS ui
    ON u.' . $conf['user_fields']['id'] . ' = ui.user_id';

$users_by_id = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $users_by_id[(int) $row['id']] = [
        'name' => $row['name'],
        'anon' => is_autorize_status(ACCESS_CLASSIC, $row['status']) ? false : true,
    ];
}

$by_user_rating_model = [
    'rates' => [],
];
foreach ($conf['rate_items'] as $rate) {
    $by_user_rating_model['rates'][$rate] = [];
}

// by user aggregation
$image_ids = [];
$by_user_ratings = [];
$query = '
SELECT * FROM ' . RATE_TABLE . ' ORDER by date DESC';
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    if (! isset($users_by_id[$row['user_id']])) {
        $users_by_id[$row['user_id']] = [
            'name' => '???' . $row['user_id'],
            'anon' => false,
        ];
    }
    $usr = $users_by_id[$row['user_id']];
    if ($usr['anon']) {
        $user_key = $usr['name'] . '(' . $row['anonymous_id'] . ')';
    } else {
        $user_key = $usr['name'];
    }
    if (! isset($by_user_ratings[$user_key])) {
        $rating = $by_user_rating_model;
        $rating['uid'] = (int) $row['user_id'];
        $rating['aid'] = $usr['anon'] ? $row['anonymous_id'] : '';
        $rating['last_date'] = $rating['first_date'] = $row['date'];
    } else {
        $rating = $by_user_ratings[$user_key];
        $rating['first_date'] = $row['date'];
    }

    $rating['rates'][$row['rate']][] = [
        'id' => $row['element_id'],
        'date' => $row['date'],
    ];
    $by_user_ratings[$user_key] = $rating;
    $image_ids[$row['element_id']] = 1;
}

// get image tn urls
$image_urls = [];
if (count($image_ids) > 0) {
    $query = 'SELECT id, name, file, path, representative_ext, level
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', array_keys($image_ids)) . ')';
    $result = pwg_query($query);
    $params = ImageStdParams::get_by_type(IMG_SQUARE);
    while ($row = pwg_db_fetch_assoc($result)) {
        $image_urls[$row['id']] = [
            'tn' => DerivativeImage::url($params, $row),
            'page' => make_picture_url([
                'image_id' => $row['id'],
                'image_file' => $row['file'],
            ]),
        ];
    }
}

// all image averages
$query = 'SELECT element_id,
    AVG(rate) AS avg
  FROM ' . RATE_TABLE . '
  GROUP BY element_id';
$all_img_sum = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $all_img_sum[(int) $row['element_id']] = [
        'avg' => (float) $row['avg'],
    ];
}

$query = 'SELECT id
  FROM ' . IMAGES_TABLE . '
  ORDER by rating_score DESC
  LIMIT ' . $consensus_top_number;
$best_rated = array_flip(array_from_query($query, 'id'));

// by user stats
foreach ($by_user_ratings as $id => &$rating) {
    $c = 0;
    $s = 0;
    $ss = 0;
    $consensus_dev = 0;
    $consensus_dev_top = 0;
    $consensus_dev_top_count = 0;
    foreach ($rating['rates'] as $rate => $rates) {
        $ct = count($rates);
        $c += $ct;
        $s += $ct * $rate;
        $ss += $ct * $rate * $rate;
        foreach ($rates as $id_date) {
            $dev = abs($rate - $all_img_sum[$id_date['id']]['avg']);
            $consensus_dev += $dev;
            if (isset($best_rated[$id_date['id']])) {
                $consensus_dev_top += $dev;
                $consensus_dev_top_count++;
            }
        }
    }

    $consensus_dev /= $c;
    if ($consensus_dev_top_count) {
        $consensus_dev_top /= $consensus_dev_top_count;
    }

    $var = ($ss - $s * $s / $c) / $c;
    $rating += [
        'id' => $id,
        'count' => $c,
        'avg' => $s / $c,
        'cv' => $s == 0 ? -1 : sqrt($var) / ($s / $c), // http://en.wikipedia.org/wiki/Coefficient_of_variation
        'cd' => $consensus_dev,
        'cdtop' => $consensus_dev_top_count ? $consensus_dev_top : '',
    ];
}
unset($rating);

// filter
foreach ($by_user_ratings as $id => $rating) {
    if ($rating['count'] <= $filter_min_rates) {
        unset($by_user_ratings[$id]);
    }
}

function avg_compare(array $a, array $b): int
{
    $d = $a['avg'] - $b['avg'];
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

function count_compare(array $a, array $b): int
{
    $d = $a['count'] - $b['count'];
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

function cv_compare(array $a, array $b): int
{
    $d = $b['cv'] - $a['cv']; // desc
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

function consensus_dev_compare(array $a, array $b): int
{
    $d = $b['cd'] - $a['cd']; // desc
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

function last_rate_compare(array $a, array $b): int
{
    return -strcmp((string) $a['last_date'], (string) $b['last_date']);
}

$order_by_index = 4;
if (isset($_GET['order_by']) and is_numeric($_GET['order_by'])) {
    $order_by_index = $_GET['order_by'];
}

$available_order_by = [
    [l10n('Average rate'), 'avg_compare'],
    [l10n('Number of rates'), 'count_compare'],
    [l10n('Variation'), 'cv_compare'],
    [l10n('Consensus deviation'), 'consensus_dev_compare'],
    [l10n('Last'), 'last_rate_compare'],
];

for ($i = 0; $i < count($available_order_by); $i++) {
    $template->append(
        'order_by_options',
        $available_order_by[$i][0]
    );
}
$template->assign('order_by_options_selected', [$order_by_index]);

$x = uasort($by_user_ratings, $available_order_by[$order_by_index][1]);

$query = '
SELECT
    COUNT(*)
  FROM ' . RATE_TABLE .
';';
[$nb_elements] = pwg_db_fetch_row(pwg_query($query));

$template->assign([
    'F_ACTION' => get_root_url() . 'admin.php',
    'F_MIN_RATES' => $filter_min_rates,
    'CONSENSUS_TOP_NUMBER' => $consensus_top_number,
    'available_rates' => $conf['rate_items'],
    'ratings' => $by_user_ratings,
    'image_urls' => $image_urls,
    'TN_WIDTH' => ImageStdParams::get_by_type(IMG_SQUARE)->sizing->ideal_size[0],
    'NB_ELEMENTS' => $nb_elements,
    'ADMIN_PAGE_TITLE' => l10n('Rating'),
]);
$template->set_filename('rating', 'rating_user.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
