<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Core\BoolUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.groups.setInfo` — update a group's name / is_default flag. */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private GroupRepository $groupRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $name = is_string($params['name']) ? $params['name'] : '';
        if (isset($params['name']) && strlen(str_replace(' ', '', $name)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $updates = [];
        $groupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        if (!$this->groupRepository->existsById($groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        if (!empty($params['name'])) {
            $sanitized = strip_tags(stripslashes(is_string($params['name']) ? $params['name'] : ''));
            if ($this->groupRepository->countByNameExcludingId($sanitized, $groupId) !== 0) {
                return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
            }
            $updates['name'] = $sanitized;
        }
        if (!empty($params['is_default']) || ($params['is_default'] ?? null) === false) {
            $isDefaultUpd          = $params['is_default'];
            $updates['is_default'] = BoolUtil::toInt(is_bool($isDefaultUpd) ? $isDefaultUpd : (is_string($isDefaultUpd) ? $isDefaultUpd : ''));
        }
        $this->groupRepository->updateById($groupId, $updates);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $groupId, 'edit'));
        return $server->invoke('pwg.groups.getList', ['group_id' => $groupId]);
    }
}
