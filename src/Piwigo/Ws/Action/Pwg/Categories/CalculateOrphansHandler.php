<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.categories.calculateOrphans` — report image counts that would become orphan if a category were deleted. */
final readonly class CalculateOrphansHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return list<array<string, int>>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $input         = CalculateOrphansParams::fromArray($params);
        $categoryId    = $input->categoryId;
        $category      = [];
        $category['has_images'] = $this->categoryRepository->hasCategoryImages($categoryId);
        $subcatIds     = $this->categoryService->getSubcatIds([$categoryId]);
        $category['nb_subcats'] = count($subcatIds) - 1;
        $subcatIdsInt      = array_values($subcatIds);
        $imageIdsRecursive = $this->categoryRepository->findImageIdsLinkedToCategories($subcatIdsInt);
        $category['nb_images_recursive'] = count($imageIdsRecursive);
        $category['nb_images_becoming_orphan']     = 0;
        $category['nb_images_associated_outside']  = 0;
        if ($category['nb_images_recursive'] > 0) {
            if ($category['nb_images_recursive'] < 1000) {
                $imageIdsAssociatedOutside = $this->categoryRepository->findImageIdsAssociatedOutsideCategories($subcatIdsInt, $imageIdsRecursive);
                $category['nb_images_associated_outside'] = count($imageIdsAssociatedOutside);
                $category['nb_images_becoming_orphan']    = count(array_diff($imageIdsRecursive, $imageIdsAssociatedOutside));
            } else {
                $recursiveKeys     = array_flip($imageIdsRecursive);
                $outsideAll        = $this->categoryRepository->findImageIdsAssociatedOutsideCategoriesAll($subcatIdsInt);
                $imageIdsNotOrphan = [];
                foreach ($outsideAll as $imageId) {
                    if (isset($recursiveKeys[$imageId])) {
                        $imageIdsNotOrphan[] = $imageId;
                    }
                }
                $category['nb_images_associated_outside'] = count(array_unique($imageIdsNotOrphan));
                $category['nb_images_becoming_orphan']    = count(array_diff($imageIdsRecursive, $imageIdsNotOrphan));
            }
        }
        return [['nb_images_associated_outside' => $category['nb_images_associated_outside'], 'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'], 'nb_images_recursive' => $category['nb_images_recursive']]];
    }
}
