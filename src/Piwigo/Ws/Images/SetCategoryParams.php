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
 * `pwg.images.setCategory` input DTO. `image_id`:
 * `WsParamFlag::FORCE_ARRAY|WsParamType::ID` -- always a list of
 * positive ints. `category_id`: `WsParamType::ID`, mandatory. `action`:
 * non-null string default ('associate') -- always present. `pwg_token`:
 * no 'default' key -- mandatory, always a plain string.
 *
 * @since 14
 */
final readonly class SetCategoryParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $categoryId,
        public string $action,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $categoryId = $raw['category_id'] ?? null;
        $action = $raw['action'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            imageIds: self::intList($raw['image_id'] ?? null),
            categoryId: is_int($categoryId) ? $categoryId : 0,
            action: is_string($action) ? $action : 'associate',
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
