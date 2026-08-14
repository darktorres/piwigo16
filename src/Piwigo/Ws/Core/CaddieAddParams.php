<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Piwigo\Ws\WsParams;

/**
 * `pwg.caddie.add` input DTO. `image_id`: `WsParamFlag::FORCE_ARRAY` +
 * `WsParamType::ID`, no 'default' key -- mandatory, always a list of
 * positive ints.
 */
final readonly class CaddieAddParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        return new self(
            imageIds: self::intList($raw['image_id'] ?? null),
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
