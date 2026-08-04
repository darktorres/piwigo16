<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\MenubarPageRenderer;
use Piwigo\Auth\AccessControl;
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
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new MenubarPageRenderer()
            ->render($this->accessControl, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentTemplate);
    }
}
