<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A frontend controller resolved by RouteDefinitions's `_controller`
 * default and invoked by Piwigo\Http\Middleware\ControllerInvokerMiddleware.
 * `__invoke()`, not PSR-15's `handle()` (Psr\Http\Server\
 * RequestHandlerInterface) -- deliberately distinct from a generic
 * middleware-chain handler, since a frontend controller is always the
 * pipeline's terminal step, never itself passed a next handler to
 * delegate to, returning a real ResponseInterface instead of mutating
 * $template/$page. Controllers whose render body is still Smarty call
 * Template::parse($handle, false) (accumulates into Template's own
 * internal buffer instead of echoing) and drain it via
 * Piwigo\Bootstrap\PageTail::renderToString() into a real Response body,
 * rather than leaving rendering as a side effect for something else to
 * emit.
 *
 * Lives in Piwigo\Http (L3Presentation), not Piwigo\Controller
 * (L4Integration) despite the name -- deptrac.yaml only allows
 * L3Presentation (Http\Middleware\ControllerInvokerMiddleware, which must
 * type-check against this contract) to depend on layers at or below
 * itself, never upward into L4Integration. Real controller
 * implementations (Piwigo\Controller\AboutController etc.) stay in
 * L4Integration and depend downward on this interface instead, the same
 * direction every other cross-layer contract in this codebase already
 * uses.
 */
interface ControllerInterface
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
