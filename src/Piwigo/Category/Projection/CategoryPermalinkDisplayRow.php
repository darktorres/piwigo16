<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Common\Contract\HasGlobalRank;

/**
 * {@see \Piwigo\Category\CategoryRepository::findAllForPermalinksDisplay()}'s
 * own row shape -- {@see \Piwigo\Category\CategoryService::
 * displaySelectForPermalinks()}'s real (and only) consumer. `name` is
 * already the permalink-aware display label the SQL computes (`"123 -
 * Album name"`, with a checkmark suffix when a permalink is set), not the
 * plain `categories.name` column. Feeds into the same
 * `sortAndDisplaySelectCategories()`/`displaySelectCategories()` pipeline
 * every other category-listing DTO in this class does, which reads this
 * object directly.
 */
final readonly class CategoryPermalinkDisplayRow implements HasGlobalRank
{
    public function __construct(
        public int $id,
        public ?string $permalink,
        public string $name,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    #[Override]
    public function getGlobalRank(): ?string
    {
        return $this->globalRank;
    }

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
