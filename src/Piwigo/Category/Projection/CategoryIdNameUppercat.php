<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findIdsNamesUppercatsForIds()}'s
 * own row shape -- {@see \Piwigo\Admin\AlbumsPageRenderer}'s own auto-order
 * sort-and-save step, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getIdsNamesUppercatsForIds()}'s
 * pass-through).
 *
 * `toArray()` exists for that consumer: it keeps its existing plain-array
 * contract and mutates each row's `name` key with an event-dispatched
 * display name.
 */
final readonly class CategoryIdNameUppercat
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $idUppercat,
    ) {}

    /**
     * @return array{id: int, name: string, id_uppercat: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'id_uppercat' => $this->idUppercat,
        ];
    }
}
