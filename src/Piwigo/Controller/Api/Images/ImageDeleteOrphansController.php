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
 * `POST /api/v1/images/actions/delete-orphans` --
 * `pwg.images.deleteOrphans`'s real replacement, admin + CSRF. Deletes
 * photos linked to no album, one block at a time -- the Batch Manager
 * "global" panel's own delete-orphans action.
 */
final readonly class ImageDeleteOrphansController implements ControllerInterface
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

        $input = ImageDeleteOrphansInput::fromArray(JsonBody::decode($request));

        $orphanIdsToDelete = array_slice($this->imageService->getOrphans(), 0, $input->blockSize);
        $deletedCount = $this->imageService->deleteElements($orphanIdsToDelete, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        return ResponseFactory::json([
            'nbDeleted' => $deletedCount,
            'nbOrphans' => count($this->imageService->getOrphans()),
        ]);
    }
}
