<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/set-category` --
 * `pwg.images.setCategory`'s real replacement, admin + CSRF
 * (`requiresAuth: true` on the WS side really means admin_only --
 * `Ws\Server::invoke()`'s own enforcement). `action` can be `associate`
 * (add photos to this album), `dissociate` (remove them), or `move`
 * (dissociate from every other album and associate with this one).
 */
final readonly class ImageSetCategoryController implements ControllerInterface
{
    private const array VALID_ACTIONS = ['associate', 'dissociate', 'move'];

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private ImageService $imageService,
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

        $input = ImageSetCategoryInput::fromArray(JsonBody::decode($request));

        if (! in_array($input->action, self::VALID_ACTIONS, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid action, possible values are {' . implode(', ', self::VALID_ACTIONS) . '}.');
        }

        if (! $this->categoryService->existsById($input->categoryId)) {
            return ResponseFactory::problem('Not Found', 404, 'category_id not found.');
        }

        match ($input->action) {
            'associate' => $this->imageService->associateImagesToCategories($input->imageIds, [$input->categoryId]),
            'dissociate' => $this->imageService->dissociateImagesFromCategory($input->imageIds, $input->categoryId),
            'move' => $this->imageService->moveImagesToCategories($input->imageIds, [$input->categoryId]),
        };

        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::noContent();
    }
}
