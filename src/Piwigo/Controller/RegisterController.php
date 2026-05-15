<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the user registration page (/register).
 * Corresponds to the former register.php entry-point.
 */
final readonly class RegisterController implements ControllerInterface
{
    public function __construct(
        private AuthService $authService,
        private HtmlService $htmlService,
        private LangService $langService,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private StringUtil $stringUtil,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserService $userService,
        private RedirectResponder $redirectResponder,
        private EphemeralKeyService $ephemeralKeyService,
        private LanguageService $languageService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Free);

        if (!Config::allowUserRegistration()) {
            $this->htmlService->pageForbidden('User registration closed');
        }

        EventDispatcher::notify('loc_begin_register');

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $post_login    = $this->stringUtil->inputString('login', null, $_POST);
        $post_mail     = $this->stringUtil->inputString('mail_address', null, $_POST);
        $post_key      = $this->stringUtil->inputString('key', null, $_POST) ?? '';
        $post_send_mail = $this->stringUtil->inputString('send_password_by_mail', null, $_POST) !== null;

        if ($this->stringUtil->inputString('submit', null, $_POST) !== null) {
            $pgErrors = [];

            if (!$this->ephemeralKeyService->verify($post_key)) {
                $this->htmlService->setStatusHeader(403);
                $pgErrors['register_page_error'] = Lang::t('Invalid/expired form key');
            }

            if (($_POST['password'] ?? '') === '') {
                $pgErrors['register_form_error'] = Lang::t('Password is missing. Please enter the password.');
            } elseif (($_POST['password_conf'] ?? '') === '') {
                $pgErrors['register_form_error'] = Lang::t('Password confirmation is missing. Please confirm the chosen password.');
            } elseif ($_POST['password'] != $_POST['password_conf']) {
                $pgErrors['register_form_error'] = Lang::t('The passwords do not match');
            }

            $post_password = is_string($rawRegisterPwd = $_POST['password'] ?? null) ? $rawRegisterPwd : '';
            $this->userService->registerUser($post_login ?? '', $post_password, $post_mail ?? '', true, $pgErrors, $post_send_mail);
            foreach ($pgErrors as $k => $v) {
                PageState::current()->keyedErrors[(string) $k] = $v;
            }

            if (count($pgErrors) == 0) {
                if ($post_send_mail && $this->stringUtil->emailCheckFormat($post_mail ?? '')) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    $_SESSION['page_infos'][] = Lang::t('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                }
                $user_id = $this->userService->getUserid($post_login ?? '');
                if ($user_id !== false) {
                    $this->authService->logUser($user_id, false);
                }
                $this->redirectResponder->redirect($this->urlService->makeIndexUrl());
            }
            $registration_post_key = $this->ephemeralKeyService->generate(2);
        } else {
            $registration_post_key = $this->ephemeralKeyService->generate(6);
        }

        $login = ($post_login !== null && $post_login !== '') ? htmlspecialchars(stripslashes($post_login)) : '';
        $email = ($post_mail !== null && $post_mail !== '') ? htmlspecialchars(stripslashes($post_mail)) : '';

        $tpl = TemplateRegistry::current();
        $tpl->assign([
            'U_HOME'                      => $this->urlService->makeIndexUrl(),
            'F_KEY'                       => $registration_post_key,
            'F_ACTION'                    => $this->urlGenerator->register(),
            'F_LOGIN'                     => $login,
            'F_EMAIL'                     => $email,
            'obligatory_user_mail_address' => Config::obligatoryUserMailAddress(),
            'U_IDENTIFICATION'             => $this->urlGenerator->identification(),
        ]);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('theRegisterPage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        $cookie_lang = $this->stringUtil->inputString('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, $this->languageService->getActiveLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            $this->langService->loadLanguage('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach ($this->languageService->getActiveLanguages() as $language_code => $language_name) {
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
        $this->htmlService->flushPageMessages();
        $tpl->parse('register.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
