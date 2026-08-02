<?php

declare(strict_types=1);

namespace Piwigo\Event\BlockManager;

/**
 * Typed event for the legacy `blockmanager_prepare_display` filter
 * (notify). No handler is registered for it anywhere today. Typed
 * `object`, not `Piwigo\Menu\BlockManager`, even though its one real
 * dispatch site (`BlockManager::prepare_display()`) always passes `$this`
 * -- matches the reference's own deliberate choice, keeping this class
 * free of a first-party dependency (deptrac's L0Data layer may depend on
 * nothing).
 */
final readonly class BlockManagerPrepareDisplay
{
    public function __construct(
        public object $value,
    ) {}
}
