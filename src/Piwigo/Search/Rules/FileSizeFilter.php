<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `filesize_min` / `filesize_max` saved-search filter — image
 * filesize range in KB. The SearchService SQL widens both bounds
 * by 100 KB (legacy behavior to compensate for the form's
 * coarse slider granularity).
 */
final readonly class FileSizeFilter
{
    public function __construct(
        public int $minKb,
        public int $maxKb,
    ) {
    }

    /**
     * @param mixed $min  raw min from $search['fields']['filesize_min']
     * @param mixed $max  raw max from $search['fields']['filesize_max']
     */
    public static function fromValues(mixed $min, mixed $max): ?self
    {
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }
        return new self((int) $min, (int) $max);
    }
}
