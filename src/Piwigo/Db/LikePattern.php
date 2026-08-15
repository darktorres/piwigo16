<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * Builds `LIKE` patterns from untrusted text.
 *
 * `%` and `_` are wildcards inside a `LIKE` pattern, and a bound parameter
 * does not neutralise them -- binding is about SQL injection, not about
 * pattern semantics. Interpolating a raw search term into `'%' . $term . '%'`
 * therefore makes a user searching for `100%` match every row, and one
 * searching `foo_bar` match `fooXbar`.
 *
 * Escaping is done with a backslash, which is the default `LIKE` escape
 * character on both supported engines, and matches the two sites that
 * already did this by hand ({@see \Piwigo\Search\SearchService}'s own
 * REGEXP/FULLTEXT branches). The escape character itself is escaped first,
 * or a term containing a literal backslash would consume the wildcard
 * escape that follows it.
 *
 * Caveat worth knowing: MySQL's `NO_BACKSLASH_ESCAPES` sql_mode disables
 * that default. It is not part of strict mode and this codebase does not
 * enable it, but a server configured with it would need an explicit
 * `ESCAPE` clause at each call site instead.
 */
final class LikePattern
{
    /**
     * Escapes the pattern metacharacters in $value, leaving it safe to embed
     * in a `LIKE` pattern as a literal.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * `%term%` -- matches $value anywhere, with $value itself treated as a
     * literal.
     */
    public static function containing(string $value): string
    {
        return '%' . self::escape($value) . '%';
    }

    /**
     * `term%` -- matches rows beginning with $value.
     */
    public static function startingWith(string $value): string
    {
        return self::escape($value) . '%';
    }
}
