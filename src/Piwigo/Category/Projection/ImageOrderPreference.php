<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryService::getPreferredImageOrders()}'s own
 * per-option row -- `$label` is the display text, `$orderBy` the real SQL
 * `ORDER BY` fragment (e.g. `"name ASC"`), `$visible` whether the option
 * should be offered at all (some options are conditionally hidden, e.g.
 * rating-based ordering when rating is disabled).
 */
final readonly class ImageOrderPreference
{
    public function __construct(
        public string $label,
        public string $orderBy,
        public bool $visible,
    ) {}
}
