<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Override;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.extensions.ignoreUpdate` -- webmaster-only. Ignores an extension if it needs update.
 */
final readonly class IgnoreUpdateHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private WsCsrfGuard $wsCsrfGuard,
        private ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|true
    {
        // No define('IN_ADMIN', true) or include_once
        // admin/include/functions.php here: IN_ADMIN has no reader left in
        // this request's lifecycle (see PluginsPerformActionHandler's own
        // comment).

        if (! $this->accessControl->isWebmaster()) {
            return new WsErrorResponse(401, 'Access denied');
        }

        $input = IgnoreUpdateParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $updateChecker = $this->extensionUpdateChecker;
        $type = $input->type !== null ? ExtensionType::fromPluralWsParam($input->type) : null;

        // Reset ignored extension
        if ($input->reset) {
            if ($type instanceof ExtensionType) {
                $updateChecker->resetIgnoredForType($type);
            } else {
                $updateChecker->resetAllIgnored();
            }

            $this->extensionUpdateCachePool->deleteItem('extensions_need_update');
            return true;
        }

        if (in_array($input->id, [null, ''], true) or ! $type instanceof ExtensionType) {
            return new WsErrorResponse(403, 'Invalid parameters');
        }

        // Add extension to ignore list -- ignoreUpdate() is itself an
        // idempotent upsert (see ExtensionIgnoredUpdateRepository::ignore()),
        // so no existence check is needed before calling it here, unlike
        // the former blob's own manual in_array() guard.
        $updateChecker->ignoreUpdate($type, $input->id);

        $this->extensionUpdateCachePool->deleteItem('extensions_need_update');
        return true;
    }
}
