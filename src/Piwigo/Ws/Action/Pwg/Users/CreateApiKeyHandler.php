<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.users.createApiKey` — mint an API key for the session user. */
final readonly class CreateApiKeyHandler implements WsAction
{
    public function __construct(
        private AuthService $authService,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private UserService $userService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $logger = LoggerRegistry::current();
        $userId = CurrentUser::get()->id;
        if ($this->permissionService->isAGuest() || !$this->authService->connectedWithPwgUi()) {
            return new PwgError(401, 'Acces Denied');
        }
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($params['duration'] < 1 || $params['duration'] > 999999) {
            return new PwgError(400, 'Invalid duration max days is 999999');
        }
        $apiKeyNameRaw = is_string($params['key_name'] ?? null) ? $params['key_name'] : '';
        if (strlen($apiKeyNameRaw) > 100) {
            return new PwgError(400, 'Key name is too long');
        }
        $duration = is_numeric($params['duration']) ? (0 == (int) $params['duration'] ? 1 : (int) $params['duration']) : 1;
        $secret   = $this->userService->createApiKey($userId, $duration, $apiKeyNameRaw);
        $logger->info('[api_key][user_id=' . $userId . '][action=create][key_name=' . $apiKeyNameRaw . ']');
        return $secret;
    }
}
