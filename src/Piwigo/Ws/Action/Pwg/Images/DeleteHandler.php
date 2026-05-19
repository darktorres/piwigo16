<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.delete` — bulk-delete images by id. */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private ImageAdminService $imageAdminService,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): PwgError|int
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $delImageIdsRaw = $params['image_id'];
        if (!is_array($delImageIdsRaw)) {
            $delImageIdsRaw = (($delSplit = preg_split('/[\s,;\|]/', is_scalar($delImageIdsRaw) ? (string) $delImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $delSplit : []);
        }
        $delImageIdsRaw = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $delImageIdsRaw);
        $imageIds       = array_filter($delImageIdsRaw, fn (int $v): bool => $v > 0);
        $ret            = $this->imageAdminService->deleteElements(array_values($imageIds), true);
        $this->userAdminService->invalidateUserCache();
        return $ret;
    }
}
