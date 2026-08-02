<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `clean_iptc_value` filter. No handler is
 * registered for it anywhere today.
 */
final readonly class CleanIptcValue
{
    public function __construct(
        public string $value,
    ) {}
}
