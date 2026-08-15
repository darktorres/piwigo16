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
use Piwigo\Core\Lang;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.users.api_key.edit` -- edits an api key for the current user.
 */
final readonly class EditApiKeyHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
        private CurrentLogger $currentLogger,
        private Lang $lang,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        $input = EditApiKeyParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken, message: $this->lang->t('Invalid security token'));
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->pkid)) {
            return new WsErrorResponse(403, $this->lang->t('Invalid pkid format'));
        }

        // realEscapeString() dropped: ApiKeyRepository::updateName()
        // parameterizes apikey_name instead of interpolating it, same
        // "dead pre-escaping" rationale as createApiKey() above.
        $key_name = $input->keyName;
        $user_id = $this->currentUser->get()
            ->id->value;
        $edited_key = $this->apiKeyService->edit($user_id, $input->pkid, $key_name);

        if ($edited_key !== true) {
            return new WsErrorResponse(403, $edited_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=edit][pkid=' . $input->pkid . '][new_name=' . $key_name . ']');

        return $this->lang->t('API Key has been successfully edited.');
    }
}
