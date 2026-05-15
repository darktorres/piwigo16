<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `empty_lounge` (notify).
 *
 * New in 12
 *
 * Dispatched from: src/Piwigo/Admin/Category/CategoryAdminService.php
 */
final readonly class EmptyLounge
{
    /**
     * @param array<mixed> $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }
}
