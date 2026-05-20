<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** (author, counter) row from SearchRepository::findAuthorsForFilter(). */
final readonly class AuthorCountRow
{
    public function __construct(
        public string $author,
        public int    $counter,
    ) {
    }
}
