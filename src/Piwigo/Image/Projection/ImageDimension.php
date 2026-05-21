<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/** (width, height) dimension pair from ImageRepository::findDistinctDimensions(). */
final readonly class ImageDimension
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            width:  is_numeric($row['width'] ?? null) ? (int) $row['width'] : 0,
            height: is_numeric($row['height'] ?? null) ? (int) $row['height'] : 0,
        );
    }
}
