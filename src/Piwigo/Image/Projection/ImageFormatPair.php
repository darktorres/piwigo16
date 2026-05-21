<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/** (format_id, image_id, ext, filesize) row from the image_format table. */
final readonly class ImageFormatPair
{
    public function __construct(
        public int $formatId,
        public int $imageId,
        public string $ext,
        public ?int $filesize,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            formatId: is_numeric($row['format_id'] ?? null) ? (int) $row['format_id'] : 0,
            imageId:  is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : 0,
            ext:      is_string($row['ext'] ?? null) ? $row['ext'] : '',
            filesize: is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
        );
    }
}
