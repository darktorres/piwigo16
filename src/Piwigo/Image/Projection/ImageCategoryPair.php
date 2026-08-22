<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::massInsertImageCategory()}'s own
 * `image_category` insert row, across 3 real callers.
 *
 * `$imageId` stays `int|string` only because {@see \Piwigo\Image\
 * ImageService::associateImagesToCategories()}'s own signature is typed
 * to accept a numeric-string image id defensively, deliberately tested
 * via a mutation-testing-motivated case in `ImageServiceTest.php` --
 * "treats a numeric-string image id as already associated ... not a
 * duplicate". Traced to every one of its 5 real call paths (direct and
 * via its own `emptyLounge()`/`moveImagesToCategories()` wrappers) --
 * every single one supplies a real `int`, never a string, all the way
 * back to origin (`ImageCategoryLink::$imageId`, `PictureModifyRequest::
 * $imageId`, `ImageSetCategoryInput::$imageIds`, explicit `(int)` casts).
 * The dedicated string-id test itself never reaches the insert path
 * either (it hits the "already associated, skip" branch first). So the
 * union is a real, tested constraint on the method's own contract, not
 * evidence any current caller actually constructs this VO with a string
 * `$imageId` -- unlike `$categoryId`/`$rank`, both genuinely exercised
 * today: {@see \Piwigo\Image\ImageCategoryRelationsHelper::
 * addImageCategoryRelations()} parses a `"cat_id[,rank];cat_id[,rank]"`
 * string-token mini-language and never casts `$cat_id` to int, and
 * `$rank` is only resolved to a real int on its 'auto' branch -- an
 * explicit numeric-string rank token passes through unchanged.
 * `Controller\Admin\SiteUpdateSubController`'s filesystem-sync insert
 * always supplies real ints for both fields and omits `$rank` entirely
 * (left to the schema's own DEFAULT).
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
