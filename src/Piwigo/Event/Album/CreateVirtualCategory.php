<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `create_virtual_category` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Category/CategoryAdminService.php
 */
final readonly class CreateVirtualCategory
{
    /**
     * @param array<mixed> $category
     */
    public function __construct(
        public array $category,
    ) {
    }
}
