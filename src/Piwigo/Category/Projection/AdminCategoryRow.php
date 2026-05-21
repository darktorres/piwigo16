<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** Admin-list projection row returned by CategoryRepository::findAdminListPage(). */
final readonly class AdminCategoryRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string|null $comment,
        public string $uppercats,
        public string|null $globalRank,
        public string|null $dir,
        public string $status,
        public string|null $imageOrder,
    ) {
    }
}
