<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserListPageRenderer;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_list.php (page slug "user_list") -- a flat page,
 * pure delegate. Confirmed via direct read: no write logic of its own
 * (user create/delete/status-change go through `/api/v1/users`, not this
 * page); only defines one page-local helper, now
 * UserListPageRenderer::webmasterIdIsLocal().
 */
final readonly class UserListSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private UserService $userService,
        private PreferencesService $preferencesService,
        private GroupService $groupService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Paths $paths,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return new UserListPageRenderer()
            ->render($this->lang, $this->urlService, $this->coreTabs, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentTemplate, $this->userService, $this->preferencesService, $this->groupService, $this->htmlRenderer, $this->currentConfig, $this->csrfService, $this->inputValidator, $this->paths, $this->entityManager, $this->renderer);
    }
}
