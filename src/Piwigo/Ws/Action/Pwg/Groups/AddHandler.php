<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

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
        $name = strip_tags(stripslashes(is_string($params['name'] ?? null) ? $params['name'] : ''));
        if ($this->groupRepository->countByName($name) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (strlen(str_replace(' ', '', $name)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $isDefaultRaw = $params['is_default'];
        $isDefaultVal = is_bool($isDefaultRaw) ? $isDefaultRaw : (is_string($isDefaultRaw) ? $isDefaultRaw : '');
        $insertedId   = $this->groupRepository->insertNew($name, BoolUtil::toInt($isDefaultVal));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, 'add'));
        return $server->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }
}
