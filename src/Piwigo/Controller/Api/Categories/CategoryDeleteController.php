<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/categories/{id}` -- `pwg.categories.delete`'s real
 * replacement, admin + CSRF, one album per call (a REST single-resource
 * delete, same shape decision as `TagDeleteController`/
 * `GroupDeleteController`). `{id}` is route-constrained to `\d+`.
 */
final readonly class CategoryDeleteController implements ControllerInterface
{
    private const array VALID_MODES = ['no_delete', 'delete_orphans', 'force_delete'];

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private UrlServiceInterface $urlService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
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

        $input = CategoryDeleteInput::fromArray(JsonBody::decode($request));
        if (! in_array($input->photoDeletionMode, self::VALID_MODES, true)) {
            return ResponseFactory::problem(
                'Unprocessable Entity',
                422,
                'Invalid photoDeletionMode, possible values are {' . implode(', ', self::VALID_MODES) . '}.'
            );
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $categoryId = is_string($rawId) ? (int) $rawId : 0;

        $existingIds = $this->categoryService->getExistingIds([$categoryId]);
        if ($existingIds === []) {
            return ResponseFactory::problem('Not Found', 404, 'This album does not exist.');
        }

        $this->categoryService->deleteCategories(
            $existingIds,
            $this->activityService,
            $this->urlService,
            $this->sessionService,
            $this->eventDispatcher,
            $this->entityManager,
            $input->photoDeletionMode
        );
        $this->categoryService->updateGlobalRank();
        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::json([
            'id' => $categoryId,
        ]);
    }
}
