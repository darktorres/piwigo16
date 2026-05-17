<?php

declare(strict_types=1);

namespace Piwigo\Filter;

/**
 * Immutable value object holding the "recent photos" filter state for the
 * current request. Built once by FilterMiddleware and distributed via
 * FilterContextRegistry.
 *
 * `visibleCategories` / `visibleImages` carry typed `int[]` arrays of allowed
 * category/image ids. When the filter is on but no rows match, the field
 * holds `[-1]` (sentinel that won't match any real id). When the filter is
 * off, the field is `[]` and callers omit the clause.
 */
final readonly class FilterContext
{
    /**
     * @param array<int|string, array<string, mixed>> $categories Category rows keyed by id (used by FilterService)
     * @param list<int>                               $visibleCategories
     * @param list<int>                               $visibleImages
     */
    public function __construct(
        public bool  $enabled            = false,
        public int   $recentPeriod       = 0,
        public array $categories         = [],
        public array $visibleCategories  = [],
        public array $visibleImages      = [],
    ) {
    }
}
