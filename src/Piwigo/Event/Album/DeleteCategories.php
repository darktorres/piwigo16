<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `delete_categories` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Category/CategoryAdminService.php
 */
final readonly class DeleteCategories
{
    /**
     * @param array<mixed> $ids
     */
    public function __construct(
        public array $ids,
    ) {
    }
}
