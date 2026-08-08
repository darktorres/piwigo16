<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesForRankUpdate()}'s
 * own row shape -- shared by {@see \Piwigo\Category\CategoryService::
 * updateGlobalRank()} and {@see \Piwigo\Category\CategoryService::
 * updateUppercats()}, its only 2 real callers.
 */
final readonly class CategoryRankUpdateRow
{
    public function __construct(
        public int $id,
        public ?int $idUppercat,
        public string $uppercats,
        public ?int $rank,
        public ?string $globalRank,
    ) {}
}
