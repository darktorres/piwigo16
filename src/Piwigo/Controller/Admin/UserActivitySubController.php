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
final class UserActivitySubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly ImageService $imageService,
        private readonly CategoryService $categoryService,
        private readonly GroupService $groupService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserActivityPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate, $this->currentConfig, $this->activityService, $this->userService, $this->imageService, $this->categoryService, $this->groupService, $this->htmlRenderer, $this->inputValidator, $this->eventDispatcher);
    }
}
