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
 * `pwg.categories.calculateOrphans` input DTO. No 'default' key --
 * mandatory, always present, `FORCE_ARRAY` always coerces to a list of
 * positive ints -- only the first element is ever read by the god-class
 * method this replaces.
 */
final readonly class CalculateOrphansParams implements WsParams
{
    public function __construct(
        public int $categoryId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $categoryIds = $raw['category_id'] ?? null;
        $first = is_array($categoryIds) ? ($categoryIds[0] ?? null) : null;

        return new self(
            categoryId: is_int($first) ? $first : 0,
        );
    }
}
