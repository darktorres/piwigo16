<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.merge` input DTO. None has a 'default' key -- all mandatory,
 * always present; `destination_tag_id`: `WsParamType::ID` guarantees a
 * plain int; `merge_tag_id`: `FORCE_ARRAY` always coerces to a list of
 * positive ints.
 */
final readonly class MergeParams implements WsParams
{
    /**
     * @param list<int> $mergeTagIds
     */
    public function __construct(
        public int $destinationTagId,
        public array $mergeTagIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $destinationTagId = $raw['destination_tag_id'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            destinationTagId: is_int($destinationTagId) ? $destinationTagId : 0,
            mergeTagIds: self::intList($raw['merge_tag_id'] ?? null),
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
