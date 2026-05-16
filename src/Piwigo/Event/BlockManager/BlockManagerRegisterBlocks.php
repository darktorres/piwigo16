<?php

declare(strict_types=1);

namespace Piwigo\Event\BlockManager;

/**
 * Typed event for legacy `blockmanager_register_blocks` (notify).
 *
 * use this trigger to add menu block
 *
 * Dispatched from: src/Piwigo/Menu/BlockManager.php (BlockManager::loadRegisteredBlocks)
 */
final readonly class BlockManagerRegisterBlocks
{
    public function __construct(
        public \Piwigo\Menu\BlockManager $menu,
    ) {
    }
}
