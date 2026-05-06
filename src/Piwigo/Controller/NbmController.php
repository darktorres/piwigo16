<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\PageState;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class NbmController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_FREE);

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_notification_by_mail.inc.php';

        load_language('admin.lang');
        trigger_notify('loading_lang');
        load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);

        $subscribe   = is_string($_GET['subscribe'] ?? null) ? $_GET['subscribe'] : null;
        $unsubscribe = is_string($_GET['unsubscribe'] ?? null) ? $_GET['unsubscribe'] : null;

        if ($subscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $subscribe)) {
            subscribe_notification_by_mail(false, [$subscribe]);
        } elseif ($unsubscribe !== null && preg_match('/^[A-Za-z0-9]{16}$/', $unsubscribe)) {
            unsubscribe_notification_by_mail(false, [$unsubscribe]);
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
            require PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        flush_page_messages();
        $tpl->parse('nbm');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
