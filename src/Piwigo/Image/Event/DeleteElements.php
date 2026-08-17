<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

/**
 * Typed event for the legacy `delete_elements` notification. No handler
 * is registered for it anywhere today.
 */
final readonly class DeleteElements
{
    /**
     * @param array<int, int|string> $ids
     */
    public function __construct(
        public array $ids,
    ) {}
}
