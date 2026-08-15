<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.users.api_key.create` -- creates a new api key for the current user.
 */
final readonly class CreateApiKeyHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
        private CurrentLogger $currentLogger,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: int, key_type: string, expired_on: string}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest() or ! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        $input = CreateApiKeyParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if ($input->duration < 1 or $input->duration > 999999) {
            return new WsErrorResponse(400, 'Invalid duration max days is 999999');
        }

        if (strlen($input->keyName) > 100) {
            return new WsErrorResponse(400, 'Key name is too long');
        }

        // realEscapeString() dropped: ApiKeyRepository::insert() parameterizes
        // apikey_name instead of interpolating it, same "dead pre-escaping"
        // rationale as Ws\Tags\RenameHandler.
        $key_name = $input->keyName;
        // the guard above already rejects any duration outside [1, 999999], so
        // it can never be 0 here.
        $duration = $input->duration;

        $user_id = $this->currentUser->get()
            ->id->value;

        $secret = $this->apiKeyService->create($user_id, $duration, $key_name);

        $logger->info('[api_key][user_id=' . $user_id . '][action=create][key_name=' . $input->keyName . ']');

        return $secret->toArray();
    }
}
