<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for the legacy `empty_lounge` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class EmptyLounge
{
    /**
     * @param list<array{image_id: int, category_id: int}> $rows
     */
    public function __construct(
        public array $rows,
    ) {}
}
