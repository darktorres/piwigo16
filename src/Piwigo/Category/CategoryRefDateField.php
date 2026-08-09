<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * {@see CategoryRepository::findRefDatesByCategoryIds()}'s `$field`
 * parameter, enumerated -- its one real caller
 * ({@see \Piwigo\Admin\AlbumsPageRenderer}) only ever reaches this method
 * after `str_starts_with($order_by_field, 'date_')` narrows
 * `$_POST['order']` (itself already validated against a fixed 8-token
 * allowlist) down to exactly these 2 values.
 */
enum CategoryRefDateField
{
    case DateCreation;
    case DateAvailable;

    /**
     * DQL property path against the `i` (ImageEntity) alias
     * {@see CategoryRepository::findRefDatesByCategoryIds()} joins in.
     */
    public function dqlProperty(): string
    {
        return match ($this) {
            self::DateCreation => 'i.dateCreation',
            self::DateAvailable => 'i.dateAvailable',
        };
    }
}
