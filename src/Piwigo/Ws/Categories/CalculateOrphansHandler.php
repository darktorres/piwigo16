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
    public function __invoke(array $params): array
    {
        $input = CalculateOrphansParams::fromArray($params);

        $impact = $this->categoryService->calculateOrphanImpact($input->categoryId);

        return [
            [
                'nb_images_associated_outside' => $impact['nbImagesAssociatedOutside'],
                'nb_images_becoming_orphan' => $impact['nbImagesBecomingOrphan'],
                'nb_images_recursive' => $impact['nbImagesRecursive'],
            ],
        ];
    }
}
