<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\CookieService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Template\Template;
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
 * "successful login" paths) happens *before* any rendering starts, so both
 * stay outside the captured closure, same exit()-based-termination
 * limitation as every other controller this phase.
 */
final class IdentificationController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $conf, $page;

        // $page['errors'] is always initialized to an array by
        // common.inc.php, but that isn't visible across the include()
        // boundary -- narrow it once here so every write below type-checks.
        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        // but if the user is already identified, we redirect to gallery
        // home instead of displaying the log in form
        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            $gallery_home_url = get_gallery_home_url();
            redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
        }

        trigger_notify('loc_begin_identification');

        unset($_SESSION['reset_password_code']);

        // security (level 1): the redirect must occur within Piwigo, so the
        // redirect param must start with the relative home url
        if (isset($_POST['redirect']) && is_string($_POST['redirect'])) {
            $_POST['redirect_decoded'] = urldecode($_POST['redirect']);
        }
        (new \Piwigo\Validation\InputValidator())->validate('redirect_decoded', $_POST, false, '{^' . preg_quote(new CookieService()->cookiePath()) . '}');

        $redirect_to = '';
        $get_redirect = $_GET['redirect'] ?? null;
        if (is_string($get_redirect) && $get_redirect !== '') {
            $redirect_to = urldecode($get_redirect);
            if ((bool) $conf['guest_access'] and ! isset($_GET['hide_redirect_error'])) {
                $page['errors']['login_page_error'] = l10n('You are not authorized to access the requested page');
            }
        }

        if (isset($_POST['login'])) {
            $session_cookie_name = session_name();
            $has_session_cookie = $session_cookie_name !== false && isset($_COOKIE[$session_cookie_name]);
            if (! $has_session_cookie) {
                $page['errors']['login_page_error'] = l10n('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
            } else {
                // $_POST['username'] is required to be a string for
                // try_log_user(); an unset/non-string value falls back to
                // '' which will simply not match any account.
                // $_POST['password'] is allowed to be null (both this and
                // ws_session_login() are try_log_user()'s only real
                // callers, and both can genuinely omit the field).
                $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : null;

                if ((bool) $conf['insensitive_case_logon']) {
                    $username = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->searchCaseUsername($username);
                }

                $redirect_to = is_string($_POST['redirect'] ?? null) ? urldecode($_POST['redirect']) : '';
                $remember_me_raw = $_POST['remember_me'] ?? null;
                $remember_me = isset($_POST['remember_me']) && is_scalar($remember_me_raw) && (string) $remember_me_raw === '1';

                if ((new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->tryLogUser($username, $password, $remember_me)) {
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
                    $root_url = get_absolute_root_url();

                    $_SESSION['connected_with'] = 'pwg_ui';

                    $gallery_home_url = get_gallery_home_url();

                    redirect(
                        $redirect_to === ''
                        ? (is_string($gallery_home_url) ? $gallery_home_url : '')
                        : substr($root_url, 0, strlen($root_url) - strlen(new CookieService()->cookiePath())) . $redirect_to
                    );
                } else {
                    $page['errors']['login_form_error'] = l10n('Invalid username or password!');
                }
            }
        }

        $body = LegacyRenderCapture::capture(static function () use ($redirect_to): void {
            /**
             * @var array<string, mixed> $conf
             * @var array<string, mixed> $page
             * @var Template $template
             * @var array<string, mixed> $user
             */
            global $conf, $page, $template, $user, $title;

            $title = l10n('Identification');
            $page['body_id'] = 'theIdentificationPage';

            $template->set_filenames([
                'identification' => 'identification.tpl',
            ]);

            $template->assign(
                [
                    'U_REDIRECT' => $redirect_to,

                    'F_LOGIN_ACTION' => get_root_url() . 'identification.php',
                    'authorize_remembering' => $conf['authorize_remembering'],
                ]
            );

            if (! (bool) $conf['gallery_locked'] && (bool) $conf['allow_user_registration']) {
                $template->assign('U_REGISTER', get_root_url() . 'register.php');
            }

            if (! (bool) $conf['gallery_locked']) {
                $template->assign('U_LOST_PASSWORD', get_root_url() . 'password.php');
            }

            $themeconf = $template->get_template_vars('themeconf');
            $themeconf = is_array($themeconf) ? $themeconf : [];
            $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
            if (! (bool) $conf['gallery_locked'] && (! is_array($hide_menu_on) or ! in_array('theIdentificationPage', $hide_menu_on, true))) {
                new MenubarRenderer()
                    ->render();
            }

            // Load language if cookie is set from login/register/password
            // pages
            $lang_cookie = $_COOKIE['lang'] ?? null;
            if ($lang_cookie !== null and (! is_string($lang_cookie) or $user['language'] !== $lang_cookie)) {
                if (! is_string($lang_cookie)) {
                    fatal_error('[Hacking attempt] the input parameter "lang" is not valid');
                }
                if (! array_key_exists($lang_cookie, \Piwigo\Lang\LangService::getLanguages())) {
                    fatal_error('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
                }

                $user['language'] = $lang_cookie;
                Lang::load('common.lang', '', [
                    'language' => $user['language'],
                ]);
            }

            $language_options = [];
            foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
                $language_options[$language_code] = $language_name;
            }

            $template->assign([
                'language_options' => $language_options,
                'current_language' => $user['language'],
            ]);

            $user_language_for_help = $user['language'] ?? '';
            $user_language_for_help = is_string($user_language_for_help) ? $user_language_for_help : '';
            if (str_starts_with($user_language_for_help, 'fr')) {
                $help_link = 'https://upstream.example.invalid/help/fr/';
            } else {
                $help_link = 'https://upstream.example.invalid/help/';
            }

            $template->assign('HELP_LINK', $help_link);

            include PHPWG_ROOT_PATH . 'include/page_header.php';
            trigger_notify('loc_end_identification');
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('identification');
            include PHPWG_ROOT_PATH . 'include/page_tail.php';
        });

        return ResponseFactory::html($body);
    }
}
