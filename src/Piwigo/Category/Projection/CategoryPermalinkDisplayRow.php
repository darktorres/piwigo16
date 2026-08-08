<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findAllForPermalinksDisplay()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryService::
 * displaySelectForPermalinks()}'s real (and only) consumer. `name` is
 * already the permalink-aware display label the SQL computes (`"123 -
 * Album name"`, with a checkmark suffix when a permalink is set), not the
 * plain `categories.name` column.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * `displaySelectForPermalinks()` feeds this into the same
 * `sortAndDisplaySelectCategories()`/`displaySelectCategories()` pipeline
 * every other category-listing DTO in this class does, which needs a
 * plain array.
 */
final readonly class CategoryPermalinkDisplayRow
{
    public function __construct(
        public int $id,
        public ?string $permalink,
        public string $name,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    /**
     * @return array{id: int, permalink: ?string, name: string, uppercats: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'permalink' => $this->permalink,
            'name' => $this->name,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
