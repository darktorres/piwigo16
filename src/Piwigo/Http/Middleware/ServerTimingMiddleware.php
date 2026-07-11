<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Core\ServerTiming;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds a Server-Timing header from whatever ServerTiming has recorded.
 * Gated by a raw SERVER_TIMING_ENABLED env var -- the doc's own
 * server_timing_enabled config-key + admin/anonymous role gate needs
 * Config (P13) and CurrentUser (P16), neither of which exist yet.
 */
final class ServerTimingMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $enabled = getenv('SERVER_TIMING_ENABLED');
        if ($enabled === false || $enabled === '' || $enabled === '0') {
            return $response;
        }

        $entries = [];
        foreach (ServerTiming::all() as $name => $durationMs) {
            $entries[] = sprintf('%s;dur=%.2f', $name, $durationMs);
        }

        if ($entries === []) {
            return $response;
        }

        return $response->withHeader('Server-Timing', implode(', ', $entries));
    }
}
