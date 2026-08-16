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
 *
 * Catches ResponseReadyException at the closest nesting level to wherever
 * it was thrown, converting it into a normal return before any outer
 * middleware ever sees a propagating exception (workstream C3 Phase 0).
 * This matters beyond ControllerInvokerMiddleware's own identical catch
 * (kept, not redundant to remove -- defense-in-depth for a case this class
 * now handles generically): without it, an exception thrown by ANY
 * middleware earlier than ControllerInvokerMiddleware would reach
 * ExceptionHandlerMiddleware instead, which has no ResponseReadyException
 * special case (it would log the deliberate response as a real error,
 * report it to Sentry, and return a generic 500) -- and would skip
 * SecurityHeadersMiddleware/ServerTimingMiddleware entirely, since both
 * only post-process a RETURNED response, never a propagating throw.
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

        try {
            return $this->middleware[0]->process($request, $next);
        } catch (ResponseReadyException $e) {
            return $e->response();
        }
    }
}
