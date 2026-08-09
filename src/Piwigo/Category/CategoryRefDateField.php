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
 *
 * Item 16I: {@see dqlProperty()} added and `findRefDatesByCategoryIds()`
 * converted to real DQL -- the original "stays on DBAL, DQL aggregates
 * take a fixed property path, closing the string->enum gap doesn't need
 * the extra DQL-rewrite risk on top" reasoning was a "not worth it" call,
 * not a real structural blocker; re-scoped and converted since the
 * per-case dispatch this needs is exactly as simple as the raw-SQL
 * column-name variant this replaced. (That raw-SQL variant, `column()`,
 * was deleted once dqlProperty() took over its one real caller -- found
 * as genuinely dead by shipmonk/dead-code-detector.)
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
