<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `begin_delete_elements` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Image/ImageAdminService.php
 */
final readonly class BeginDeleteElements
{
    /**
     * @param array<mixed> $ids
     */
    public function __construct(
        public array $ids,
    ) {
    }
}
