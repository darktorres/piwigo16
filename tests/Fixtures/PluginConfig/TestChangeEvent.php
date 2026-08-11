<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\PluginConfig;

/**
 * Throwaway change-shape fixture for EventDispatcherTest's
 * dispatchChange()/addTypedHandler() tests -- matches the real target
 * shape (final, exactly one mutable property, readonly context).
 */
final class TestChangeEvent
{
    public function __construct(
        public string $value,
        public readonly string $context = ''
    ) {}
}
