<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Core\BoolUtil;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.groups.add` — create a new group. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private GroupRepository $groupRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $input = AddParams::fromArray($params);
        if ($this->groupRepository->countByName($input->name) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (strlen(str_replace(' ', '', $input->name)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $insertedId = $this->groupRepository->insertNew($input->name, BoolUtil::toInt($input->isDefault));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, ActivityAction::Add));
        return $server->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }
}
