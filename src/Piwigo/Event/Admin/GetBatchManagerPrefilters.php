<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `get_batch_manager_prefilters` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class GetBatchManagerPrefilters
{
    /**
     * @param array<mixed> $prefilters
     */
    public function __construct(
        public array $prefilters,
    ) {}
}
