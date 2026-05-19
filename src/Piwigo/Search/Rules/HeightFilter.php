<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `height_min` / `height_max` saved-search filter — image
 * height range in pixels. SQL: `height BETWEEN min AND max`.
 */
final readonly class HeightFilter
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
