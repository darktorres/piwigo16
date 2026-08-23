<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\PluginConfig;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Throwaway stoppable-event fixture for EventDispatcherTest's dispatch()
 * propagation-stopping tests -- a handler calls stop() (mutating a
 * non-readonly property, same shape TestChangeEvent's own $value uses)
 * to halt further handlers.
 */
final class TestStoppableEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    /**
     * @param list<string> $calls
     */
    public function __construct(
        public array $calls = [],
    ) {}

    public function stop(): void
    {
        $this->stopped = true;
    }

    #[\Override]
    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
