<?php

declare(strict_types=1);

namespace Piwigo\Event\Location;

/**
 * Typed event for the legacy `loc_end_add_format` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class LocEndAddFormat
{
    /**
     * @param array<mixed> $formatInfos
     */
    public function __construct(
        public array $formatInfos,
    ) {}
}
