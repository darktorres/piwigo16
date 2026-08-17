<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Core\AppInfo;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/version` -- P27's replacement for the WS-only
 * `pwg.getVersion`. Unauthenticated (same as its WS predecessor's
 * `requiresAuth: false`), no DB, the thin slice this whole surface's
 * routing/response conventions were first proven against.
 */
final readonly class VersionController implements ControllerInterface
{
    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return ResponseFactory::json([
            'version' => AppInfo::VERSION,
        ]);
    }
}
