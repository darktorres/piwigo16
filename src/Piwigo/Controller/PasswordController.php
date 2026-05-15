<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\PasswordService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Location\LocBeginPassword;
use Piwigo\Event\Location\LocEndPassword;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the three-stage password-reset flow (/password).
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
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private RedirectResponder $redirectResponder,
        private LanguageService $languageService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        $this->permissionService->checkStatus(AccessLevel::Free);

        $this->dispatcher->dispatch(new LocBeginPassword());

        $this->inputValidator->check('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $action     = null;
        $username   = null;
        $get_action = $this->stringUtil->inputString('action', null, $_GET);

        if ($this->stringUtil->inputString('submit', null, $_POST) !== null) {
            $this->csrfService->check();

            if ('lost' == $get_action) {
                if ($this->passwordService->processVerificationCode()) {
                    PageState::current()->addInfo(Lang::t('If your account exists, a verification code has been sent to your email address.'));
                    $action = 'lost_code';
                }
            }
            if ('lost_code' == $get_action) {
                if ($this->passwordService->processPasswordRequest()) {
                    PageState::current()->addInfo(Lang::t('Verification successful! You can now choose a new password.'));
                    $action = 'reset';
                }
            }
            if ('reset' == $get_action) {
                if ($this->passwordService->resetPassword()) {
                    $action = 'reset_end';
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
                $username = $userdata !== false ? $userdata['username'] : '';
                TemplateRegistry::current()->assign('key', $get_key);
                $first_login = $this->userService->hasAlreadyLoggedIn($user_id);
                if ($action === null) {
                    $action = 'reset';
                }
            } else {
                $action = 'none';
            }
        }

        if ($action === null) {
            if ($get_action === null) {
                $action = 'lost';
            } elseif (in_array($get_action, ['lost', 'lost_code', 'reset', 'none'])) {
                $action = $get_action;
            }
        }

        if ('reset' == $action) {
            if (($get_key === null && ($this->permissionService->isAGuest() || $this->permissionService->isGeneric())) && !isset($_SESSION['valid_reset_password_code'])) {
                $this->redirectResponder->redirect($this->urlService->getGalleryHomeUrl());
            }
        }
        if ('lost' == $action && !$this->permissionService->isAGuest()) {
            $this->redirectResponder->redirect($this->urlService->getGalleryHomeUrl());
        }
        if ('lost_code' == $action && !isset($_SESSION['reset_password_code'])) {
            $this->redirectResponder->redirect($this->urlGenerator->identification());
        }
        if ('lost' == $action && isset($_SESSION['reset_password_code'])) {
            $action = 'lost_code';
        }

        $title = Lang::t('Password Reset');
        $tpl   = TemplateRegistry::current();

        if ('lost' == $action) {
            $title       = Lang::t('Forgot your password?');
            $post_uoe    = $this->stringUtil->inputString('username_or_email', null, $_POST);
            if ($post_uoe !== null) {
                $tpl->assign('username_or_email', htmlspecialchars(stripslashes($post_uoe)));
            }
        } elseif ('reset' == $action && $first_login) {
            $title = Lang::t('Welcome');
            $tpl->assign('is_first_login', true);
        }

        PageState::current()->bodyId = 'thePasswordPage';
        $userLang = is_string($user['language'] ?? null) ? $user['language'] : '';
        $tpl->assign([
            'title'          => $title,
            'form_action'    => $this->urlGenerator->password(),
            'action'         => $action,
            'username'       => $username ?? ($user['username'] ?? ''),
            'PWG_TOKEN'      => $this->csrfService->getToken(),
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
        $this->dispatcher->dispatch(new LocEndPassword());
        $this->htmlService->flushPageMessages();
        $tpl->pparse('password.latte');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
