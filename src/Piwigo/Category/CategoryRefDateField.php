<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::findRefDatesByCategoryIds()}'s
 * `$field` parameter, enumerated -- its one real caller
 * ({@see \Piwigo\Admin\AlbumsPageRenderer}) only ever reaches this method
 * after `str_starts_with($order_by_field, 'date_')` narrows
 * `$_POST['order']` (itself already validated against a fixed 8-token
 * allowlist) down to exactly these 2 values, confirmed via a fresh trace
 * before converting.
 */
enum CategoryRefDateField
{
    case DateCreation;
    case DateAvailable;

    public function column(): string
    {
        return match ($this) {
            self::DateCreation => 'date_creation',
            self::DateAvailable => 'date_available',
        };
    }
}
