<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces nbm.php -- the public "notification by mail" subscribe/
 * unsubscribe confirmation link target (distinct from admin/
 * notification_by_mail.php, P21's admin sender page).
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class NbmController implements ControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
        private readonly \Piwigo\Core\PageState $pageState,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        // Translations are in the admin file too.
        Lang::load('admin.lang');
        // Need to update a second time.
        $this->eventDispatcher->dispatchNotify(new LoadingLang());
        Lang::load('lang', \Piwigo\Core\CurrentPaths::get()->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();

        $conn = DbConnection::build();
        $nbmSender = \Piwigo\Bootstrap\PresentationAccessor::notificationByMailSender();

        $queryParams = $request->getQueryParams();
        $subscribe = $queryParams['subscribe'] ?? null;
        $unsubscribe = $queryParams['unsubscribe'] ?? null;
        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (is_string($subscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
            $nbmSender->subscribeNotificationByMail(false, [$subscribe]);
        } elseif (is_string($unsubscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
            $nbmSender->unsubscribeNotificationByMail(false, [$unsubscribe]);
        } else {
            $this->pageState->addError(Lang::t('Unknown identifier'));
        }

        $title = Lang::t('Notification');
        $this->pageState->setBodyId('theNBMPage');

        $template->set_filenames([
            'nbm' => 'nbm.tpl',
        ]);

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('theNBMPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState);
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        $template->parse('nbm');
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
