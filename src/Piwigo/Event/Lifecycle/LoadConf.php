<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `load_conf` (notify).
 *
 * New in 2.6. Warning: you can't trigger the first call done in common.inc.php. Use init instead.
 *
 * Dispatched from: src/Piwigo/Config/ConfigService.php
 */
final readonly class LoadConf
{
    public function __construct(
        public string $condition,
    ) {
    }
}
