<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::massInsertImageCategory()}'s own
 * `image_category` insert row. Unlike {@see \Piwigo\Tag\Projection\
 * ImageTagPair}, every field genuinely stays `int|string`, across 3 real
 * callers: {@see \Piwigo\Image\ImageService::associateImagesToCategories()}
 * takes caller-supplied `$images`/`$categories` that are documented
 * `int|string` themselves (real callers pass both shapes) and always
 * resolves `$rank` to a real int; {@see \Piwigo\Image\
 * ImageCategoryRelationsHelper::addImageCategoryRelations()} parses a
 * `"cat_id[,rank];cat_id[,rank]"` string-token mini-language and never
 * casts `$cat_id` to int, and `$rank` is only resolved to a real int on
 * its 'auto' branch -- an explicit numeric-string rank token passes
 * through unchanged; `Controller\Admin\SiteUpdateSubController`'s
 * filesystem-sync insert always supplies real ints and omits `$rank`
 * entirely (left to the schema's own DEFAULT).
 */
final readonly class ImageCategoryPair
{
    public function __construct(
        public int|string $imageId,
        public int|string $categoryId,
        public int|string|null $rank = null,
    ) {}

    /**
     * @return array{image_id: int|string, category_id: int|string, rank?: int|string}
     */
    public function toArray(): array
    {
        $row = [
            'image_id' => $this->imageId,
            'category_id' => $this->categoryId,
        ];

        if ($this->rank !== null) {
            $row['rank'] = $this->rank;
        }

        return $row;
    }
}
