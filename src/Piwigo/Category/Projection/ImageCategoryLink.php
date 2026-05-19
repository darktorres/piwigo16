<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;

/**
 * Single `(image_id, category_id)` pair from the `image_category` junction —
 * the typed analogue of \Piwigo\Tag\Projection\ImageTagPair for the
 * category domain.
 */
final readonly class ImageCategoryLink
{
    public function __construct(
        public ImageId $imageId,
        public CategoryId $categoryId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $imageIdRaw    = $row['image_id'] ?? null;
        $categoryIdRaw = $row['category_id'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('ImageCategoryLink row is missing required `image_id` field');
        }
        if (!is_numeric($categoryIdRaw)) {
            throw new \InvalidArgumentException('ImageCategoryLink row is missing required `category_id` field');
        }
        return new self(
            imageId:    ImageId::from((int) $imageIdRaw),
            categoryId: CategoryId::from((int) $categoryIdRaw),
        );
    }
}
