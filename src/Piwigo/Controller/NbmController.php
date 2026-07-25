<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
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
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        // Translations are in the admin file too.
        Lang::load('admin.lang');
        // Need to update a second time.
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loading_lang');
        Lang::load('lang', \Piwigo\Core\CurrentPaths::get()->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();

        $conn = DbConnection::build();
        $nbmSender = new NotificationByMailSender(
            new NotificationByMailService(new NotificationByMailRepository($conn)),
            new NotificationService(
                new NotificationRepository($conn),
                \Piwigo\Bootstrap\CoreDomainAccessor::permissionService(),
                $htmlRenderer,
                $this->urlService
            ),
            new \Piwigo\Db\BatchWriter($conn),
            new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository($conn), new GroupRepository($conn), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)), $htmlRenderer, $conn),
            new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository($conn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)), $htmlRenderer, new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($conn)), new \Piwigo\Auth\CookieService()),
            $this->urlService
        );

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
            \Piwigo\Core\PageState::current()->addError(Lang::t('Unknown identifier'));
        }

        $title = Lang::t('Notification');
        \Piwigo\Core\PageState::current()->setBodyId('theNBMPage');

        $template->set_filenames([
            'nbm' => 'nbm.tpl',
        ]);

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('theNBMPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($urlService);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        $template->parse('nbm');
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
