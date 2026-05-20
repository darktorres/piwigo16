<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\Details\UserAssocDetails;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\BoolUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;

/** `pwg.groups.duplicate` — clone a group (incl. memberships) under a new name. */
final readonly class DuplicateHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private GroupRepository $groupRepository,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        try {
            $input = DuplicateParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->groupRepository->countByName($input->copyName) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (!$this->groupRepository->existsById($input->groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $isDefault  = $this->groupRepository->findIsDefault($input->groupId);
        $insertedId = $this->groupRepository->insertNew($input->copyName, BoolUtil::toInt($isDefault));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, ActivityAction::Add));
        $users   = $this->groupRepository->findUserIdsByGroupId($input->groupId);
        $inserts = [];
        foreach ($users as $user) {
            $inserts[] = ['group_id' => $insertedId, 'user_id' => $user];
        }
        $this->groupRepository->insertUserGroupIgnoreDuplicates($inserts);
        $this->userAdminService->invalidateUserCache();
        foreach ($users as $userId) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, ActivityAction::Edit, new UserAssocDetails($input->groupId)));
        }
        return $server->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }
}
