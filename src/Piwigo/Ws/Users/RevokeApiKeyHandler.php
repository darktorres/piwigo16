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
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.api_key.revoke` -- revokes an api key for the current user.
 */
final readonly class RevokeApiKeyHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
        private CurrentLogger $currentLogger,
        private Lang $lang,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|string
    {
        $logger = $this->currentLogger->get();

        if ($this->accessControl->isAGuest() or ! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        $input = RevokeApiKeyParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken, message: $this->lang->t('Invalid security token'));
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (! (bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $input->pkid)) {
            return new WsErrorResponse(403, $this->lang->t('Invalid pkid format'));
        }

        $user_id = $this->currentUser->get()
            ->id->value;

        $revoked_key = $this->apiKeyService->revoke($user_id, $input->pkid);

        if ($revoked_key !== true) {
            return new WsErrorResponse(403, $revoked_key);
        }

        $logger->info('[api_key][user_id=' . $user_id . '][action=revoke][pkid=' . $input->pkid . ']');

        return $this->lang->t('API Key has been successfully revoked.');
    }
}
