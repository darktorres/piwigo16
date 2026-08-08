<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::countAndSumFormats()}'s own row
 * shape -- `Admin\InstallationStats`'s own "nb_formats"/
 * "formats_disk_usage" summary figures.
 */
final readonly class FormatCountSum
{
    public function __construct(
        public int $count,
        public int $sum,
    ) {}
}
