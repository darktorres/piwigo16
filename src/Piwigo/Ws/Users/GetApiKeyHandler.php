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
use Piwigo\Auth\Projection\ApiKeySummary;
use Piwigo\Core\Lang;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.api_key.get` -- gets all api keys for the current user.
 */
final readonly class GetApiKeyHandler implements WsAction
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
        private Lang $lang,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|string|list<array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}>
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array|string
    {
        if ($this->accessControl->isAGuest()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        if (! $this->apiKeyService->connectedWithPwgUi()) {
            return new WsErrorResponse(401, 'Acces Denied');
        }

        $input = GetApiKeyParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        // ApiKeyService::get() takes a native int $userId, same as
        // create()/revoke()/edit() above.
        $user_id = $this->currentUser->get()
            ->id->value;
        $api_keys = $this->apiKeyService->get($user_id);

        return ((bool) $api_keys) ? array_map(static fn (ApiKeySummary $key): array => $key->toArray(), $api_keys) : $this->lang->t('No API key found');
    }
}
