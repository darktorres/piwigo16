<?php

declare(strict_types=1);

namespace Piwigo\Template\Event;

use Piwigo\Asset\ResolvedAsset;

/**
 * Typed event for the legacy `combined_script` filter. No handler is
 * registered for it anywhere today -- a pure information carrier, not a
 * behavior change. Lives under `Piwigo\Template\Event\`, not
 * `Piwigo\Event\Template\`, since it's dispatched from
 * `Template::makeAssetSrc()` -- both `Piwigo\Template` and
 * `Piwigo\Asset` (where `$asset`'s own type lives) are the same
 * L3Presentation deptrac layer (P41-G, docs/PLAN.md). Mutable on
 * `$src`; `$asset` stays context.
 */
final class CombinedScript
{
    public function __construct(
        public string $src,
        public readonly ResolvedAsset $asset,
    ) {}
}
