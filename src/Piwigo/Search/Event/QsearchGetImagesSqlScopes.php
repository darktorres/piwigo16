<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

use Piwigo\Search\QExpression;
use Piwigo\Search\QSingleToken;

/**
 * Typed event for the legacy `qsearch_get_images_sql_scopes` filter. No
 * handler is registered for it anywhere today. Lives under
 * `Piwigo\Search\Event\`, not `Piwigo\Event\Search\`, since it carries
 * real `Piwigo\Search\QSingleToken`/`QExpression` instances -- deptrac's
 * L0Data layer may depend on nothing. `$clauses` stays loosely
 * `array<mixed>` -- the one real consumer already defensively filters
 * each element via `is_string()`, same reasoning as `QsearchGetScopes`.
 */
final readonly class QsearchGetImagesSqlScopes
{
    /**
     * @param array<mixed> $clauses
     */
    public function __construct(
        public array $clauses,
        public QSingleToken $token,
        public QExpression $expr,
    ) {}
}
