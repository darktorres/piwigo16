<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AlbumsPageRenderer;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/albums.php (page slug "albums") -- a flat page (no tab
 * dispatch), so this sub-controller is a pure delegate to
 * Piwigo\Admin\AlbumsPageRenderer (P23 batch 6f). The page's own
 * auto-order write logic already calls
 * Piwigo\Admin\Category\CategoryAdminService::getCategoriesRefDate()
 * (an earlier batch's dedup of a real byte-for-byte duplicate that used to
 * also live in admin/cat_list.php).
 */
final class AlbumsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly \Piwigo\Category\CategoryService $categoryService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new AlbumsPageRenderer()
            ->render(\Piwigo\Core\Lang::current(), $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->currentUser, $this->currentTemplate, $this->categoryAdminService, $this->categoryService, $this->htmlRenderer);
    }
}
