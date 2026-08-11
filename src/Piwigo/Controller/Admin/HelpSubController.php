<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\HelpPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/help.php (page slug "help") -- pure page/template glue, no
 * data access (loads a static help/*.html language file via the existing
 * load_language() bridge). admin/popuphelp.php is a distinct, standalone
 * root-style entry point (self-bootstraps PHPWG_ROOT_PATH/common.inc.php,
 * never dispatched through admin.php) -- ported separately in this same
 * sub-batch to Piwigo\Controller\Admin\AdminPopuphelpController, its own
 * config/routes.php entry rather than a page slug here.
 */
final readonly class HelpSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new HelpPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate);
    }
}
