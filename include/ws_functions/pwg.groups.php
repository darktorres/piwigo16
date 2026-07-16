<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;

/**
 * API method
 * Returns the list of groups
 *
 * @param array{group_id?: array<int, int>, name?: string, per_page: int, page: int, order: string, ...} $params
 *   group_id/name: WS_PARAM_OPTIONAL with no 'default' key -- may be
 *   entirely absent; FORCE_ARRAY always coerces group_id to a list of
 *   positive ints when present. per_page/page: non-null int default --
 *   always present. order: non-null string default ('name'), no 'type'
 *   flag -- always present, always string.
 * @return PwgError|array{paging: PwgNamedStruct, groups: PwgNamedArray}
 */
function ws_groups_getList(array $params, PwgServer &$service): PwgError|array
{
    if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid input parameter order');
    }

    $groups = new GroupRepository(DbConnection::build())
        ->findWithMemberCounts(
            $params['group_id'] ?? [],
            ! empty($params['name']) ? $params['name'] : null,
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
 *   bool default, WS_TYPE_BOOL -- always present.
 * @return mixed PwgError, or the result of the pwg.groups.getList invocation
 */
function ws_groups_add(array $params, PwgServer &$service): mixed
{
    /** @var array<string, mixed> $user */
    global $user;

    $name = strip_tags(stripslashes($params['name']));

    try {
        $inserted_id = new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
            ->create($name, $params['is_default']);
    } catch (\InvalidArgumentException $e) {
        return new PwgError(WS_ERR_INVALID_PARAM, $e->getMessage());
    }

    // [SEC-57]
    $actor_id = $user['id'] ?? null;
    new AuditService(new AuditRepository(DbConnection::build()))
        ->record(is_numeric($actor_id) ? (int) $actor_id : null, 'create', 'group', $inserted_id, null, [
            'name' => $name,
        ]);

    return $service->invoke('pwg.groups.getList', [
        'group_id' => $inserted_id,
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
function ws_groups_delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    $deleted_groups = delete_groups($params['group_id']);
    if ($deleted_groups === false) {
        return new PwgError(500, 'There is no group to delete');
    }
    $groupnames = array_values($deleted_groups);

    invalidate_user_cache();

    return new PwgNamedArray($groupnames, 'group_deleted');
}

/**
 * API method
 * Updates a group
 *
 * @param array{group_id: int, name?: string, is_default?: bool, pwg_token: string, ...} $params
 *   group_id/pwg_token: no 'default' key -- mandatory, always present,
 *   WS_TYPE_ID guarantees a plain int for group_id. name/is_default:
 *   WS_PARAM_OPTIONAL with no 'default' key -- may be entirely absent.
 * @return mixed PwgError, or the result of the pwg.groups.getList invocation
 */
function ws_groups_setInfo(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
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
        new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
            ->update($params['group_id'], $updates);
    } catch (\InvalidArgumentException $e) {
        return new PwgError(WS_ERR_INVALID_PARAM, $e->getMessage());
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
 *   WS_TYPE_ID guarantees a plain int; user_id: FORCE_ARRAY always
 *   coerces to a list of positive ints.
 * @return mixed PwgError, or the result of the pwg.groups.getList invocation
 */
function ws_groups_addUser(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $added = new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
        ->addMembers($params['group_id'], $params['user_id']);
    if (! $added) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
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
 *   destination_group_id: WS_TYPE_ID guarantees a plain int;
 *   merge_group_id: FORCE_ARRAY always coerces to a list of positive
 *   ints.
 * @return PwgError|array{destination_group: mixed, deleted_group: mixed}
 */
function ws_groups_merge(array $params, PwgServer &$service): PwgError|array
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $merge_group_object = $service->invoke('pwg.groups.getList', [
        'group_id' => $params['merge_group_id'],
    ]);

    $merged = new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
        ->merge($params['destination_group_id'], $params['merge_group_id']);
    if (! $merged) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'All groups does not exist.');
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
 *   WS_TYPE_ID guarantees a plain int for group_id.
 * @return mixed PwgError, or the result of the pwg.groups.getList invocation
 */
function ws_groups_duplicate(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    try {
        $inserted_id = new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
            ->duplicate($params['group_id'], $params['copy_name']);
    } catch (\InvalidArgumentException $e) {
        return new PwgError(WS_ERR_INVALID_PARAM, $e->getMessage());
    }

    return $service->invoke('pwg.groups.getList', [
        'group_id' => $inserted_id,
    ]);
}

/**
 * API method
 * Removes user(s) from a group
 *
 * @param array{group_id: int, user_id: array<int, int>, pwg_token: string, ...} $params
 *   none has a 'default' key -- all mandatory, always present; group_id:
 *   WS_TYPE_ID guarantees a plain int; user_id: FORCE_ARRAY always
 *   coerces to a list of positive ints.
 * @return mixed PwgError, or the result of the pwg.groups.getList invocation
 */
function ws_groups_deleteUser(array $params, PwgServer &$service): mixed
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $removed = new GroupService(new GroupRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
        ->removeMembers($params['group_id'], $params['user_id']);
    if (! $removed) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'This group does not exist.');
    }

    return $service->invoke('pwg.groups.getList', [
        'group_id' => $params['group_id'],
    ]);
}
