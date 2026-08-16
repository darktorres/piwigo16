<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Event;

/**
 * Typed event for the legacy `get_batch_manager_prefilters` filter. No
 * handler is registered for it anywhere today. No context -- every real
 * call site passes only the prefilter list. Co-located here from `Piwigo\Event\Admin\GetBatchManagerPrefilters` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetBatchManagerPrefilters
{
    /**
     * @param array<mixed> $prefilters
     */
    public function __construct(
        public array $prefilters,
    ) {}
}
