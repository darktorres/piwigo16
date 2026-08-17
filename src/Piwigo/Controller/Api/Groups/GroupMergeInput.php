<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

/**
 * `POST /api/v1/groups/actions/merge` body DTO -- mirrors
 * `Ws\Groups\MergeParams`'s own `destination_group_id`/`merge_group_id`
 * fields.
 */
final readonly class GroupMergeInput
{
    /**
     * @param list<int> $mergeGroupIds
     */
    public function __construct(
        public int $destinationGroupId,
        public array $mergeGroupIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $destinationGroupId = $raw['destinationGroupId'] ?? null;

        return new self(
            destinationGroupId: is_int($destinationGroupId) ? $destinationGroupId : 0,
            mergeGroupIds: self::intList($raw['mergeGroupIds'] ?? null),
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
