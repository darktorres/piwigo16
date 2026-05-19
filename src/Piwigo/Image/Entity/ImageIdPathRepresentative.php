<?php

declare(strict_types=1);

namespace Piwigo\Image\Entity;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\RelPath;

/**
 * Narrow projection of an `images` row: id + path + representative_ext.
 *
 * Used by callers that delete derivative files / iterate the on-disk
 * representation of each image but don't need the full `Image` entity.
 * The matching SQL projects `SELECT id, path, representative_ext`.
 */
final readonly class ImageIdPathRepresentative
{
    public function __construct(
        public ImageId $id,
        public RelPath $path,
        public ?string $representativeExt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('ImageIdPathRepresentative row is missing required `id` field');
        }
        $pathRaw = $row['path'] ?? null;
        if (!is_string($pathRaw) || $pathRaw === '') {
            throw new \InvalidArgumentException('ImageIdPathRepresentative row is missing required `path` field');
        }
        return new self(
            ImageId::from((int) $idRaw),
            RelPath::from($pathRaw),
            is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
        );
    }
}
