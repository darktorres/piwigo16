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
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.deleteRepresentative` -- admin only. Deletes the album
 * thumbnail. Only possible if `CurrentConfig::allowRandomRepresentative`
 * or if the album has no direct photos.
 */
final readonly class DeleteRepresentativeHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): ?WsErrorResponse
    {
        $input = DeleteRepresentativeParams::fromArray($params);

        // does the category really exist?
        if (! $this->categoryService->existsById($input->categoryId)) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $has_images = $this->categoryService->hasImages($input->categoryId);

        if (! $this->currentConfig->allowRandomRepresentative and $has_images) {
            return new WsErrorResponse(401, 'not permitted');
        }

        $this->categoryService->clearRepresentativeImage(CategoryId::from($input->categoryId));
        $this->entityManager->clear();

        $this->activityService->record('album', $input->categoryId, 'edit');

        return null;
    }
}
