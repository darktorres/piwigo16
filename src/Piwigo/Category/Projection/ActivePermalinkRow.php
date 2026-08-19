<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\Contract\HasGlobalRank;

/**
 * {@see \Piwigo\Category\CategoryRepository::findActivePermalinksList()}'s
 * own row shape -- {@see \Piwigo\Controller\Admin\PermalinksSubController}'s
 * own listing, its real (and only) consumer (through
 * {@see \Piwigo\Category\CategoryService::getActivePermalinksList()}'s
 * pass-through), which reads this object directly.
 */
final readonly class ActivePermalinkRow implements HasGlobalRank
{
    public function __construct(
        public int $id,
        public ?string $permalink,
        public string $uppercats,
        public ?string $globalRank,
    ) {}

    public function getGlobalRank(): ?string
    {
        return $this->globalRank;
    }

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
