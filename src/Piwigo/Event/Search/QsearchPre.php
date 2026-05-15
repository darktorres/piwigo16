<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_pre` (dispatch).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchPre
{
    public function __construct(
        public string $query,
    ) {
    }

    public function withQuery(string $query): self
    {
        return new self($query);
    }
}
