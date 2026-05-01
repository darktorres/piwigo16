<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\rate
 */


/**
 * Rate a picture by the current user.
 *
 * @param int $image_id
 * @param float $rate
 *  array|false as return by update_rating_score()
 */
/** @return array<mixed>|false */
function rate_picture(int $image_id, float|int|null $rate): array|false
{
    global $user;

    if (!isset($rate)
        or !\Piwigo\Core\Config::rateEnabled()
        or !preg_match('/^[0-9]+$/', (string)$rate)
        or !in_array($rate, \Piwigo\Core\Config::rateItems())) {
        return false;
    }

    $user_anonymous = is_autorize_status(ACCESS_CLASSIC) ? false : true;

    if ($user_anonymous and !\Piwigo\Core\Config::rateAnonymous()) {
        return false;
    }

    $ip_components = explode('.', is_scalar($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
    if (count($ip_components) > 3) {
        array_pop($ip_components);
    }
    $anonymous_id = implode('.', $ip_components);

    if ($user_anonymous) {
        $save_anonymous_id_raw = pwg_get_cookie_var('anonymous_rater', $anonymous_id);
        $save_anonymous_id = is_scalar($save_anonymous_id_raw) ? (string) $save_anonymous_id_raw : $anonymous_id;

        if ($anonymous_id != $save_anonymous_id) { // client has changed his IP adress or he's trying to fool us
            $query = '
SELECT element_id
  FROM '.RATE_TABLE.'
  WHERE user_id = '.$user['id'].'
    AND anonymous_id = \''.$anonymous_id.'\'
;';
            $already_there = query2array($query, null, 'element_id');

            if (count($already_there) > 0) {
                $query = '
DELETE
  FROM '.RATE_TABLE.'
  WHERE user_id = '.$user['id'].'
    AND anonymous_id = \''.$save_anonymous_id.'\'
    AND element_id IN ('.implode(',', $already_there).')
;';
                pwg_query($query);
            }

            $query = '
UPDATE '.RATE_TABLE.'
  SET anonymous_id = \'' .$anonymous_id.'\'
  WHERE user_id = '.$user['id'].'
    AND anonymous_id = \'' . $save_anonymous_id.'\'
;';
            pwg_query($query);
        } // end client changed ip

        pwg_set_cookie_var('anonymous_rater', $anonymous_id);
    } // end anonymous user

    $query = '
DELETE
  FROM '.RATE_TABLE.'
  WHERE element_id = '.$image_id.'
    AND user_id = '.$user['id'].'
';
    if ($user_anonymous) {
        $query .= ' AND anonymous_id = \''.$anonymous_id.'\'';
    }
    pwg_query($query);
    $query = '
INSERT
  INTO '.RATE_TABLE.'
  (user_id,anonymous_id,element_id,rate,date)
  VALUES
  ('
      .$user['id'].','
      .'\''.$anonymous_id.'\','
      .$image_id.','
      .$rate
      .',NOW())
;';
    pwg_query($query);

    return update_rating_score($image_id);
}


/**
 * Update images.rating_score field.
 * We use a bayesian average (http://en.wikipedia.org/wiki/Bayesian_average) with
 *  C = average number of rates per item
 *  m = global average rate (all rates)
 *
 * @param int|false $element_id if false applies to all
 * @return array (score, average, count) values are null if $element_id is false
*/
/** @return array<mixed> */
function update_rating_score(int|false $element_id = false): array
{
    $_ = trigger_change('update_rating_score', false, $element_id);

    $query = '
SELECT element_id,
    COUNT(rate) AS rcount,
    SUM(rate) AS rsum
  FROM '.RATE_TABLE.'
  GROUP by element_id';

    $all_rates_count = 0;
    $all_rates_avg = 0;
    $item_ratecount_avg = 0;
    $by_item = [];

    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $all_rates_count += (int) $row['rcount'];
        $all_rates_avg += (float) $row['rsum'];
        $element_id_key = is_numeric($row['element_id']) ? (int) $row['element_id'] : (is_scalar($row['element_id']) ? (string) $row['element_id'] : 0);
        $by_item[$element_id_key] = $row;
    }

    if ($all_rates_count > 0) {
        $all_rates_avg /= $all_rates_count;
        $item_ratecount_avg = $all_rates_count / count($by_item);
    }

    $updates = [];
    foreach ($by_item as $id => $rate_summary) {
        $rsum = (float) $rate_summary['rsum'];
        $rcount = (int) $rate_summary['rcount'];
        $score = ($item_ratecount_avg * $all_rates_avg + $rsum) / ($item_ratecount_avg + $rcount);
        $score = round($score, 2);
        if ($id == $element_id) {
            $return = [
              'score' => $score,
              'average' => $rcount > 0 ? round($rsum / $rcount, 2) : 0.0,
              'count' => $rcount,
              ];
        }
        $updates[] = [ 'id' => $id, 'rating_score' => $score ];
    }
    mass_updates(
        IMAGES_TABLE,
        [
        'primary' => ['id'],
        'update' => ['rating_score'],
        ],
        $updates
    );

    //set to null all items with no rate
    if (!isset($by_item[$element_id])) {
        $query = '
SELECT id FROM '.IMAGES_TABLE .'
  LEFT JOIN '.RATE_TABLE.' ON id=element_id
  WHERE element_id IS NULL AND rating_score IS NOT NULL';

        $to_update = query2array($query, null, 'id');

        if (!empty($to_update)) {
            $query = '
UPDATE '.IMAGES_TABLE .'
  SET rating_score=NULL
  WHERE id IN (' . implode(',', $to_update) . ')';
            pwg_query($query);
        }
    }

    return $return ?? ['score' => null, 'average' => null, 'count' => 0 ];
}
