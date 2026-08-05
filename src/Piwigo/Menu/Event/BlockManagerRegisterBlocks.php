<?php

declare(strict_types=1);

namespace Piwigo\Menu\Event;

use Piwigo\Menu\BlockManager;

/**
 * Typed event for the legacy `blockmanager_register_blocks` filter
 * (notify). Carries a real `Piwigo\Menu\BlockManager` instance -- lives
 * under `Piwigo\Menu\Event\`, not `Piwigo\Event\BlockManager\`, since
 * deptrac's L0Data layer (`Piwigo\Event\*`) may depend on nothing.
 */
final readonly class BlockManagerRegisterBlocks
{
    public function __construct(
        public BlockManager $menu,
    ) {}
}
