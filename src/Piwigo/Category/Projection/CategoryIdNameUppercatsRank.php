<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\Contract\HasGlobalRank;

/**
 * Shared `id`/`name`/`uppercats`/`global_rank` row shape for 9
 * {@see \Piwigo\Category\CategoryRepository} methods, all narrowed through
 * its own private `narrowIdNameUppercatsRankRows()` helper
 * (`findByCommentable()`, `findByVisible()`, `findByStatus()`,
 * `findByRepresentativePresence()`, `findPrivateCategoriesGrantedToUser()`,
 * `findPrivateCategoriesGrantedToGroup()`,
 * `findPrivateCategoriesExcluding()`, `findIdNameUppercatsRank()`,
 * `findIdNameUppercatsRankBySite()`) -- every one of them funnels into
 * {@see \Piwigo\Category\CategoryService::sortAndDisplaySelectCategories()},
 * their real (and only) shared consumer, which reads this object directly.
 */
final readonly class CategoryIdNameUppercatsRank implements HasGlobalRank
{
    public function __construct(
        public int $id,
        public string $name,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    public function getGlobalRank(): ?string
    {
        return $this->globalRank;
    }

    /**
     * @return array{id: int, name: string, uppercats: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
