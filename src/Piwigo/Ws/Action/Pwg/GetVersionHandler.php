<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Core\AppInfo;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.getVersion` — returns the running Piwigo version string. */
final readonly class GetVersionHandler implements WsAction
{
    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): string
    {
        return AppInfo::VERSION;
    }
}
