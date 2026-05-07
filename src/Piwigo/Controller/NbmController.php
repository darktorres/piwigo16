<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Users\PermissionService;

final class NbmController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(ACCESS_FREE);

        MailNotificationContext::init();
        load_language('admin.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);

        $subscribe   = is_string($_GET['subscribe'] ?? null) ? $_GET['subscribe'] : null;
        $unsubscribe = is_string($_GET['unsubscribe'] ?? null) ? $_GET['unsubscribe'] : null;

        if ($subscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
            ServiceLocator::get(NotificationAdminService::class)->subscribeNotificationByMail(false, [$subscribe]);
        } elseif ($unsubscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
            ServiceLocator::get(NotificationAdminService::class)->unsubscribeNotificationByMail(false, [$unsubscribe]);
        } else {
            PageState::current()->addError(l10n('Unknown identifier'));
        }

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $title           = l10n('Notification');
        $page['body_id'] = 'theNBMPage';

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['nbm' => 'nbm.tpl']);

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theNBMPage', $hideMenuOn, true)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        flush_page_messages();
        $tpl->parse('nbm');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
