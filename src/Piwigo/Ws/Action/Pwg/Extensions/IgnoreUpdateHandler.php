<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\IgnoredUpdatesRepository;
use Piwigo\Csrf\CsrfService;
use Piwigo\Session\Session;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

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
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $typeRaw = $params['type'] ?? null;
        $type    = is_string($typeRaw) ? ExtensionType::tryFrom($typeRaw) : null;
        if ($params['reset']) {
            if ($type !== null) {
                $this->ignoredUpdates->clearType($type);
            } else {
                $this->ignoredUpdates->clearAll();
            }
            $this->session->extensionsNeedUpdate = null;
            return true;
        }
        $idRaw    = $params['id'] ?? null;
        $ignoreId = is_string($idRaw) ? $idRaw : '';
        if ($ignoreId === '' || $type === null) {
            return new PwgError(403, 'Invalid parameters');
        }
        $this->ignoredUpdates->ignore($type, $ignoreId);
        $this->session->extensionsNeedUpdate = null;
        return true;
    }
}
