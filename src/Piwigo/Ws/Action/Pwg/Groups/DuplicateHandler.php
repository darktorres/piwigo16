<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\BoolUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

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
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $groupId  = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $copyName = is_string($params['copy_name'] ?? null) ? $params['copy_name'] : '';
        if ($this->groupRepository->countByName($copyName) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (!$this->groupRepository->existsById($groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $isDefault  = $this->groupRepository->findIsDefault($groupId);
        $insertedId = $this->groupRepository->insertNew($copyName, BoolUtil::toInt($isDefault));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, 'add'));
        $users   = $this->groupRepository->findUserIdsByGroupId($groupId);
        $inserts = [];
        foreach ($users as $user) {
            $inserts[] = ['group_id' => $insertedId, 'user_id' => $user];
        }
        $this->groupRepository->insertUserGroupIgnoreDuplicates($inserts);
        $this->userAdminService->invalidateUserCache();
        foreach ($users as $userId) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, 'edit', ['associated' => $groupId]));
        }
        return $server->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }
}
