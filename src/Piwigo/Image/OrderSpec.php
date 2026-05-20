<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Common\Enum\SortOrder;

/**
 * One `field DIR` clause inside the structured `order_by*` config
 * values that {@see OrderByService} renders to SQL. Replaces the
 * `array{field: string, dir: string}` shape that was previously
 * threaded through Config / OrderByService / SectionInitializer /
 * ConfigurationController as a positional dictionary.
 *
 * `$field` is constrained by callers to {@see OrderByService::ALLOWED_FIELDS};
 * the VO itself does not validate it — that gate lives in
 * `OrderByService::parseFormToken()` which is the only path that
 * accepts free-form admin input.
 */
final readonly class OrderSpec
{
    public function __construct(
        public string    $field,
        public SortOrder $dir,
    ) {
    }
}
