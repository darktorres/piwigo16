<?php

declare(strict_types=1);

namespace Piwigo\Event\Album;

/**
 * Typed event for legacy `get_category_preferred_image_orders` (dispatch).
 *
 * Dispatched from: src/Piwigo/Category/CategoryService.php
 */
final readonly class GetCategoryPreferredImageOrders
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
    ) {
    }

    /**
     * @param array<mixed> $value
     */
    public function withValue(array $value): self
    {
        return new self($value);
    }
}
