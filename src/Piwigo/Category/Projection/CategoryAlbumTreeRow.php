<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findAllForAlbumTree()}'s own
 * row shape -- {@see \Piwigo\Admin\AlbumsPageRenderer}'s own full
 * album-tree listing, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getAllForAlbumTree()}'s
 * pass-through).
 *
 * `toArray()` exists for that consumer: it keeps its existing plain-array
 * contract and mutates each row's `name`/`lastmodified` keys (an
 * event-dispatched display name, and a "time since" relative label).
 */
final readonly class CategoryAlbumTreeRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $rank,
        public string $status,
        public bool $visible,
        public string $uppercats,
        public string $lastmodified,
    ) {}

    /**
     * @return array{id: int, name: string, rank: ?int, status: string, visible: bool, uppercats: string, lastmodified: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rank' => $this->rank,
            'status' => $this->status,
            'visible' => $this->visible,
            'uppercats' => $this->uppercats,
            'lastmodified' => $this->lastmodified,
        ];
    }
}
