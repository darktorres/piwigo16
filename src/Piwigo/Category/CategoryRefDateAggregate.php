<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::findRefDatesByCategoryIds()}'s
 * `$minmax` parameter, enumerated -- its one real caller
 * ({@see \Piwigo\Admin\AlbumsPageRenderer}) only ever passes `'min'` or
 * `'max'` (a ternary on ascending/descending sort order), confirmed via a
 * fresh trace before converting.
 */
enum CategoryRefDateAggregate
{
    case Min;
    case Max;

    public function sqlFunction(): string
    {
        return match ($this) {
            self::Min => 'MIN',
            self::Max => 'MAX',
        };
    }
}
