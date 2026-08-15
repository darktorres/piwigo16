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
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.setCategory` -- admin only. Associates/Dissociates/Moves
 * photos with an album.
 *
 * @since 14
 */
final readonly class SetCategoryHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): ?WsErrorResponse
    {
        $input = SetCategoryParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        // does the category really exist?
        if (! $this->categoryService->existsById($input->categoryId)) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $imageService = $this->imageService;

        if ($input->action === 'associate') {
            $imageService->associateImagesToCategories($input->imageIds, [$input->categoryId]);
        } elseif ($input->action === 'dissociate') {
            $imageService->dissociateImagesFromCategory($input->imageIds, $input->categoryId);
        } elseif ($input->action === 'move') {
            $imageService->moveImagesToCategories($input->imageIds, [$input->categoryId]);
        }

        PermissionCacheInvalidator::invalidate();

        return null;
    }
}
