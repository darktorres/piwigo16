<?php

declare(strict_types=1);

namespace Piwigo\Template\Event;

use Piwigo\Template\Combinable;

/**
 * Typed event for the legacy `combined_script` filter. No handler is
 * registered for it anywhere today -- a pure information carrier, not a
 * behavior change. Lives under `Piwigo\Template\Event\`, not
 * `Piwigo\Event\Template\`, since it carries a real `Piwigo\Template\
 * Combinable` instance -- deptrac's L0Data layer may depend on nothing.
 * Mutable on `$src`; `$combinable` stays context.
 */
final class CombinedScript
{
    public function __construct(
        public string $src,
        public readonly Combinable $combinable,
    ) {}
}
