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
 * `pwg.categories.getImages` input DTO -- the category-specific fields
 * only. The shared `f_*` image-filter params (merged into this method's
 * own registration) plus `order` stay in the raw `$params` array and
 * are read directly by
 * `WsHelper::stdImageSqlFilterCriteria()`/`stdImageSqlOrder()`, same as
 * the god-class method this replaces -- no need to duplicate their
 * shape here.
 *
 * `cat_id`: `FORCE_ARRAY` + `WsParamType::INT | WsParamType::POSITIVE`,
 * null default -- `makeArrayParam()` converts the null default to `[]`,
 * always a list of positive ints. `recursive`/`per_page`/`page`:
 * non-null default, always present.
 */
final readonly class GetImagesParams implements WsParams
{
    /**
     * @param list<int> $catIds
     */
    public function __construct(
        public array $catIds,
        public bool $recursive,
        public int $perPage,
        public int $page,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $recursive = $raw['recursive'] ?? null;
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;

        return new self(
            catIds: self::intList($raw['cat_id'] ?? null),
            recursive: is_bool($recursive) ? $recursive : false,
            perPage: is_int($perPage) ? $perPage : 100,
            page: is_int($page) ? $page : 0,
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
