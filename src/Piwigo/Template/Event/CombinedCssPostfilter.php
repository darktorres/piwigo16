<?php

declare(strict_types=1);

namespace Piwigo\Template\Event;

/**
 * Typed event for the legacy `combined_css_postfilter` filter. No handler
 * is registered for it anywhere today -- a pure information carrier, not
 * a behavior change. No context -- every real call site passes only the
 * css string. Co-located here from `Piwigo\Event\Template\CombinedCssPostfilter` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class CombinedCssPostfilter
{
    public function __construct(
        public string $css,
    ) {}
}
