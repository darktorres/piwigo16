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
        $input      = SetRankParams::fromArray($params);
        $categories = $this->categoryRepository->findIdIdUppercatRankByIds($input->categoryIds);
        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }
        $category = $categories[0];
        if (count($input->categoryIds) > 1) {
            $orderNew     = $input->categoryIds;
            $orderNewById = $orderNew;
            sort($orderNewById, SORT_NUMERIC);
            $parentForAsc = $category->idUppercat?->value;
            $catAsc       = $this->categoryRepository->findIdsByParentOrderedById($parentForAsc);
            if ($catAsc !== $orderNewById) {
                return new PwgError(WsError::InvalidParam->value, 'you need to provide all sub-category ids for a given category');
            }
        } else {
            $singleCatId  = $input->categoryIds[0];
            $parentForOld = $category->idUppercat?->value;
            $orderOld     = $this->categoryRepository->findOtherIdsByParentOrderedByRank($parentForOld, $singleCatId);
            $orderNew     = [];
            $wasInserted  = false;
            $i            = 1;
            foreach ($orderOld as $categoryId) {
                if ($i === $input->rank) {
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
