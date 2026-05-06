<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the user registration page (/register).
 * Corresponds to the former register.php entry-point.
 */
final class RegisterController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        check_status(ACCESS_FREE);

        if (!Config::allowUserRegistration()) {
            page_forbidden('User registration closed');
        }

        trigger_notify('loc_begin_register');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $post_login    = input_string('login', null, $_POST);
        $post_mail     = input_string('mail_address', null, $_POST);
        $post_key      = input_string('key', null, $_POST) ?? '';
        $post_send_mail = input_string('send_password_by_mail', null, $_POST) !== null;

        if (input_string('submit', null, $_POST) !== null) {
            /** @var string[] $pgErrors */
            $pgErrors = [];

            if (!verify_ephemeral_key($post_key)) {
                set_status_header(403);
                $pgErrors['register_page_error'] = l10n('Invalid/expired form key');
            }

            if (empty($_POST['password'])) {
                $pgErrors['register_form_error'] = l10n('Password is missing. Please enter the password.');
            } elseif (empty($_POST['password_conf'])) {
                $pgErrors['register_form_error'] = l10n('Password confirmation is missing. Please confirm the chosen password.');
            } elseif ($_POST['password'] != $_POST['password_conf']) {
                $pgErrors['register_form_error'] = l10n('The passwords do not match');
            }

            $post_password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            register_user($post_login ?? '', $post_password, $post_mail ?? '', true, $pgErrors, $post_send_mail);
            $page['errors'] = $pgErrors;

            if (count($pgErrors) == 0) {
                if ($post_send_mail && email_check_format($post_mail ?? '')) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    $_SESSION['page_infos'][] = l10n('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                }
                $user_id = get_userid($post_login ?? '');
                if ($user_id !== false) {
                    log_user((int) $user_id, false);
                }
                redirect(make_index_url());
            }
            $registration_post_key = get_ephemeral_key(2);
        } else {
            $registration_post_key = get_ephemeral_key(6);
        }

        $login = !empty($post_login) ? htmlspecialchars(stripslashes($post_login)) : '';
        $email = !empty($post_mail) ? htmlspecialchars(stripslashes($post_mail)) : '';

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['register' => 'register.tpl']);
        $tpl->assign([
            'U_HOME'                      => make_index_url(),
            'F_KEY'                       => $registration_post_key,
            'F_ACTION'                    => ServiceLocator::get(UrlGenerator::class)->register(),
            'F_LOGIN'                     => $login,
            'F_EMAIL'                     => $email,
            'obligatory_user_mail_address' => Config::obligatoryUserMailAddress(),
            'U_IDENTIFICATION'             => ServiceLocator::get(UrlGenerator::class)->identification(),
        ]);

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theRegisterPage', $hideMenuOn)) {
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
        trigger_notify('loc_end_register');
        flush_page_messages();
        $tpl->parse('register');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
