<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Csrf\CsrfService;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.users.setInfo` — admin-side user updates. */
final readonly class SetInfoHandler implements WsAction
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
            $input = SetInfoParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $updatedUsers = $this->userService->checkAndSaveUserInfos($input->payload);
        if (isset($updatedUsers['error'])) {
            $err = is_array($updatedUsers['error']) ? $updatedUsers['error'] : [];
            return new PwgError(is_int($err['code'] ?? null) ? $err['code'] : null, is_string($err['message'] ?? null) ? $err['message'] : '');
        }
        $infosVal = is_array($updatedUsers['infos'] ?? null) ? $updatedUsers['infos'] : [];
        return $server->invoke('pwg.users.getList', ['user_id' => $updatedUsers['user_id'], 'display' => 'basics,' . implode(',', array_keys($infosVal))]);
    }
}
