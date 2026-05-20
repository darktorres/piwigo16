<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Activity\ActivityAction;
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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = SetInfoParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->name !== null && strlen(str_replace(' ', '', $input->name)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $updates = [];
        $groupId = $input->groupId;
        if (!$this->groupRepository->existsById($groupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        if ($input->name !== null && $input->name !== '') {
            $sanitized = strip_tags(stripslashes($input->name));
            if ($this->groupRepository->countByNameExcludingId($sanitized, $groupId) !== 0) {
                return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
            }
            $updates['name'] = $sanitized;
        }
        if ($input->isDefaultSet) {
            $updates['is_default'] = BoolUtil::toInt($input->isDefault ?? '');
        }
        $this->groupRepository->updateById($groupId, $updates);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $groupId, ActivityAction::Edit));
        return $server->invoke('pwg.groups.getList', ['group_id' => $groupId]);
    }
}
