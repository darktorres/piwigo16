<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocBeginRegister;
use Piwigo\Event\Location\LocEndRegister;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces register.php -- the user self-registration form + POST handler.
 * page_forbidden()/redirect() both happen before any rendering starts.
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class RegisterController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\FilterState $filterState,
        private readonly \Piwigo\Section\SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Audit\AuditService $auditService,
        private readonly \Piwigo\Auth\AuthService $authService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Field-keyed, controller-local -- read by specific key
        // ('register_page_error'/'register_form_error') in register.tpl, a
        // different shape than PageState::$errors' plain list<string>.
        $errors = [];

        // Real bug, found while adding coverage for the invalid-key branch
        // below: same root cause as PictureController's own documented
        // recent_pics fix (see that class's docblock) -- Http\ResponseEmitter::
        // emit() always sends `header(..., true, $response->getStatusCode())`,
        // which unconditionally overwrites whatever a prior raw
        // HtmlService::setStatusHeader() call already sent. This method's own
        // final `return ResponseFactory::html($body)` defaults to 200, so a
        // bare setStatusHeader(403) call here was silently dead -- confirmed
        // live, an invalid/expired form key came back 200, never the intended
        // 403. Threading the real status through the Response object built at
        // the end (instead of a separate, now-dead setStatusHeader() call) is
        // the fix, matching PictureController's own chosen pattern.
        $status = 200;

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        if (! \Piwigo\Config\CurrentConfig::allowUserRegistration()) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->pageForbidden($this->redirectService, 'User registration closed');
        }

        $this->eventDispatcher->dispatchNotify(new LocBeginRegister());

        $registerSubmit = Request\RegisterSubmitRequest::fromGlobals();

        if ($registerSubmit->isSubmitted) {
            if (! new \Piwigo\Auth\EphemeralKeyService()->verify($registerSubmit->key)) {
                $status = 403;
                $errors['register_page_error'] = Lang::t('Invalid/expired form key');
            }

            $post_password_raw = $registerSubmit->password;
            $post_password_conf_raw = $registerSubmit->passwordConf;

            if ($post_password_raw === '' || $post_password_raw === '0') {
                $errors['register_form_error'] = Lang::t('Password is missing. Please enter the password.');
            } elseif ($post_password_conf_raw === '' || $post_password_conf_raw === '0') {
                $errors['register_form_error'] = Lang::t('Password confirmation is missing. Please confirm the chosen password.');
            } elseif ($post_password_raw !== $post_password_conf_raw) {
                $errors['register_form_error'] = Lang::t('The passwords do not match');
            }

            $post_login = $registerSubmit->login;
            $post_password = $post_password_raw;
            $post_mail_address = $registerSubmit->mailAddress;

            // Only attempt the actual registration once the form itself is
            // valid (real key, matching non-empty passwords) -- registerUser()
            // creates a real account using $post_password alone, with no
            // knowledge of $post_password_conf_raw, so calling it while
            // $errors is already non-empty would silently create an account
            // with a password the requester never confirmed (or despite an
            // invalid/expired key) while showing them an error that implies
            // nothing happened.
            if ($errors === []) {
                // [SEC-31] registerUser() never puts a "this login is already
                // used" message in $errors -- a duplicate username comes back
                // as duplicateUsername: true with userId: null instead, and is
                // handled identically to a real success below (same redirect,
                // same flash message), so a requester probing for taken
                // usernames can't tell the two apart from the response. See
                // UserService::registerUser()'s own docblock for the full
                // rationale (this is also why an existing account gets a
                // "someone tried to register your username" email instead of
                // the requester ever seeing an error here).
                $conn = DbConnection::build();
                $userService = $this->userService;
                $registration_result = $userService
                    ->registerUser(
                        $post_login,
                        $post_password,
                        $post_mail_address,
                        $this->urlService,
                        true,
                        $registerSubmit->sendPasswordByMail
                    );
                $registration_errors = $registration_result['errors'];
                $new_user_id = $registration_result['userId'];

                // [SEC-57] Only a real new account is audit-logged -- a
                // duplicate username (userId: null) never reaches here, same
                // "don't reveal/don't act on a duplicate as if it were real"
                // discipline as the auto-login gate below. Actor is the new
                // user themselves (self-registration has no separate acting
                // admin); only the username is recorded, never the password.
                if ($new_user_id !== null) {
                    $this->auditService
                        ->record($new_user_id, 'create', 'user', $new_user_id, null, [
                            'username' => $post_login,
                        ]);
                }

                if ($registration_errors !== []) {
                    $errors['register_form_error'] = implode(' ', $registration_errors);
                }

                if (count($errors) === 0) {
                    // email notification
                    if ($registerSubmit->sendPasswordByMail and \Piwigo\Validation\InputValidator::checkEmailFormat($post_mail_address)) {
                        if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                            $_SESSION['page_infos'] = [];
                        }
                        $_SESSION['page_infos'][] = Lang::t('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                    }

                    // [SEC-31] Only a real new account gets logged in -- a
                    // duplicate username (userId: null) must never look up and
                    // log into the *existing* account by name here, which
                    // would be a full account-takeover, not just an
                    // information leak. Both cases redirect identically.
                    if ($new_user_id !== null) {
                        // Same real bug as InstallWizard's identical fix: this
                        // controller never goes through
                        // Bootstrap\UserBootstrap::initialize() either, so
                        // without this sync, ActivityService::record()
                        // misattributes this new user's own activity -- including
                        // the 'login' row logUser() itself records internally,
                        // which is why this must run BEFORE calling it, not
                        // after -- to performed_by=NULL instead of their own new id.
                        $this->currentUser->set(\Piwigo\Users\User::fromUserArray(
                            $userService->buildUser(\Piwigo\Common\ValueObject\UserId::from($new_user_id))
                        ));
                        $this->currentUser->markRealUserResolved();
                        $this->authService
                            ->logUser($new_user_id, false);
                    }
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }
            }
            $registration_post_key = new \Piwigo\Auth\EphemeralKeyService()
                ->generate(2);
        } else {
            $registration_post_key = new \Piwigo\Auth\EphemeralKeyService()
                ->generate(6);
        }

        $login = $registerSubmit->login !== '' && $registerSubmit->login !== '0' ? htmlspecialchars(stripslashes($registerSubmit->login)) : '';

        $email = $registerSubmit->mailAddress !== null && $registerSubmit->mailAddress !== '' && $registerSubmit->mailAddress !== '0' ? htmlspecialchars(stripslashes($registerSubmit->mailAddress)) : '';

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $title = Lang::t('Registration');
        $this->pageState->setBodyId('theRegisterPage');

        $template->set_filenames([
            'register' => 'register.tpl',
        ]);
        $template->assign([
            'U_HOME' => $urlService->makeIndexUrl(),
            'F_KEY' => $registration_post_key,
            'F_ACTION' => 'register.php',
            'F_LOGIN' => $login,
            'F_EMAIL' => $email,
            'obligatory_user_mail_address' => \Piwigo\Config\CurrentConfig::obligatoryUserMailAddress(),
        ]);

        $themeconf = $template->get_template_vars('themeconf');
        $hide_menu_on = is_array($themeconf) ? ($themeconf['hide_menu_on'] ?? null) : null;
        if (! is_array($hide_menu_on) or ! in_array('theRegisterPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $lang_cookie = $_COOKIE['lang'] ?? null;
        if ($lang_cookie !== null and (! is_string($lang_cookie) or $this->currentUser->get()->language !== $lang_cookie)) {
            if (! is_string($lang_cookie)) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "lang" is not valid');
            }
            if (! array_key_exists($lang_cookie, \Piwigo\Lang\LangService::getLanguages())) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "' . $lang_cookie . '" is not valid');
            }

            $this->currentUser->updateLanguage($lang_cookie);
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
            'current_language' => $this->currentUser->get()
                ->language,
        ]);

        if (str_starts_with($this->currentUser->get()->language, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assign('HELP_LINK', $help_link);

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate);
        $this->eventDispatcher->dispatchNotify(new LocEndRegister());
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushKeyedErrors($errors);
        $template->parse('register');
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body, $status);
    }
}
