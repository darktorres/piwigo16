<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findActivePermalinksList()}'s
 * own row shape -- {@see \Piwigo\Controller\Admin\PermalinksSubController}'s
 * own listing, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getActivePermalinksList()}'s
 * pass-through).
 *
 * `toArray()` exists for that consumer: it splices a new `name` key onto
 * each row and `usort()`s the result via the generic, `array`-typed
 * {@see \Piwigo\Category\CategoryService::compareByGlobalRank()}.
 */
final readonly class ActivePermalinkRow
{
    public function __construct(
        public int $id,
        public ?string $permalink,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    /**
     * @return array{id: int, permalink: ?string, uppercats: string, global_rank: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'permalink' => $this->permalink,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
