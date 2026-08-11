<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `pwg_log_update_last_visit` filter. No
 * handler is registered for it anywhere today.
 */
final readonly class LogUpdateLastVisit
{
    public function __construct(
        public bool $update,
    ) {}
}
