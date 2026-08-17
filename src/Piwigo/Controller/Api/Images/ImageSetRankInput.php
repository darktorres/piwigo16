<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/set-rank` body DTO -- an `imageIds` list
 * with more than one entry means "here is the full, already-ordered list
 * for this album" (`rank` becomes meaningless); exactly one id plus
 * `rank` means "insert this one photo at this position among its album
 * siblings".
 */
final readonly class ImageSetRankInput
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $categoryId,
        public ?int $rank,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $categoryId = $raw['categoryId'] ?? null;
        $rank = $raw['rank'] ?? null;

        return new self(
            imageIds: self::intList($raw['imageIds'] ?? null),
            categoryId: is_int($categoryId) ? $categoryId : 0,
            rank: is_int($rank) ? $rank : null,
        );
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }
}
