<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One end-pair of a search-filter slider: the lowest and highest value it
 * offers, or the pair currently selected on it.
 *
 * `?string`, not a numeric type, because the values really are display
 * strings by the time they get here and one of them is formatted: the
 * filesize slider works in megabytes and renders `sprintf('%.1f', …)`, so
 * `'2.0'` would come back as `2.0` and render as `2` under any numeric
 * type. The height and width sliders' values arrive as strings from the
 * database in the first place.
 *
 * `null` is "no value", which the templates render as the empty attribute
 * they already render today: the producing expressions are
 * `$values[0] ?? null` (empty option set) and `end($values)`, whose own
 * `false` on an empty array Latte echoes as `''` exactly as it does `null`.
 * Normalizing both to `null` at construction keeps that byte-identical
 * while removing `false` from the type.
 */
final readonly class RangeBounds
{
    public function __construct(
        public ?string $min,
        public ?string $max,
    ) {}

    /**
     * Normalizes one raw slider endpoint. The sources are array keys
     * (`int|string` -- a bucket label like `'2.0'` stays a string, but the
     * fallback set's own `0`/`2`/`5`/`8`/`15` are integer keys),
     * `SearchRules`' own `int|string|null` bounds, and `end()`'s `false`.
     */
    public static function value(int|string|false|null $value): ?string
    {
        return $value === false || $value === null ? null : (string) $value;
    }
}
