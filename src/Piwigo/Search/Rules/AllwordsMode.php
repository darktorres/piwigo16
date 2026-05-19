<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * Search-rule allwords/tags mode: whether the search input words
 * must all match (AND) or any may match (OR). Persisted as the
 * literal string 'AND' / 'OR' inside the saved-search JSON.
 */
enum AllwordsMode: string
{
    case And = 'AND';
    case Or  = 'OR';

    /**
     * Parse the saved-search JSON value. Unknown / missing input
     * falls back to AND (the gallery search form default).
     */
    public static function tryParse(mixed $value): self
    {
        if (is_string($value)) {
            return self::tryFrom($value) ?? self::And;
        }
        return self::And;
    }
}
