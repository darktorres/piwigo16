<?php

declare(strict_types=1);

namespace Piwigo\Category\Event;

/**
 * Typed event for the legacy `get_category_preferred_image_orders`
 * filter. No handler is registered for it anywhere today. `$orders`
 * stays loosely `array<mixed>` (not the real
 * `array<int, array{0: string, 1: string, 2: bool}>` shape
 * `CategoryService::getPreferredImageOrders()` itself builds): the one
 * real consumer already defensively filters each element
 * (is_array()/isset()/is_string()), and a precise element type would
 * make PHPStan treat that filter as dead code. No context -- every real
 * call site passes only the orders list.
 */
final class GetCategoryPreferredImageOrders
{
    /**
     * @param array<mixed> $orders
     */
    public function __construct(
        public array $orders,
    ) {}
}
