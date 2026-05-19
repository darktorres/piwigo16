<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.categories.setRank` — reorder one or all sub-categories under their parent. */
final readonly class SetRankHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $rawIds = is_array($params['category_id']) ? $params['category_id'] : [];
        /** @var int[] $categoryIds */
        $categoryIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawIds);
        $categories  = $this->categoryRepository->findIdIdUppercatRankByIds($categoryIds);
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (count($categoryIds) > 1) {
            $orderNew     = $categoryIds;
            $orderNewById = $orderNew;
            sort($orderNewById, SORT_NUMERIC);
            $parentForAsc = empty($category['id_uppercat']) ? null : (is_numeric($category['id_uppercat']) ? (int) $category['id_uppercat'] : 0);
            $catAsc       = $this->categoryRepository->findIdsByParentOrderedById($parentForAsc);
            if ($catAsc !== $orderNewById) {
                return new PwgError(WsError::InvalidParam->value, 'you need to provide all sub-category ids for a given category');
            }
            $orderNew = $categoryIds;
        } else {
            $singleCatId   = $categoryIds[0];
            $idUppercatRaw = $category['id_uppercat'] ?? null;
            $parentForOld  = is_numeric($idUppercatRaw) ? (int) $idUppercatRaw : null;
            $orderOld      = $this->categoryRepository->findOtherIdsByParentOrderedByRank($parentForOld, $singleCatId);
            $rankTarget    = is_numeric($params['rank']) ? (int) $params['rank'] : 0;
            $orderNew      = [];
            $wasInserted   = false;
            $i             = 1;
            foreach ($orderOld as $categoryId) {
                if ($i === $rankTarget) {
                    $orderNew[]  = $singleCatId;
                    $wasInserted = true;
                }
                $orderNew[] = $categoryId;
                ++$i;
            }
            if (!$wasInserted) {
                $orderNew[] = $singleCatId;
            }
        }
        $this->categoryAdminService->saveCategoriesOrder($orderNew);
        return null;
    }
}
