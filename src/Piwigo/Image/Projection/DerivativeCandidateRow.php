<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/** Derivative-candidate image row from ImageRepository::findDerivativeCandidatesBeforeId(). */
final readonly class DerivativeCandidateRow
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $representativeExt,
        public ?int $width,
        public ?int $height,
        public ?int $rotation,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            path:              is_string($row['path'] ?? null) ? $row['path'] : '',
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            width:             is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
            height:            is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
            rotation:          is_numeric($row['rotation'] ?? null) ? (int) $row['rotation'] : null,
        );
    }
}
