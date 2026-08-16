<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

use Piwigo\Common\ValueObject\FormatId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageFormatEntity;

/**
 * Typed row shape for `image_format`. `fromRow()` centralises the
 * `is_scalar($row['x']) ? ... :
 * default` narrowing for any raw `SELECT *` reader of this table, same
 * shape as {@see \Piwigo\Category\Projection\Category} -- current real
 * readers (SiteUpdateSubController, PictureController) go through
 * {@see ImageRepository::findFullFormatsByImageIds()}'s `fromEntity()`
 * path instead, so `fromRow()` itself currently has no real caller.
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
        public ImageId $imageId,
        public string $ext,
        public ?int $filesize,
    ) {}

    public static function fromEntity(ImageFormatEntity $entity): self
    {
        return new self(
            formatId: $entity->formatId instanceof FormatId ? $entity->formatId->value : 0,
            imageId: $entity->imageId,
            ext: $entity->ext,
            filesize: $entity->filesize,
        );
    }

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `image_format`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            formatId: is_numeric($row['format_id'] ?? null) ? (int) $row['format_id'] : 0,
            imageId: ImageId::from(is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : 0),
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
            'image_id' => $this->imageId->value,
            'ext' => $this->ext,
            'filesize' => $this->filesize,
        ];
    }
}
