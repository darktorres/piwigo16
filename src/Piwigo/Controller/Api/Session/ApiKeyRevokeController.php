<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/session/api-keys/{pkid}` --
 * `pwg.users.api_key.revoke`'s real replacement. `{pkid}` is
 * route-constrained to the exact `pkid-YYYYMMDD-{20 alnum}` shape, so a
 * malformed id 404s at the routing layer before this controller ever
 * runs.
 */
final readonly class ApiKeyRevokeController implements ControllerInterface
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

        $routeArgs = $request->getAttribute('route_args');
        $pkid = is_array($routeArgs) && is_string($routeArgs['pkid'] ?? null) ? $routeArgs['pkid'] : '';

        $userId = $this->currentUser->get()
            ->id->value;

        $revoked = $this->apiKeyService->revoke($userId, $pkid);
        if ($revoked !== true) {
            return ResponseFactory::problem('Not Found', 404, $revoked);
        }

        $this->currentLogger->get()
            ->info('[api_key][user_id=' . $userId . '][action=revoke][pkid=' . $pkid . ']');

        return ResponseFactory::noContent();
    }
}
