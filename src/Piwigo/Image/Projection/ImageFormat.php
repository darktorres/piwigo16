<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * Typed row shape for `piwigo_image_format` (P17-23 Stage 1b, Image domain
 * -- `docs/PLAN-REPLAY.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_scalar($row['x']) ? ... :
 * default` narrowing that used to be duplicated across every one of this
 * table's own `SELECT *` call sites (ActionController/
 * PictureFormatsPageRenderer/PhotosAddDirectPageRenderer/
 * PictureModifyPageRenderer/PictureController/SiteUpdateSubController),
 * same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * `ImageRepository::findFormatsByImageIds()`'s own curated `image_id, ext`
 * projection stays a raw array, deliberately -- narrower than this shape,
 * with its own single real consumer (`ImageService::deleteElementFiles()`)
 * needing nothing else.
 */
final readonly class ImageFormat
{
    public function __construct(
        public int $formatId,
        public int $imageId,
        public string $ext,
        public ?int $filesize,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_image_format`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            formatId: is_numeric($row['format_id'] ?? null) ? (int) $row['format_id'] : 0,
            imageId: is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : 0,
            ext: is_string($row['ext'] ?? null) ? $row['ext'] : '',
            filesize: is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
        );
    }

    /**
     * @return array{format_id: int, image_id: int, ext: string, filesize: ?int}
     */
    public function toArray(): array
    {
        return [
            'format_id' => $this->formatId,
            'image_id' => $this->imageId,
            'ext' => $this->ext,
            'filesize' => $this->filesize,
        ];
    }
}
