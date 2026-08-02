<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for the legacy `delete_categories` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class DeleteCategories
{
    /**
     * @param array<int, int> $ids
     */
    public function __construct(
        public array $ids,
    ) {}
}
