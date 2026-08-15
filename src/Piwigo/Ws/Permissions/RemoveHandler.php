<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Permissions;

use Override;
use Piwigo\Category\CategoryService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.permissions.remove` -- revoke per-album access from users/groups.
 */
final readonly class RemoveHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private WsHelper $wsHelper,
        private GetListHandler $getListHandler,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{categories: NamedArray} the result of
     *   GetListHandler::resolve(), called directly (P25 Stage 1's
     *   recursive-dispatch removal)
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = RemoveParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $cat_ids = $this->categoryService->getSubcatIds($input->categoryIds);

        if ($input->groupIds !== []) {
            $this->categoryService->denyGroupAccess($input->groupIds, $cat_ids);
        }

        if ($input->userIds !== []) {
            $this->categoryService->denyUserAccess($input->userIds, $cat_ids);
        }

        return $this->getListHandler->resolve([
            'cat_id' => $input->categoryIds,
        ]);
    }
}
