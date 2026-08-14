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
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.permissions.remove` -- revoke per-album access from users/groups.
 */
final readonly class RemoveHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<array-key, mixed> WsErrorResponse, or the result of the
     *   pwg.permissions.getList invocation (really always
     *   array{categories: NamedArray} at runtime, but narrowGetListResult()
     *   can't prove the sealed shape from a re-narrowed value, only that
     *   it's a real array)
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = RemoveParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $cat_ids = $this->categoryService->getSubcatIds($input->categoryIds);

        if ($input->groupIds !== []) {
            $this->categoryService->denyGroupAccess($input->groupIds, $cat_ids);
        }

        if ($input->userIds !== []) {
            $this->categoryService->denyUserAccess($input->userIds, $cat_ids);
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
