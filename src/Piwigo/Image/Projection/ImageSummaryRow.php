<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/** Summary image row from ImageRepository::findActivityFeedSummaryByIds(), keyed by image id. */
final readonly class ImageSummaryRow
{
    public function __construct(
        public int $id,
        public string $label,
        public ?int $filesize,
        public string $file,
        public string $path,
        public ?string $representativeExt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            label:             is_string($row['label'] ?? null) ? $row['label'] : '',
            filesize:          is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
            file:              is_string($row['file'] ?? null) ? $row['file'] : '',
            path:              is_string($row['path'] ?? null) ? $row['path'] : '',
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
        );
    }
}
