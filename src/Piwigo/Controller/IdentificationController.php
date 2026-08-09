<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\IdentificationPageContext;
use Piwigo\Controller\Request\IdentificationSubmitRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocBeginIdentification;
use Piwigo\Event\Location\LocEndIdentification;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
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
final class IdentificationController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly FilterState $filterState,
        private readonly SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly UserService $userService,
        private readonly AuthService $authService,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Field-keyed, controller-local -- read by specific key
        // ('login_page_error'/'login_form_error') in identification.tpl, a
        // different shape than PageState::$errors' plain list<string>.
        $errors = [];

        $this->accessControl->checkStatus(AccessLevel::Free);

        // but if the user is already identified, we redirect to gallery
        // home instead of displaying the log in form
        if (! $this->accessControl->isAGuest()) {
            $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
        }

        $this->eventDispatcher->dispatchNotify(new LocBeginIdentification());

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

                $conn = DbConnection::build();
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

                    $_SESSION['connected_with'] = 'pwg_ui';

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
        $this->pageState->setBodyId('theIdentificationPage');

        $template->set_filenames([
            'identification' => 'identification.tpl',
        ]);

        $register = null;
        if (! $this->currentConfig->galleryLocked && $this->currentConfig->allowUserRegistration) {
            $register = $urlService->getRootUrl() . 'register.php';
        }

        $lost_password = null;
        if (! $this->currentConfig->galleryLocked) {
            $lost_password = $urlService->getRootUrl() . 'password.php';
        }

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! $this->currentConfig->galleryLocked && (! is_array($hide_menu_on) or ! in_array('theIdentificationPage', $hide_menu_on, true))) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $lang_cookie = $_COOKIE['lang'] ?? null;
        if ($lang_cookie !== null and (! is_string($lang_cookie) or $this->currentUser->get()->language->value !== $lang_cookie)) {
            if (! is_string($lang_cookie)) {
                $this->htmlService
                    ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($lang_cookie, LangService::getLanguages($this->paths))) {
                $this->htmlService
                    ->fatalError('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
            }

            $this->currentUser->updateLanguage(LangCode::from($lang_cookie));
            $this->lang->load('common.lang', '', [
                'language' => $lang_cookie,
            ]);
        }

        $language_options = [];
        foreach (LangService::getLanguages($this->paths) as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        if (str_starts_with($this->currentUser->get()->language->value, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assignContext(new IdentificationPageContext(
            redirect: $redirect_to,
            loginAction: $urlService->getRootUrl() . 'identification.php',
            authorizeRemembering: $this->currentConfig->authorizeRemembering,
            register: $register,
            lostPassword: $lost_password,
            languageOptions: $language_options,
            currentLanguage: $this->currentUser->get()
                ->language->value,
            helpLink: $help_link,
        ));

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatchNotify(new LocEndIdentification());
        $this->htmlService
            ->flushPageMessages();
        $this->htmlService
            ->flushKeyedErrors($errors);
        $template->parse('identification', false);
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
