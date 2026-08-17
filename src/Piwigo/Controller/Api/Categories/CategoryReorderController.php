<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/categories/actions/reorder` -- `pwg.categories.setRank`'s
 * real replacement, admin + CSRF.
 */
final readonly class CategoryReorderController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = CategoryReorderInput::fromArray(JsonBody::decode($request));

        $categories = $this->categoryService->getRankInfoByIds($input->categoryIds);
        if ($categories === []) {
            return ResponseFactory::problem('Not Found', 404, 'category_id not found.');
        }

        $category = $categories[0];
        $parentId = ($category->idUppercat !== null && $category->idUppercat !== 0) ? $category->idUppercat : null;

        if (count($input->categoryIds) > 1) {
            $orderNewById = $input->categoryIds;
            sort($orderNewById, SORT_NUMERIC);

            $catAsc = $this->categoryService->getIdsByParentOrderedById($parentId);
            if (strcmp(implode(',', $catAsc), implode(',', $orderNewById)) !== 0) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'You need to provide all sub-album ids for a given album.');
            }

            $orderNew = $input->categoryIds;
        } else {
            $categoryIdStr = implode('', $input->categoryIds);

            $orderOld = $this->categoryService->getSiblingIdsExcludingOrderedByRank($parentId, (int) $categoryIdStr);
            $orderNew = [];
            $wasInserted = false;
            $i = 1;
            foreach ($orderOld as $siblingId) {
                if ($i === $input->rank) {
                    $orderNew[] = $categoryIdStr;
                    $wasInserted = true;
                }
                $orderNew[] = $siblingId;
                ++$i;
            }

            if (! $wasInserted) {
                $orderNew[] = $categoryIdStr;
            }
        }

        $this->categoryService->saveCategoriesOrder($orderNew);

        return ResponseFactory::noContent();
    }
}
