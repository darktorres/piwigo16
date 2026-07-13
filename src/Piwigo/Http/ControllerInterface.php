<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A frontend controller resolved by config/routes.php's `_controller`
 * default and invoked by Piwigo\Http\Middleware\ControllerInvokerMiddleware.
 * `__invoke()`, not PSR-15's `handle()` (Psr\Http\Server\
 * RequestHandlerInterface) -- deliberately distinct from a generic
 * middleware-chain handler, since a frontend controller is always the
 * pipeline's terminal step, never itself passed a next handler to
 * delegate to. Same interim-contract precedent as P21's
 * AdminSubControllerInterface::handle(), but returning a real
 * ResponseInterface instead of mutating $template/$page -- P22 controllers
 * capture their legacy render body's output (still Smarty, still echoing)
 * via an output-buffer bridge (Piwigo\Controller\LegacyRenderCapture) and
 * wrap it in a Response, rather than leaving rendering as a side effect
 * for something else to emit.
 *
 * Lives in Piwigo\Http (L3Presentation), not Piwigo\Controller
 * (L4Integration) despite the name -- deptrac.yaml only allows
 * L3Presentation (Http\Middleware\ControllerInvokerMiddleware, which must
 * type-check against this contract) to depend on layers at or below
 * itself, never upward into L4Integration. Real controller
 * implementations (Piwigo\Controller\AboutController etc.) stay in
 * L4Integration and depend downward on this interface instead, the same
 * direction every other cross-layer contract in this codebase already
 * uses (confirmed via a real DependsOnDisallowedLayer violation caught by
 * `deptrac analyse`, not assumed).
 */
interface ControllerInterface
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
