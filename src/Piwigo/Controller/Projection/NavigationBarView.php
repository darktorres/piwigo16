<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\Projection\Navbar;

/**
 * `{templateType}` target shared by both theme variants of
 * `navigation_bar.latte` (`themes/default/template/`,
 * `themes/admin/default/template/`) -- identical real body, one
 * property. Never rendered via `Renderer::render()` -- reached only
 * through Latte's own native `{include 'navigation_bar.latte', navbar:
 * $x}` (isolated scope, at 10 of its 11 real call sites) or, at
 * `comments.latte`'s own one remaining bare
 * `{include 'navigation_bar.latte'}`, full parent-scope inheritance
 * from `CommentsView::$navbar` (same shape). Contract-only conversion,
 * same shape as {@see \Piwigo\Menu\Projection\MenubarBlockView} --
 * deliberately not implementing `Piwigo\Core\View`, carrying no
 * `#[Template]` attribute (there is no single file it corresponds to).
 */
final readonly class NavigationBarView
{
    public function __construct(
        public Navbar $navbar,
    ) {}
}
