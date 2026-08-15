<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Core\Env;
use Tracy\Debugger;

/**
 * Initializes Tracy's debug bar -- dev-only, gated behind
 * `Env::isTracyEnabled()` (`PIWIGO_TRACY_ENABLED`, same "no-op unless
 * explicitly opted in" shape as `SentryBootstrap`/`SENTRY_DSN`). Forces
 * `Debugger::Development` mode explicitly rather than Tracy's own
 * IP-based auto-detection heuristic (`Debugger::enable(null)`) -- the env
 * var itself is already the authoritative "yes, this is dev" signal, so
 * letting Tracy independently guess on top of that would only add a second,
 * less predictable way for it to end up disabled (or, worse, a
 * misconfigured deployment guessing "development" in production).
 *
 * `Piwigo\Template\LatteEngine` reads `Env::isTracyEnabled()` too, to
 * decide whether to register `Latte\Bridges\Tracy\TracyExtension` -- that
 * extension's own constructor calls `Tracy\Debugger::getBar()->
 * addPanel(...)` unconditionally, which lazily self-initializes a `Bar`
 * that's never actually rendered unless `Debugger::enable()` also ran, so
 * registering it without this being enabled would just be dead weight on
 * every request.
 */
final class TracyBootstrap
{
    public static function init(): void
    {
        if (! Env::isTracyEnabled()) {
            return;
        }

        Debugger::enable(Debugger::Development);
    }
}
