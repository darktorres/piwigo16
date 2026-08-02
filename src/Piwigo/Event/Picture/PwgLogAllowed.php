<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `pwg_log_allowed` filter. No handler is
 * registered for it anywhere today. `$imageType` is a plain `?string`,
 * not the reference's `?ImageType` enum -- that type doesn't exist on
 * this branch yet (confirmed against `HistoryService::isLoggingAllowed()`'s
 * own real `?string $imageType` parameter).
 */
final readonly class PwgLogAllowed
{
    public function __construct(
        public bool $doLog,
        public ?int $imageId,
        public ?string $imageType,
    ) {}
}
