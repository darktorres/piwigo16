<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserListPageRenderer;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Group\GroupService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_list.php (page slug "user_list") -- a flat page,
 * pure delegate. Confirmed via direct read: no write logic of its own
 * (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, now
 * UserListPageRenderer::webmasterIdIsLocal().
 */
final class UserListSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly UserService $userService,
        private readonly PreferencesService $preferencesService,
        private readonly GroupService $groupService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly \Piwigo\Core\Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserListPageRenderer()
            ->render($this->lang, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->userService, $this->preferencesService, $this->groupService, $this->htmlRenderer, $this->currentConfig, $this->inputValidator, $this->paths);
    }
}
