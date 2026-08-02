<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `batch_manager_url_filter` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class BatchManagerUrlFilter
{
    /**
     * @param array<mixed> $bulkManagerFilter
     */
    public function __construct(
        public array $bulkManagerFilter,
        public string $filter,
    ) {}
}
