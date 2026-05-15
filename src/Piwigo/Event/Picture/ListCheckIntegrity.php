<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `list_check_integrity` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Integrity/CheckIntegrity.php (CheckIntegrity::check)
 */
final readonly class ListCheckIntegrity
{
    public function __construct(
        public object $value,
    ) {
    }
}
