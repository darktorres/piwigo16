<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\PasswordService;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Page\PageHeaderRenderer;

/**
 * Handles the three-stage password-reset flow (/password).
 * Helper functions live in include/password_functions.php.
 * Corresponds to the former password.php entry-point.
 */
final class PasswordController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        PermissionService::get()->checkStatus(ACCESS_FREE);

        EventDispatcher::notify('loc_begin_password');

        check_input_parameter('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $get_action = input_string('action', null, $_GET);

        if (input_string('submit', null, $_POST) !== null) {
            check_pwg_token();

            if ('lost' == $get_action) {
                if (ServiceLocator::get(PasswordService::class)->processVerificationCode()) {
                    PageState::current()->addInfo(l10n('If your account exists, a verification code has been sent to your email address.'));
                    $page['action'] = 'lost_code';
                }
            }
            if ('lost_code' == $get_action) {
                if (ServiceLocator::get(PasswordService::class)->processPasswordRequest()) {
                    PageState::current()->addInfo(l10n('Verification successful! You can now choose a new password.'));
                    $page['action'] = 'reset';
                }
            }
            if ('reset' == $get_action) {
                if (ServiceLocator::get(PasswordService::class)->resetPassword()) {
                    $page['action'] = 'reset_end';
                }
            }
        }

        if (input_string('key', null, $_GET) !== null && !PermissionService::get()->isAGuest()) {
            unset($_GET['key']);
        }

        $first_login = false;
        $get_key     = input_string('key', null, $_GET);
        if ($get_key !== null && input_string('submit', null, $_POST) === null) {
            $user_id = ServiceLocator::get(PasswordService::class)->checkPasswordResetKey($get_key);
            if (is_numeric($user_id)) {
                $userdata = UserService::get()->getuserdata($user_id, false);
                $page['username'] = $userdata !== false ? $userdata['username'] : '';
                TemplateRegistry::current()->assign('key', $get_key);
                $first_login = UserService::get()->hasAlreadyLoggedIn($user_id);
                if (!isset($page['action'])) {
                    $page['action'] = 'reset';
                }
            } else {
                $page['action'] = 'none';
            }
        }

        if (!isset($page['action'])) {
            if ($get_action === null) {
                $page['action'] = 'lost';
            } elseif (in_array($get_action, ['lost', 'lost_code', 'reset', 'none'])) {
                $page['action'] = $get_action;
            }
        }

        if ('reset' == $page['action']) {
            if (($get_key === null && (PermissionService::get()->isAGuest() || PermissionService::get()->isGeneric())) && !isset($_SESSION['valid_reset_password_code'])) {
                redirect(get_gallery_home_url());
            }
        }
        if ('lost' == $page['action'] && !PermissionService::get()->isAGuest()) {
            redirect(get_gallery_home_url());
        }
        if ('lost_code' == $page['action'] && !isset($_SESSION['reset_password_code'])) {
            redirect(ServiceLocator::get(UrlGenerator::class)->identification());
        }
        if ('lost' == $page['action'] && isset($_SESSION['reset_password_code'])) {
            $page['action'] = 'lost_code';
        }

        $title = l10n('Password Reset');
        $tpl   = TemplateRegistry::current();

        if ('lost' == $page['action']) {
            $title       = l10n('Forgot your password?');
            $post_uoe    = input_string('username_or_email', null, $_POST);
            if ($post_uoe !== null) {
                $tpl->assign('username_or_email', htmlspecialchars(stripslashes($post_uoe)));
            }
        } elseif ('reset' == $page['action'] && $first_login) {
            $title = l10n('Welcome');
            $tpl->assign('is_first_login', true);
        }

        $page['body_id'] = 'thePasswordPage';
        $tpl->setFilenames(['password' => 'password.tpl']);
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign([
            'title'          => $title,
            'form_action'    => ServiceLocator::get(UrlGenerator::class)->password(),
            'action'         => $page['action'],
            'username'       => is_scalar($page['username'] ?? null) ? $page['username'] : ($user['username'] ?? ''),
            'PWG_TOKEN'      => get_pwg_token(),
            'U_IDENTIFICATION' => ServiceLocator::get(UrlGenerator::class)->identification(),
            'U_REGISTER'     => ServiceLocator::get(UrlGenerator::class)->register(),
        ]);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('thePasswordPage', $hideMenuOn)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
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

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_password');
        flush_page_messages();
        $tpl->pparse('password');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
