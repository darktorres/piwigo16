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
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.categories.calculateOrphans` -- admin only. Returns the number of
 * orphan photos if an album is deleted.
 * @since 12
 */
final readonly class CalculateOrphansHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array<int, array{nb_images_associated_outside: int, nb_images_becoming_orphan: int, nb_images_recursive: int}>
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $input = CalculateOrphansParams::fromArray($params);
        $category_id = $input->categoryId;

        $category = [];
        $category['has_images'] = $this->categoryService->hasImages($category_id);

        // number of sub-categories
        $subcat_ids = $this->categoryService->getSubcatIds([$category_id]);

        $category['nb_subcats'] = count($subcat_ids) - 1;

        // total number of images under this category (including sub-categories)
        $image_ids_recursive = $this->categoryService->getDistinctLinkedImageIds($subcat_ids);

        $category['nb_images_recursive'] = count($image_ids_recursive);

        // number of images that would become orphan on album deletion
        $category['nb_images_becoming_orphan'] = 0;
        $category['nb_images_associated_outside'] = 0;

        if ($category['nb_images_recursive'] > 0) {
            // if we don't have "too many" photos, it's faster to compute the orphans with MySQL
            if ($category['nb_images_recursive'] < 1000) {
                $image_ids_associated_outside = $this->categoryService->getNonOrphanImageIds($image_ids_recursive, $subcat_ids);
                $category['nb_images_associated_outside'] = count($image_ids_associated_outside);

                $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_associated_outside);
                $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
            }
            // else it's better to avoid sending a huge SQL request, we compute the orphan list with PHP
            else {
                // image_id is a NOT NULL column of image_category --
                // $image_ids_recursive is already list<int> (cast at
                // extraction above), safe to flip directly.
                $image_ids_recursive_keys = array_flip($image_ids_recursive);

                $image_ids_associated_outside = $this->categoryService->getImageIdsOutsideCategories($subcat_ids);
                $image_ids_not_orphan = [];

                foreach ($image_ids_associated_outside as $image_id) {
                    if (isset($image_ids_recursive_keys[$image_id])) {
                        $image_ids_not_orphan[] = $image_id;
                    }
                }

                $category['nb_images_associated_outside'] = count(array_unique($image_ids_not_orphan));
                $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_not_orphan);
                $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
            }
        }

        $output = [];
        $output[] = [
            'nb_images_associated_outside' => $category['nb_images_associated_outside'],
            'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'],
            'nb_images_recursive' => $category['nb_images_recursive'],
        ];

        return $output;
    }
}
