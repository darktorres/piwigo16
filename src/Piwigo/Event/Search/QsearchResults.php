<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_results` (dispatch).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchResults
{
    /**
     * @param array<mixed> $results
     */
    public function __construct(
        public array $results,
        public \Piwigo\Search\QExpression $expression,
        public object $qsr,
    ) {
    }
}
