<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\PluginConfig;

/**
 * Throwaway fire-and-forget-shape fixture for EventDispatcherTest's
 * dispatch()/addTypedHandler() tests -- matches the real target shape
 * (final readonly, no mutable property; a handler only cares about its
 * own side effect, never reads a value back off this event).
 */
final readonly class TestNotifyEvent
{
    public function __construct(
        public string $value
    ) {}
}
