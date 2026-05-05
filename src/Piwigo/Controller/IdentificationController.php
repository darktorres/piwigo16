<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the login page (/identification).
 * Corresponds to the former identification.php entry-point.
 */
final class IdentificationController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_FREE);

        if (!is_a_guest()) {
            redirect(get_gallery_home_url());
        }

        trigger_notify('loc_begin_identification');

        unset($_SESSION['reset_password_code']);

        $post_redirect = input_string('redirect', null, $_POST);
        if ($post_redirect !== null) {
            $_POST['redirect_decoded'] = urldecode($post_redirect);
        }
        check_input_parameter('redirect_decoded', $_POST, false, '{^' . preg_quote((string) cookie_path()) . '}');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        $redirect_to = '';
        $get_redirect = input_string('redirect', null, $_GET);
        if (!empty($get_redirect)) {
            $redirect_to = urldecode($get_redirect);
            if (Config::guestAccess() && input_string('hide_redirect_error', null, $_GET) === null) {
                $pgErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
                $pgErrors['login_page_error'] = l10n('You are not authorized to access the requested page');
                $page['errors'] = $pgErrors;
            }
        }

        if (input_string('login', null, $_POST) !== null) {
            if (!isset($_COOKIE[session_name()])) {
                $pgErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
                $pgErrors['login_page_error'] = l10n('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
                $page['errors'] = $pgErrors;
            } else {
                $username = input_string('username', null, $_POST) ?? '';
                if (Config::insensitiveCaseLogon() == true) {
                    $username = search_case_username($username);
                }
                $redirect_to  = $post_redirect !== null ? urldecode($post_redirect) : '';
                $remember_me  = input_string('remember_me', null, $_POST) !== null && input_string('remember_me', null, $_POST) == 1;
                $post_password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if (try_log_user($username, $post_password, $remember_me)) {
                    $root_url = get_absolute_root_url();
                    $_SESSION['connected_with'] = 'pwg_ui';
                    redirect(
                        empty($redirect_to)
                        ? get_gallery_home_url()
                        : substr((string) $root_url, 0, strlen((string) $root_url) - strlen((string) cookie_path())) . $redirect_to
                    );
                } else {
                    $pgErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
                    $pgErrors['login_form_error'] = l10n('Invalid username or password!');
                    $page['errors'] = $pgErrors;
                }
            }
        }

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];
        $tpl  = TemplateRegistry::current();

        $tpl->set_filenames(['identification' => 'identification.tpl']);
        $tpl->assign([
            'U_REDIRECT'           => $redirect_to,
            'F_LOGIN_ACTION'       => get_root_url() . 'identification.php',
            'authorize_remembering' => Config::authorizeRemembering(),
        ]);

        if (!Config::galleryLocked() && Config::allowUserRegistration()) {
            $tpl->assign('U_REGISTER', get_root_url() . 'register.php');
        }
        if (!Config::galleryLocked()) {
            $tpl->assign('U_LOST_PASSWORD', get_root_url() . 'password.php');
        }

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!Config::galleryLocked() && !in_array('theIdentificationPage', $hideMenuOn)) {
            require PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }

        $cookie_lang = input_string('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, get_languages())) {
                fatal_error('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            load_language('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach (get_languages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign(['language_options' => $language_options, 'current_language' => $userLang]);
        $tpl->assign('page_data_json', json_encode([
            'selected_language' => $language_options[$userLang] ?? '',
            'url_logo_light'    => get_root_url() . 'themes/standard_pages/images/piwigo_logo.svg',
            'url_logo_dark'     => get_root_url() . 'themes/standard_pages/images/piwigo_logo_dark.svg',
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $help_link = str_starts_with($userLang, 'fr')
            ? 'https://doc-fr.piwigo.org/les-utilisateurs/se-connecter-a-piwigo'
            : 'https://doc.piwigo.org/managing-users/log-in-to-piwigo';
        $tpl->assign('HELP_LINK', $help_link);

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        trigger_notify('loc_end_identification');
        flush_page_messages();
        $tpl->pparse('identification');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
