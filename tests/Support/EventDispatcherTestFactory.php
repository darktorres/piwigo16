<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * Returns the container-shared instance once Kernel has booted. Before
 * boot, returns a memoized fallback instance: EventDispatcher accumulates
 * handler registrations across separate addTypedHandler() calls and
 * dispatches to them from other call sites, so a fresh instance per call
 * would silently lose every handler registered since the last call.
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
