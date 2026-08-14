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
 * `pwg.groups.getList` input DTO. `group_id`: `OPTIONAL|FORCE_ARRAY` +
 * `WsParamType::ID` -- empty list when absent. `name`: `OPTIONAL`, no
 * 'type' flag -- null when absent or empty (matching the original
 * `isset($params['name']) && $params['name'] !== ''` check).
 * `per_page`/`page`: non-null int defaults -- always present. `order`:
 * non-null string default ('name') -- always present.
 */
final readonly class GetListParams implements WsParams
{
    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        public array $groupIds,
        public ?string $name,
        public int $perPage,
        public int $page,
        public string $order,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $name = $raw['name'] ?? null;
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;
        $order = $raw['order'] ?? null;

        return new self(
            groupIds: self::intList($raw['group_id'] ?? null),
            name: is_string($name) && $name !== '' ? $name : null,
            perPage: is_int($perPage) ? $perPage : 100,
            page: is_int($page) ? $page : 0,
            order: is_string($order) ? $order : 'name',
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
