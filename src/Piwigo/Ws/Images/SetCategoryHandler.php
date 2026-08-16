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
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
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
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): ?WsErrorResponse
    {
        $input = SetCategoryParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
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
