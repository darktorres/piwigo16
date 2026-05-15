<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `batch_manager_perform_filters` (dispatch).
 *
 * New in 2.7
 *
 * Dispatched from: src/Piwigo/Controller/Admin/BatchManagerController.php
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
    ) {
    }

    /**
     * @param array<mixed> $filterSets
     */
    public function withFilterSets(array $filterSets): self
    {
        return new self($filterSets, $this->bulkManagerFilter);
    }
}
