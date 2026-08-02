<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for the legacy `create_virtual_category` notification. No
 * handler is registered for it anywhere today.
 */
final readonly class CreateVirtualCategory
{
    /**
     * @param array<mixed> $category
     */
    public function __construct(
        public array $category,
    ) {}
}
