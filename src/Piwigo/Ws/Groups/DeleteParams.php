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
 * `pwg.groups.delete` input DTO. Neither has a 'default' key -- both
 * mandatory, always present; `FORCE_ARRAY` always coerces `group_id` to
 * a list of positive ints.
 */
final readonly class DeleteParams implements WsParams
{
    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        public array $groupIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            groupIds: self::intList($raw['group_id'] ?? null),
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
