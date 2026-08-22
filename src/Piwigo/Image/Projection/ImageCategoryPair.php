<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::massInsertImageCategory()}'s own
 * `image_category` insert row, across 3 real callers. `$imageId`/
 * `$categoryId` are real `int` in every one -- {@see \Piwigo\Image\
 * ImageCategoryRelationsHelper::addImageCategoryRelations()} parses a
 * `"cat_id[,rank];cat_id[,rank]"` string-token mini-language, but every
 * `cat_id` token is regex-validated digits-only before use.
 *
 * `$rank` stays `int|string`: that same method's `rank` token is *not*
 * validated (`$token_parts[1] ?? 'auto'`), and its one real caller,
 * `Controller\Api\Images\ImageUpdateController`, passes an unvalidated
 * request-body string straight through -- an explicit non-numeric rank
 * token reaches this VO unchanged. `$rank` is only resolved to a real
 * int on the 'auto' branch. `Controller\Admin\SiteUpdateSubController`'s
 * filesystem-sync insert always supplies real ints for both fields and
 * omits `$rank` entirely (left to the schema's own DEFAULT).
 */
final readonly class ImageCategoryPair
{
    public function __construct(
        public int $imageId,
        public int $categoryId,
        public int|string|null $rank = null,
    ) {}

    /**
     * @return array{image_id: int, category_id: int, rank?: int|string}
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
