<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

use Piwigo\Common\ValueObject\ImageId;

/**
 * Typed event for the legacy `pwg_log_allowed` filter. No handler is
 * registered for it anywhere today. `$imageType` is a plain `?string`,
 * not the reference's `?ImageType` enum -- that type doesn't exist on
 * this branch yet (confirmed against `HistoryService::isLoggingAllowed()`'s
 * own real `?string $imageType` parameter). Mutable on `$doLog`;
 * `$imageId`/`$imageType` stay context.
 */
final class LogAllowed
{
    public function __construct(
        public bool $doLog,
        public readonly ?ImageId $imageId,
        public readonly ?string $imageType,
    ) {}
}
