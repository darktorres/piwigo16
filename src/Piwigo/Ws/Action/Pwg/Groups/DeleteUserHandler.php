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

/** `pwg.groups.deleteUser` — remove one or more users from a group. */
final readonly class DeleteUserHandler implements WsAction
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
        $groupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $userIds = is_array($params['user_id']) ? $params['user_id'] : [];
        if (!$this->groupRepository->existsById($groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $this->groupRepository->deleteUserGroupMembers($groupId, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIds));
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $groupId, 'edit'));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIds), 'edit'));
        return $server->invoke('pwg.groups.getList', ['group_id' => $groupId]);
    }
}
