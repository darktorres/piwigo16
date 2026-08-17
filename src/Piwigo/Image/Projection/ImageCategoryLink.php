<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * Shared `image_id`/`category_id` link row shape for
 * {@see \Piwigo\Image\ImageRepository::findLoungeRows()} (the `lounge`
 * table) and
 * {@see \Piwigo\Image\ImageRepository::findCategoryLinksForImageIdsWithCondition()}
 * (the `image_category` table) -- same 2-column shape, different source
 * tables.
 *
 * `toArray()` exists for {@see \Piwigo\Image\ImageService::emptyLounge()}'s
 * own WS-response boundary (`Ws\Images::emptyLounge()`/`uploadCompleted()`
 * expect the original `array{image_id: int, category_id: int}` shape).
 */
final readonly class ImageCategoryLink
{
    public function __construct(
        public int $imageId,
        public int $categoryId,
    ) {}

    /**
     * @return array{image_id: int, category_id: int}
     */
    public function toArray(): array
    {
        return [
            'image_id' => $this->imageId,
            'category_id' => $this->categoryId,
        ];
    }
}
