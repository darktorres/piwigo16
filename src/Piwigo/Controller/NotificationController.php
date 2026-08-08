<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\NotificationPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocBeginNotification;
use Piwigo\Event\Location\LocEndNotification;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces notification.php -- mints a new per-user feed subscription and
 * shows its URL. `find_available_feed_id()` is a private method here
 * rather than a free function: only this controller calls it, and a bare
 * global function risks colliding with another same-named global function
 * declared elsewhere.
 */
final class NotificationController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly FilterState $filterState,
        private readonly SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $this->eventDispatcher->dispatchNotify(new LocBeginNotification());

        $feedRepo = EntityManagerFactory::build(DbConnection::build())->getRepository(FeedEntity::class);
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

        $title = $this->lang->t('Notification');
        $this->pageState->setBodyId('theNotificationPage');
        $this->pageState->setMetaRobots([
            'noindex' => 1,
            'nofollow' => 1,
        ]);

        $template->set_filenames([
            'notification' => 'notification.tpl',
        ]);

        $template->assignContext(new NotificationPageContext(
            feedUrl: $feed_url,
            feedImageOnlyUrl: $feed_image_only_url,
        ));

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theNotificationPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatchNotify(new LocEndNotification());
        $this->htmlService
            ->flushPageMessages();
        $template->parse('notification', false);
        $body = PageTail::renderToString();

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
