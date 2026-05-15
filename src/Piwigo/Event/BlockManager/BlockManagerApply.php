<?php

declare(strict_types=1);

namespace Piwigo\Event\BlockManager;

/**
 * Typed event for legacy `blockmanager_apply` (notify).
 *
 * use this trigger to modify existing menu blocks
 *
 * Dispatched from: src/Piwigo/Menu/BlockManager.php
 */
final readonly class BlockManagerApply
{
    public function __construct(
        public object $menublock,
    ) {
    }
}
