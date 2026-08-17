<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /api/v1/categories/{id}/representative` --
 * `pwg.categories.setRepresentative`'s real replacement, admin + CSRF.
 * `Ws\Categories\SetRepresentativeHandler` itself has no CSRF check (a
 * real, standing gap in its own registered params) -- this fresh
 * implementation adds one anyway, matching every other mutating
 * `/api/v1` endpoint in this family; nothing about P27's own surface
 * inherits WS's specific historical gap.
 */
final readonly class CategorySetRepresentativeController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ImageService $imageService,
        private ActivityService $activityService,
        private CategoryTreeCachePool $categoryTreeCachePool,
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

        $input = CategorySetRepresentativeInput::fromArray(JsonBody::decode($request));

        if (! $this->imageService->existsById(ImageId::from($input->imageId))) {
            return ResponseFactory::problem('Not Found', 404, 'image_id not found.');
        }

        $this->categoryService->setRepresentativeImage($categoryId, $input->imageId);
        $this->entityManager->clear();

        // Invalidates every user's own remembered-representative cache
        // entry -- PSR-6 has no per-key-prefix bulk delete, so this
        // clears the whole pool, same reasoning as
        // Ws\Categories\SetRepresentativeHandler's own identical call.
        $this->categoryTreeCachePool->clear();

        $this->activityService->record('album', $categoryId, 'edit', [
            'image_id' => $input->imageId,
        ]);

        return ResponseFactory::noContent();
    }
}
