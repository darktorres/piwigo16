<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use Piwigo\Ws\WsParams;

/**
 * `pwg.groups.merge` input DTO. None has a 'default' key -- all
 * mandatory, always present; `destination_group_id`: `WsParamType::ID`
 * guarantees a plain int; `merge_group_id`: `FORCE_ARRAY` always coerces
 * to a list of positive ints.
 */
final readonly class MergeParams implements WsParams
{
    /**
     * @param list<int> $mergeGroupIds
     */
    public function __construct(
        public int $destinationGroupId,
        public array $mergeGroupIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $destinationGroupId = $raw['destination_group_id'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            destinationGroupId: is_int($destinationGroupId) ? $destinationGroupId : 0,
            mergeGroupIds: self::intList($raw['merge_group_id'] ?? null),
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
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
