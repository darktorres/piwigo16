<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\OperationError;

/**
 * Sets an image's category associations -- originally shared by WS's
 * `AddHandler`/`SetInfoHandler` (the god-class
 * `Images::addImageCategoryRelations()` private helper both used).
 * `Controller\Api\Images\ImageUpdateController` is its one real caller
 * now; moved here from `Piwigo\Ws\Images` when the WS layer itself was
 * deleted (P27) -- it was never WS-protocol-specific, just born inside
 * that namespace.
 */
final readonly class ImageCategoryRelationsHelper
{
    public function __construct(
        private CategoryService $categoryService,
        private ImageService $imageService,
    ) {}

    /**
     * @param string $categories_string - "cat_id[,rank];cat_id[,rank]"
     * @param bool $replace_mode - removes old associations
     */
    public function addImageCategoryRelations(ImageId $image_id, string $categories_string, bool $replace_mode = false): true|OperationError
    {
        $categoryService = $this->categoryService;

        // let's add links between the image and the categories
        //
        // $params['categories'] should look like 123,12;456,auto;789 which means:
        //
        // 1. associate with category 123 on rank 12
        // 2. associate with category 456 on automatic rank
        // 3. associate with category 789 on automatic rank
        $cat_ids = [];
        $rank_on_category = [];
        $search_current_ranks = false;

        if ($categories_string === '') {
            if ($replace_mode) {
                $this->imageService->deleteImageCategoryRowsForImageIds([$image_id->value]);
                $categoryService->updateCategory([]);
            }
            return true;
        }
        $tokens = explode(';', $categories_string);
        foreach ($tokens as $token) {
            $token_parts = explode(',', $token);
            $cat_id = $token_parts[0];
            $rank = $token_parts[1] ?? 'auto';

            if (! (bool) preg_match('/^\d+$/', $cat_id)) {
                continue;
            }

            $cat_ids[] = $cat_id;
            $rank_on_category[$cat_id] = $rank;

            if ($rank === 'auto') {
                $search_current_ranks = true;
            }
        }

        $cat_ids = array_unique($cat_ids);

        if (count($cat_ids) === 0) {
            if ($replace_mode) {
                $this->imageService->deleteImageCategoryRowsForImageIds([$image_id->value]);
                $categoryService->updateCategory([]);
            }
            return true;
        }

        // native int under DBAL -- cast to string so array_diff() below
        // (string-based comparison against $cat_ids, which comes from
        // explode()-derived string tokens) keeps comparing like-for-like.
        $db_cat_ids = array_map(strval(...), $categoryService->getExistingIds(array_values(array_map(intval(...), $cat_ids))));

        $unknown_cat_ids = array_diff($cat_ids, $db_cat_ids);
        if (count($unknown_cat_ids) !== 0) {
            return new OperationError(
                '[ws_add_image_category_relations] the following categories are unknown: ' . implode(', ', $unknown_cat_ids)
            );
        }

        // in case of replace mode, we first check the existing associations
        // native int under DBAL -- same string-cast rationale as
        // $db_cat_ids above.
        $existing_cat_ids = array_map(strval(...), $this->imageService->getCategoryIdsForImage($image_id));

        if ($replace_mode) {
            $to_remove_cat_ids = array_values(array_diff($existing_cat_ids, $cat_ids));
            if (count($to_remove_cat_ids) > 0) {
                $this->imageService->deleteImageCategoryLinksForCategoryIds($image_id, $to_remove_cat_ids);
                $categoryService->updateCategory($to_remove_cat_ids);
            }
        }

        $new_cat_ids = array_diff($cat_ids, $existing_cat_ids);
        if (count($new_cat_ids) === 0) {
            return true;
        }

        if ($search_current_ranks) {
            $current_rank_of = $this->imageService->getMaxRanksByCategory($new_cat_ids);

            foreach ($new_cat_ids as $cat_id) {
                if (! isset($current_rank_of[$cat_id])) {
                    $current_rank_of[$cat_id] = 0;
                }

                if ($rank_on_category[$cat_id] === 'auto') {
                    $rank_on_category[$cat_id] = $current_rank_of[$cat_id] + 1;
                }
            }
        }

        $inserts = [];

        foreach ($new_cat_ids as $cat_id) {
            $inserts[] = [
                'image_id' => $image_id->value,
                'category_id' => $cat_id,
                'rank' => $rank_on_category[$cat_id],
            ];
        }

        $this->imageService->insertImageCategoryLinks($inserts);

        $categoryService->updateCategory($new_cat_ids);
        return true;
    }
}
