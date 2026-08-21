<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A navigational link a plugin contributes to the menubar's own "Menu"
 * block (`mbMenu`/`menubar_menu.latte`) -- the typed replacement for a
 * hand-written `set_prefilter('menubar', ...)` patch, appended to that
 * block's own existing `Tags`/`Search`/`Comments`/`About`/`Notification`
 * row list by `Menu\MenubarRenderer::render()` (no template change
 * needed -- `menubar_menu.latte` already iterates that list generically).
 *
 * `$counter` renders as the same `(n)` suffix core's own `Tags`/
 * `Comments` rows already show; `$title` is the row's tooltip, matching
 * every real core row's own optional `TITLE`.
 */
final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $url,
        public ?string $title = null,
        public ?int $counter = null,
        public int $order = 50,
    ) {}
}
