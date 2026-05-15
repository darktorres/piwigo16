<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_before_eval` (notify).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchBeforeEval
{
    public function __construct(
        public \Piwigo\Search\QExpression $expression,
        public object $qsr,
    ) {
    }
}
