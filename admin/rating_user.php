<?php

declare(strict_types=1);

use Piwigo\Admin\tabsheet;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

global $template, $user, $page, $persistent_cache, $lang;

$tabsheet = new tabsheet();
$tabsheet->set_id('rating');
$tabsheet->select('rating_user');
$tabsheet->assign();

$filter_min_rates = 2;
if (isset($_GET['f_min_rates'])) {
    $raw_f_min_rates = $_GET['f_min_rates'];
    $filter_min_rates = is_scalar($raw_f_min_rates) ? (int) $raw_f_min_rates : 2;
}

$consensus_top_number = \Piwigo\Core\Config::topNumber();
if (isset($_GET['consensus_top_number'])) {
    $raw_consensus_top = $_GET['consensus_top_number'];
    $consensus_top_number = is_scalar($raw_consensus_top) ? (int) $raw_consensus_top : $consensus_top_number;
}

// build users
$query = 'SELECT DISTINCT
  u.'.\Piwigo\Core\Config::userFields()['id'].' AS id,
  u.'.\Piwigo\Core\Config::userFields()['username'].' AS name,
  ui.status
  FROM '.USERS_TABLE.' AS u INNER JOIN '.USER_INFOS_TABLE.' AS ui
    ON u.'.\Piwigo\Core\Config::userFields()['id'].' = ui.user_id';

$users_by_id = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $users_by_id[(int)$row['id']] = [
      'name' => (string)$row['name'],
      'anon' => is_autorize_status(ACCESS_CLASSIC, (string)$row['status']) ? false : true,
    ];
}

$by_user_rating_model = [ 'rates' => [] ];
foreach (\Piwigo\Core\Config::rateItems() as $rate) {
    $by_user_rating_model['rates'][(int)$rate] = [];
}

// by user aggregation
$image_ids = [];
$by_user_ratings = [];
$query = '
SELECT * FROM '.RATE_TABLE.' ORDER by date DESC';
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $user_id = (int)$row['user_id'];
    if (!isset($users_by_id[$user_id])) {
        $users_by_id[$user_id] = ['name' => '???'.$user_id, 'anon' => false];
    }
    $usr = $users_by_id[$user_id];
    if ($usr['anon']) {
        $user_key = $usr['name'].'('.(string)$row['anonymous_id'].')';
    } else {
        $user_key = $usr['name'];
    }
    if (!isset($by_user_ratings[$user_key])) {
        $by_user_ratings[$user_key] = $by_user_rating_model;
        $by_user_ratings[$user_key]['uid'] = (int)$row['user_id'];
        $by_user_ratings[$user_key]['aid'] = $usr['anon'] ? $row['anonymous_id'] : '';
        $by_user_ratings[$user_key]['last_date'] = $row['date'];
        $by_user_ratings[$user_key]['first_date'] = $row['date'];
    } else {
        $by_user_ratings[$user_key]['first_date'] = $row['date'];
    }

    $rate = (int)$row['rate'];
    $element_id = (int)$row['element_id'];
    $by_user_ratings[$user_key]['rates'][$rate][] = [
      'id' => $element_id,
      'date' => $row['date'],
    ];
    $image_ids[$element_id] = 1;
}

// get image tn urls
$image_urls = [];
if (count($image_ids) > 0) {
    $query = 'SELECT id, name, file, path, representative_ext, level
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', array_keys($image_ids)).')';
    $result = pwg_query($query);
    $params = ImageStdParams::get_by_type(IMG_SQUARE);
    while ($row = pwg_db_fetch_assoc($result)) {
        $id = (int)$row['id'];
        $image_urls[ $id ] = [
          'tn' => DerivativeImage::url($params, $row),
          'page' => make_picture_url(['image_id' => $row['id'], 'image_file' => $row['file']]),
        ];
    }
}

//all image averages
$query = 'SELECT element_id,
    AVG(rate) AS avg
  FROM '.RATE_TABLE.'
  GROUP BY element_id';
$all_img_sum = [];
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result)) {
    $all_img_sum[(int)$row['element_id']] = [ 'avg' => (float)$row['avg'] ];
}

$query = 'SELECT id
  FROM '.IMAGES_TABLE.'
  ORDER by rating_score DESC
  LIMIT '.$consensus_top_number;
$best_rated = array_flip(array_map(static fn($id) => (int)$id, query2array($query, null, 'id')));

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
      'cv'  => $s == 0 ? -1 : sqrt($var) / ($s / $c), // http://en.wikipedia.org/wiki/Coefficient_of_variation
      'cd'  => $consensus_dev,
      'cdtop'  => $consensus_dev_top_count ? $consensus_dev_top : '',
    ];
}
unset($rating);

// filter
foreach ($by_user_ratings as $id => $rating) {
    if ($rating['count'] <= $filter_min_rates) {
        unset($by_user_ratings[$id]);
    }
}


/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function avg_compare(array $a, array $b): int
{
    $d = (is_numeric($a['avg']) ? (float) $a['avg'] : 0.0) - (is_numeric($b['avg']) ? (float) $b['avg'] : 0.0);
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function count_compare(array $a, array $b): int
{
    $d = (is_numeric($a['count']) ? (int) $a['count'] : 0) - (is_numeric($b['count']) ? (int) $b['count'] : 0);
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function cv_compare(array $a, array $b): int
{
    $d = (is_numeric($b['cv']) ? (float) $b['cv'] : 0.0) - (is_numeric($a['cv']) ? (float) $a['cv'] : 0.0); //desc
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function consensus_dev_compare(array $a, array $b): int
{
    $d = (is_numeric($b['cd']) ? (float) $b['cd'] : 0.0) - (is_numeric($a['cd']) ? (float) $a['cd'] : 0.0); //desc
    return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function last_rate_compare(array $a, array $b): int
{
    $da = is_scalar($a['last_date'] ?? null) ? (string) $a['last_date'] : '';
    $db = is_scalar($b['last_date'] ?? null) ? (string) $b['last_date'] : '';
    return -strcmp($da, $db);
}

$order_by_index = 4;
if (isset($_GET['order_by']) and is_numeric($_GET['order_by'])) {
    $order_by_index = (int) $_GET['order_by'];
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
  FROM '.RATE_TABLE.
';';
[$nb_elements] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

$template->assign([
  'F_ACTION' => get_root_url().'admin.php',
  'F_MIN_RATES' => $filter_min_rates,
  'CONSENSUS_TOP_NUMBER' => $consensus_top_number,
  'available_rates' => \Piwigo\Core\Config::rateItems(),
  'ratings' => $by_user_ratings,
  'image_urls' => $image_urls,
  'TN_WIDTH' => ImageStdParams::get_by_type(IMG_SQUARE)->sizing->ideal_size[0],
  'NB_ELEMENTS' => $nb_elements,
  'ADMIN_PAGE_TITLE' => l10n('Rating'),
  'page_data_json' => json_encode([
      'nb_elements' => $nb_elements,
      'root_url' => get_root_url(),
      'str_delete_ratings_confirm' => l10n('Are you sure you want to delete the ratings of the user "%s"?'),
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
  ]);
$template->set_filename('rating', 'rating_user.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
