<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Event;

/**
 * Typed event for the legacy `batch_manager_register_filters` filter. No
 * handler is registered for it anywhere today. No context -- every real
 * call site passes only the filter set. Co-located here from `Piwigo\Event\Admin\BatchManagerRegisterFilters` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class BatchManagerRegisterFilters
{
    /**
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $bulkManagerFilter,
    ) {}
}
