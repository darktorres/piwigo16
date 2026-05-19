<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.syncMetadata` — re-extract EXIF/IPTC for an image set. */
final readonly class SyncMetadataHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private ImageRepository $imageRepository,
        private MetadataAdminService $metadataAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, int>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $syncImageIdsRaw = $params['image_id'];
        if (!is_array($syncImageIdsRaw)) {
            $syncImageIdsRaw = (($syncSplit = preg_split('/[\s,;\|]/', is_scalar($syncImageIdsRaw) ? (string) $syncImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $syncSplit : []);
        }
        $imageIds = [];
        foreach ($syncImageIdsRaw as $imageId) {
            $imageId = trim(is_scalar($imageId) ? (string) $imageId : '');
            if (!preg_match(ValidationPattern::ID, $imageId)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid image_id "' . $imageId . '"');
            }
            $imageIds[] = $imageId;
        }
        if (empty($imageIds)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid image_id (no value after filters)');
        }
        $imageIdsInt = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $imageIds);
        $imageIds    = $this->imageRepository->findExistingIdsAmong($imageIdsInt);
        if (empty($imageIds)) {
            return new PwgError(403, 'No image found');
        }
        $this->metadataAdminService->syncMetadata($imageIds);
        return ['nb_synchronized' => count($imageIds)];
    }
}
