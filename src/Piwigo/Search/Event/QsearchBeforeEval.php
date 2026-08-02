<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

use Piwigo\Search\QExpression;
use Piwigo\Search\QResults;

/**
 * Typed event for the legacy `qsearch_before_eval` notification. No
 * handler is registered for it anywhere today. Lives under
 * `Piwigo\Search\Event\`, not `Piwigo\Event\Search\`, since it carries
 * real `Piwigo\Search\QExpression`/`QResults` instances -- deptrac's
 * L0Data layer may depend on nothing.
 */
final readonly class QsearchBeforeEval
{
    public function __construct(
        public QExpression $expression,
        public QResults $qsr,
    ) {}
}
