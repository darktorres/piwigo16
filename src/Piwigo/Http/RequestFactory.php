<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a PSR-7 ServerRequest from PHP superglobals. No `_route_path`
 * request attribute yet — that's RoutingMiddleware's concern (P9).
 */
final class RequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return new ServerRequestCreator($factory, $factory, $factory, $factory)
            ->fromGlobals();
    }
}
