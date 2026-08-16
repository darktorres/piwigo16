<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.setRepresentative` -- admin only. Sets the representative photo for an album.
 */
final readonly class SetRepresentativeHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ImageService $imageService,
        private ActivityService $activityService,
        private CategoryTreeCachePool $categoryTreeCachePool,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): ?WsErrorResponse
    {
        $input = SetRepresentativeParams::fromArray($params);

        // does the category really exist?
        if (! $this->categoryService->existsById($input->categoryId)) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($input->imageId))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        // apply change
        $this->categoryService->setRepresentativeImage($input->categoryId, $input->imageId);
        $this->entityManager->clear();

        // Invalidates every user's own remembered-representative cache
        // entry so the admin's explicit choice above takes priority on
        // the next read. PSR-6 has no per-key-prefix bulk delete, so
        // this clears the whole pool -- a rare admin action, not a hot
        // path, and the pool's own 300s TTL already treats broader
        // staleness as tolerable.
        $this->categoryTreeCachePool->clear();

        $this->activityService->record('album', $input->categoryId, 'edit', [
            'image_id' => $input->imageId,
        ]);

        return null;
    }
}
