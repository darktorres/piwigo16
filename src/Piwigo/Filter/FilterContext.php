<?php

declare(strict_types=1);

namespace Piwigo\Filter;

/**
 * Immutable value object holding the "recent photos" filter state for the
 * current request. Built once by FilterMiddleware and distributed via
 * FilterContextRegistry.
 *
 * `visibleCategories` / `visibleImages` carry SQL-safe CSV lists. When the
 * filter is on but no rows match, the field holds the string "-1" so that
 * `IN ($visible)` matches nothing (the original sentinel from the legacy
 * `$GLOBALS['filter']` implementation). When the filter is off, the field
 * is `""` and callers omit the clause.
 */
final readonly class FilterContext
{
    /**
     * @param array<int|string, array<string, mixed>> $categories Category rows keyed by id (used by FilterService)
     */
    public function __construct(
        public bool   $enabled            = false,
        public int    $recentPeriod       = 0,
        public array  $categories         = [],
        public string $visibleCategories  = '',
        public string $visibleImages      = '',
    ) {
    }
}
