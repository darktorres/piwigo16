<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `init` (notify).
 *
 * fired just after common initialization; $conf, $user and $page (partial) are available
 *
 * Dispatched from: src/Piwigo/Bootstrap/CommonBootstrap.php
 */
final readonly class Init
{
}
