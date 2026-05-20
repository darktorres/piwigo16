<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.images.deleteOrphans` — purge orphan images in `block_size` chunks. */
final readonly class DeleteOrphansHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private ImageAdminService $imageAdminService,
        private UserAdminService $userAdminService,
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
            $input = DeleteOrphansParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $orphanIdsToDelete = array_slice($this->imageAdminService->getOrphans(), 0, $input->blockSize);
        $deletedCount      = $this->imageAdminService->deleteElements($orphanIdsToDelete, true);
        $this->userAdminService->invalidateUserCache();
        return ['nb_deleted' => $deletedCount, 'nb_orphans' => count($this->imageAdminService->getOrphans())];
    }
}
