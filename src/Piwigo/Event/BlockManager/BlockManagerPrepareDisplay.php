<?php

declare(strict_types=1);

namespace Piwigo\Event\BlockManager;

/**
 * Typed event for legacy `blockmanager_prepare_display` (notify).
 *
 * Dispatched from: src/Piwigo/Menu/BlockManager.php (BlockManager::prepareDisplay)
 */
final readonly class BlockManagerPrepareDisplay
{
    public function __construct(
        public object $value,
    ) {
    }
}
