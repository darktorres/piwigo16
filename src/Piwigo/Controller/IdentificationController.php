<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Html\HtmlService;
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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the login page (/identification).
 * Corresponds to the former identification.php entry-point.
 */
final readonly class IdentificationController implements ControllerInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private MenubarRenderer $menubarRenderer,
        private UrlGenerator $urlGenerator,
        private Util $util,
        private StringUtil $stringUtil,
        private PermissionService $permissionService,
        private LangService $langService,
        private AuthService $authService,
        private UrlService $urlService,
        private LanguageService $languageService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Free);

        if (!$this->permissionService->isAGuest()) {
            $this->util->redirect($this->urlService->getGalleryHomeUrl());
        }

        EventDispatcher::notify('loc_begin_identification');

        unset($_SESSION['reset_password_code']);

        $post_redirect = $this->stringUtil->inputString('redirect', null, $_POST);
        if ($post_redirect !== null) {
            $_POST['redirect_decoded'] = urldecode($post_redirect);
        }
        $this->util->checkInputParameter('redirect_decoded', $_POST, false, '{^' . preg_quote((string) CookieService::cookiePath()) . '}');

        $redirect_to = '';
        $get_redirect = $this->stringUtil->inputString('redirect', null, $_GET);
        if ($get_redirect !== null && $get_redirect !== '') {
            $redirect_to = urldecode($get_redirect);
            if (Config::guestAccess() && $this->stringUtil->inputString('hide_redirect_error', null, $_GET) === null) {
                PageState::current()->keyedErrors['login_page_error'] = Lang::t('You are not authorized to access the requested page');
            }
        }

        if ($this->stringUtil->inputString('login', null, $_POST) !== null) {
            if (!isset($_COOKIE[session_name()])) {
                PageState::current()->keyedErrors['login_page_error'] = Lang::t('Cookies are blocked or not supported by your browser. You must enable cookies to connect.');
            } else {
                $username = $this->stringUtil->inputString('username', null, $_POST) ?? '';
                if (Config::insensitiveCaseLogon() == true) {
                    $username = $this->authService->searchCaseUsername($username);
                }
                $redirect_to  = $post_redirect !== null ? urldecode($post_redirect) : '';
                $remember_me  = $this->stringUtil->inputString('remember_me', null, $_POST) !== null && $this->stringUtil->inputString('remember_me', null, $_POST) == 1;
                $post_password = is_string($rawPassword = $_POST['password'] ?? null) ? $rawPassword : '';
                if ($this->authService->tryLogUser($username, $post_password, $remember_me)) {
                    $root_url = UrlService::getAbsoluteRootUrl();
                    $_SESSION['connected_with'] = 'pwg_ui';
                    $this->util->redirect(
                        empty($redirect_to)
                        ? $this->urlService->getGalleryHomeUrl()
                        : substr($root_url, 0, strlen($root_url) - strlen(CookieService::cookiePath() ?? '')) . $redirect_to
                    );
                } else {
                    PageState::current()->keyedErrors['login_form_error'] = Lang::t('Invalid username or password!');
                }
            }
        }

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $tpl  = TemplateRegistry::current();

        $tpl->assign([
            'U_REDIRECT'           => $redirect_to,
            'F_LOGIN_ACTION'       => $this->urlGenerator->identification(),
            'authorize_remembering' => Config::authorizeRemembering(),
        ]);

        if (!Config::galleryLocked() && Config::allowUserRegistration()) {
            $tpl->assign('U_REGISTER', $this->urlGenerator->register());
        }
        if (!Config::galleryLocked()) {
            $tpl->assign('U_LOST_PASSWORD', $this->urlGenerator->password());
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!Config::galleryLocked() && !in_array('theIdentificationPage', $hideMenuOn)) {
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
        EventDispatcher::notify('loc_end_identification');
        $this->htmlService->flushPageMessages();
        $tpl->pparse('identification.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
