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
        if ($updatedUsers->isError) {
            return new PwgError($updatedUsers->errorCode, $updatedUsers->errorMessage ?? '');
        }
        return $server->invoke('pwg.users.getList', ['user_id' => $updatedUsers->userId, 'display' => 'basics,' . implode(',', array_keys($updatedUsers->infos ?? []))]);
    }
}
