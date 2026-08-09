<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Replaces the `list<string> $whereClauses`
 * `Ws\PwgCore::getMissingDerivatives()` used to build -- an
 * {@see ImageFilterCriteria}, plus an optional `id IN (...)` restriction
 * when the caller supplied specific ids.
 * `ImageRepository::findForMissingDerivatives()` applies these (plus its
 * own `id < :startId` cursor condition) internally.
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
