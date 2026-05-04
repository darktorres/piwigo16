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
    $userId = \Piwigo\Users\CurrentUser::get()->id;

    if (!isset($rate)
        or !\Piwigo\Config\Config::rateEnabled()
        or !preg_match('/^[0-9]+$/', (string)$rate)
        or !in_array($rate, \Piwigo\Config\Config::rateItems())) {
        return false;
    }

    $user_anonymous = is_autorize_status(ACCESS_CLASSIC) ? false : true;

    if ($user_anonymous and !\Piwigo\Config\Config::rateAnonymous()) {
        return false;
    }

    $ip_components = explode('.', is_scalar($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
    if (count($ip_components) > 3) {
        array_pop($ip_components);
    }
    $anonymous_id = implode('.', $ip_components);

    $rateRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class);

    if ($user_anonymous) {
        $save_anonymous_id_raw = pwg_get_cookie_var('anonymous_rater', $anonymous_id);
        $save_anonymous_id = is_scalar($save_anonymous_id_raw) ? (string) $save_anonymous_id_raw : $anonymous_id;

        if ($anonymous_id != $save_anonymous_id) { // client has changed his IP address or he's trying to fool us
            $already_there = $rateRepo->findElementIdsByUserAndAnonId($userId, $anonymous_id);

            if (count($already_there) > 0) {
                $rateRepo->deleteByUserAnonElements($userId, $save_anonymous_id, $already_there);
            }

            $rateRepo->updateAnonId($userId, $save_anonymous_id, $anonymous_id);
        } // end client changed ip

        pwg_set_cookie_var('anonymous_rater', $anonymous_id);
    } // end anonymous user

    $rateRepo->deleteByElementAndUser($image_id, $userId, $user_anonymous ? $anonymous_id : null);
    $rateRepo->insert($userId, $anonymous_id, $image_id, (float) $rate);

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

    $rateRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class);

    $all_rates_count = 0;
    $all_rates_avg = 0;
    $item_ratecount_avg = 0;
    $by_item = [];

    foreach ($rateRepo->getSumsByElement() as $row) {
        $all_rates_count += is_numeric($row['rcount']) ? (int) $row['rcount'] : 0;
        $all_rates_avg += is_numeric($row['rsum']) ? (float) $row['rsum'] : 0.0;
        $element_id_key = is_numeric($row['element_id']) ? (int) $row['element_id'] : (is_scalar($row['element_id']) ? (string) $row['element_id'] : 0);
        $by_item[$element_id_key] = $row;
    }

    if ($all_rates_count > 0) {
        $all_rates_avg /= $all_rates_count;
        $item_ratecount_avg = $all_rates_count / count($by_item);
    }

    $updates = [];
    foreach ($by_item as $id => $rate_summary) {
        $rsum = is_numeric($rate_summary['rsum']) ? (float) $rate_summary['rsum'] : 0.0;
        $rcount = is_numeric($rate_summary['rcount']) ? (int) $rate_summary['rcount'] : 0;
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
        $to_update = $rateRepo->findImageIdsWithNoRates();

        if (!empty($to_update)) {
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class)
                ->clearRatingScoreByIds($to_update);
        }
    }

    return $return ?? ['score' => null, 'average' => null, 'count' => 0 ];
}
