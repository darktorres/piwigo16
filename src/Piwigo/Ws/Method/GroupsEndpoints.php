<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\OpenApi\ApiMethod;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;

final readonly class GroupsEndpoints
{
    public function __construct(
        private Connection $conn,
        private GroupRepository $groupRepository,
        private UserAdminService $userAdminService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Retrieves a list of all groups. The list can be filtered.', tags: ['groups'])]
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $orderStr = is_string($params['order'] ?? null) ? $params['order'] : '';
        if (!preg_match(ValidationPattern::ORDER, $orderStr)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        $whereClauses = ['1=1'];
        if (!empty($params['name'])) {
            $whereClauses[] = 'LOWER(name) LIKE ' . $this->conn->quote(is_string($params['name']) ? $params['name'] : '');
        }
        if (!empty($params['group_id'])) {
            $groupIdArr     = is_array($params['group_id']) ? $params['group_id'] : [];
            $whereClauses[] = 'id IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdArr)) . ')';
        }
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $query   = 'SELECT g.*, COUNT(user_id) AS nb_users FROM `' . Tables::groups() . '` AS g LEFT JOIN ' . Tables::userGroup() . ' AS ug ON ug.group_id = g.id WHERE ' . implode(' AND ', $whereClauses) . ' GROUP BY id ORDER BY ' . $orderStr . ' LIMIT ' . $perPage . ' OFFSET ' . ($perPage * $page) . ';';
        $groups  = $this->conn->executeQuery($query)->fetchAllAssociative();
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($groups)]), 'groups' => new PwgNamedArray($groups, 'group')];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Creates a group and returns the new group record.', tags: ['groups'])]
    public function add(array $params, PwgServer &$service): mixed
    {
        $params['name'] = strip_tags(stripslashes(is_string($params['name'] ?? null) ? $params['name'] : ''));
        $groupRepo      = $this->groupRepository;
        if ($groupRepo->countByName($params['name']) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (strlen(str_replace(' ', '', $params['name'])) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $isDefaultRaw = $params['is_default'];
        $isDefaultVal = is_bool($isDefaultRaw) ? $isDefaultRaw : (is_string($isDefaultRaw) ? $isDefaultRaw : '');
        Dml::singleInsert(Tables::groups(), ['name' => $params['name'], 'is_default' => BoolUtil::toInt($isDefaultVal)]);
        $insertedId = (int) $this->conn->lastInsertId();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, 'add'));
        return $service->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Deletes a or more groups. Users and photos are not deleted.', tags: ['groups'])]
    public function delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $groupIdInt = is_numeric($params['group_id']) ? (int) $params['group_id'] : (is_array($params['group_id']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['group_id']) : 0);
        $deleteResult = $this->userAdminService->deleteGroups($groupIdInt);
        $groupnames = array_values($deleteResult);
        $this->userAdminService->invalidateUserCache();
        return new PwgNamedArray($groupnames, 'group_deleted');
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Updates a group. Leave a field blank to keep the current value.', tags: ['groups'])]
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $setinfoName = is_string($params['name']) ? $params['name'] : '';
        if (isset($params['name']) && strlen(str_replace(' ', '', $setinfoName)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        $updates         = [];
        $setinfoGroupId  = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $groupRepo       = $this->groupRepository;
        if (!$groupRepo->existsById($setinfoGroupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        if (!empty($params['name'])) {
            $params['name'] = strip_tags(stripslashes(is_string($params['name']) ? $params['name'] : ''));
            if ($groupRepo->countByNameExcludingId($params['name'], $setinfoGroupId) !== 0) {
                return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
            }
            $updates['name'] = $params['name'];
        }
        if (!empty($params['is_default']) || ($params['is_default'] ?? null) === false) {
            $isDefaultUpd          = $params['is_default'];
            $updates['is_default'] = BoolUtil::toInt(is_bool($isDefaultUpd) ? $isDefaultUpd : (is_string($isDefaultUpd) ? $isDefaultUpd : ''));
        }
        Dml::singleUpdate(Tables::groups(), $updates, ['id' => $setinfoGroupId]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $setinfoGroupId, 'edit'));
        return $service->invoke('pwg.groups.getList', ['group_id' => $setinfoGroupId]);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Adds one or more users to a group.', tags: ['groups'])]
    public function addUser(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $adduserGroupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        if (!$this->groupRepository->existsById($adduserGroupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $userIds = is_array($params['user_id']) ? $params['user_id'] : [];
        $inserts = [];
        foreach ($userIds as $userId) {
            $inserts[] = ['group_id' => $adduserGroupId, 'user_id' => $userId];
        }
        Dml::massInserts(Tables::userGroup(), ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $adduserGroupId, 'edit'));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $userIds), 'edit'));
        return $service->invoke('pwg.groups.getList', ['group_id' => $adduserGroupId]);
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[ApiMethod(summary: 'Merge groups in one other group', tags: ['groups'])]
    public function merge(array $params, PwgServer &$service): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $destGroupId   = is_numeric($params['destination_group_id']) ? (int) $params['destination_group_id'] : 0;
        $mergeGroupIds = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_group_id']) ? $params['merge_group_id'] : []);
        $allGroups     = array_unique(array_merge($mergeGroupIds, [$destGroupId]));
        $mergeGroup    = array_diff($mergeGroupIds, [$destGroupId]);
        $mergeGroupObj = $service->invoke('pwg.groups.getList', ['group_id' => $mergeGroupIds]);
        $groupRepo     = $this->groupRepository;
        if ($groupRepo->countByIds($allGroups) !== count($allGroups)) {
            return new PwgError(WsError::InvalidParam->value, 'All groups does not exist.');
        }
        $userInMergeGroups = array_column($this->conn->executeQuery('SELECT DISTINCT(user_id) FROM `' . Tables::userGroup() . '` WHERE group_id IN (' . implode(',', $mergeGroup) . ');')->fetchAllAssociative(), 'user_id');
        $userInDest        = array_column($this->conn->executeQuery('SELECT user_id FROM `' . Tables::userGroup() . '` WHERE group_id = ' . $destGroupId . ';')->fetchAllAssociative(), 'user_id');
        $userToAdd         = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userInMergeGroups), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $userInDest));
        $inserts           = [];
        foreach ($userToAdd as $user) {
            $inserts[] = ['group_id' => $destGroupId, 'user_id' => $user];
        }
        Dml::massInserts(Tables::userGroup(), ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $destGroupId, 'edit'));
        foreach ($userToAdd as $userId) {
            $userIdInt = is_numeric($userId) ? (int) $userId : 0;
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $userIdInt, 'edit', ['associated' => $destGroupId]));
        }
        $this->userAdminService->deleteGroups($mergeGroup);
        return ['destination_group' => $service->invoke('pwg.groups.getList', ['group_id' => $destGroupId]), 'deleted_group' => $mergeGroupObj];
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Create a copy of a group', tags: ['groups'])]
    public function duplicate(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $dupGroupId  = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $copyNameStr = is_string($params['copy_name'] ?? null) ? $params['copy_name'] : '';
        $groupRepo   = $this->groupRepository;
        if ($groupRepo->countByName($copyNameStr) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already used by another group.');
        }
        if (!$groupRepo->existsById($dupGroupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $isDefault = $groupRepo->findIsDefault($dupGroupId);
        Dml::singleInsert(Tables::groups(), ['name' => $copyNameStr, 'is_default' => BoolUtil::toInt($isDefault)]);
        $insertedId = (int) $this->conn->lastInsertId();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $insertedId, 'add'));
        $users   = array_column($this->conn->executeQuery('SELECT user_id FROM `' . Tables::userGroup() . '` WHERE group_id = ' . $dupGroupId . ';')->fetchAllAssociative(), 'user_id');
        $inserts = [];
        foreach ($users as $user) {
            $inserts[] = ['group_id' => $insertedId, 'user_id' => $user];
        }
        Dml::massInserts(Tables::userGroup(), ['group_id', 'user_id'], $inserts, ['ignore' => true]);
        $this->userAdminService->invalidateUserCache();
        foreach ($users as $userId) {
            $uid = is_numeric($userId) ? (int) $userId : 0;
            $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $uid, 'edit', ['associated' => $dupGroupId]));
        }
        return $service->invoke('pwg.groups.getList', ['group_id' => $insertedId]);
    }

    /** @param array<mixed> $params */
    #[ApiMethod(summary: 'Removes one or more users from a group.', tags: ['groups'])]
    public function deleteUser(array $params, PwgServer &$service): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $deluserGroupId = is_numeric($params['group_id']) ? (int) $params['group_id'] : 0;
        $deluserUserIds = is_array($params['user_id']) ? $params['user_id'] : [];
        $groupRepo      = $this->groupRepository;
        if (!$groupRepo->existsById($deluserGroupId)) {
            return new PwgError(WsError::InvalidParam->value, 'This group does not exist.');
        }
        $groupRepo->deleteUserGroupMembers($deluserGroupId, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $deluserUserIds));
        $this->userAdminService->invalidateUserCache();
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Group, $deluserGroupId, 'edit'));
        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $deluserUserIds), 'edit'));
        return $service->invoke('pwg.groups.getList', ['group_id' => $deluserGroupId]);
    }
}
