<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a PSR-7 ServerRequest from PHP superglobals.
 *
 * Attaches the server-agnostic route path as the '_route_path' request attribute
 * so the RoutingMiddleware does not need to re-derive it.
 */
final class RequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)
            ->fromGlobals();

        return $request->withAttribute('_route_path', PathExtractor::fromServer($_SERVER));
    }
}
