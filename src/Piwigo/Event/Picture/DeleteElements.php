<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `delete_elements` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Image/ImageAdminService.php
 */
final readonly class DeleteElements
{
    /**
     * @param array<mixed> $ids
     */
    public function __construct(
        public array $ids,
    ) {
    }
}
