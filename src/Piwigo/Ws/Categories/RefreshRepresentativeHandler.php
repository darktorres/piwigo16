<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\Category;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.refreshRepresentative` -- admin only. Finds a new album thumbnail.
 */
final readonly class RefreshRepresentativeHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CategoryRepository $categoryRepository,
        private UrlServiceInterface $urlService,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{src: string|array<int|string, mixed>, url: string} matches
     *   CategoryService::getCategoryRepresentantProperties()'s own
     *   already-precise return type (this method's only real array return)
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = RefreshRepresentativeParams::fromArray($params);
        $categoryService = $this->categoryService;

        // does the category really exist?
        if (! $categoryService->existsById($input->categoryId)) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        if (! $categoryService->hasImages($input->categoryId)) {
            return new WsErrorResponse(401, 'not permitted');
        }

        $categoryService->setRandomRepresentant([$input->categoryId]);

        $this->activityService->record('album', $input->categoryId, 'edit');

        // return url of the new representative
        $category = $this->categoryRepository->findById($input->categoryId);
        // the category's existence was already verified above, and nothing
        // in between could have deleted it
        assert($category instanceof Category);

        // setRandomRepresentant() is expected to have populated
        // representative_picture_id above, but it's not a NOT NULL column, so
        // guard for real instead of assuming the update landed.
        $representative_picture_id = $category->representativePictureId;
        if ($representative_picture_id === null) {
            return new WsErrorResponse(500, 'unable to determine a new representative picture for this category');
        }

        return $categoryService->getCategoryRepresentantProperties($representative_picture_id, $this->urlService, $this->entityManager, ImageStdParams::SMALL);
    }
}
