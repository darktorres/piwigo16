<?php

declare(strict_types=1);

namespace Piwigo\Menu\Event;

/**
 * Typed `dispatchChange()` event replacing the old `eval_visible`
 * mechanism (SEC-49): `Menu\MenubarRenderer::render()` dispatches one of
 * these per configured `mb_links` entry that declares a
 * `Config\MenuLink::$visibilityLinkId`, instead of `eval()`ing arbitrary
 * PHP source stored in config. `$linkId` is the admin-configured
 * identifier for that link (opaque to core -- a plugin's own
 * `subscribedEvents()` handler compares it against whatever value it
 * expects); `$visible` starts `true` (a link with no subscriber stays
 * visible) and a handler sets it `false` to hide the link.
 *
 * Not `readonly` as a whole class -- `$visible` is deliberately mutable
 * so a handler can flip it and return the same `$event` instance,
 * matching `EventDispatcher::dispatchChange()`'s own documented "handler
 * mutates a non-readonly property and returns $event" shape.
 */
final class CheckMenuLinkVisibility
{
    public function __construct(
        public readonly string $linkId,
        public bool $visible = true,
    ) {}
}
