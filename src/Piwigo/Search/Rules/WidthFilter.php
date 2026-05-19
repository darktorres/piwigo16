<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `width_min` / `width_max` saved-search filter — image width
 * range in pixels. SQL: `width BETWEEN min AND max`.
 */
final readonly class WidthFilter
{
    public function __construct(
        public int $min,
        public int $max,
    ) {
    }

    public static function fromValues(mixed $min, mixed $max): ?self
    {
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }
        return new self((int) $min, (int) $max);
    }
}
