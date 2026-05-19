<?php

declare(strict_types=1);

namespace Piwigo\Image\Entity;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\RelPath;

/**
 * Narrow projection of an `images` row holding only the `id` and `file`
 * columns. Used by callers that need to iterate every image's filename
 * cheaply without paying the cost of fetching every column.
 *
 * Parallel to `Image` (the full-row entity) but with a tighter SQL shape
 * — `SELECT id, file` instead of `SELECT *`. On a gallery with millions
 * of rows the column trim matters.
 */
final readonly class ImageIdFilename
{
    public function __construct(
        public ImageId $id,
        public RelPath $file,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('ImageIdFilename row is missing required `id` field');
        }
        $fileRaw = $row['file'] ?? null;
        if (!is_string($fileRaw) || $fileRaw === '') {
            throw new \InvalidArgumentException('ImageIdFilename row is missing required `file` field');
        }
        return new self(ImageId::from((int) $idRaw), RelPath::from($fileRaw));
    }
}
