<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;

/**
 * Rate a picture by the current user.
 *
 * @param int $image_id
 * @param int|string|null $rate raw $_POST value (string) from picture.php,
 *   an (int)-cast value from the WS layer, or null when absent
 * @return array<string, mixed>|false as returned by update_rating_score(), or false if the
 *   rate is invalid or forbidden
 */
function rate_picture($image_id, int|string|null $rate): false|array
{
    /** @var array<string, mixed> $conf */
    global $conf;
    /** @var array<string, mixed> $user */
    global $user;

    // $conf['rate_items'] is config_default.inc.php's list of allowed rate
    // values (an int[]); narrow it once so in_array() gets a real array
    // regardless of what a plugin/local config override might have put there.
    $rate_items = $conf['rate_items'];
    $rate_items = is_array($rate_items) ? $rate_items : [];

    if (! isset($rate)
        or ! (bool) $conf['rate']
        or ! (bool) preg_match('/^[0-9]+$/', (string) $rate)
        or ! in_array($rate, $rate_items)) {
        return false;
    }

    $user_anonymous = is_autorize_status(AccessLevel::Classic) ? false : true;

    if ($user_anonymous and ! (bool) $conf['rate_anonymous']) {
        return false;
    }

    // $user['id'] is the current user's numeric id (int, or a numeric string
    // when it comes straight from the DB layer); narrow it once here and
    // reuse the local everywhere below instead of re-reading the mixed offset.
    $user_id = $user['id'];
    $user_id = is_numeric($user_id) ? (int) $user_id : 0;

    $remote_addr = $_SERVER['REMOTE_ADDR'];
    $remote_addr = is_string($remote_addr) ? $remote_addr : '';
    $ip_components = explode('.', $remote_addr);
    if (count($ip_components) > 3) {
        array_pop($ip_components);
    }
    $anonymous_id = implode('.', $ip_components);

    if ($user_anonymous) {
        $save_anonymous_id = pwg_get_cookie_var('anonymous_rater', $anonymous_id);
        // pwg_get_cookie_var()'s own return type is mixed; a cookie value is
        // always a string when present, and the $default we passed
        // ($anonymous_id) is already a string, so fail back to it.
        $save_anonymous_id = is_string($save_anonymous_id) ? $save_anonymous_id : $anonymous_id;

        if ($anonymous_id != $save_anonymous_id) { // client has changed his IP adress or he's trying to fool us
            $query = '
SELECT element_id
  FROM ' . Tables::rate() . '
  WHERE user_id = ' . $user_id . '
    AND anonymous_id = \'' . $anonymous_id . '\'
;';
            $already_there = array_from_query($query, 'element_id');
            // array_from_query() is typed array<int|string, mixed>; the
            // element_id column is a string|null DB value, and implode()
            // needs every element to be string-castable.
            $already_there = array_filter($already_there, is_string(...));

            if (count($already_there) > 0) {
                $query = '
DELETE
  FROM ' . Tables::rate() . '
  WHERE user_id = ' . $user_id . '
    AND anonymous_id = \'' . $save_anonymous_id . '\'
    AND element_id IN (' . implode(',', $already_there) . ')
;';
                pwg_query($query);
            }

            $query = '
UPDATE ' . Tables::rate() . '
  SET anonymous_id = \'' . $anonymous_id . '\'
  WHERE user_id = ' . $user_id . '
    AND anonymous_id = \'' . $save_anonymous_id . '\'
;';
            pwg_query($query);
        } // end client changed ip

        pwg_set_cookie_var('anonymous_rater', $anonymous_id);
    } // end anonymous user

    $query = '
DELETE
  FROM ' . Tables::rate() . '
  WHERE element_id = ' . $image_id . '
    AND user_id = ' . $user_id . '
';
    if ($user_anonymous) {
        $query .= ' AND anonymous_id = \'' . $anonymous_id . '\'';
    }
    pwg_query($query);
    $query = '
INSERT
  INTO ' . Tables::rate() . '
  (user_id,anonymous_id,element_id,rate,date)
  VALUES
  ('
      . $user_id . ','
      . '\'' . $anonymous_id . '\','
      . $image_id . ','
      . $rate
      . ',NOW())
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
 * @return array<string, mixed> (score, average, count) values are null if $element_id is false
 */
function update_rating_score($element_id = false): array
{
    if (($alt_result = trigger_change('update_rating_score', false, $element_id)) !== false) {
        // trigger_change()'s own return type is mixed; the contract of this
        // event is to return the (score, average, count) shape documented
        // above, so fail through to the real computation below rather than
        // trust a misbehaving handler's return value.
        if (is_array($alt_result)) {
            /** @var array<string, mixed> $alt_result */
            return $alt_result;
        }
    }

    $query = '
SELECT element_id,
    COUNT(rate) AS rcount,
    SUM(rate) AS rsum
  FROM ' . Tables::rate() . '
  GROUP by element_id';

    $all_rates_count = 0;
    $all_rates_avg = 0;
    $item_ratecount_avg = 0;
    /** @var array<int, array{rcount: int, rsum: float}> $by_item */
    $by_item = [];

    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // COUNT()/SUM()/the grouped-by column all come back as string|null
        // from the DB layer; COUNT() is never null, and this GROUP BY only
        // ever yields rows that have at least one rate, so all three are
        // numeric here. Narrow them once so the arithmetic and array-key
        // uses below are properly typed.
        $row_rcount = is_numeric($row['rcount']) ? (int) $row['rcount'] : 0;
        $row_rsum = is_numeric($row['rsum']) ? (float) $row['rsum'] : 0.0;
        $row_element_id = is_numeric($row['element_id']) ? (int) $row['element_id'] : null;
        if ($row_element_id === null) {
            continue;
        }
        $all_rates_count += $row_rcount;
        $all_rates_avg += $row_rsum;
        $by_item[$row_element_id] = [
            'rcount' => $row_rcount,
            'rsum' => $row_rsum,
        ];
    }

    if ($all_rates_count > 0) {
        $all_rates_avg /= $all_rates_count;
        $item_ratecount_avg = $all_rates_count / count($by_item);
    }

    $updates = [];
    foreach ($by_item as $id => $rate_summary) {
        $score = ($item_ratecount_avg * $all_rates_avg + $rate_summary['rsum']) / ($item_ratecount_avg + $rate_summary['rcount']);
        $score = round($score, 2);
        if ($id == $element_id) {
            $return = [
                'score' => $score,
                'average' => round($rate_summary['rsum'] / $rate_summary['rcount'], 2),
                'count' => $rate_summary['rcount'],
            ];
        }
        $updates[] = [
            'id' => $id,
            'rating_score' => $score,
        ];
    }
    mass_updates(
        Tables::images(),
        [
            'primary' => ['id'],
            'update' => ['rating_score'],
        ],
        $updates
    );

    // set to null all items with no rate
    // $by_item is keyed by int; PHP itself casts a bool array offset to int
    // (false => 0), made explicit here so the offset type matches.
    $element_id_key = $element_id === false ? 0 : $element_id;
    if (! isset($by_item[$element_id_key])) {
        $query = '
SELECT id FROM ' . Tables::images() . '
  LEFT JOIN ' . Tables::rate() . ' ON id=element_id
  WHERE element_id IS NULL AND rating_score IS NOT NULL';

        $to_update = array_from_query($query, 'id');
        // array_from_query() is typed array<int|string, mixed>; the id
        // column is a string|null DB value, and implode() needs every
        // element to be string-castable.
        $to_update = array_filter($to_update, is_string(...));

        if (! empty($to_update)) {
            $query = '
UPDATE ' . Tables::images() . '
  SET rating_score=NULL
  WHERE id IN (' . implode(',', $to_update) . ')';
            pwg_query($query);
        }
    }

    return $return ?? [
        'score' => null,
        'average' => null,
        'count' => 0,
    ];
}
