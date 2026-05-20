<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityAction;
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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = DeleteUserParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!$this->groupRepository->existsById($input->groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $this->groupRepository->deleteUserGroupMembers($input->groupId, $input->userIds);
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $input->groupId, ActivityAction::Edit));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $input->userIds, ActivityAction::Edit));
        return $server->invoke('pwg.groups.getList', ['group_id' => $input->groupId]);
    }
}
