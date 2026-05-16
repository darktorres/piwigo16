<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `perform_batch_manager_prefilters` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/BatchManagerController.php
 */
final readonly class PerformBatchManagerPrefilters
{
    /**
     * @param array<mixed> $filterSets
     */
    public function __construct(
        public array $filterSets,
        public string $prefilter,
    ) {
    }
}
