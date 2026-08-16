<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Core\DateHelper;
use Piwigo\Image\ImageFilterCriteria;

/**
 * Builds the "standard" (for our web service) image-filter criteria --
 * the shared f_* range-filter set (images table only). Split out of the
 * former WsHelper god-class (P25 Stage 1 step 6).
 *
 * Called from every WS method that merges ws.php's shared $f_params into
 * its registration (pwg.images.search, pwg.categories.getImages,
 * pwg.getMissingDerivatives, pwg.tags.getImages) -- all 11 f_* keys are
 * always present, per that shared registration block.
 */
final readonly class ImageFilterCriteriaBuilder
{
    /**
     * $params's own float|null/int|null typing (Server's own
     * WsParamType::FLOAT/WsParamType::INT coercion, per this method's own
     * $params shape below) already guarantees each f_* value's real type --
     * the 4 date fields are validated below via
     * DateHelper::isValidMysqlDatetime(), which round-trips through
     * DateTime::createFromFormat() and can never let a
     * non-digit/hyphen/space/colon character through.
     *
     * @param array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     */
    public function stdImageSqlFilterCriteria(array $params): ImageFilterCriteria|WsErrorResponse
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) and ! DateHelper::isValidMysqlDatetime($params[$datefield])) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid ' . $datefield);
            }
        }

        return new ImageFilterCriteria(
            minRate: $params['f_min_rate'],
            maxRate: $params['f_max_rate'],
            minHit: $params['f_min_hit'],
            maxHit: $params['f_max_hit'],
            minDateAvailable: $params['f_min_date_available'],
            maxDateAvailable: $params['f_max_date_available'],
            minDateCreated: $params['f_min_date_created'],
            maxDateCreated: $params['f_max_date_created'],
            minRatio: $params['f_min_ratio'],
            maxRatio: $params['f_max_ratio'],
            maxLevel: $params['f_max_level'],
        );
    }
}
