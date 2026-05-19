<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;

/**
 * Single `(image_id, tag_id)` row from the `image_tag` junction. Returned
 * by `TagRepository::findImageTagPairs` and consumed by TagAdminService
 * for before/after assignment diffs.
 */
final readonly class ImageTagPair
{
    public function __construct(
        public ImageId $imageId,
        public TagId $tagId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $imageIdRaw = $row['image_id'] ?? null;
        $tagIdRaw   = $row['tag_id'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('ImageTagPair row is missing required `image_id` field');
        }
        if (!is_numeric($tagIdRaw)) {
            throw new \InvalidArgumentException('ImageTagPair row is missing required `tag_id` field');
        }
        return new self(
            imageId: ImageId::from((int) $imageIdRaw),
            tagId:   TagId::from((int) $tagIdRaw),
        );
    }
}
