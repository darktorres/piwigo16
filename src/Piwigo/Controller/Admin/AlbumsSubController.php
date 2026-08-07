<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\AlbumsPageRenderer;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/albums.php (page slug "albums") -- a flat page (no tab
 * dispatch), so this sub-controller is a pure delegate to
 * Piwigo\Admin\AlbumsPageRenderer. The page's own auto-order write logic
 * calls Piwigo\Admin\Category\CategoryAdminService::getCategoriesRefDate().
 */
final class AlbumsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryService $categoryService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new AlbumsPageRenderer()
            ->render($this->lang, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->categoryAdminService, $this->categoryService, $this->htmlRenderer, $this->inputValidator);
    }
}
