<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Further SQL-modernization audit, Item 13: replaces the `list<string>
 * $whereClauses` `Ws\PwgCore::getMissingDerivatives()` used to build --
 * an {@see ImageFilterCriteria}, plus an optional `id IN (...)`
 * restriction when the caller supplied specific ids.
 * `ImageRepository::findForMissingDerivatives()` applies these (plus its
 * own `id < :startId` cursor condition) internally.
 *
 * SQL-modernization audit, Item 14 Sub-phase C3: `$filterCondition` (a raw
 * `Piwigo\Permission\SqlCondition`) replaced by `$filterCriteria` (an
 * {@see ImageFilterCriteria}) -- see that class's own docblock.
 */
final readonly class MissingDerivativesCriteria
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public ImageFilterCriteria $filterCriteria,
        public array $ids = [],
    ) {}
}
