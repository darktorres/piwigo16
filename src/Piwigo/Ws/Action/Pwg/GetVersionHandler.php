<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Core\AppInfo;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.getVersion` — returns the running Piwigo version string.
 *
 * First WS endpoint migrated to the F5-h per-endpoint action-class
 * pattern. The historical `GeneralEndpoints::getVersion()` callback
 * also still exists for plugin compatibility; PwgServer dispatches
 * here through the `handlerClass` route registered in
 * `WsMethodRegistrar`.
 */
final readonly class GetVersionHandler implements WsAction
{
    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): string
    {
        return AppInfo::VERSION;
    }
}
