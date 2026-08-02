<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `batch_manager_perform_filters` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class BatchManagerPerformFilters
{
    /**
     * @param array<mixed> $filterSets
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $filterSets,
        public array $bulkManagerFilter,
    ) {}
}
