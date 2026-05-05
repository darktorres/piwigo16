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


/** @return array<mixed>|false */
function rate_picture(int $image_id, float|int|null $rate): array|false
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateService::class)->ratePicture($image_id, $rate);
}

/** @return array<mixed> */
function update_rating_score(int|false $element_id = false): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateService::class)->updateRatingScore($element_id);
}
