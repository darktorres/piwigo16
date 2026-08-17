<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/categories` -- `pwg.categories.add`'s real replacement,
 * admin + CSRF. HTML in name/comment is allowed only when
 * `CurrentConfig::allowHtmlDescriptions` is enabled.
 */
final readonly class CategoryCreateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CurrentUser $currentUser,
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

        $input = CategoryCreateInput::fromArray(JsonBody::decode($request));

        if (in_array($input->position, ['first', 'last'], true)) {
            // In-memory override only (this request's own CurrentConfig
            // property), not a real persisted preference -- a known
            // limitation.
            $this->currentConfig->newcatDefaultPosition = $input->position;
        }

        $options = [
            'visible' => $input->visible,
            'commentable' => $input->commentable,
        ];
        if (in_array($input->status, ['private', 'public'], true)) {
            $options['status'] = $input->status;
        }
        if ($input->comment !== null && $input->comment !== '') {
            $options['comment'] = $this->currentConfig->allowHtmlDescriptions ? $input->comment : strip_tags($input->comment);
        }

        $creationOutcome = $this->categoryService->createVirtualCategory(
            $this->currentConfig->allowHtmlDescriptions ? $input->name : strip_tags($input->name),
            $this->activityService,
            $this->currentUser,
            $this->entityManager,
            $input->parentId,
            $options
        );

        if ($creationOutcome->error !== null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $creationOutcome->error);
        }

        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::json([
            'id' => $creationOutcome->id,
            'message' => $creationOutcome->info,
        ], 201);
    }
}
