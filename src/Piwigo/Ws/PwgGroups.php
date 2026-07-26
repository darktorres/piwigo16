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
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
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
    public static function getList(array $params, PwgServer &$service): PwgError|array
    {
        if (! (bool) preg_match(ValidationPattern::ORDER, $params['order'])) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        $groups = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class)
            ->findWithMemberCounts(
                $params['group_id'] ?? [],
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
    public static function add(array $params, PwgServer &$service): mixed
    {
        $name = strip_tags(stripslashes($params['name']));

        try {
            $inserted_id = self::groupService()->create($name, $params['is_default']);
        } catch (InvalidArgumentException $e) {
            return new PwgError(WsError::INVALID_PARAM, $e->getMessage());
        }

        // [SEC-57]
        $actor_id = \Piwigo\Users\CurrentUser::get()->id;
        \Piwigo\Bootstrap\CoreDomainAccessor::auditService()
            ->record($actor_id, 'create', 'group', $inserted_id, null, [
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
    public static function delete(array $params, PwgServer &$service): PwgError|PwgNamedArray
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $deleted_groups = self::groupService()->delete($params['group_id']);
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
    public static function setInfo(array $params, PwgServer &$service): mixed
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
            self::groupService()->update($params['group_id'], $updates);
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
    public static function addUser(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $added = self::groupService()->addMembers($params['group_id'], $params['user_id']);
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
     * @return PwgError|array{destination_group: mixed, deleted_group: mixed}
     */
    public static function merge(array $params, PwgServer &$service): PwgError|array
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $merge_group_object = $service->invoke('pwg.groups.getList', [
            'group_id' => $params['merge_group_id'],
        ]);

        $merged = self::groupService()->merge($params['destination_group_id'], $params['merge_group_id']);
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
    public static function duplicate(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        try {
            $inserted_id = self::groupService()->duplicate($params['group_id'], $params['copy_name']);
        } catch (InvalidArgumentException $e) {
            return new PwgError(WsError::INVALID_PARAM, $e->getMessage());
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
     *   WsParamType::ID guarantees a plain int; user_id: FORCE_ARRAY always
     *   coerces to a list of positive ints.
     * @return mixed PwgError, or the result of the pwg.groups.getList invocation
     */
    public static function deleteUser(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $removed = self::groupService()->removeMembers($params['group_id'], $params['user_id']);
        if (! $removed) {
            return new PwgError(WsError::INVALID_PARAM, 'This group does not exist.');
        }

        return $service->invoke('pwg.groups.getList', [
            'group_id' => $params['group_id'],
        ]);
    }

    /**
     * Constructed identically across add()/delete()/setInfo()/addUser()/
     * merge()/duplicate()/deleteUser() -- all static methods, no shared
     * instance state to inject into, same "private static helper"
     * precedent as Bootstrap\RequestBootstrap::activityService() /
     * PwgComments::commentService().
     */
    private static function groupService(): GroupService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::groupService();
    }
}
