<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.setRank` input DTO. `category_id`: no 'default' key --
 * mandatory, always present; `FORCE_ARRAY` always coerces to a list of
 * positive ints. `rank`: `OPTIONAL` (explicit flag) with no 'default'
 * key -- may be entirely absent.
 */
final readonly class SetRankParams implements WsParams
{
    /**
     * @param list<int> $categoryIds
     */
    public function __construct(
        public array $categoryIds,
        public ?int $rank,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $rank = $raw['rank'] ?? null;

        return new self(
            categoryIds: self::intList($raw['category_id'] ?? null),
            rank: is_int($rank) ? $rank : null,
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
