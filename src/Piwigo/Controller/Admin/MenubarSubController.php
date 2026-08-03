<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\MenubarPageRenderer;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/menubar.php (page slug "menubar") -- a flat page, pure
 * delegate. Its write path already goes through
 * Piwigo\Config\ConfigRepository::upsert() (P24 Part B; previously a
 * dedicated Piwigo\Menu\MenubarLayoutRepository::saveLayout(), built P20
 * and deleted once ConfigEntry existed); nothing left to extract.
 */
final class MenubarSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new MenubarPageRenderer()
            ->render($this->urlService, $this->coreTabs);
    }
}
