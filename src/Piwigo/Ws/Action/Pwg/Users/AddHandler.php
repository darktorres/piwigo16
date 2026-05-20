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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = AddParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if (strlen(str_replace(' ', '', $input->username)) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'Name field must not be empty');
        }
        if (Config::doublePasswordTypeInAdmin() && $input->password !== ($input->passwordConfirm ?? '')) {
            return new PwgError(WsError::InvalidParam->value, Lang::t('The passwords do not match'));
        }
        $password = $input->autoPassword ? StringUtil::generateKey(random_int(15, 20)) : $input->password;
        $errors   = [];
        $userId   = $this->userService->registerUser($input->username, $password, $input->email, false, $errors, false);
        if ($userId === false || $userId === 0) {
            return new PwgError(WsError::InvalidParam->value, $errors[0]);
        }
        return $server->invoke('pwg.users.getList', ['user_id' => $userId]);
    }
}
