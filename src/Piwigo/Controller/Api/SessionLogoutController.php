<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/session` -- `pwg.session.logout`'s real replacement.
 */
final readonly class SessionLogoutController implements ControllerInterface
{
    public function __construct(
        private ApiKeyRequestFlag $apiKeyRequestFlag,
        private AuthService $authService,
        private AccessControl $accessControl,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return ResponseFactory::problem('Unauthorized', 401, 'Cannot use this method with an api key.');
        }

        if (! $this->accessControl->isAGuest()) {
            $this->authService->logoutUser();
        }

        return ResponseFactory::noContent();
    }
}
