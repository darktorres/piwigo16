<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

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
 * their real (and only) shared consumer.
 *
 * `toArray()` exists for that consumer: it `usort()`s the result via the
 * generic, cross-domain {@see \Piwigo\Category\CategoryService::
 * compareByGlobalRank()} comparator (deliberately `array`-typed so it stays
 * reusable across several differently-shaped rows, not just this one), so
 * this DTO is unwrapped to a plain array once, right where it arrives.
 */
final readonly class CategoryIdNameUppercatsRank
{
    public function __construct(
        public int $id,
        public string $name,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

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
