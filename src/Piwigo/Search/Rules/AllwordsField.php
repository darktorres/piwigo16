<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * Whitelist of columns the `allwords` saved-search filter can match
 * against. `CatTitle` / `CatDesc` are category-level fields that
 * `SearchService` joins via the parent category row; the other five
 * map to the image table directly.
 */
enum AllwordsField: string
{
    case File     = 'file';
    case Name     = 'name';
    case Comment  = 'comment';
    case Tags     = 'tags';
    case Author   = 'author';
    case CatTitle = 'cat-title';
    case CatDesc  = 'cat-desc';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
