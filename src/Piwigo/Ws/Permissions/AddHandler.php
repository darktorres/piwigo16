<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Permissions;

use LogicException;
use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Permission\PermissionService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.permissions.add` -- grant per-album access to users/groups.
 *
 * `recursive` is passed directly as the `$applyOnSub` argument to
 * `PermissionService::addPermissionOnCategory()` -- this WS method has no
 * `$_POST` state of its own.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<array-key, mixed> WsErrorResponse, or the result of the
     *   pwg.permissions.getList invocation (really always
     *   array{categories: NamedArray} at runtime, but narrowGetListResult()
     *   can't prove the sealed shape from a re-narrowed value, only that
     *   it's a real array)
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = AddParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if ($input->groupIds !== []) {
            $cat_ids = $this->categoryService->getUppercatIds($input->categoryIds);
            if ($input->recursive) {
                $cat_ids = array_merge($cat_ids, $this->categoryService->getSubcatIds($input->categoryIds));
            }

            $private_cats = $this->permissionService->getPrivateCategoryIdsAmong(array_values($cat_ids));

            $inserts = [];
            foreach ($private_cats as $cat_id) {
                foreach ($input->groupIds as $group_id) {
                    $inserts[] = [
                        'group_id' => $group_id,
                        'cat_id' => $cat_id,
                    ];
                }
            }

            $this->categoryService->massInsertGroupAccess($inserts, ignore: true);
        }

        if ($input->userIds !== []) {
            $this->permissionService
                ->addPermissionOnCategory($input->categoryIds, $input->userIds, $input->recursive);
        }

        return $this->narrowGetListResult($server->invoke('pwg.permissions.getList', [
            'cat_id' => $input->categoryIds,
        ]));
    }

    /**
     * $server->invoke() is a genuine string-keyed dynamic dispatcher (see
     * Server's own class docblock) -- its declared return type is
     * `mixed` by design. This narrows it to the real shape this specific
     * sub-invocation (always 'pwg.permissions.getList', which itself
     * really does return WsErrorResponse|array{categories: NamedArray}) is
     * known to return, the same "resolve, narrow, or throw" idiom already
     * used throughout this codebase for other statically-unknowable-but-
     * really-fixed-shape values.
     *
     * @return WsErrorResponse|array<array-key, mixed>
     */
    private function narrowGetListResult(mixed $result): WsErrorResponse|array
    {
        if (! $result instanceof WsErrorResponse && ! is_array($result)) {
            throw new LogicException('pwg.permissions.getList returned an unexpected type');
        }

        return $result;
    }
}
