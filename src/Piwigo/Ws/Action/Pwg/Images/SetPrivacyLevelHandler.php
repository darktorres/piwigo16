<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SetPrivacyLevelParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(WsError::InvalidParam->value, $e->getMessage());
        }
        $affected = $this->imageRepository->setLevelForIds($input->level, $input->imageIds);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $input->imageIds, ActivityAction::Edit));
        if ($affected) {
            $this->userAdminService->invalidateUserCache();
        }
        return $affected;
    }
}
