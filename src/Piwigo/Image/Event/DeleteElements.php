<?php

declare(strict_types=1);

namespace Piwigo\Image\Event;

/**
 * Typed event for the legacy `delete_elements` notification. No handler
 * is registered for it anywhere today. Co-located here from `Piwigo\Event\Picture\DeleteElements` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
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
