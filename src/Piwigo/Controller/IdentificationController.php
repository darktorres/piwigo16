<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\CookieService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginIdentification;
use Piwigo\Event\Location\LocEndIdentification;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces identification.php -- the login form + POST handler. Reads
 * $_GET/$_POST/$_SESSION/$_COOKIE directly rather than through $request:
 * the underlying legacy functions this page calls (check_input_parameter(),
 * try_log_user()) are themselves written against the real superglobals,
 * not a passed-in copy, and superglobals (unlike ordinary global variables)
 * are already reachable from any scope with no `global` declaration needed
 * -- routing them through $request here would only desync two copies of
 * the same request data for no real benefit, the same "keep legacy glue
 * functioning as before" scoping this whole phase uses elsewhere.
 *
 * Every redirect() in this file (both the "already logged in" and the
 * "successful login" paths) happens *before* any rendering starts.
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class IdentificationController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Field-keyed, controller-local -- read by specific key
        // ('login_page_error'/'login_form_error') in identification.tpl, a
        // different shape than PageState::$errors' plain list<string>.
        $errors = [];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        // but if the user is already identified, we redirect to gallery
        // home instead of displaying the log in form
        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocBeginIdentification());

        unset($_SESSION['reset_password_code']);

        $identificationSubmit = Request\IdentificationSubmitRequest::fromGlobals();

        $redirect_to = '';
        if ($identificationSubmit->getRedirect !== null) {
            $redirect_to = urldecode($identificationSubmit->getRedirect);
            if (\Piwigo\Config\CurrentConfig::guestAccess() and ! $identificationSubmit->hideRedirectErrorPresent) {
                $errors['login_page_error'] = Lang::t('You are not authorized to access the requested page');
            }
        }

        if ($identificationSubmit->isLoginSubmitted) {
            $session_cookie_name = session_name();
            $has_session_cookie = $session_cookie_name !== false && isset($_COOKIE[$session_cookie_name]);
            if (! $has_session_cookie) {
                $errors['login_page_error'] = Lang::t('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
            } else {
                // $_POST['username'] is required to be a string for
                // try_log_user(); an unset/non-string value falls back to
                // '' which will simply not match any account.
                // $_POST['password'] is allowed to be null (both this and
                // ws_session_login() are try_log_user()'s only real
                // callers, and both can genuinely omit the field).
                $username = $identificationSubmit->username;
                $password = $identificationSubmit->password;

                $conn = \Piwigo\Db\DbConnection::build();
                if (\Piwigo\Config\CurrentConfig::insensitiveCaseLogon()) {
                    $username = \Piwigo\Bootstrap\CoreDomainAccessor::userService()
                        ->searchCaseUsername($username);
                }

                $redirect_to = $identificationSubmit->postRedirectDecoded ?? '';
                $remember_me = $identificationSubmit->isRememberMe;

                if (\Piwigo\Bootstrap\CoreDomainAccessor::authService()->tryLogUser($username, $password, $remember_me)) {
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
                    $errors['login_form_error'] = Lang::t('Invalid username or password!');
                }
            }
        }

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = \Piwigo\Template\CurrentTemplate::get();

        $title = Lang::t('Identification');
        \Piwigo\Core\PageState::current()->setBodyId('theIdentificationPage');

        $template->set_filenames([
            'identification' => 'identification.tpl',
        ]);

        $template->assign(
            [
                'U_REDIRECT' => $redirect_to,

                'F_LOGIN_ACTION' => $urlService->getRootUrl() . 'identification.php',
                'authorize_remembering' => \Piwigo\Config\CurrentConfig::authorizeRemembering(),
            ]
        );

        if (! \Piwigo\Config\CurrentConfig::galleryLocked() && \Piwigo\Config\CurrentConfig::allowUserRegistration()) {
            $template->assign('U_REGISTER', $urlService->getRootUrl() . 'register.php');
        }

        if (! \Piwigo\Config\CurrentConfig::galleryLocked()) {
            $template->assign('U_LOST_PASSWORD', $urlService->getRootUrl() . 'password.php');
        }

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! \Piwigo\Config\CurrentConfig::galleryLocked() && (! is_array($hide_menu_on) or ! in_array('theIdentificationPage', $hide_menu_on, true))) {
            new MenubarRenderer()
                ->render($urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $lang_cookie = $_COOKIE['lang'] ?? null;
        if ($lang_cookie !== null and (! is_string($lang_cookie) or \Piwigo\Users\CurrentUser::get()->language !== $lang_cookie)) {
            if (! is_string($lang_cookie)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($lang_cookie, \Piwigo\Lang\LangService::getLanguages())) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
            }

            \Piwigo\Users\CurrentUser::updateLanguage($lang_cookie);
            Lang::load('common.lang', '', [
                'language' => $lang_cookie,
            ]);
        }

        $language_options = [];
        foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        $template->assign([
            'language_options' => $language_options,
            'current_language' => \Piwigo\Users\CurrentUser::get()->language,
        ]);

        if (str_starts_with(\Piwigo\Users\CurrentUser::get()->language, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assign('HELP_LINK', $help_link);

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);
        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocEndIdentification());
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushKeyedErrors($errors);
        $template->parse('identification', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }
}
