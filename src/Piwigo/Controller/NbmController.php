<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\Translator;
use Piwigo\Mail\NotificationByMailSender;
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
 * The public "notification by mail" subscribe/unsubscribe confirmation
 * link target (distinct from admin/notification_by_mail.php, the admin
 * sender page).
 */
final class NbmController implements ControllerInterface
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
        private readonly NotificationByMailSender $notificationByMailSender,
        private readonly CurrentConfig $currentConfig,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Free);

        // Translations are in the admin file too.
        $this->lang->load('admin.lang');
        // Need to update a second time.
        $this->eventDispatcher->dispatchNotify(new LoadingLang());
        $this->lang->load('lang', $this->paths->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        $htmlRenderer = $this->htmlService;

        $conn = DbConnection::build();
        $nbmSender = $this->notificationByMailSender;

        $queryParams = $request->getQueryParams();
        $subscribe = $queryParams['subscribe'] ?? null;
        $unsubscribe = $queryParams['unsubscribe'] ?? null;
        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        if (is_string($subscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
            $nbmSender->subscribeNotificationByMail(false, [$subscribe]);
        } elseif (is_string($unsubscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
            $nbmSender->unsubscribeNotificationByMail(false, [$unsubscribe]);
        } else {
            $this->pageState->addError($this->lang->t('Unknown identifier'));
        }

        $title = $this->lang->t('Notification');
        $this->pageState->setBodyId('theNBMPage');

        $template->setFilenames([
            'nbm' => 'nbm.tpl',
        ]);

        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('theNBMPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->htmlService
            ->flushPageMessages();
        $template->parse('nbm');
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
