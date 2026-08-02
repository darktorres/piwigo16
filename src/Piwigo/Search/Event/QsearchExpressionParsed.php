<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

use Piwigo\Search\QExpression;

/**
 * Typed event for the legacy `qsearch_expression_parsed` notification.
 * No handler is registered for it anywhere today. Lives under
 * `Piwigo\Search\Event\`, not `Piwigo\Event\Search\`, since it carries a
 * real `Piwigo\Search\QExpression` instance -- deptrac's L0Data layer
 * may depend on nothing.
 */
final readonly class QsearchExpressionParsed
{
    public function __construct(
        public QExpression $expression,
    ) {}
}
