<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\ExtendForTemplatesPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\CurrentTemplate;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/extend_for_templates.php (page slug "extend_for_templates")
 * -- a flat page, pure delegate to Piwigo\Admin\ExtendForTemplatesPageRenderer.
 * Its config write goes through ConfigService, not raw SQL.
 */
final readonly class ExtendForTemplatesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private CategoryService $categoryService,
        private CurrentConfig $currentConfig,
        private Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new ExtendForTemplatesPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->categoryService, $this->currentConfig, $this->paths);
    }
}
