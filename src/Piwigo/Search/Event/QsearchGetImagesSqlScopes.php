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
 * `$clauses` is `list<QsearchClause>`, not a loose `array<mixed>` --
 * zero real in-tree listeners exist today, consistent with v17.0
 * already breaking every PEM extension contract. See
 * {@see QsearchClause}'s own docblock for why this is a real fix, not
 * just a type. Mutable on `$clauses`; `$token`/`$expr` stay context.
 */
final class QsearchGetImagesSqlScopes
{
    /**
     * @param list<QsearchClause> $clauses
     */
    public function __construct(
        public array $clauses,
        public readonly QSingleToken $token,
        public readonly QExpression $expr,
    ) {}
}
