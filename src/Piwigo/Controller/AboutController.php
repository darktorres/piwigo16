<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginAbout;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
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
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Html\HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $title = $this->lang->t('About Piwigo');
        $this->pageState->setBodyId('theAboutPage');

        $this->eventDispatcher->dispatchNotify(new LocBeginAbout());

        $template->set_filename('about', 'about.tpl');

        $template->assign('ABOUT_MESSAGE', $this->lang->load('about.html', '', [
            'return' => true,
        ]));

        $user_theme = $this->currentUser->get()
            ->theme;

        $theme_about = $this->lang->load('about.html', $this->currentConfig->themesPath() . $user_theme . '/', [
            'return' => true,
        ]);
        if ($theme_about !== false) {
            $template->assign('THEME_ABOUT', $theme_about);
        }

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, $this->accessControl, $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig);
        }

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->htmlService
            ->flushPageMessages();
        $template->parse('about', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
