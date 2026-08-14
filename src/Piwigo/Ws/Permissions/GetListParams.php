<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Permissions;

use Piwigo\Ws\WsParams;

/**
 * `pwg.permissions.getList` input DTO. `cat_id`/`group_id`/`user_id` are
 * all registered `FORCE_ARRAY | WsParamFlag::OPTIONAL` +
 * `WsParamType::ID`, so `Server::invoke()` has already coerced any
 * present key to a list of positive ints -- but an absent OPTIONAL key
 * with no 'default' never lands in $params at all, so `*Set` tracks
 * whether the client provided the key, matching `GetListHandler`'s
 * original `isset($params[...])` checks (an empty list filter must still
 * drop every row, distinct from "no filter requested"). `cat_id`'s own
 * "was it provided" check is done by `GetListHandler` directly against
 * the raw params (it feeds the mutual-exclusivity error message), so
 * there's no `catIdSet` here -- only `catIds` itself is consumed.
 */
final readonly class GetListParams implements WsParams
{
    /**
     * @param list<int> $catIds
     * @param list<int> $groupIds
     * @param list<int> $userIds
     */
    public function __construct(
        public array $catIds,
        public bool $groupIdSet,
        public array $groupIds,
        public bool $userIdSet,
        public array $userIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        return new self(
            catIds: self::intList($raw['cat_id'] ?? null),
            groupIdSet: array_key_exists('group_id', $raw),
            groupIds: self::intList($raw['group_id'] ?? null),
            userIdSet: array_key_exists('user_id', $raw),
            userIds: self::intList($raw['user_id'] ?? null),
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
