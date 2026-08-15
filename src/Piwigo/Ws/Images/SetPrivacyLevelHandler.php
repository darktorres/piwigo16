<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Image\ImageService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.setPrivacyLevel` -- admin only. Sets the privacy levels for the images.
 */
final readonly class SetPrivacyLevelHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|int
    {
        $input = SetPrivacyLevelParams::fromArray($params);

        $available_permission_levels = $this->currentConfig->availablePermissionLevels;

        if (! in_array($input->level, $available_permission_levels, true)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid level');
        }

        $affected_rows = $this->imageService->updateLevelForImages($input->imageIds, $input->level);
        $this->entityManager->clear();

        $this->activityService->record('photo', $input->imageIds, 'edit');

        if ($affected_rows > 0) {
            PermissionCacheInvalidator::invalidate();
        }
        return $affected_rows;
    }
}
