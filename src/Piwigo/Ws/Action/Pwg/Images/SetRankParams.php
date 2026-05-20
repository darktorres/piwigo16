<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.setRank` input DTO.
 *
 * `rankSet` distinguishes "rank not supplied" (full-resort mode) from
 * "rank == 0" (which the original handler rejected as missing).
 */
final readonly class SetRankParams implements WsParams
{
    /** @param list<int> $imageIds */
    public function __construct(
        public array $imageIds,
        public int $categoryId,
        public ?int $rank,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $rawIds   = is_array($raw['image_id'] ?? null) ? $raw['image_id'] : [];
        $imageIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawIds,
        ));
        $rankIn = $raw['rank'] ?? null;
        $rank   = is_numeric($rankIn) && (int) $rankIn !== 0 ? (int) $rankIn : null;
        return new self(
            imageIds:   $imageIds,
            categoryId: is_numeric($raw['category_id'] ?? null) ? (int) $raw['category_id'] : 0,
            rank:       $rank,
        );
    }
}
