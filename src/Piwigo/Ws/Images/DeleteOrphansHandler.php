<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.deleteOrphans` -- admin only. Deletes orphan photos, by
 * block. Returns how many orphans were deleted and how many are
 * remaining.
 */
final readonly class DeleteOrphansHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{nb_deleted: int, nb_orphans: int}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = DeleteOrphansParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $imageService = $this->imageService;

        $orphan_ids_to_delete = array_slice($imageService->getOrphans(), 0, $input->blockSize);
        $deleted_count = $imageService->deleteElements($orphan_ids_to_delete, $this->urlService, true);
        PermissionCacheInvalidator::invalidate();

        return [
            'nb_deleted' => $deleted_count,
            'nb_orphans' => count($imageService->getOrphans()),
        ];
    }
}
