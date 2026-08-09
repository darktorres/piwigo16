<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\AboutPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginAbout;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the about page: no POST handling, no redirects.
 *
 * Nothing in this render chain echoes -- PageHeaderRenderer/MenubarRenderer
 * only ever call $template->assignContext()/parse($handle, true) internally,
 * $template->parse('about', false) accumulates into Template's own
 * $output buffer, and PageTail::renderToString() drains that whole buffer
 * (header + about content + tail) as one string. See
 * Template::fetchOutput()'s own docblock for the accumulator mechanics
 * this relies on.
 *
 * accessControl->checkStatus() throws ResponseReadyException on failure
 * (via HtmlService::accessDenied()/RedirectService::redirectHttp()/
 * redirectHtml()) rather than terminating via exit().
 */
final class AboutController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly FilterState $filterState,
        private readonly SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
    ) {}

    #[Override]
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

        $about_message_raw = $this->lang->load('about.html', '', [
            'return' => true,
        ]);

        $user_theme = $this->currentUser->get()
            ->theme;

        $theme_about = $this->lang->load('about.html', $this->currentConfig->themesPath() . $user_theme . '/', [
            'return' => true,
        ]);

        $template->assignContext(new AboutPageContext(
            aboutMessage: is_string($about_message_raw) ? $about_message_raw : '',
            themeAbout: is_string($theme_about) ? $theme_about : null,
        ));

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->htmlService
            ->flushPageMessages();
        $template->parse('about', false);
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
