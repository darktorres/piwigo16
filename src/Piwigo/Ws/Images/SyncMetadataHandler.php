<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.syncMetadata` -- admin only. Synchronizes metadatas of
 * photos. Returns how many metadatas were synchronized.
 */
final readonly class SyncMetadataHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private MetadataService $metadataService,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{nb_synchronized: int}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SyncMetadataParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $image_ids = [];
        foreach ($input->imageIds as $image_id) {
            $image_id = trim($image_id);

            if (! (bool) preg_match(ValidationPattern::ID, $image_id)) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid image_id "' . $image_id . '"');
            }

            $image_ids[] = $image_id;
        }

        if ($image_ids === []) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid image_id (no value after filters)');
        }

        $image_ids = $this->imageService->getExistingIds($image_ids);

        if ($image_ids === []) {
            return new WsErrorResponse(403, 'No image found');
        }

        $this->metadataService
            ->syncMetadata($image_ids, $this->permissionService, $this->entityManager);

        return [
            'nb_synchronized' => count($image_ids),
        ];
    }
}
