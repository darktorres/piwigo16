<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for legacy `delete_tags` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Tag/TagAdminService.php
 */
final readonly class DeleteTags
{
    /**
     * @param array<mixed> $tagIds
     */
    public function __construct(
        public array $tagIds,
    ) {
    }
}
