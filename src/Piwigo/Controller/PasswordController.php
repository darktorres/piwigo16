<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\PasswordService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
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
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the three-stage password-reset flow (/password).
 * Helper functions live in include/password_functions.php.
 * Corresponds to the former password.php entry-point.
 */
final readonly class PasswordController implements ControllerInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private LangService $langService,
        private MenubarRenderer $menubarRenderer,
        private PasswordService $passwordService,
        private PermissionService $permissionService,
        private StringUtil $stringUtil,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserService $userService,
        private Util $util,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        $this->permissionService->checkStatus(AccessLevel::Free);

        EventDispatcher::notify('loc_begin_password');

        $this->util->checkInputParameter('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $get_action = $this->stringUtil->inputString('action', null, $_GET);

        if ($this->stringUtil->inputString('submit', null, $_POST) !== null) {
            $this->util->checkPwgToken();

            if ('lost' == $get_action) {
                if ($this->passwordService->processVerificationCode()) {
                    PageState::current()->addInfo(Lang::t('If your account exists, a verification code has been sent to your email address.'));
                    $page['action'] = 'lost_code';
                }
            }
            if ('lost_code' == $get_action) {
                if ($this->passwordService->processPasswordRequest()) {
                    PageState::current()->addInfo(Lang::t('Verification successful! You can now choose a new password.'));
                    $page['action'] = 'reset';
                }
            }
            if ('reset' == $get_action) {
                if ($this->passwordService->resetPassword()) {
                    $page['action'] = 'reset_end';
                }
            }
        }

        if ($this->stringUtil->inputString('key', null, $_GET) !== null && !$this->permissionService->isAGuest()) {
            unset($_GET['key']);
        }

        $first_login = false;
        $get_key     = $this->stringUtil->inputString('key', null, $_GET);
        if ($get_key !== null && $this->stringUtil->inputString('submit', null, $_POST) === null) {
            $user_id = $this->passwordService->checkPasswordResetKey($get_key);
            if (is_numeric($user_id)) {
                $userdata = $this->userService->getuserdata($user_id, false);
                $page['username'] = $userdata !== false ? $userdata['username'] : '';
                TemplateRegistry::current()->assign('key', $get_key);
                $first_login = $this->userService->hasAlreadyLoggedIn($user_id);
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
            if (($get_key === null && ($this->permissionService->isAGuest() || $this->permissionService->isGeneric())) && !isset($_SESSION['valid_reset_password_code'])) {
                $this->util->redirect($this->urlService->getGalleryHomeUrl());
            }
        }
        if ('lost' == $page['action'] && !$this->permissionService->isAGuest()) {
            $this->util->redirect($this->urlService->getGalleryHomeUrl());
        }
        if ('lost_code' == $page['action'] && !isset($_SESSION['reset_password_code'])) {
            $this->util->redirect($this->urlGenerator->identification());
        }
        if ('lost' == $page['action'] && isset($_SESSION['reset_password_code'])) {
            $page['action'] = 'lost_code';
        }

        $title = Lang::t('Password Reset');
        $tpl   = TemplateRegistry::current();

        if ('lost' == $page['action']) {
            $title       = Lang::t('Forgot your password?');
            $post_uoe    = $this->stringUtil->inputString('username_or_email', null, $_POST);
            if ($post_uoe !== null) {
                $tpl->assign('username_or_email', htmlspecialchars(stripslashes($post_uoe)));
            }
        } elseif ('reset' == $page['action'] && $first_login) {
            $title = Lang::t('Welcome');
            $tpl->assign('is_first_login', true);
        }

        $page['body_id'] = 'thePasswordPage';
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign([
            'title'          => $title,
            'form_action'    => $this->urlGenerator->password(),
            'action'         => $page['action'],
            'username'       => is_scalar($page['username'] ?? null) ? $page['username'] : ($user['username'] ?? ''),
            'PWG_TOKEN'      => $this->util->getPwgToken(),
            'U_IDENTIFICATION' => $this->urlGenerator->identification(),
            'U_REGISTER'     => $this->urlGenerator->register(),
        ]);

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (!in_array('thePasswordPage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        $cookie_lang = $this->stringUtil->inputString('lang', null, $_COOKIE);
        if ($cookie_lang !== null && $user['language'] != $cookie_lang) {
            if (!array_key_exists($cookie_lang, $this->util->getLanguages())) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }
            $user['language'] = $cookie_lang;
            $this->langService->loadLanguage('common.lang', '', ['language' => $cookie_lang]);
        }

        $language_options = [];
        foreach ($this->util->getLanguages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }
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

        PageHeaderRenderer::render($title);
        EventDispatcher::notify('loc_end_password');
        $this->htmlService->flushPageMessages();
        $tpl->pparse('password.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
