<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\MenubarPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/menubar.php (page slug "menubar") -- a flat page, pure
 * delegate. Its write path already goes through
 * Piwigo\Menu\MenubarLayoutRepository::saveLayout() (built P20); nothing
 * left to extract.
 */
final class MenubarSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new MenubarPageRenderer()
            ->render();
    }
}
