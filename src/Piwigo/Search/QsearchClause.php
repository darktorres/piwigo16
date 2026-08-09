<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * Typed replacement for the raw string entries
 * {@see \Piwigo\Search\Event\QsearchGetImagesSqlScopes}'s own
 * `$clauses` payload used to carry -- a boolean SQL fragment
 * ({@see \Piwigo\Search\SearchRepository::findIdsByClause()}'s own
 * `$whereSql`, OR-joined against every other scope's clause) plus its own
 * positional `?` values, same convention `SearchService::
 * qsearchGetImages()`'s own `photo`/`file`/`author` cases already use for
 * `$fileLike`/`qsearchGetTextTokenSearchSql()` (unlike the numeric-range
 * scopes' `get_sql()`, which hand-embeds already-validated numeric
 * literals directly and needs no params at all).
 *
 * Fixes a real latent gap, not just a type: the old `array<mixed>` payload
 * had no params slot whatsoever, so a listener wanting to match against a
 * caller-controlled value had only 2 options -- hand-embed it unescaped
 * (a real injection risk) or {@see SearchRepository::quote()}'s literal-
 * embedding escape hatch. A `?`-bound clause is now possible instead.
 */
final readonly class QsearchClause
{
    /**
     * @param list<int|float|string> $params positional values matching $sql's own '?' placeholders, in order
     */
    public function __construct(
        public string $sql,
        public array $params = [],
    ) {}
}
