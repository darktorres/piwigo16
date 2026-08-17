<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\Projection\ApiKeySummary;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/session/api-keys` -- `pwg.users.api_key.get`'s real
 * replacement. Requires a signed-in (non-guest) session established via
 * `identification.php` -- `ApiKeyService::connectedWithPwgUi()`'s own
 * check, same as its WS predecessor: you can manage your own api keys
 * only from a real login session, not from an api-key-authenticated
 * request.
 */
final readonly class ApiKeyListController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->accessControl->isAGuest() || ! $this->apiKeyService->connectedWithPwgUi()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $userId = $this->currentUser->get()
            ->id->value;
        $apiKeys = $this->apiKeyService->get($userId);

        $result = $apiKeys === false ? [] : array_map(
            static fn (ApiKeySummary $key): array => [
                'authKey' => $key->authKey,
                'apikeySecret' => $key->apikeySecret,
                'apikeyName' => $key->apikeyName,
                'createdOn' => $key->createdOn,
                'duration' => $key->duration,
                'expiredOn' => $key->expiredOn,
                'revokedOn' => $key->revokedOn,
                'lastUsedOn' => $key->lastUsedOn,
                'isExpired' => $key->isExpired,
            ],
            $apiKeys
        );

        return ResponseFactory::json([
            'apiKeys' => $result,
        ]);
    }
}
