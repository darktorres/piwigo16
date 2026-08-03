<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Category\CategoryService;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionService;

/**
 * P23 batch 8e-2: relocated from include/ws_functions/pwg.permissions.php.
 * `pwg.permissions.*` WS methods (3 registrations, all admin_only) --
 * registered via callable arrays in include/ws_default_methods.inc.php.
 */
final class PwgPermissions
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly CategoryService $categoryService,
    ) {}

    /**
     * API method
     * Returns permissions
     *
     * @param array{cat_id?: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, ...} $params
     *   all three keys: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent; FORCE_ARRAY always coerces to a list of positive
     *   ints when present.
     * @return PwgError|array{categories: PwgNamedArray}
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        $my_params = array_filter(
            ['cat_id', 'group_id', 'user_id'],
            static fn (string $key): bool => array_key_exists($key, $params)
        );
        if (count($my_params) > 1) {
            return new PwgError(WsError::INVALID_PARAM, 'Too many parameters, provide cat_id OR user_id OR group_id');
        }

        $cat_ids_filter = array_values($params['cat_id'] ?? []);

        $perms = [];

        // direct users
        foreach ($this->permissionService->getDirectUserAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row['cat_id'];
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users'][] = $row['user_id'];
        }

        // indirect users
        foreach ($this->permissionService->getIndirectUserAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row['cat_id'];
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users_indirect'][] = $row['user_id'];
        }

        // groups
        foreach ($this->permissionService->getGroupAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row['cat_id'];
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['groups'][] = $row['group_id'];
        }

        // filter by group and user
        foreach ($perms as $cat_id => &$cat) {
            if (isset($params['group_id'])) {
                if (! isset($cat['groups']) or count(array_intersect($cat['groups'], $params['group_id'])) === 0) {
                    unset($perms[$cat_id]);
                    continue;
                }
            }
            if (isset($params['user_id'])) {
                if (
                    (! isset($cat['users_indirect']) or count(array_intersect($cat['users_indirect'], $params['user_id'])) === 0)
                    and (! isset($cat['users']) or count(array_intersect($cat['users'], $params['user_id'])) === 0)
                ) {
                    unset($perms[$cat_id]);
                    continue;
                }
            }

            $cat['groups'] = isset($cat['groups']) ? array_values(array_unique($cat['groups'])) : [];
            $cat['users'] = isset($cat['users']) ? array_values(array_unique($cat['users'])) : [];
            $cat['users_indirect'] = isset($cat['users_indirect']) ? array_values(array_unique($cat['users_indirect'])) : [];
        }
        unset($cat);

        return [
            'categories' => new PwgNamedArray(
                array_values($perms),
                'category',
                ['id']
            ),
        ];
    }

    /**
     * API method
     * Add permissions
     *
     * @param array{cat_id: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, recursive: bool, pwg_token: string, ...} $params
     *   cat_id: no 'default' key -- mandatory, always present, FORCE_ARRAY
     *   always coerces to a list of positive ints. group_id/user_id:
     *   WsParamFlag::OPTIONAL with no 'default' key -- may be entirely absent,
     *   same FORCE_ARRAY coercion when present. recursive: non-null bool
     *   default, WsParamType::BOOL -- always present. pwg_token: no 'default'
     *   key -- mandatory, always present.
     * @return mixed PwgError, or the result of the pwg.permissions.getList invocation
     *
     * P26/SEC-40: previously passed `recursive` to
     * PermissionService::addPermissionOnCategory() by mutating
     * `$_POST['apply_on_sub']` (that method used to read it as ambient
     * request state) -- addPermissionOnCategory() now takes `$applyOnSub`
     * as a real parameter, so this WS method (which has no `$_POST` of its
     * own) passes `recursive` straight through instead.
     */
    public function add(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (isset($params['group_id']) && $params['group_id'] !== []) {
            $cat_ids = $this->categoryService->getUppercatIds($params['cat_id']);
            if ($params['recursive']) {
                $cat_ids = array_merge($cat_ids, $this->categoryService->getSubcatIds($params['cat_id']));
            }

            $private_cats = $this->permissionService->getPrivateCategoryIdsAmong(array_values($cat_ids));

            $inserts = [];
            foreach ($private_cats as $cat_id) {
                foreach ($params['group_id'] as $group_id) {
                    $inserts[] = [
                        'group_id' => $group_id,
                        'cat_id' => $cat_id,
                    ];
                }
            }

            $this->categoryService->massInsertGroupAccess($inserts, ignore: true);
        }

        if (isset($params['user_id']) && $params['user_id'] !== []) {
            $this->permissionService
                ->addPermissionOnCategory($params['cat_id'], $params['user_id'], $params['recursive']);
        }

        return $service->invoke('pwg.permissions.getList', [
            'cat_id' => $params['cat_id'],
        ]);
    }

    /**
     * API method
     * Removes permissions
     *
     * @param array{cat_id: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, pwg_token: string, ...} $params
     *   cat_id/pwg_token: no 'default' key -- mandatory, always present,
     *   FORCE_ARRAY always coerces cat_id to a list of positive ints.
     *   group_id/user_id: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent, same FORCE_ARRAY coercion when present.
     * @return mixed PwgError, or the result of the pwg.permissions.getList invocation
     */
    public function remove(array $params, PwgServer &$service): mixed
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $cat_ids = $this->categoryService->getSubcatIds($params['cat_id']);

        if (isset($params['group_id']) && $params['group_id'] !== []) {
            $this->categoryService->denyGroupAccess($params['group_id'], $cat_ids);
        }

        if (isset($params['user_id']) && $params['user_id'] !== []) {
            $this->categoryService->denyUserAccess($params['user_id'], $cat_ids);
        }

        return $service->invoke('pwg.permissions.getList', [
            'cat_id' => $params['cat_id'],
        ]);
    }
}
