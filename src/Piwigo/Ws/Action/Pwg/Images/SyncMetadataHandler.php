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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SyncMetadataParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        foreach ($input->imageIds as $imageId) {
            if (!preg_match(ValidationPattern::ID, $imageId)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid image_id "' . $imageId . '"');
            }
        }
        if (count($input->imageIds) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid image_id (no value after filters)');
        }
        $imageIdsInt = array_map(static fn (string $id): int => (int) $id, $input->imageIds);
        $imageIds    = $this->imageRepository->findExistingIdsAmong($imageIdsInt);
        if (empty($imageIds)) {
            return new PwgError(403, 'No image found');
        }
        $this->metadataAdminService->syncMetadata($imageIds);
        return ['nb_synchronized' => count($imageIds)];
    }
}
