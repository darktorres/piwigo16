<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** Physical (dir-backed) category row as returned by CategoryRepository::findPhysicalSyncableForSite(). */
final readonly class PhysicalCategoryRow
{
    public function __construct(
        public int $id,
        public int|null $idUppercat,
        public string $uppercats,
        public string|null $globalRank,
        public string $status,
        public bool $visible,
    ) {
    }
}
