<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.setRank` -- admin only. Changes the rank of an album.
 * If you provide a list for category_id: rank becomes useless, only the
 * order of the image_id list matters; you are supposed to provide the
 * list of all categories_ids belonging to the album.
 */
final readonly class SetRankHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): ?WsErrorResponse
    {
        $input = SetRankParams::fromArray($params);

        // does the category really exist?
        $categories = $this->categoryService->getRankInfoByIds($input->categoryIds);

        if (count($categories) === 0) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $category = $categories[0];
        $parent_id = ($category->idUppercat !== null && $category->idUppercat !== 0) ? $category->idUppercat : null;

        // check the number of category given by the user
        if (count($input->categoryIds) > 1) {
            $order_new = $input->categoryIds;
            $order_new_by_id = $order_new;
            sort($order_new_by_id, SORT_NUMERIC);

            $cat_asc = $this->categoryService->getIdsByParentOrderedById($parent_id);

            if (strcmp(implode(',', $cat_asc), implode(',', $order_new_by_id)) !== 0) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'you need to provide all sub-category ids for a given category');
            }
        } else {
            $category_id_str = implode('', $input->categoryIds);

            $order_old = $this->categoryService->getSiblingIdsExcludingOrderedByRank($parent_id, (int) $category_id_str);
            $order_new = [];
            $was_inserted = false;
            $i = 1;
            foreach ($order_old as $category_id) {
                if ($i === $input->rank) {
                    $order_new[] = $category_id_str;
                    $was_inserted = true;
                }
                $order_new[] = $category_id;
                ++$i;
            }

            if (! $was_inserted) {
                $order_new[] = $category_id_str;
            }
        }
        // set the global rank
        $this->categoryService->saveCategoriesOrder($order_new);

        return null;
    }
}
