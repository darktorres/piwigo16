<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.setRank` input DTO. `image_id`:
 * `WsParamFlag::FORCE_ARRAY|WsParamType::ID` -- always a list of
 * positive ints. `category_id`: `WsParamType::ID`, mandatory.
 * `rank`: `WsParamType::INT|POSITIVE|NOTNULL` with a null default -- int
 * when the caller provides it, null otherwise.
 */
final readonly class SetRankParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $categoryId,
        public ?int $rank,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $categoryId = $raw['category_id'] ?? null;
        $rank = $raw['rank'] ?? null;

        return new self(
            imageIds: self::intList($raw['image_id'] ?? null),
            categoryId: is_int($categoryId) ? $categoryId : 0,
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
