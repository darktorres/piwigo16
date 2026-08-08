<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesAuthorizedViaGroupsForUser()}'s
 * own row shape -- {@see \Piwigo\Admin\UserPermPageRenderer}'s own
 * "authorized because of a group" display, its real (and only) consumer
 * (through {@see \Piwigo\Category\CategoryService::
 * getCategoriesAuthorizedViaGroupsForUser()}'s pass-through).
 *
 * `toArray()` exists for that consumer: it keeps its existing plain-array
 * contract and `usort()`s the result via the generic, `array`-typed
 * {@see \Piwigo\Category\CategoryService::compareByGlobalRank()}.
 */
final readonly class CategoryGroupAuthorizationRow
{
    public function __construct(
        public int $catId,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    /**
     * @return array{cat_id: int, uppercats: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'cat_id' => $this->catId,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
