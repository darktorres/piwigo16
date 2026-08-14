<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\MenubarPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page slug "menubar" -- a flat page, pure delegate. Its write path goes
 * through Piwigo\Config\ConfigRepository::upsert().
 */
final readonly class MenubarSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new MenubarPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig, $this->entityManager);
    }
}
