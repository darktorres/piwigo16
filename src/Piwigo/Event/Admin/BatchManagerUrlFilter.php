<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `batch_manager_url_filter` (dispatch).
 *
 * New in 2.7.
 *
 * Dispatched from: src/Piwigo/Controller/Admin/BatchManagerController.php
 */
final readonly class BatchManagerUrlFilter
{
    /**
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $bulkManagerFilter,
        public string $filter,
    ) {
    }

    /**
     * @param array<mixed> $bulkManagerFilter
     */
    public function withBulkManagerFilter(array $bulkManagerFilter): self
    {
        return new self($bulkManagerFilter, $this->filter);
    }
}
