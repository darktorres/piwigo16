<?php

declare(strict_types=1);

namespace Piwigo\Metadata\Event;

/**
 * Typed event for the legacy `clean_iptc_value` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the value. Co-located here from `Piwigo\Event\Picture\CleanIptcValue` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class CleanIptcValue
{
    public function __construct(
        public string $value,
    ) {}
}
