<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Feed\FeedRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the notification/RSS subscription page (/notification).
 * Corresponds to the former notification.php entry-point.
 */
final readonly class NotificationController implements ControllerInterface
{
    public function __construct(
        private FeedRepository $feedRepository,
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private UrlGenerator $urlGenerator,
        private PermissionService $permissionService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);

        EventDispatcher::notify('loc_begin_notification');

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $feedId = $this->findAvailableFeedId();

        $this->feedRepository
            ->insert($feedId, is_numeric($user['id']) ? (int) $user['id'] : 0);

        $feed_url = $this->urlGenerator->feed();
        $sep      = str_contains($feed_url, '?') ? '&' : '?';
        if ($this->permissionService->isAGuest()) {
            $feed_image_only_url = $feed_url;
            $feed_url .= $sep . 'feed=' . $feedId;
        } else {
            $feed_url .= $sep . 'feed=' . $feedId;
            $feed_image_only_url = $feed_url . '&image_only';
        }

        $title = Lang::t('Notification');
        $ps = PageState::current();
        $ps->bodyId     = 'theNotificationPage';
        $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];

        $tpl = TemplateRegistry::current();
        $tpl->assign(['U_FEED' => $feed_url, 'U_FEED_IMAGE_ONLY' => $feed_image_only_url]);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theNotificationPage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_notification');
        $this->htmlService->flushPageMessages();
        $tpl->pparse('notification.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }

    private function findAvailableFeedId(): string
    {
        $feedRepo = $this->feedRepository;
        while (true) {
            $key = StringUtil::generateKey(50);
            if (!$feedRepo->existsById($key)) {
                return $key;
            }
        }
    }
}
