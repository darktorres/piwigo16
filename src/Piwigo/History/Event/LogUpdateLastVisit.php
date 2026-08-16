<?php

declare(strict_types=1);

namespace Piwigo\History\Event;

/**
 * Typed event for the legacy `pwg_log_update_last_visit` filter. No
 * handler is registered for it anywhere today. No context -- every real
 * call site passes only the flag. Co-located here from `Piwigo\Event\Picture\LogUpdateLastVisit` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class LogUpdateLastVisit
{
    public function __construct(
        public bool $update,
    ) {}
}
