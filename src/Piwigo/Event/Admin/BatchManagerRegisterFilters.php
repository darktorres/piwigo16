<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `batch_manager_register_filters` filter. No
 * handler is registered for it anywhere today. No context -- every real
 * call site passes only the filter set.
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
