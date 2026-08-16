<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Override;
use Piwigo\Core\ServerTiming;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Calls the still-unconverted, Template-dependent remainder of
 * `RequestBootstrap::finalize()` (theme resolution, `Template`
 * construction, `NoPhotoYetRenderer`, the gallery-locked 503 check,
 * default event-handler registrations, final `CurrentUser`/`PageState`/
 * `Lang` syncs) as a real middleware, last in workstream C3 Phase 1's own
 * bootstrap-phase chain, right before routing.
 *
 * A genuine bridge, not a permanent home: this remainder needs Plan 2's
 * P38 (`Template` split into `Renderer`/`ThemeChain`/typed view objects)
 * and P39 (shell-last rendering) to land before it can get the same real
 * decomposition the rest of `connect()`/`finalize()` just did (workstream
 * C3 Phase 2) -- `NoPhotoYetRenderer`'s own short-circuit is still a raw
 * `$template->pparse(...); exit();`, not yet even a `ResponseReadyException`,
 * and can't become one without capturing `pparse()`'s echoed output, which
 * is exactly what P39 deletes the mechanism for doing honestly.
 *
 * Lives in `Piwigo\Bootstrap\` (L4Integration): `finalize()`'s remaining
 * body registers `Listener\UploadFormatListener`, explicitly carved out
 * of the general `Piwigo\Listener\*` L3Presentation collector into
 * L4Integration in `deptrac.yaml` -- same reasoning as `UserResolution
 * Middleware`'s own placement, a real L3-incompatible dependency, not
 * just convenience.
 *
 * Owns the tail end of the `'boot'` `ServerTiming` bracket --
 * `configure()` still calls `start('boot', ...)` as its own first
 * statement (`Http\Middleware\ExceptionHandlerMiddleware`/`Sentry
 * Middleware`/`ServerTimingMiddleware` all wrap this entire bootstrap-phase
 * chain, so they still see the same combined timing they did when
 * `configure()`+`connect()`+`finalize()` ran as one synchronous call
 * inside `bootEntryPoint()`), and this middleware -- the last step of that
 * same chain -- calls `stop('boot')` once `finalize()` returns, matching
 * the original method's own bracket exactly, just moved from
 * `bootEntryPoint()`'s own trailing statement to here.
 */
final readonly class FinalizeBridgeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ServerTiming $serverTiming,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        RequestBootstrap::finalize();
        $this->serverTiming->stop('boot');

        return $handler->handle($request);
    }
}
