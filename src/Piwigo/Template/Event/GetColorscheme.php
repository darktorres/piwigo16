<?php

declare(strict_types=1);

namespace Piwigo\Template\Event;

use Piwigo\Common\ValueObject\ThemeId;

/**
 * Dispatched from `Template::setTheme()`, right after `ThemeChain::
 * resolve()` and before the resolved `themeconf` is assigned to the page
 * context -- lets a theme override its own effective `colorscheme` at
 * request time (P29.6, the `modus` theme's 18 runtime-selectable skins,
 * 10 light/8 dark, vs. `theme.json`'s single static declared value).
 * `Get*`-prefixed, matching this codebase's established filter-event
 * convention (mutable field, dispatch returns the event, caller reads the
 * mutated field -- e.g. `Asset\Event\GetPageAssets`, `Image\Event\
 * GetHighUrl`).
 *
 * Zero-listener dispatch is a true no-op (confirmed against
 * `EventDispatcher`'s own no-listener path): `$colorscheme` stays exactly
 * what `ThemeChain::resolve()` already computed, so every theme that
 * doesn't register a handler (`default`, `standard_pages`, every admin
 * skin) sees byte-identical behavior to before this event existed.
 *
 * `$theme` is the originally-requested theme id (not `ThemeChain::
 * walk()`'s own internal `standard_pages` substitution), matching the id
 * `ThemeRegistry::bootCurrent()` registers a theme's `subscribedEvents()`
 * against.
 */
final class GetColorscheme
{
    public function __construct(
        public readonly ThemeId $theme,
        public string $colorscheme,
    ) {}
}
