<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `ratios` saved-search filter — list of aspect-ratio bucket
 * codes (Portrait / square / Landscape / Panorama) the image must
 * fall into. Each code maps to a width/height range in the SQL.
 */
final readonly class RatioFilter
{
    /** @param list<string> $codes */
    public function __construct(public array $codes)
    {
    }

    /** @param array<int|string, mixed> $raw  flat list of ratio codes */
    public static function fromArray(array $raw): ?self
    {
        $codes = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw),
            static fn (string $c): bool => $c !== '',
        ));
        return $codes === [] ? null : new self($codes);
    }
}
