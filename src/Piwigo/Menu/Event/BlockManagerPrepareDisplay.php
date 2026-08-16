<?php

declare(strict_types=1);

namespace Piwigo\Menu\Event;

/**
 * Typed event for the legacy `blockmanager_prepare_display` filter
 * (notify). No handler is registered for it anywhere today. Typed
 * `object`, not `Piwigo\Menu\BlockManager`, even though its one real
 * dispatch site (`BlockManager::prepareDisplay()`) always passes `$this`
 * -- matches the reference's own deliberate choice, keeping this class
 * free of a same-file circular dependency on `BlockManager` (which
 * constructs this event) even now that both live in the same
 * `L3Presentation` layer. Co-located here from
 * `Piwigo\Event\BlockManager\BlockManagerPrepareDisplay` (P32 Stage A5 --
 * see `docs/events-legacy-map.md`).
 */
final readonly class BlockManagerPrepareDisplay
{
    public function __construct(
        public object $value,
    ) {}
}
