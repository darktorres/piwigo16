<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/categories/{id}/representative` --
 * `pwg.categories.deleteRepresentative`'s real replacement, admin + CSRF.
 * Only possible if `CurrentConfig::allowRandomRepresentative` or the
 * album has no direct photos.
 */
final readonly class CategoryDeleteRepresentativeController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
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

        if (! $this->currentConfig->allowRandomRepresentative && $this->categoryService->hasImages($categoryId)) {
            return ResponseFactory::problem('Forbidden', 403, 'Not permitted.');
        }

        $this->categoryService->clearRepresentativeImage(CategoryId::from($categoryId));
        $this->entityManager->clear();

        $this->activityService->record('album', $categoryId, 'edit');

        return ResponseFactory::noContent();
    }
}
