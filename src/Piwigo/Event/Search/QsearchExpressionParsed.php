<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_expression_parsed` (notify).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchExpressionParsed
{
    public function __construct(
        public \Piwigo\Search\QExpression $expression,
    ) {
    }
}
