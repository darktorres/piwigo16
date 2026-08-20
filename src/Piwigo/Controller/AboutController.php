<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Event\AboutPageRendering;
use Piwigo\Controller\Projection\AboutView;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the about page: no POST handling, no redirects.
 *
 * Nothing in this render chain echoes -- PageHeaderRenderer::prepareContext()/
 * MenubarRenderer::render() only ever call $template->assignContext()
 * internally; $this->renderer->render($aboutView) renders the whole page
 * (header + about content + tail) in one shot, since about.latte declares
 * {layout 'layout.latte'} (P41, docs/PLAN.md), and
 * $template->finalizeHtml() runs the combined-CSS/JS/JSON-island
 * substitution pass over the result.
 *
 * accessControl->checkStatus() throws ResponseReadyException on failure
 * (via HtmlService::accessDenied()/RedirectService::redirectHttp()/
 * redirectHtml()) rather than terminating via exit().
 */
final readonly class AboutController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private LayoutState $layoutState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
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
        $this->layoutState->setBodyId('theAboutPage');

        $this->eventDispatcher->dispatch(new AboutPageRendering());

        $about_message_raw = $this->lang->load('about.html', '', [
            'return' => true,
        ]);

        $user_theme = $this->currentUser->get()
            ->theme->value;

        $theme_about = $this->lang->load('about.html', $this->currentConfig->themesPath . $user_theme . '/', [
            'return' => true,
        ]);

        $aboutView = new AboutView(
            aboutMessage: is_string($about_message_raw) ? $about_message_raw : '',
            themeAbout: is_string($theme_about) ? $theme_about : null,
        );

        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'], true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager, $this->renderer);
        }

        new PageHeaderRenderer()
            ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig);
        $this->htmlService
            ->flushPageMessages();

        PageTail::prepareContext();

        $html = $this->renderer->render($aboutView);
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }
}
