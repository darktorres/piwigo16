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
 * -- a flat page, pure delegate. P23 batch fixed a real, verified defect:
 * its single-param config UPDATE spliced the serialized value into SQL with
 * zero escaping (unlike every other raw-SQL config write in this codebase,
 * which all double single quotes first) -- fixed with the same
 * str_replace("\'", "''", ...) escaping admin/configuration.php's own
 * generic config-row loop already used.
 *
 * Phase 5 (Legacy Coupling Retirement): that raw-SQL write is now routed
 * through ConfigService instead, closing the gap for good rather than
 * just tightening the manual escaping.
 */
final class ExtendForTemplatesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CategoryService $categoryService,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new ExtendForTemplatesPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->categoryService, $this->currentConfig, $this->paths);
    }
}
