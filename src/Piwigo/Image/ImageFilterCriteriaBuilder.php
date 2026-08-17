<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Piwigo\Core\DateHelper;
use Piwigo\Core\OperationError;

/**
 * Builds the shared `f_*` image-filter criteria (images table only) that
 * every real `/api/v1` image-listing endpoint accepts (`ImageSearchController`,
 * `ImageMissingDerivativesController`, `CategoryImagesController`,
 * `TagImagesController`) -- moved here from `Piwigo\Ws` (P25 Stage 1
 * step 6 first split it out of the former WsHelper god-class; P27 moved
 * it again when the WS layer itself was deleted, since this class turned
 * out to be real, live domain logic every REST family above still needs,
 * not WS-protocol-specific).
 *
 * The `1003` literal below is `Ws\WsError::InvalidParam->value` inlined
 * -- no real caller reads `OperationError::code()` anymore (every REST
 * controller reads only `->message()`, mapping it onto its own real HTTP
 * status via `ResponseFactory::problem()`), so keeping the whole
 * WS-protocol numeric error-code taxonomy alive just for this one
 * call site wasn't worth it.
 */
final readonly class ImageFilterCriteriaBuilder
{
    /**
     * $params's own float|null/int|null typing already guarantees each
     * f_* value's real type -- the 4 date fields are validated below via
     * DateHelper::isValidMysqlDatetime(), which round-trips through
     * DateTime::createFromFormat() and can never let a
     * non-digit/hyphen/space/colon character through.
     *
     * @param array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     */
    public function stdImageSqlFilterCriteria(array $params): ImageFilterCriteria|OperationError
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) and ! DateHelper::isValidMysqlDatetime($params[$datefield])) {
                return new OperationError('Invalid ' . $datefield);
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
