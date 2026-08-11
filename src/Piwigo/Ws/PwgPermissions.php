<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use LogicException;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionService;

/**
 * `pwg.permissions.*` WS methods (3 registrations, all admin_only) --
 * registered via callable arrays in WsDefaultMethods.
 */
final class PwgPermissions
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly CategoryService $categoryService,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * API method
     * Returns permissions
     *
     * @param array{cat_id?: array<int, int>, group_id?: array<int, int>, user_id?: array<int, int>, ...} $params
     *   all three keys: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent; FORCE_ARRAY always coerces to a list of positive
     *   ints when present.
     * @return PwgError|array{categories: NamedArray}
     */
    public function getList(array $params, Server &$service): PwgError|array
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
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users'][] = $row->userId;
        }

        // indirect users
        foreach ($this->permissionService->getIndirectUserAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['users_indirect'][] = $row->userId;
        }

        // groups
        foreach ($this->permissionService->getGroupAccessRows($cat_ids_filter) as $row) {
            $cat_id = $row->catId;
            if (! isset($perms[$cat_id])) {
                $perms[$cat_id]['id'] = $cat_id;
            }
            $perms[$cat_id]['groups'][] = $row->groupId;
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
            'categories' => new NamedArray(
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
     * @return PwgError|array<array-key, mixed> PwgError, or the result of the
     *   pwg.permissions.getList invocation (really always
     *   array{categories: NamedArray} at runtime, but narrowGetListResult()
     *   can't prove the sealed shape from a re-narrowed value, only that
     *   it's a real array)
     *
     * `recursive` is passed directly as the `$applyOnSub` argument to
     * PermissionService::addPermissionOnCategory() -- this WS method has no
     * `$_POST` state of its own.
     */
    public function add(array $params, Server &$service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
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

        return $this->narrowGetListResult($service->invoke('pwg.permissions.getList', [
            'cat_id' => $params['cat_id'],
        ]));
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
     * @return PwgError|array<array-key, mixed> PwgError, or the result of the
     *   pwg.permissions.getList invocation (really always
     *   array{categories: NamedArray} at runtime, but narrowGetListResult()
     *   can't prove the sealed shape from a re-narrowed value, only that
     *   it's a real array)
     */
    public function remove(array $params, Server &$service): PwgError|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $cat_ids = $this->categoryService->getSubcatIds($params['cat_id']);

        if (isset($params['group_id']) && $params['group_id'] !== []) {
            $this->categoryService->denyGroupAccess($params['group_id'], $cat_ids);
        }

        if (isset($params['user_id']) && $params['user_id'] !== []) {
            $this->categoryService->denyUserAccess($params['user_id'], $cat_ids);
        }

        return $this->narrowGetListResult($service->invoke('pwg.permissions.getList', [
            'cat_id' => $params['cat_id'],
        ]));
    }

    /**
     * $service->invoke() is a genuine string-keyed dynamic dispatcher (see
     * Server's own class docblock) -- its declared return type is
     * `mixed` by design. This narrows it to the real shape this specific
     * sub-invocation (always 'pwg.permissions.getList', which itself
     * really does return PwgError|array{categories: NamedArray}) is
     * known to return, the same "resolve, narrow, or throw" idiom already
     * used throughout this codebase for other statically-unknowable-but-
     * really-fixed-shape values (e.g. PwgImage::currentConfig()'s
     * container resolve). Kept as a plain (non-sealed) `array` here --
     * unlike getList() itself, this method never literally constructs the
     * array, only narrows an already-built one, so PHPStan can't verify
     * the sealed `array{categories: ...}` shape from this call site alone.
     *
     * @return PwgError|array<array-key, mixed>
     */
    private function narrowGetListResult(mixed $result): PwgError|array
    {
        if (! $result instanceof PwgError && ! is_array($result)) {
            throw new LogicException('pwg.permissions.getList returned an unexpected type');
        }

        return $result;
    }
}
