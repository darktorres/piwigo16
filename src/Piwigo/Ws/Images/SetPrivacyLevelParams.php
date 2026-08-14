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
 * `pwg.images.setPrivacyLevel` input DTO. `image_id`:
 * `WsParamFlag::FORCE_ARRAY|WsParamType::ID` -- always coerced to a list
 * of positive ints by `Server::invoke()` before this runs. `level`:
 * `WsParamType::INT|WsParamType::POSITIVE`, mandatory (no 'default') --
 * always a plain int.
 */
final readonly class SetPrivacyLevelParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $level,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $level = $raw['level'] ?? null;

        return new self(
            imageIds: self::intList($raw['image_id'] ?? null),
            level: is_int($level) ? $level : 0,
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
