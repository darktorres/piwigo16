<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocBeginNotification;
use Piwigo\Event\Location\LocEndNotification;
use Piwigo\Feed\FeedRepository;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces notification.php -- mints a new per-user feed subscription and
 * shows its URL. The legacy file's own top-level find_available_feed_id()
 * becomes a private method here instead of a free function -- only this
 * controller ever called it, and a bare global function risks the same
 * name-collision class of bug P21's Extensions batch found (two admin
 * pages both declaring `function cmp()`).
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class NotificationController implements ControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Html\HtmlService $htmlService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $this->eventDispatcher->dispatchNotify(new LocBeginNotification());

        $feedRepo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Feed\FeedEntity::class);
        $feedId = $this->findAvailableFeedId($feedRepo);
        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $user_id = $this->currentUser->get()
            ->id->value;

        $feedRepo->insert($feedId, $user_id);

        $feed_url = $urlService->getRootUrl() . 'feed.php';
        if ($this->accessControl->isAGuest()) {
            $feed_image_only_url = $feed_url;
            $feed_url .= '?feed=' . $feedId;
        } else {
            $feed_url .= '?feed=' . $feedId;
            $feed_image_only_url = $feed_url . '&amp;image_only';
        }

        $title = Lang::t('Notification');
        $this->pageState->setBodyId('theNotificationPage');
        $this->pageState->setMetaRobots([
            'noindex' => 1,
            'nofollow' => 1,
        ]);

        $template->set_filenames([
            'notification' => 'notification.tpl',
        ]);

        $template->assign(
            [
                'U_FEED' => $feed_url,
                'U_FEED_IMAGE_ONLY' => $feed_image_only_url,
            ]
        );

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theNotificationPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate);
        $this->eventDispatcher->dispatchNotify(new LocEndNotification());
        $this->htmlService
            ->flushPageMessages();
        $template->parse('notification', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }

    private function findAvailableFeedId(FeedRepository $feedRepo): string
    {
        while (true) {
            $key = $this->sessionService->generateKey(50);
            if (! $feedRepo->existsById($key)) {
                return $key;
            }
        }
    }
}
