<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Override;
use Piwigo\Core\AppInfo;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.getVersion` -- returns the Piwigo version.
 */
final readonly class GetVersionHandler implements WsAction
{
    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     */
    #[Override]
    public function __invoke(array $params, Server $server): string
    {
        return AppInfo::VERSION;
    }
}
