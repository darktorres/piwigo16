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
 * `pwg.permissions.add` input DTO. `cat_id` is mandatory (`FORCE_ARRAY` +
 * `WsParamType::ID`); `group_id`/`user_id` are the same coercion but
 * `OPTIONAL` (empty list when absent, matching `AddHandler`'s original
 * `isset($params[...]) && $params[...] !== []` guard); `recursive` is a
 * non-null bool default; `pwg_token` is mandatory, unchecked type.
 */
final readonly class AddParams implements WsParams
{
    /**
     * @param list<int> $categoryIds
     * @param list<int> $groupIds
     * @param list<int> $userIds
     */
    public function __construct(
        public array $categoryIds,
        public array $groupIds,
        public array $userIds,
        public bool $recursive,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        $recursive = $raw['recursive'] ?? null;

        return new self(
            categoryIds: self::intList($raw['cat_id'] ?? null),
            groupIds: self::intList($raw['group_id'] ?? null),
            userIds: self::intList($raw['user_id'] ?? null),
            recursive: is_bool($recursive) ? $recursive : false,
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
