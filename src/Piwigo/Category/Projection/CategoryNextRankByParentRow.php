<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findNextRanksByParent()}'s own
 * row shape -- {@see \Piwigo\Controller\Admin\SiteUpdateSubController}'s
 * own "does this parent already have sub-categories, and if so what's the
 * next free rank" step, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getNextRanksByParent()}'s
 * pass-through).
 *
 * `toArray()` exists for that consumer: it mutates each row's
 * `id_uppercat` key (a `'NULL'` string sentinel replacing a real `null`).
 */
final readonly class CategoryNextRankByParentRow
{
    public function __construct(
        public ?int $idUppercat,
        public ?int $nextRank,
    ) {}

    /**
     * @return array{id_uppercat: ?int, next_rank: ?int}
     */
    public function toArray(): array
    {
        return [
            'id_uppercat' => $this->idUppercat,
            'next_rank' => $this->nextRank,
        ];
    }
}
