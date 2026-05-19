<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use Piwigo\Common\ValueObject\ImageId;

/**
 * `(image_id, GROUP_CONCAT(tag_id) AS tag_ids)` returned by
 * `TagRepository::findImageTagMap` for the OR-mode tag listing in
 * `pwg.tags.getImages`. `tagIdsCsv` is the raw comma-separated string the
 * caller splits with `explode(',', ...)`.
 */
final readonly class ImageTagGroup
{
    public function __construct(
        public ImageId $imageId,
        public string $tagIdsCsv,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $imageIdRaw   = $row['image_id'] ?? null;
        $tagIdsCsvRaw = $row['tag_ids'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('ImageTagGroup row is missing required `image_id` field');
        }
        return new self(
            imageId:   ImageId::from((int) $imageIdRaw),
            tagIdsCsv: is_string($tagIdsCsvRaw) ? $tagIdsCsvRaw : '',
        );
    }
}
