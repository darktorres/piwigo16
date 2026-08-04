<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\UrlServiceInterface;
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
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Activity\ActivityService $activityService,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Image\ImageService $imageService,
        private readonly \Piwigo\Category\CategoryService $categoryService,
        private readonly \Piwigo\Group\GroupService $groupService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserActivityPageRenderer()
            ->render($this->accessControl, $this->urlService, $this->coreTabs, $this->currentTemplate, $this->activityService, $this->userService, $this->imageService, $this->categoryService, $this->groupService, $this->htmlRenderer);
    }
}
