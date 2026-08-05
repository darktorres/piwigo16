<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 compliant. Immutable recursive peel: the first middleware calls
 * $handler->handle() on a new pipeline wrapping the remaining middleware.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $fallback,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middleware === []) {
            return $this->fallback->handle($request);
        }

        $next = new self(array_slice($this->middleware, 1), $this->fallback);

        return $this->middleware[0]->process($request, $next);
    }
}
