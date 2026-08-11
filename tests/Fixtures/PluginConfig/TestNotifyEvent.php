<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\PluginConfig;

/**
 * Throwaway notify-shape fixture for EventDispatcherTest's
 * dispatchNotify()/addTypedHandler() tests -- matches the real target
 * shape (final readonly, no mutable property).
 */
final readonly class TestNotifyEvent
{
    public function __construct(
        public string $value
    ) {}
}
