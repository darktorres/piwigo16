<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
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
        PermissionService::get()->checkStatus(AccessLevel::Free);

        if (!Config::allowUserRegistration()) {
            ServiceLocator::get(HtmlService::class)->pageForbidden('User registration closed');
        }

        EventDispatcher::notify('loc_begin_register');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $post_login    = StringUtil::inputString('login', null, $_POST);
        $post_mail     = StringUtil::inputString('mail_address', null, $_POST);
        $post_key      = StringUtil::inputString('key', null, $_POST) ?? '';
        $post_send_mail = StringUtil::inputString('send_password_by_mail', null, $_POST) !== null;

        if (StringUtil::inputString('submit', null, $_POST) !== null) {
            /** @var string[] $pgErrors */
            $pgErrors = [];

            if (!ServiceLocator::get(Util::class)->verifyEphemeralKey($post_key)) {
                ServiceLocator::get(HtmlService::class)->setStatusHeader(403);
                $pgErrors['register_page_error'] = Lang::t('Invalid/expired form key');
            }

            if (empty($_POST['password'])) {
                $pgErrors['register_form_error'] = Lang::t('Password is missing. Please enter the password.');
            } elseif (empty($_POST['password_conf'])) {
                $pgErrors['register_form_error'] = Lang::t('Password confirmation is missing. Please confirm the chosen password.');
            } elseif ($_POST['password'] != $_POST['password_conf']) {
                $pgErrors['register_form_error'] = Lang::t('The passwords do not match');
            }

            $post_password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            UserService::get()->registerUser($post_login ?? '', $post_password, $post_mail ?? '', true, $pgErrors, $post_send_mail);
            $page['errors'] = $pgErrors;

            if (count($pgErrors) == 0) {
                if ($post_send_mail && ServiceLocator::get(StringUtil::class)->emailCheckFormat($post_mail ?? '')) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    $_SESSION['page_infos'][] = Lang::t('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                }
                $user_id = UserService::get()->getUserid($post_login ?? '');
                if ($user_id !== false) {
                    AuthService::get()->logUser((int) $user_id, false);
                }
                Util::get()->redirect(UrlService::get()->makeIndexUrl());
            }
            $registration_post_key = ServiceLocator::get(Util::class)->getEphemeralKey(2);
        } else {
            $registration_post_key = ServiceLocator::get(Util::class)->getEphemeralKey(6);
        }

        $login = !empty($post_login) ? htmlspecialchars(stripslashes($post_login)) : '';
        $email = !empty($post_mail) ? htmlspecialchars(stripslashes($post_mail)) : '';

        $tpl = TemplateRegistry::current();
        $tpl->setFilenames(['register' => 'register.tpl']);
        $tpl->assign([
            'U_HOME'                      => UrlService::get()->makeIndexUrl(),
            'F_KEY'                       => $registration_post_key,
            'F_ACTION'                    => ServiceLocator::get(UrlGenerator::class)->register(),
            'F_LOGIN'                     => $login,
            'F_EMAIL'                     => $email,
            'obligatory_user_mail_address' => Config::obligatoryUserMailAddress(),
            'U_IDENTIFICATION'             => ServiceLocator::get(UrlGenerator::class)->identification(),
        ]);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theRegisterPage', $hideMenuOn)) {
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        $cookie_lang = StringUtil::inputString('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, Util::get()->getLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            LangService::get()->loadLanguage('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach (Util::get()->getLanguages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign(['language_options' => $language_options, 'current_language' => $userLang]);
        $tpl->assign('page_data_json', json_encode([
            'selected_language' => $language_options[$userLang] ?? '',
            'url_logo_light'    => UrlService::getRootUrl() . 'themes/standard_pages/images/piwigo_logo.svg',
            'url_logo_dark'     => UrlService::getRootUrl() . 'themes/standard_pages/images/piwigo_logo_dark.svg',
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $help_link = str_starts_with($userLang, 'fr')
            ? 'https://doc-fr.piwigo.org/les-utilisateurs/se-connecter-a-piwigo'
            : 'https://doc.piwigo.org/managing-users/log-in-to-piwigo';
        $tpl->assign('HELP_LINK', $help_link);

        PageHeaderRenderer::render();
        EventDispatcher::notify('loc_end_register');
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        $tpl->parse('register');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
