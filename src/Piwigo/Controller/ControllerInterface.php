<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for all page and API controllers.
 *
 * Controllers are resolved from the DI container and called by
 * ControllerInvokerMiddleware with the matched route args.
 */
interface ControllerInterface
{
    /**
     * @param array<string, string> $args  Route parameters extracted from the URL.
     */
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface;
}
