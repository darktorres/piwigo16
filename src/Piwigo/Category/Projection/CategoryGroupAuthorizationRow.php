<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\Contract\HasGlobalRank;

/**
 * {@see \Piwigo\Category\CategoryRepository::findCategoriesAuthorizedViaGroupsForUser()}'s
 * own row shape -- {@see \Piwigo\Admin\UserPermPageRenderer}'s own
 * "authorized because of a group" display, its real (and only) consumer
 * (through {@see \Piwigo\Category\CategoryService::
 * getCategoriesAuthorizedViaGroupsForUser()}'s pass-through), which reads
 * this object directly.
 */
final readonly class CategoryGroupAuthorizationRow implements HasGlobalRank
{
    public function __construct(
        public int $catId,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    public function getGlobalRank(): ?string
    {
        return $this->globalRank;
    }

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
