<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Auth\CookieService;
use Piwigo\Db\DbConnection;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;

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
    return new RateService(new RateRepository(DbConnection::build()), new CookieService())
        ->rate($image_id, $rate);
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
    return new RateService(new RateRepository(DbConnection::build()), new CookieService())
        ->updateRatingScore($element_id);
}
