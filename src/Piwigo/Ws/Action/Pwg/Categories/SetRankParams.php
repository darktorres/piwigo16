<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.setRank` input DTO.
 *
 * Two modes:
 *   - Single-category mode: 1 entry in categoryIds + a non-zero rank → move that category to position `rank`.
 *   - Full-resort mode: all sub-categories of a parent are listed in their new order; `rank` is ignored.
 */
final readonly class SetRankParams implements WsParams
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public array $categoryIds,
        public int $rank,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $rawIds = is_array($raw['category_id'] ?? null) ? $raw['category_id'] : [];
        $categoryIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawIds,
        ));
        return new self(
            categoryIds: $categoryIds,
            rank:        is_numeric($raw['rank'] ?? null) ? (int) $raw['rank'] : 0,
        );
    }
}
