<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\RegisterPageContext;
use Piwigo\Controller\Request\RegisterSubmitRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocBeginRegister;
use Piwigo\Event\Location\LocEndRegister;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces register.php -- the user self-registration form + POST handler.
 * page_forbidden()/redirect() both happen before any rendering starts.
 *
 * Builds its response body via PageTail::renderToString(), the same
 * accumulator pattern AboutController uses -- see that class's own
 * docblock for the mechanics.
 */
final readonly class RegisterController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private FilterState $filterState,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private UserService $userService,
        private MailerInterface $mailer,
        private AuditService $auditService,
        private AuthService $authService,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private Translator $translator,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private PermissionService $permissionService,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Field-keyed, controller-local -- read by specific key
        // ('register_page_error'/'register_form_error') in register.latte, a
        // different shape than PageState::$errors' plain list<string>.
        $errors = [];

        // Http\ResponseEmitter::emit() always sends
        // `header(..., true, $response->getStatusCode())`, which
        // unconditionally overwrites any status a prior raw
        // HtmlService::setStatusHeader() call already sent. The real status
        // for an invalid/expired form key must therefore be threaded through
        // the Response object built at the end (`return
        // ResponseFactory::html($body, $status)`), not sent via a separate
        // setStatusHeader() call -- matching PictureController's own chosen
        // pattern (see that class's docblock).
        $status = 200;

        $this->accessControl->checkStatus(AccessLevel::Free);

        if (! $this->currentConfig->allowUserRegistration) {
            $this->htmlService
                ->pageForbidden($this->redirectService, 'User registration closed');
        }

        $this->eventDispatcher->dispatch(new LocBeginRegister());

        $registerSubmit = RegisterSubmitRequest::fromGlobals();

        if ($registerSubmit->isSubmitted) {
            if (! new EphemeralKeyService($this->currentConfig)->verify($registerSubmit->key)) {
                $status = 403;
                $errors['register_page_error'] = $this->lang->t('Invalid/expired form key');
            }

            $post_password_raw = $registerSubmit->password;
            $post_password_conf_raw = $registerSubmit->passwordConf;

            if ($post_password_raw === '' || $post_password_raw === '0') {
                $errors['register_form_error'] = $this->lang->t('Password is missing. Please enter the password.');
            } elseif ($post_password_conf_raw === '' || $post_password_conf_raw === '0') {
                $errors['register_form_error'] = $this->lang->t('Password confirmation is missing. Please confirm the chosen password.');
            } elseif ($post_password_raw !== $post_password_conf_raw) {
                $errors['register_form_error'] = $this->lang->t('The passwords do not match');
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
                $userService = $this->userService;
                $registration_result = $userService
                    ->registerUser(
                        $post_login,
                        $post_password,
                        $post_mail_address,
                        $this->urlService,
                        $this->mailer,
                        true,
                        $registerSubmit->sendPasswordByMail
                    );
                $registration_errors = $registration_result->errors;
                $new_user_id = $registration_result->userId;

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
                    if ($registerSubmit->sendPasswordByMail and InputValidator::checkEmailFormat($post_mail_address)) {
                        if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                            $_SESSION['page_infos'] = [];
                        }
                        $_SESSION['page_infos'][] = $this->lang->t('Successfully registered, you will soon receive an email with your connection settings. Welcome!');
                    }

                    // [SEC-31] Only a real new account gets logged in -- a
                    // duplicate username (userId: null) must never look up and
                    // log into the *existing* account by name here, which
                    // would be a full account-takeover, not just an
                    // information leak. Both cases redirect identically.
                    if ($new_user_id !== null) {
                        // This controller does not go through
                        // Bootstrap\UserBootstrap::initialize(), so the
                        // current-user sync below is required:
                        // ActivityService::record() attributes activity to
                        // whichever user CurrentUser currently resolves to,
                        // and AuthService::logUser() itself records a
                        // 'login' activity row internally. Setting the
                        // current user before calling logUser() (not after)
                        // is what makes that internal login row -- and every
                        // subsequent record() call in this request --
                        // attribute to this new user's own id instead of
                        // performed_by=NULL. Matches InstallWizard's own
                        // equivalent sync.
                        $this->currentUser->set(User::fromUserArray(
                            $userService->buildUser(UserId::from($new_user_id))
                        ));
                        $this->currentUser->markRealUserResolved();
                        $this->authService
                            ->logUser($new_user_id, false);
                    }
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }
            }
            $registration_post_key = new EphemeralKeyService($this->currentConfig)
                ->generate(2);
        } else {
            $registration_post_key = new EphemeralKeyService($this->currentConfig)
                ->generate(6);
        }

        $login = $registerSubmit->login !== '' && $registerSubmit->login !== '0' ? htmlspecialchars($registerSubmit->login) : '';

        $email = $registerSubmit->mailAddress !== null && $registerSubmit->mailAddress !== '' && $registerSubmit->mailAddress !== '0' ? htmlspecialchars($registerSubmit->mailAddress) : '';

        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $template = $this->currentTemplate->get();

        $title = $this->lang->t('Registration');
        $this->pageState->setBodyId('theRegisterPage');

        $themeconf = $template->getTemplateVars('themeconf');
        $hide_menu_on = is_array($themeconf) ? ($themeconf['hide_menu_on'] ?? null) : null;
        if (! is_array($hide_menu_on) or ! in_array('theRegisterPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $lang_cookie = $_COOKIE['lang'] ?? null;
        if ($lang_cookie !== null and (! is_string($lang_cookie) or $this->currentUser->get()->language->value !== $lang_cookie)) {
            if (! is_string($lang_cookie)) {
                $this->htmlService
                    ->fatalError('Invalid request parameter "lang"');
            }
            if (! array_key_exists($lang_cookie, LangService::getLanguages($this->paths, $this->entityManager))) {
                $this->htmlService
                    ->fatalError('Unrecognized value for parameter "lang"');
            }

            $this->currentUser->updateLanguage(LangCode::from($lang_cookie));
            $this->lang->load('common.lang', '', [
                'language' => $lang_cookie,
            ]);
        }

        $language_options = [];
        foreach (LangService::getLanguages($this->paths, $this->entityManager) as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        if (str_starts_with($this->currentUser->get()->language->value, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assignContext(new RegisterPageContext(
            homeUrl: $urlService->makeIndexUrl(),
            formKey: $registration_post_key,
            formAction: 'register.php',
            formLogin: $login,
            formEmail: $email,
            obligatoryUserMailAddress: $this->currentConfig->obligatoryUserMailAddress,
            languageOptions: $language_options,
            currentLanguage: $this->currentUser->get()
                ->language,
            helpLink: $help_link,
        ));

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatch(new LocEndRegister());
        $this->htmlService
            ->flushPageMessages();
        $this->htmlService
            ->flushKeyedErrors($errors);
        $template->parse('register.latte');
        $body = PageTail::renderToString();

        return ResponseFactory::html($body, $status);
    }
}
