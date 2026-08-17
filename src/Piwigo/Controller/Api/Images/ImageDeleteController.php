<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/delete` -- `pwg.images.delete`'s real
 * replacement, admin + CSRF. Bulk by design -- matches the admin photo
 * management UI's own batch-select workflow.
 */
final readonly class ImageDeleteController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
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

        $input = ImageIdsInput::fromArray(JsonBody::decode($request));
        if ($input->imageIds === []) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'No image id given.');
        }

        $deletedCount = $this->imageService->deleteElements($input->imageIds, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::json([
            'deletedCount' => $deletedCount,
        ]);
    }
}
