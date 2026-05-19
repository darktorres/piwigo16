<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.users.add` — register a new user. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private UserService $userService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (strlen(str_replace(' ', '', is_string($params['username']) ? $params['username'] : '')) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        if (Config::doublePasswordTypeInAdmin() && $params['password'] !== $params['password_confirm']) {
            return new PwgError(WsError::InvalidParam->value, Lang::t('The passwords do not match'));
        }
        if ($params['auto_password']) {
            $params['password'] = StringUtil::generateKey(random_int(15, 20));
        }
        $errors      = [];
        $passwordRaw = $params['password'] ?? null;
        $userId      = $this->userService->registerUser(is_string($params['username']) ? $params['username'] : '', is_string($passwordRaw) ? $passwordRaw : '', is_string($params['email']) ? $params['email'] : null, false, $errors, false);
        if ($userId === false || $userId === 0) {
            return new PwgError(WsError::InvalidParam->value, $errors[0]);
        }
        return $server->invoke('pwg.users.getList', ['user_id' => $userId]);
    }
}
