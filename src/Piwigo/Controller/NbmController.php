<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Notification\MailNotificationContext;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class NbmController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Free);

        MailNotificationContext::init();
        LangService::get()->loadLanguage('admin.lang');
        EventDispatcher::notify('loading_lang');
        LangService::get()->loadLanguage('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);

        $rawSubscribe   = $_GET['subscribe']   ?? null;
        $rawUnsubscribe = $_GET['unsubscribe'] ?? null;
        $subscribe   = is_string($rawSubscribe) ? $rawSubscribe : null;
        $unsubscribe = is_string($rawUnsubscribe) ? $rawUnsubscribe : null;

        if ($subscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
            ServiceLocator::get(NotificationAdminService::class)->subscribeNotificationByMail(false, [$subscribe]);
        } elseif ($unsubscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
            ServiceLocator::get(NotificationAdminService::class)->unsubscribeNotificationByMail(false, [$unsubscribe]);
        } else {
            PageState::current()->addError(Lang::t('Unknown identifier'));
        }

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $title           = Lang::t('Notification');
        $page['body_id'] = 'theNBMPage';

        $tpl = TemplateRegistry::current();

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theNBMPage', $hideMenuOn, true)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        PageHeaderRenderer::render($title);
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        $tpl->parse('nbm.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
