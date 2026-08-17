<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/categories/{id}/orphan-impact` --
 * `pwg.categories.calculateOrphans`'s real replacement, admin only. How
 * many photos would become orphan (linked to no other album) if this
 * album (and its sub-albums) were deleted -- the "delete album"
 * confirmation dialog's own data source.
 */
final readonly class CategoryOrphanImpactController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CategoryService $categoryService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $categoryId = is_string($rawId) ? (int) $rawId : 0;

        $impact = $this->categoryService->calculateOrphanImpact($categoryId);

        return ResponseFactory::json([
            'nbImagesAssociatedOutside' => $impact['nbImagesAssociatedOutside'],
            'nbImagesBecomingOrphan' => $impact['nbImagesBecomingOrphan'],
            'nbImagesRecursive' => $impact['nbImagesRecursive'],
        ]);
    }
}
