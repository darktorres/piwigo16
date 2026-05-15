<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `set_status_header` (notify).
 *
 * Dispatched from: src/Piwigo/Html/HtmlService.php
 */
final readonly class SetStatusHeader
{
    public function __construct(
        public int $code,
        public string $text,
    ) {
    }
}
