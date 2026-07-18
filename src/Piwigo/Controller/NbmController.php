<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Cache\PersistentCache;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\NotificationByMailSender;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\NotificationByMailService;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces nbm.php -- the public "notification by mail" subscribe/
 * unsubscribe confirmation link target (distinct from admin/
 * notification_by_mail.php, P21's admin sender page). check_status() stays
 * outside the captured closure, same exit()-based-termination limitation
 * as every other controller this phase.
 */
final class NbmController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        // Translations are in the admin file too.
        Lang::load('admin.lang');
        // Need to update a second time.
        trigger_notify('loading_lang');
        Lang::load('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
            'no_fallback' => true,
            'local' => true,
        ]);

        /** @var mixed $persistent_cache */
        global $persistent_cache;

        $htmlRenderer = new HtmlService();

        if (! $persistent_cache instanceof PersistentCache) {
            $htmlRenderer->fatalError('persistent cache not initialized');
        }

        $conn = DbConnection::build();
        $nbmSender = new NotificationByMailSender(
            new NotificationByMailService(new NotificationByMailRepository($conn)),
            new NotificationService(
                new NotificationRepository($conn),
                new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)),
                $persistent_cache,
                $htmlRenderer
            )
        );

        $queryParams = $request->getQueryParams();
        $subscribe = $queryParams['subscribe'] ?? null;
        $unsubscribe = $queryParams['unsubscribe'] ?? null;

        $body = LegacyRenderCapture::capture(static function () use ($subscribe, $unsubscribe, $nbmSender): void {
            /** @var array<string, mixed> $page */
            global $page;
            global $title;
            $template = \Piwigo\Template\CurrentTemplate::get();

            if (is_string($subscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
                $nbmSender->subscribeNotificationByMail(false, [$subscribe]);
            } elseif (is_string($unsubscribe) && (bool) preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
                $nbmSender->unsubscribeNotificationByMail(false, [$unsubscribe]);
            } else {
                \Piwigo\Core\PageState::current()->addError(l10n('Unknown identifier'));
            }

            $title = l10n('Notification');
            $page['body_id'] = 'theNBMPage';

            $template->set_filenames([
                'nbm' => 'nbm.tpl',
            ]);

            $themeconf = $template->get_template_vars('themeconf');
            $themeconf = is_array($themeconf) ? $themeconf : [];
            $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
            if (! is_array($hide_menu_on) or ! in_array('theNBMPage', $hide_menu_on, true)) {
                new MenubarRenderer()
                    ->render();
            }

            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            new HtmlService()
                ->flushPageMessages();
            $template->parse('nbm');
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}
