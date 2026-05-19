<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Updates;
use Piwigo\Core\Kernel;
use Piwigo\Session\Session;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.extensions.checkUpdates` — refresh + report piwigo / extension pending-update flags. */
final readonly class CheckUpdatesHandler implements WsAction
{
    public function __construct(
        private Session $session,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        $update  = Kernel::service(Updates::class);
        $result  = [];
        if ($this->session->piwigoNeedsUpdate === null) {
            $update->checkPiwigoUpgrade();
        }
        $result['piwigo_need_update'] = $this->session->piwigoNeedsUpdate;
        $update->checkExtensions();
        $result['ext_need_update'] = $this->session->extensionsNeedUpdate !== null
            ? $this->session->extensionsNeedUpdate !== []
            : null;
        return $result;
    }
}
