<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.images.setMd5sum` — backfill md5sum column in `block_size` chunks. */
final readonly class SetMd5sumHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private ImageAdminService $imageAdminService,
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
            $input = SetMd5sumParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $noMd5sumIds = $this->imageAdminService->getPhotosNoMd5sum();
        $addedCount  = 0;
        if (count($noMd5sumIds) > 0) {
            $md5sumIdsToAdd = array_slice($noMd5sumIds, 0, $input->blockSize);
            $addedCount     = $this->imageAdminService->addMd5sum($md5sumIdsToAdd);
        }
        return ['nb_added' => $addedCount, 'nb_no_md5sum' => count($this->imageAdminService->getPhotosNoMd5sum())];
    }
}
