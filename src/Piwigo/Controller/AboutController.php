<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginAbout;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces about.php -- P22's own proof-of-concept controller (smallest,
 * lowest-risk real page: no POST handling, no redirects).
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture. Nothing in
 * this chain echoes anymore -- PageHeaderRenderer/MenubarRenderer only
 * ever called $template->assign()/parse($handle, true) internally
 * (confirmed by reading both, not assumed), $template->parse('about', false)
 * accumulates into Template's own $output buffer instead of the former
 * pparse()'s early flush+echo, and PageTail::renderToString() drains that
 * whole buffer (header + about content + tail) as one string instead of
 * echoing it. See Template::fetchOutput()'s own docblock for the
 * accumulator mechanics this relies on.
 *
 * check_status() stays outside this method's own render logic on
 * purpose: on failure it throws ResponseReadyException (via
 * HtmlService::accessDenied()/RedirectService::redirectHttp()/
 * redirectHtml()), same as every other controller (Workstream C3) --
 * not an exit()-based termination.
 */
final class AboutController implements ControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = \Piwigo\Template\CurrentTemplate::get();

        $title = Lang::t('About Piwigo');
        \Piwigo\Core\PageState::current()->setBodyId('theAboutPage');

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocBeginAbout());

        $template->set_filename('about', 'about.tpl');

        $template->assign('ABOUT_MESSAGE', Lang::load('about.html', '', [
            'return' => true,
        ]));

        $user_theme = \Piwigo\Users\CurrentUser::get()->theme;

        $theme_about = Lang::load('about.html', CurrentConfig::themesPath() . $user_theme . '/', [
            'return' => true,
        ]);
        if ($theme_about !== false) {
            $template->assign('THEME_ABOUT', $theme_about);
        }

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($urlService);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        $template->parse('about', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
