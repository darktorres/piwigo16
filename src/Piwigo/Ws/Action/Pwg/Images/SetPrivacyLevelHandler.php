<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.setPrivacyLevel` — bulk-update the `level` column. */
final readonly class SetPrivacyLevelHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ImageRepository $imageRepository,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if (!in_array($params['level'], Config::availablePermissionLevels())) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid level');
        }
        $pLevel    = is_numeric($params['level']) ? (int) $params['level'] : 0;
        $pImageIds = is_array($params['image_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['image_id']) : [];
        $affected  = $this->imageRepository->setLevelForIds($pLevel, $pImageIds);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $pImageIds, 'edit'));
        if ($affected) {
            $this->userAdminService->invalidateUserCache();
        }
        return $affected;
    }
}
