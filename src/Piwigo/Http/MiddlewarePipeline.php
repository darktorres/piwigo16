<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 compliant middleware pipeline.
 *
 * Each middleware in the list is called in order.  When the list is exhausted
 * the fallback handler is invoked.  This class is immutable — process() creates
 * a new instance for each layer, so the original pipeline can be reused.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly array                  $middleware,
        private readonly RequestHandlerInterface $fallback,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middleware === []) {
            return $this->fallback->handle($request);
        }

        $middleware   = $this->middleware[0];
        $remaining    = array_slice($this->middleware, 1);
        $nextHandler  = new self($remaining, $this->fallback);

        return $middleware->process($request, $nextHandler);
    }
}
