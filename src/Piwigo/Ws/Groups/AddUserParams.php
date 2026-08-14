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
 * `pwg.groups.addUser` input DTO. None has a 'default' key -- all
 * mandatory, always present; `group_id`: `WsParamType::ID` guarantees a
 * plain int; `user_id`: `FORCE_ARRAY` always coerces to a list of
 * positive ints.
 */
final readonly class AddUserParams implements WsParams
{
    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public int $groupId,
        public array $userIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $groupId = $raw['group_id'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            groupId: is_int($groupId) ? $groupId : 0,
            userIds: self::intList($raw['user_id'] ?? null),
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
