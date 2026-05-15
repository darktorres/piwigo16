<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Event\Location\LocBeginAbout;
use Piwigo\Event\Location\LocEndAbout;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AboutController implements ControllerInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private LangService $langService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);

        $this->dispatcher->dispatch(new LocBeginAbout());

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $title = Lang::t('About Piwigo');
        PageState::current()->bodyId = 'theAboutPage';

        $tpl = TemplateRegistry::current();

        $aboutMessage = $this->langService->loadLanguage('about.html', '', ['return' => true]);
        $tpl->assign('ABOUT_MESSAGE', new Html(is_string($aboutMessage) ? $aboutMessage : ''));

        $theme      = is_string($user['theme'] ?? null) ? $user['theme'] : '_base';
        $themeAbout = $this->langService->loadLanguage('about.html', Config::themesPath() . $theme . '/', ['return' => true]);
        if ($themeAbout !== false) {
            $tpl->assign('THEME_ABOUT', new Html(is_string($themeAbout) ? $themeAbout : ''));
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theAboutPage', $hideMenuOn, true)) {
            $this->menubarRenderer->render();
        }

        PageHeaderRenderer::render($title);
        $this->dispatcher->dispatch(new LocEndAbout());
        $this->htmlService->flushPageMessages();
        $tpl->pparse('about.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
