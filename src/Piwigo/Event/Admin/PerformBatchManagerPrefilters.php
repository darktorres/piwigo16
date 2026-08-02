<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `perform_batch_manager_prefilters` filter.
 * No handler is registered for it anywhere today.
 */
final readonly class PerformBatchManagerPrefilters
{
    /**
     * @param array<mixed> $filterSets
     */
    public function __construct(
        public array $filterSets,
        public string $prefilter,
    ) {}
}
