<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/sync-metadata` --
 * `pwg.images.syncMetadata`'s real replacement, admin + CSRF (its WS
 * predecessor already checked CSRF too). Re-reads EXIF/IPTC metadata
 * from disk for the given photos -- the Batch Manager "unit"/"global"
 * panels' own sync action.
 */
final readonly class ImageSyncMetadataController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private ImageService $imageService,
        private MetadataService $metadataService,
        private PermissionService $permissionService,
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

        $input = ImageSyncMetadataInput::fromArray(JsonBody::decode($request));

        $imageIds = $this->imageService->getExistingIds($input->imageIds);
        if ($imageIds === []) {
            return ResponseFactory::problem('Not Found', 404, 'No matching photo found.');
        }

        $this->metadataService->syncMetadata($imageIds, $this->permissionService, $this->entityManager);

        return ResponseFactory::json([
            'nbSynchronized' => count($imageIds),
        ]);
    }
}
