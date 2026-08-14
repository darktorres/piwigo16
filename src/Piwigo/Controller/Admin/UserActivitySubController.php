<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_activity.php (page slug "user_activity") -- a flat,
 * read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all (aside from the ?type=download_logs CSV-export branch,
 * which streams directly and exits, never touching persistent state).
 */
final readonly class UserActivitySubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private CurrentTemplate $currentTemplate,
        private ActivityService $activityService,
        private UserService $userService,
        private ImageService $imageService,
        private CategoryService $categoryService,
        private GroupService $groupService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserActivityPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate, $this->currentConfig, $this->csrfService, $this->activityService, $this->userService, $this->imageService, $this->categoryService, $this->groupService, $this->htmlRenderer, $this->inputValidator, $this->eventDispatcher);
    }
}
