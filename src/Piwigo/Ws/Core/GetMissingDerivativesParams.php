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
 * `pwg.getMissingDerivatives` input DTO -- the method-specific fields
 * only. The shared `f_*` image-filter params (merged into this method's
 * own registration via `WsDefaultMethods::sharedImageFilterParams()`)
 * stay in the raw `$params` array and are read directly by
 * `WsHelper::stdImageSqlFilterCriteria()`, same as the god-class method
 * this replaces -- no need to duplicate their shape here.
 *
 * `types`/`ids`: `FORCE_ARRAY` with a null default -- `makeArrayParam()`
 * converts the null default to `[]`, always a list (`ids`: positive ints
 * via `WsParamType::ID`; `types`: untyped, so strings). `max_urls`:
 * non-null int default (200) -- always present. `prev_page`: null
 * default, `WsParamType::INT | WsParamType::POSITIVE` -- int|null.
 */
final readonly class GetMissingDerivativesParams implements WsParams
{
    /**
     * @param list<string> $types
     * @param list<int> $ids
     */
    public function __construct(
        public array $types,
        public array $ids,
        public int $maxUrls,
        public ?int $prevPage,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $maxUrls = $raw['max_urls'] ?? null;
        $prevPage = $raw['prev_page'] ?? null;

        return new self(
            types: self::stringList($raw['types'] ?? null),
            ids: self::intList($raw['ids'] ?? null),
            maxUrls: is_int($maxUrls) ? $maxUrls : 200,
            prevPage: is_int($prevPage) ? $prevPage : null,
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
            if (is_string($v)) {
                $values[] = $v;
            }
        }
        return $values;
    }
}
