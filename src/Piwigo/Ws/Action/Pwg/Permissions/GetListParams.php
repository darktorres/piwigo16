<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Permissions;

use Piwigo\Ws\WsParams;

/**
 * `pwg.permissions.getList` input DTO.
 *
 * Exactly one of {catIds, groupIds, userIds} can be provided. The
 * handler enforces that constraint; the DTO only normalizes input.
 * `mode-filter present` is tracked via the `groupIdsSet` / `userIdsSet`
 * flags so the handler can replicate the original isset() semantics
 * (an empty list filter still drops all rows).
 */
final readonly class GetListParams implements WsParams
{
    /**
     * @param ?list<int>    $catIds
     * @param list<string>  $groupIds
     * @param list<string>  $userIds
     */
    public function __construct(
        public ?array $catIds,
        public array $groupIds,
        public bool $groupIdsSet,
        public array $userIds,
        public bool $userIdsSet,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $catRaw   = $raw['cat_id']   ?? null;
        $catIds   = null;
        if (is_array($catRaw) && count($catRaw) > 0) {
            $catIds = array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $catRaw,
            ));
        }
        $groupRaw     = $raw['group_id'] ?? null;
        $groupIdsSet  = array_key_exists('group_id', $raw);
        $groupIds     = is_array($groupRaw)
            ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $groupRaw))
            : [];
        $userRaw     = $raw['user_id'] ?? null;
        $userIdsSet  = array_key_exists('user_id', $raw);
        $userIds     = is_array($userRaw)
            ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userRaw))
            : [];
        return new self(
            catIds:      $catIds,
            groupIds:    $groupIds,
            groupIdsSet: $groupIdsSet,
            userIds:     $userIds,
            userIdsSet:  $userIdsSet,
        );
    }
}
