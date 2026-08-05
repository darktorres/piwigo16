<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use InvalidArgumentException;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupService;

/**
 * P23 batch 8e-3: relocated from include/ws_functions/pwg.groups.php.
 * `pwg.groups.*` WS methods (8 registrations) -- registered via callable
 * arrays in include/ws_default_methods.inc.php.
 */
final class PwgGroups
{
    public function __construct(
        private readonly GroupService $groupService,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Audit\AuditService $auditService,
    ) {}

    /**
     * API method
     * Returns the list of groups
     *
     * @param array{group_id?: array<int, int>, name?: string, per_page: int, page: int, order: string, ...} $params
     *   group_id/name: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent; FORCE_ARRAY always coerces group_id to a list of
     *   positive ints when present. per_page/page: non-null int default --
     *   always present. order: non-null string default ('name'), no 'type'
     *   flag -- always present, always string.
     * @return PwgError|array{paging: PwgNamedStruct, groups: PwgNamedArray}
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        $groups = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class)
            ->findWithMemberCounts(
                array_values(array_map(GroupId::from(...), $params['group_id'] ?? [])),
                isset($params['name']) && $params['name'] !== '' ? $params['name'] : null,
                $params['order'],
                $params['per_page'],
                $params['page']
            );

        return [
            'paging' => new PwgNamedStruct([
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'count' => count($groups),
            ]),
            'groups' => new PwgNamedArray($groups, 'group'),
        ];
    }

    /**
     * API method
     * Adds a group
     *
     * @param array{name: string, is_default: bool, ...} $params name has no
     *   'default' key -- mandatory, always present. is_default: non-null
     *   bool default, WsParamType::BOOL -- always present.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public function add(array $params, PwgServer &$service): mixed
    {
        $name = strip_tags(stripslashes($params['name']));

        try {
            $inserted_id = $this->groupService->create($name, $params['is_default']);
        } catch (InvalidArgumentException $e) {
            return new PwgError(WsError::INVALID_PARAM, $e->getMessage());
        }

        // [SEC-57]
        $actor_id = $this->currentUser->get()
            ->id->value;
        $this->auditService
            ->record($actor_id, 'create', 'group', $inserted_id->value, null, [
                'name' => $name,
            ]);

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $inserted_id->value,
        ]);
    }

    /**
     * API method
     * Deletes a group
     *
     * @param array{group_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces group_id to a list of positive ints.
     */
    public function delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $deleted_groups = $this->groupService->delete(array_values(array_map(GroupId::from(...), $params['group_id'])));
        if ($deleted_groups === false) {
            return new PwgError(500, 'There is no group to delete');
        }
        $groupnames = array_values($deleted_groups);

        PermissionCacheInvalidator::invalidate();

        return new PwgNamedArray($groupnames, 'group_deleted');
    }

    /**
     * API method
     * Updates a group
     *
     * @param array{group_id: int, name?: string, is_default?: bool, pwg_token: string, ...} $params
     *   group_id/pwg_token: no 'default' key -- mandatory, always present,
     *   WsParamType::ID guarantees a plain int for group_id. name/is_default:
     *   WsParamFlag::OPTIONAL with no 'default' key -- may be entirely absent.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public function setInfo(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $updates = [];
        if (isset($params['name'])) {
            $updates['name'] = strip_tags(stripslashes($params['name']));
        }

        if (isset($params['is_default'])) {
            $updates['is_default'] = $params['is_default'];
        }

        try {
            $this->groupService->update(GroupId::from($params['group_id']), $updates);
        } catch (InvalidArgumentException $e) {
            return new PwgError(WsError::INVALID_PARAM, $e->getMessage());
        }

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $params['group_id'],
        ]);
    }

    /**
     * API method
     * Adds user(s) to a group
     *
     * @param array{group_id: int, user_id: array<int, int>, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present; group_id:
     *   WsParamType::ID guarantees a plain int; user_id: FORCE_ARRAY always
     *   coerces to a list of positive ints.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public function addUser(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $added = $this->groupService->addMembers(GroupId::from($params['group_id']), array_values(array_map(UserId::from(...), $params['user_id'])));
        if (! $added) {
            return new PwgError(WsError::INVALID_PARAM, 'This group does not exist.');
        }

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $params['group_id'],
        ]);
    }

    /**
     * API method
     * Merge groups in one other group
     *
     * @param array{destination_group_id: int, merge_group_id: array<int, int>, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present;
     *   destination_group_id: WsParamType::ID guarantees a plain int;
     *   merge_group_id: FORCE_ARRAY always coerces to a list of positive
     *   ints.
     * Both values are $service->invoke('pwg.groups.getList', ...)'s own
     * result -- same by-name-dispatcher rationale as add()/setInfo() above.
     * @return PwgError|array{destination_group: mixed, deleted_group: mixed}
     */
    public function merge(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $merge_group_object = $service->invoke('pwg.groups.getList', [
            'group_id' => $params['merge_group_id'],
        ]);

        $merged = $this->groupService->merge(GroupId::from($params['destination_group_id']), array_values(array_map(GroupId::from(...), $params['merge_group_id'])));
        if (! $merged) {
            return new PwgError(WsError::INVALID_PARAM, 'All groups does not exist.');
        }

        return [
            'destination_group' => $service->invoke('pwg.groups.getList', [
                'group_id' => $params['destination_group_id'],
            ]),
            'deleted_group' => $merge_group_object,
        ];
    }

    /**
     * API method
     * Create a copy of a group
     *
     * @param array{group_id: int, copy_name: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present,
     *   WsParamType::ID guarantees a plain int for group_id.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public function duplicate(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        try {
            $inserted_id = $this->groupService->duplicate(GroupId::from($params['group_id']), $params['copy_name']);
        } catch (InvalidArgumentException $e) {
            return new PwgError(WsError::INVALID_PARAM, $e->getMessage());
        }

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $inserted_id->value,
        ]);
    }

    /**
     * API method
     * Removes user(s) from a group
     *
     * @param array{group_id: int, user_id: array<int, int>, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present; group_id:
     *   WsParamType::ID guarantees a plain int; user_id: FORCE_ARRAY always
     *   coerces to a list of positive ints.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public function deleteUser(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $removed = $this->groupService->removeMembers(GroupId::from($params['group_id']), array_values(array_map(UserId::from(...), $params['user_id'])));
        if (! $removed) {
            return new PwgError(WsError::INVALID_PARAM, 'This group does not exist.');
        }

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $params['group_id'],
        ]);
    }
}
