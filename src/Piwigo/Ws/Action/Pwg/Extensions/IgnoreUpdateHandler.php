<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Extensions\IgnoredUpdatesRepository;
use Piwigo\Csrf\CsrfService;
use Piwigo\Session\Session;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.extensions.ignoreUpdate` — mute an extension's pending-update marker. */
final readonly class IgnoreUpdateHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private IgnoredUpdatesRepository $ignoredUpdates,
        private PermissionService $permissionService,
        private Session $session,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }
        try {
            $input = IgnoreUpdateParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->reset) {
            if ($input->type !== null) {
                $this->ignoredUpdates->clearType($input->type);
            } else {
                $this->ignoredUpdates->clearAll();
            }
            $this->session->extensionsNeedUpdate = null;
            return true;
        }
        if ($input->id === '' || $input->type === null) {
            return new PwgError(403, 'Invalid parameters');
        }
        $this->ignoredUpdates->ignore($input->type, $input->id);
        $this->session->extensionsNeedUpdate = null;
        return true;
    }
}
