<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/session/api-keys` -- `pwg.users.api_key.create`'s real
 * replacement. Requires a signed-in (non-guest) session established via
 * `identification.php`, same as `Ws\Users\CreateApiKeyHandler`'s own
 * `ApiKeyService::connectedWithPwgUi()` check.
 */
final readonly class ApiKeyCreateController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private ApiKeyService $apiKeyService,
        private CurrentUser $currentUser,
        private CurrentLogger $currentLogger,
        private CsrfGuard $csrfGuard,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->accessControl->isAGuest() || ! $this->apiKeyService->connectedWithPwgUi()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Access denied.');
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = ApiKeyCreateInput::fromArray(JsonBody::decode($request));

        if ($input->duration < 1 || $input->duration > 999999) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid duration, max days is 999999.');
        }

        if (strlen($input->keyName) > 100) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Key name is too long.');
        }

        $userId = $this->currentUser->get()
            ->id->value;
        $created = $this->apiKeyService->create($userId, $input->duration, $input->keyName);

        $this->currentLogger->get()
            ->info('[api_key][user_id=' . $userId . '][action=create][key_name=' . $input->keyName . ']');

        return ResponseFactory::json([
            'authKey' => $created->authKey,
            'apikeySecret' => $created->apikeySecret,
            'apikeyName' => $created->apikeyName,
            'createdOn' => $created->createdOn,
            'duration' => $created->duration,
            'expiredOn' => $created->expiredOn,
        ], 201);
    }
}
