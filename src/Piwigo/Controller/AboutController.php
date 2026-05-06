<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AboutController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_GUEST);

        trigger_notify('loc_begin_about');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $title           = l10n('About Piwigo');
        $page['body_id'] = 'theAboutPage';

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['about' => 'about.tpl']);

        $tpl->assign('ABOUT_MESSAGE', load_language('about.html', '', ['return' => true]));

        $theme      = is_string($user['theme'] ?? null) ? $user['theme'] : 'default';
        $themeAbout = load_language('about.html', PHPWG_THEMES_PATH . $theme . '/', ['return' => true]);
        if ($themeAbout !== false) {
            $tpl->assign('THEME_ABOUT', $themeAbout);
        }

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theAboutPage', $hideMenuOn, true)) {
            require PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        trigger_notify('loc_end_about');
        flush_page_messages();
        $tpl->pparse('about');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
