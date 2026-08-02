<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for the legacy `set_status_header` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class SetStatusHeader
{
    public function __construct(
        public int $code,
        public string $text,
    ) {}
}
