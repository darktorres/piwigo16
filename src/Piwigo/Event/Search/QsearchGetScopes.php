<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_get_scopes` (dispatch).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchGetScopes
{
    /**
     * @param array<mixed> $scopes
     */
    public function __construct(
        public array $scopes,
    ) {
    }
}
