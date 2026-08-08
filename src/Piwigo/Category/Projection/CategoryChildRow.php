<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findChildrenOfParent()}'s own
 * row shape -- {@see \Piwigo\Admin\CatListPageRenderer}'s own album
 * listing, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getChildrenOfParent()}'s
 * pass-through).
 *
 * `toArray()` exists for that consumer: it keeps its existing plain-array
 * return contract.
 */
final readonly class CategoryChildRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
        public ?string $dir,
        public ?int $rank,
        public string $status,
    ) {}

    /**
     * @return array{id: int, name: string, permalink: ?string, dir: ?string, rank: ?int, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
            'dir' => $this->dir,
            'rank' => $this->rank,
            'status' => $this->status,
        ];
    }
}
