<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\Category;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageStdParams;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/categories/{id}/actions/refresh-representative` --
 * `pwg.categories.refreshRepresentative`'s real replacement, admin +
 * CSRF. Finds a new random album thumbnail among the album's own photos.
 */
final readonly class CategoryRefreshRepresentativeController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CategoryRepository $categoryRepository,
        private UrlServiceInterface $urlService,
        private EntityManagerInterface $entityManager,
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

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $categoryId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->categoryService->existsById($categoryId)) {
            return ResponseFactory::problem('Not Found', 404, 'category_id not found.');
        }

        if (! $this->categoryService->hasImages($categoryId)) {
            return ResponseFactory::problem('Forbidden', 403, 'Not permitted.');
        }

        $this->categoryService->setRandomRepresentant([$categoryId]);
        $this->activityService->record('album', $categoryId, 'edit');

        $category = $this->categoryRepository->findById($categoryId);
        assert($category instanceof Category);

        $representativePictureId = $category->representativePictureId;
        if ($representativePictureId === null) {
            return ResponseFactory::problem('Internal Server Error', 500, 'Unable to determine a new representative picture for this album.');
        }

        $representative = $this->categoryService->getCategoryRepresentantProperties(
            $representativePictureId,
            $this->urlService,
            $this->entityManager,
            ImageStdParams::SMALL
        );

        return ResponseFactory::json($representative->toArray());
    }
}
