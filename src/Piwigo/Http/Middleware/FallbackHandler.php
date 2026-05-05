<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Terminal handler for requests that no controller claims.
 *
 * Returns a bare 404 during the migration transition.  Once all Wave-A and
 * Wave-B controllers are in place this handler should never be reached; it
 * can be removed then.
 */
final class FallbackHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ResponseFactory::html('<h1>404 Not Found</h1>', 404);
    }
}
