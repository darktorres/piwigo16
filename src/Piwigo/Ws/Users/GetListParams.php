<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.getList` input DTO. `user_id`/`status`/`group_id`/`exclude`:
 * `OPTIONAL|FORCE_ARRAY` -- empty list when absent. `username`/`filter`/
 * `min_register`/`max_register`: `OPTIONAL`, no 'type' flag -- null when
 * absent or empty. `min_level`/`per_page`/`page`: non-null int defaults
 * -- always present. `order`/`display`: non-null string defaults --
 * always present. `max_level` is deliberately NOT a field here -- it's
 * not a registered ws.php param at all (reachable only via an
 * unregistered extra GET/POST key), so `GetListHandler` reads it
 * straight off the raw `$params` array, matching the god-class method
 * this replaces.
 */
final readonly class GetListParams implements WsParams
{
    /**
     * @param list<int> $userIds
     * @param list<string> $status
     * @param list<int> $groupIds
     * @param list<int> $exclude
     */
    public function __construct(
        public array $userIds,
        public ?string $username,
        public array $status,
        public int $minLevel,
        public array $groupIds,
        public int $perPage,
        public int $page,
        public string $order,
        public array $exclude,
        public string $display,
        public ?string $filter,
        public ?string $minRegister,
        public ?string $maxRegister,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $username = $raw['username'] ?? null;
        $minLevel = $raw['min_level'] ?? null;
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;
        $order = $raw['order'] ?? null;
        $display = $raw['display'] ?? null;
        $filter = $raw['filter'] ?? null;
        $minRegister = $raw['min_register'] ?? null;
        $maxRegister = $raw['max_register'] ?? null;

        return new self(
            userIds: self::intList($raw['user_id'] ?? null),
            username: is_string($username) && $username !== '' ? $username : null,
            status: self::stringList($raw['status'] ?? null),
            minLevel: is_int($minLevel) ? $minLevel : 0,
            groupIds: self::intList($raw['group_id'] ?? null),
            perPage: is_int($perPage) ? $perPage : 100,
            page: is_int($page) ? $page : 0,
            order: is_string($order) ? $order : 'id',
            exclude: self::intList($raw['exclude'] ?? null),
            display: is_string($display) ? $display : 'basics',
            filter: is_string($filter) && $filter !== '' ? $filter : null,
            minRegister: is_string($minRegister) && $minRegister !== '' ? $minRegister : null,
            maxRegister: is_string($maxRegister) && $maxRegister !== '' ? $maxRegister : null,
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

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $values[] = $v;
            }
        }
        return $values;
    }
}
