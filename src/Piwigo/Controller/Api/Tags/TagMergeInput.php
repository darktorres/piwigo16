<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

/**
 * `POST /api/v1/tags/actions/merge` body DTO.
 */
final readonly class TagMergeInput
{
    /**
     * @param list<int> $mergeTagIds
     */
    public function __construct(
        public int $destinationTagId,
        public array $mergeTagIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $destinationTagId = $raw['destinationTagId'] ?? null;

        return new self(
            destinationTagId: is_int($destinationTagId) ? $destinationTagId : 0,
            mergeTagIds: self::intList($raw['mergeTagIds'] ?? null),
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
