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
use Piwigo\Ws\WsParamException;

/** `pwg.groups.addUser` — associate one or more users with a group. */
final readonly class AddUserHandler implements WsAction
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
            $input = AddUserParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!$this->groupRepository->existsById($input->groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $inserts = [];
        foreach ($input->userIds as $userId) {
            $inserts[] = ['group_id' => $input->groupId, 'user_id' => $userId];
        }
        $this->groupRepository->insertUserGroupIgnoreDuplicates($inserts);
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $input->groupId, 'edit'));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $input->userIds, 'edit'));
        return $server->invoke('pwg.groups.getList', ['group_id' => $input->groupId]);
    }
}
