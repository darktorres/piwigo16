<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserListPageRenderer;
use Piwigo\Core\UrlServiceInterface;
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
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Users\PreferencesService $preferencesService,
        private readonly \Piwigo\Group\GroupService $groupService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserListPageRenderer()
            ->render($this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->userService, $this->preferencesService, $this->groupService);
    }
}
