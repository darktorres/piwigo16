<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

use Piwigo\Search\QExpression;
use Piwigo\Search\QsearchClause;
use Piwigo\Search\QSingleToken;

/**
 * Typed event for the legacy `qsearch_get_images_sql_scopes` filter. No
 * handler is registered for it anywhere today. Lives under
 * `Piwigo\Search\Event\`, not `Piwigo\Event\Search\`, since it carries
 * real `Piwigo\Search\QSingleToken`/`QExpression` instances -- deptrac's
 * L0Data layer may depend on nothing.
 *
 * SQL-modernization audit, Item 16K: `$clauses` retyped from loose
 * `array<mixed>` (the one real consumer used to defensively filter each
 * element via `is_string()`) to `list<QsearchClause>` -- a forward API
 * design change, not a live migration (zero real in-tree listeners exist
 * today, confirmed via a fresh grep), consistent with v17.0 already
 * breaking every PEM extension contract. See {@see QsearchClause}'s own
 * docblock for why this is a real fix, not just a type.
 */
final readonly class QsearchGetImagesSqlScopes
{
    /**
     * @param list<QsearchClause> $clauses
     */
    public function __construct(
        public array $clauses,
        public QSingleToken $token,
        public QExpression $expr,
    ) {}
}
