<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `batch_manager_register_filters` (dispatch).
 *
 * New in 2.7
 *
 * Dispatched from: src/Piwigo/Controller/Admin/BatchManagerController.php
 */
final readonly class BatchManagerRegisterFilters
{
    /**
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $bulkManagerFilter,
    ) {
    }
}
