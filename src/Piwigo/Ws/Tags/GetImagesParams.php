<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.getImages` input DTO -- the tag-specific fields only. The
 * shared `f_*` image-filter params (merged into this method's own
 * registration) stay in the raw `$params` array and are read directly
 * by `ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()`/`stdImageSqlOrder()`, same
 * as the god-class method this replaces -- no need to duplicate their
 * shape here.
 *
 * `tag_id`/`tag_url_name`/`tag_name`: `FORCE_ARRAY` with a null default
 * -- `makeArrayParam()` converts the null default to `[]`, always a
 * list (`tag_id`: positive ints via `WsParamType::ID`; `tag_url_name`/
 * `tag_name`: untyped, so strings). `tag_mode_and`/`per_page`/`page`:
 * non-null default, always present. `order` is deliberately NOT a field
 * here -- `GetImagesHandler` reads it (along with the rest of the
 * shared `f_*` filter params) straight off the raw `$params` array via
 * its own `@var`-narrowed copy, the same as those `f_*` fields.
 */
final readonly class GetImagesParams implements WsParams
{
    /**
     * @param list<int> $tagIds
     * @param list<string> $tagUrlNames
     * @param list<string> $tagNames
     */
    public function __construct(
        public array $tagIds,
        public array $tagUrlNames,
        public array $tagNames,
        public bool $tagModeAnd,
        public int $perPage,
        public int $page,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $tagModeAnd = $raw['tag_mode_and'] ?? null;
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;

        return new self(
            tagIds: self::intList($raw['tag_id'] ?? null),
            tagUrlNames: self::stringList($raw['tag_url_name'] ?? null),
            tagNames: self::stringList($raw['tag_name'] ?? null),
            tagModeAnd: is_bool($tagModeAnd) ? $tagModeAnd : false,
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

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $v) {
            if (is_scalar($v)) {
                $values[] = (string) $v;
            }
        }
        return $values;
    }
}
