<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.groups.merge` — merge several groups into a destination group. */
final readonly class MergeHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private GroupRepository $groupRepository,
        private UserAdminService $userAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $destGroupId   = is_numeric($params['destination_group_id']) ? (int) $params['destination_group_id'] : 0;
        $mergeGroupIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_group_id']) ? $params['merge_group_id'] : []);
        $allGroups     = array_unique(array_merge($mergeGroupIds, [$destGroupId]));
        $mergeGroup    = array_diff($mergeGroupIds, [$destGroupId]);
        $mergeGroupObj = $server->invoke('pwg.groups.getList', ['group_id' => $mergeGroupIds]);
        if ($this->groupRepository->countByIds($allGroups) !== count($allGroups)) {
            return new PwgError(WsError::InvalidParam->value, 'All groups does not exist.');
        }
        $userInMergeGroups = $this->groupRepository->findDistinctUserIdsInGroups(array_values($mergeGroup));
        $userInDest        = $this->groupRepository->findUserIdsByGroupId($destGroupId);
        $userToAdd         = array_values(array_diff($userInMergeGroups, $userInDest));
        $inserts           = [];
        foreach ($userToAdd as $user) {
            $inserts[] = ['group_id' => $destGroupId, 'user_id' => $user];
        }
        $this->groupRepository->insertUserGroupIgnoreDuplicates($inserts);
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $destGroupId, 'edit'));
        foreach ($userToAdd as $userId) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userId, 'edit', ['associated' => $destGroupId]));
        }
        $this->userAdminService->deleteGroups($mergeGroup);
        return ['destination_group' => $server->invoke('pwg.groups.getList', ['group_id' => $destGroupId]), 'deleted_group' => $mergeGroupObj];
    }
}
