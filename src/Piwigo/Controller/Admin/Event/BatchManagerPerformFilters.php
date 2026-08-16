<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Event;

/**
 * Typed event for the legacy `batch_manager_perform_filters` filter. No
 * handler is registered for it anywhere today. Mutable on `$filterSets`;
 * `$bulkManagerFilter` stays context. Co-located here from `Piwigo\Event\Admin\BatchManagerPerformFilters` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class BatchManagerPerformFilters
{
    /**
     * @param array<mixed> $filterSets
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $filterSets,
        public readonly array $bulkManagerFilter,
    ) {}
}
