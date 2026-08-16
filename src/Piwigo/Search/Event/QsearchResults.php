<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

use Piwigo\Search\QExpression;
use Piwigo\Search\QResults;

/**
 * Typed event for the legacy `qsearch_results` filter. No handler is
 * registered for it anywhere today. Lives under `Piwigo\Search\Event\`,
 * not `Piwigo\Event\Search\`, since it carries real
 * `Piwigo\Search\QExpression`/`QResults` instances -- deptrac's L0Data
 * layer may depend on nothing. `$searchResults` stays loosely
 * `array<mixed>` -- the one real consumer already merges the hook's
 * result back key-by-key, defensively filtering non-string keys via
 * `is_string()`, same reasoning as `QsearchGetScopes`. Mutable on
 * `$searchResults`; `$expression`/`$qsr` stay context.
 */
final class QsearchResults
{
    /**
     * @param array<mixed> $searchResults
     */
    public function __construct(
        public array $searchResults,
        public readonly QExpression $expression,
        public readonly QResults $qsr,
    ) {}
}
