<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Event\IdentificationPageRendered;
use Piwigo\Controller\Event\IdentificationPageRendering;
use Piwigo\Controller\Projection\IdentificationView;
use Piwigo\Controller\Request\IdentificationSubmitRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
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
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs identification.php -- the login form + POST handler. Reads
 * $_GET/$_POST/$_SESSION/$_COOKIE directly rather than through $request:
 * superglobals are already reachable from any scope with no `global`
 * declaration needed, so routing them through $request here would only
 * desync two copies of the same request data for no real benefit.
 *
 * Every redirect() in this file (both the "already logged in" and the
 * "successful login" paths) happens *before* any rendering starts.
 */
final readonly class IdentificationController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private LayoutState $layoutState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private UserService $userService,
        private AuthService $authService,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
        private ConnectedWithSession $connectedWithSession,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Field-keyed, controller-local -- read by specific key
        // ('login_page_error'/'login_form_error') in identification.latte, a
        // different shape than PageState::$errors' plain list<string>.
        $errors = [];

        $this->accessControl->checkStatus(AccessLevel::Free);

        // but if the user is already identified, we redirect to gallery
        // home instead of displaying the log in form
        if (! $this->accessControl->isAGuest()) {
            $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
        }

        $this->eventDispatcher->dispatch(new IdentificationPageRendering());

        unset($_SESSION['reset_password_code']);

        $identificationSubmit = IdentificationSubmitRequest::fromGlobals($this->inputValidator);

        $redirect_to = '';
        if ($identificationSubmit->getRedirect !== null) {
            $redirect_to = urldecode($identificationSubmit->getRedirect);
            if ($this->currentConfig->guestAccess and ! $identificationSubmit->hideRedirectErrorPresent) {
                $errors['login_page_error'] = $this->lang->t('You are not authorized to access the requested page');
            }
        }

        if ($identificationSubmit->isLoginSubmitted) {
            $session_cookie_name = session_name();
            $has_session_cookie = $session_cookie_name !== false && isset($_COOKIE[$session_cookie_name]);
            if (! $has_session_cookie) {
                $errors['login_page_error'] = $this->lang->t('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
            } else {
                // $_POST['username'] is required to be a string for
                // try_log_user(); an unset/non-string value falls back to
                // '' which will simply not match any account.
                // $_POST['password'] is allowed to be null (both this and
                // ws_session_login() are try_log_user()'s only real
                // callers, and both can genuinely omit the field).
                $username = $identificationSubmit->username;
                $password = $identificationSubmit->password;

                if ($this->currentConfig->insensitiveCaseLogon) {
                    $username = $this->userService
                        ->searchCaseUsername($username);
                }

                $redirect_to = $identificationSubmit->postRedirectDecoded ?? '';
                $remember_me = $identificationSubmit->isRememberMe;

                if ($this->authService->tryLogUser($username, $password, $remember_me)) {
                    // security (level 2): force redirect within Piwigo. We
                    // redirect to absolute root url, including http(s)://,
                    // without the cookie path, concatenated with
                    // $_POST['redirect'] param.
                    //
                    // example:
                    // {redirect (raw) = /piwigo/git/admin.php}
                    // {get_absolute_root_url = http://localhost/piwigo/git/}
                    // {cookie_path = /piwigo/git/}
                    // {host = http://localhost}
                    // {redirect (final) = http://localhost/piwigo/git/admin.php}
                    $root_url = $this->urlService->getAbsoluteRootUrl();

                    $this->connectedWithSession->set(ConnectedWith::PwgUi);

                    $gallery_home_url = $this->urlService->getGalleryHomeUrl();

                    $this->redirectService->redirect(
                        $redirect_to === ''
                        ? $gallery_home_url
                        : substr($root_url, 0, strlen($root_url) - strlen(new CookieService()->cookiePath())) . $redirect_to
                    );
                } else {
                    $errors['login_form_error'] = $this->lang->t('Invalid username or password!');
                }
            }
        }

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $title = $this->lang->t('Identification');
        $this->layoutState->setBodyId('theIdentificationPage');

        $register = null;
        if (! $this->currentConfig->galleryLocked && $this->currentConfig->allowUserRegistration) {
            $register = $urlService->getRootUrl() . 'register.php';
        }

        $lost_password = null;
        if (! $this->currentConfig->galleryLocked) {
            $lost_password = $urlService->getRootUrl() . 'password.php';
        }

        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! $this->currentConfig->galleryLocked && (! is_array($hide_menu_on) or ! in_array('theIdentificationPage', $hide_menu_on, true))) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager, $this->renderer);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $lang_cookie = $_COOKIE['lang'] ?? null;
        if ($lang_cookie !== null and (! is_string($lang_cookie) or $this->currentUser->get()->language->value !== $lang_cookie)) {
            if (! is_string($lang_cookie)) {
                $this->htmlService
                    ->fatalError('Invalid request parameter "lang"');
            }
            if (! array_key_exists($lang_cookie, LangService::getLanguages($this->paths, $this->entityManager))) {
                $this->htmlService
                    ->fatalError('Unrecognized value for parameter "lang"');
            }

            $this->currentUser->updateLanguage(LangCode::from($lang_cookie));
            $this->lang->load('common.lang', '', [
                'language' => $lang_cookie,
            ]);
        }

        $language_options = [];
        foreach (LangService::getLanguages($this->paths, $this->entityManager) as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        if (str_starts_with($this->currentUser->get()->language->value, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $identificationView = new IdentificationView(
            homeUrl: $urlService->makeIndexUrl(),
            redirect: $redirect_to,
            loginAction: $urlService->getRootUrl() . 'identification.php',
            authorizeRemembering: $this->currentConfig->authorizeRemembering,
            register: $register,
            lostPassword: $lost_password,
            languageOptions: $language_options,
            currentLanguage: $this->currentUser->get()
                ->language->value,
            helpLink: $help_link,
        );

        new PageHeaderRenderer()
            ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatch(new IdentificationPageRendered());
        $this->htmlService
            ->flushPageMessages();
        $this->htmlService
            ->flushKeyedErrors($errors);

        PageTail::prepareContext();

        $html = $this->renderer->render($identificationView);
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }
}
