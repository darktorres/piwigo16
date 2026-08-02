<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Permission\SqlCondition;

/**
 * Further SQL-modernization audit, Item 13: replaces the `list<string>
 * $whereClauses` `Ws\PwgCore::getMissingDerivatives()` used to build --
 * `WsHelper::stdImageSqlFilter()`'s own SqlCondition, plus an optional
 * `id IN (...)` restriction when the caller supplied specific ids.
 * `ImageRepository::findForMissingDerivatives()` combines these (plus its
 * own `id < :startId` cursor condition) internally via
 * `SqlCondition::combine()`.
 */
final readonly class MissingDerivativesCriteria
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public SqlCondition $filterCondition,
        public array $ids = [],
    ) {}
}
