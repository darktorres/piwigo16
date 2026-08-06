<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-9:
 * replaces the deleted `EventDispatcher::get()` transitional shim for test
 * call sites -- reproduces its exact behavior (the real container-shared
 * instance once Kernel has booted, a MEMOIZED, not fresh-per-call,
 * instance otherwise). EventDispatcher accumulates handler registrations
 * across many separate addEventHandler() calls, then dispatches to them
 * from other call sites entirely -- a fresh instance per call would
 * silently lose every handler registered since the last call.
 */
final class EventDispatcherTestFactory
{
    private static ?EventDispatcher $fallback = null;

    public static function get(): EventDispatcher
    {
        if (Kernel::isBooted()) {
            $dispatcher = Kernel::container()->get(EventDispatcher::class);
            if (! $dispatcher instanceof EventDispatcher) {
                throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
            }

            return $dispatcher;
        }

        return self::$fallback ??= new EventDispatcher();
    }
}
