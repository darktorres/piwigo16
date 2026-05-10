<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AboutController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Guest);

        EventDispatcher::notify('loc_begin_about');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $title           = Lang::t('About Piwigo');
        $page['body_id'] = 'theAboutPage';

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['about' => 'about.latte']);

        $aboutMessage = LangService::get()->loadLanguage('about.html', '', ['return' => true]);
        $tpl->assign('ABOUT_MESSAGE', new Html(is_string($aboutMessage) ? $aboutMessage : ''));

        $theme      = is_string($user['theme'] ?? null) ? $user['theme'] : '_base';
        $themeAbout = LangService::get()->loadLanguage('about.html', Config::themesPath() . $theme . '/', ['return' => true]);
        if ($themeAbout !== false) {
            $tpl->assign('THEME_ABOUT', new Html(is_string($themeAbout) ? $themeAbout : ''));
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theAboutPage', $hideMenuOn, true)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_about');
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        $tpl->pparse('about');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
