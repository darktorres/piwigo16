<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Projection\PasswordPageContext;
use Piwigo\Controller\Request\PasswordRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocBeginPassword;
use Piwigo\Event\Location\LocEndPassword;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The "forgot password" verification-code + reset-key flow.
 *
 * Every redirect() in this file happens before any rendering starts.
 */
final class PasswordController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly FilterState $filterState,
        private readonly SectionContextRegistry $sectionContextRegistry,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly PasswordService $passwordService,
        private readonly AuthService $authService,
        private readonly PreferencesService $preferencesService,
        private readonly ApiKeyService $apiKeyService,
        private readonly HtmlService $htmlService,
        private readonly MailService $mailService,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Translator $translator,
        private readonly CurrentLogger $currentLogger,
        private readonly Paths $paths,
    ) {}

    /**
     * Field-keyed, controller-local -- read by specific key
     * ('password_page_error'/'password_form_error') in password.tpl, a
     * different shape than PageState::$errors' plain list<string>. Shared
     * across the private handler methods below via this property since
     * they're all called from the same __invoke(). The lockout branch of
     * processPasswordRequest() deliberately does NOT use this property --
     * see its own comment for why it flashes through
     * $_SESSION['page_errors'] instead.
     *
     * @var array<string, string>
     */
    private array $errors = [];

    /**
     * Local to __invoke() -- unlike $username below (set by both
     * __invoke() and processPasswordRequest()), $action is never read or
     * written outside this one method.
     */
    private ?string $action = null;

    private ?string $username = null;

    private PasswordRequest $request;

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->currentTemplate->get();

        $this->accessControl->checkStatus(AccessLevel::Free);

        $this->eventDispatcher->dispatchNotify(new LocBeginPassword());

        $this->request = PasswordRequest::fromGlobals($this->inputValidator);
        $action_param = $this->request->action;

        // ------------------------------------------------------- process form
        if ($this->request->isSubmitted) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlService, $this->redirectService);

            if ($action_param === 'lost') {
                if ($this->processVerificationCode()) {
                    $this->pageState->addInfo($this->lang->t('If your account exists, a verification code has been sent to your email address.'));
                    $this->action = 'lost_code';
                }
            }

            if ($action_param === 'lost_code') {
                if ($this->processPasswordRequest()) {
                    $this->pageState->addInfo($this->lang->t('Verification successful! You can now choose a new password.'));
                    $this->action = 'reset';
                }
            }

            if ($action_param === 'reset') {
                if ($this->resetPassword()) {
                    $this->action = 'reset_end';
                }
            }
        }

        // --------------------------------------------------------- key and action
        $first_login = false;
        $key_value = null;

        // a connected user can't reset the password from a mail
        $key = $this->request->key;
        if ($key !== null and ! $this->accessControl->isAGuest()) {
            $key = null;
        }

        if ($key !== null and ! $this->request->isSubmitted) {
            $user_id = $this->checkPasswordResetKey($key);
            if (is_int($user_id)) {
                $conn = DbConnection::build();
                $userdata = $this->userService->getUserData(UserId::from($user_id));
                $userdata_username = $userdata['username'] ?? null;
                $this->username = is_string($userdata_username) ? $userdata_username : '';
                $key_value = $key;
                $first_login = $this->authService->hasAlreadyLoggedIn($user_id, EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));

                if ($this->action === null) {
                    $this->action = 'reset';
                }
            } else {
                $this->action = 'none';
            }
        }

        if ($this->action === null) {
            if ($action_param === null) {
                $this->action = 'lost';
            } elseif (in_array($action_param, ['lost', 'lost_code', 'reset', 'none'], true)) {
                $this->action = $action_param;
            }
        }

        if ($this->action === 'reset') {
            if (($key === null and ($this->accessControl->isAGuest() or $this->accessControl->isGeneric())) and ! isset($_SESSION['valid_reset_password_code'])) {
                $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
            }
        }

        if ($this->action === 'lost' and ! $this->accessControl->isAGuest()) {
            $this->redirectService->redirect($this->urlService->getGalleryHomeUrl());
        }

        // ! isset($this->errors['password_form_error']): processPasswordRequest()
        // unsets $_SESSION['reset_password_code'] as part of 3 of its own
        // *inline* error branches (expired code, valid code with no
        // resolvable user_id, valid code but reset-not-allowed-for-user) --
        // each already queues a password_form_error message meant to render
        // right here on password.tpl, not to be silently discarded by this
        // "you never had a pending code at all" guard. Provably safe: this
        // guard is only ever reached with action==='lost_code' after a
        // submission when processPasswordRequest() returned false while the
        // session var WAS a valid array at call time (its only other return
        // path, `! is_array($state)`, returns true and sets action='reset'
        // instead, never reaching here) -- so a real, empty-handed stale GET
        // to ?action=lost_code (no error ever queued) is unaffected.
        if (
            $this->action === 'lost_code'
            and ! isset($_SESSION['reset_password_code'])
            and ! isset($this->errors['password_form_error'])
        ) {
            $this->redirectService->redirect($this->urlService->getGalleryHomeUrl() . 'identification.php');
        }

        if ($this->action === 'lost' and isset($_SESSION['reset_password_code'])) {
            $this->action = 'lost_code';
        }

        $formErrors = $this->errors;
        $action = $this->action;
        $username = $this->username;
        $urlService = $this->urlService;

        // $title is set and read entirely within this method (passed
        // straight into PageHeaderRenderer::render() below) -- no other
        // file reads $GLOBALS['title']. Plain local, not global.
        $title = $this->lang->t('Password Reset');
        $username_or_email_value = null;
        $is_first_login_value = null;
        if ($action === 'lost') {
            $title = $this->lang->t('Forgot your password?');

            if ($this->request->usernameOrEmailPresent) {
                $username_or_email_value = htmlspecialchars(stripslashes($this->request->usernameOrEmail));
            }
        } elseif ($action === 'reset' and $first_login) {
            $title = $this->lang->t('Welcome');
            $is_first_login_value = true;
        }

        $this->pageState->setBodyId('thePasswordPage');

        $template->setFilenames([
            'password' => 'password.tpl',
        ]);

        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('thePasswordPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $cookie_lang = $_COOKIE['lang'] ?? null;
        if (is_string($cookie_lang) and $this->currentUser->get()->language->value !== $cookie_lang) {
            if (! array_key_exists($cookie_lang, LangService::getLanguages($this->paths))) {
                $this->htmlService
                    ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }

            $this->currentUser->updateLanguage(LangCode::from($cookie_lang));
            $this->lang->load('common.lang', '', [
                'language' => $cookie_lang,
            ]);
        }

        $language_options = [];
        foreach (LangService::getLanguages($this->paths) as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        if (str_starts_with($this->currentUser->get()->language->value, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assignContext(new PasswordPageContext(
            key: $key_value,
            usernameOrEmail: $username_or_email_value,
            isFirstLogin: $is_first_login_value,
            title: $title,
            formAction: $urlService->getRootUrl() . 'password.php',
            action: $action,
            username: $username ?? $this->currentUser->get()
                ->username?->value,
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            languageOptions: $language_options,
            currentLanguage: $this->currentUser->get()
                ->language->value,
            helpLink: $help_link,
        ));

        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, $this->currentTemplate, $this->currentConfig);
        $this->eventDispatcher->dispatchNotify(new LocEndPassword());
        $this->htmlService
            ->flushPageMessages();
        $this->htmlService
            ->flushKeyedErrors($formErrors);
        $template->parse('password', false);
        $body = PageTail::renderToString();

        return ResponseFactory::html($body);
    }

    /**
     * checks the validity of input parameters, fills $this->errors,
     * and sends an email with the verification code (the caller,
     * __invoke(), adds the PageState success info once this returns true)
     */
    private function processVerificationCode(): bool
    {
        if (isset($_SESSION['reset_password_code'])) {
            return true;
        }

        // empty param
        $username_or_email = trim($this->request->usernameOrEmail);
        if ($username_or_email === '') {
            $this->errors['password_form_error'] = $this->lang->t('Invalid username or email');
            return false;
        }

        $conn = DbConnection::build();

        // retrievies user by email is not try by username
        $emailOrNull = Email::tryFrom($username_or_email);
        $user_id_raw = $emailOrNull instanceof Email ? $this->userService->getUserIdByEmail($emailOrNull) : null;

        if (! $user_id_raw instanceof UserId) {
            $usernameOrNull = Username::tryFrom($username_or_email);
            $user_id_raw = $usernameOrNull instanceof Username ? $this->userService->getUserId($usernameOrNull) : null;
        }

        // when no user is found, we assign guest_id instead of stopping.
        // this lets the function behave identically for unknown users,
        // preventing username/email enumeration through timing or responses.
        $is_user_found = $user_id_raw instanceof UserId;
        if ($user_id_raw instanceof UserId) {
            $user_id = $user_id_raw;
        } else {
            $user_id = UserId::from($this->currentConfig->guestId);
        }

        $userdata = $this->userService->getUserData($user_id);

        $status = $userdata['status'] ?? '';
        $status = is_string($status) ? $status : '';

        if ($is_user_found) {
            // block early for generic or guest user because we don't
            // consider theses users has sensible for username/email
            // enumeration
            if ($this->accessControl->isAGuest($status) or $this->accessControl->isGeneric($status)) {
                $this->errors['password_form_error'] = $this->lang->t('Password reset is not allowed for this user');
                return false;
            }

            // check lockout
            $preferences = $userdata['preferences'] ?? null;
            if (
                is_array($preferences)
                and isset($preferences['reset_password_forbidden_until'])
                and $preferences['reset_password_forbidden_until'] > time()
            ) {
                $this->errors['password_form_error'] = $this->lang->t('Too many attempts, please try later..');
                return false;
            }
        }

        // check if we want to skip email sending if user is guest, generic
        // or doesn't have email
        $email = $userdata['email'] ?? null;
        $email = is_string($email) ? $email : null;
        $skip_mail = ! $is_user_found || $email === null || $email === '';

        // send mail with verification code to user
        $language = $userdata['language'] ?? '';
        $language = is_string($language) ? $language : '';
        $this->mailService
            ->switchLangTo($language);
        $user_code = $this->authService->generateUserCode();
        $template_mail = $this->mailService
            ->generateCodeVerificationMail($user_code['code']);
        // $skip_mail already covers $email === null/''), so $email is
        // provably a non-empty string here.
        if (! $skip_mail) {
            $this->mailService
                ->mail($email, $template_mail->toArray());
        }
        $this->mailService
            ->switchLangBack();

        $_SESSION['reset_password_code'] = [
            'secret' => $user_code['secret'],
            'attempts' => 0,
            'user_id' => $is_user_found ? $user_id->value : null,
            'created_at' => time(),
            'ttl' => min($this->currentConfig->passwordResetCodeDuration, 900), // max 15 min
        ];

        return true;
    }

    /**
     * checks the validity of input parameters, fills $this->errors
     */
    private function processPasswordRequest(): bool
    {
        $conn = DbConnection::build();

        $state = $_SESSION['reset_password_code'] ?? null;
        if (! is_array($state)) {
            return true;
        }

        $created_at = $state['created_at'] ?? 0;
        $created_at = is_numeric($created_at) ? (int) $created_at : 0;
        $ttl = $state['ttl'] ?? 0;
        $ttl = is_numeric($ttl) ? (int) $ttl : 0;

        // check expired
        if (time() > $created_at + $ttl) {
            unset($_SESSION['reset_password_code']);
            $this->errors['password_form_error'] = $this->lang->t('Code expired');
            return false;
        }

        $attempts = $state['attempts'] ?? 0;
        $attempts = (is_numeric($attempts) ? (int) $attempts : 0) + 1;
        $state['attempts'] = $attempts;
        $_SESSION['reset_password_code'] = $state;

        $secret = $state['secret'] ?? '';
        $secret = is_string($secret) ? $secret : '';

        $user_id_raw = $state['user_id'] ?? null;
        // real user ids from get_userid_by_email()/get_userid() are always
        // positive, so is_numeric()+non-zero is the strict-comparison-safe
        // equivalent of the legacy `!empty($user_id_raw) && is_numeric(...)`
        // pair (empty('0')/empty(0) are both true in PHP).
        $has_valid_user_id = is_numeric($user_id_raw) && (int) $user_id_raw !== 0;

        $is_valid = true;
        $user_code = trim($this->request->userCode);

        if (
            $user_code === '' // empty user code
            || ! (bool) preg_match('/^\d{6}$/', $user_code) // check digit 6
            || ! $this->authService->verifyUserCode($secret, $user_code)) { // verify user code
            $is_valid = false;
        }

        if (! $is_valid) {
            if ($attempts >= 3) {
                unset($_SESSION['reset_password_code']);
                // lockout account for 1hour
                if ($has_valid_user_id) {
                    $saveCurrentUser = $this->currentUser->get();
                    $target_user_data = $this->userService->buildUser(UserId::from((int) $user_id_raw));
                    // PreferencesService writes onto CurrentUser::get()->id,
                    // so the identity must switch here too, or the
                    // preference would land on the ORIGINAL requester
                    // instead of the locked-out target user.
                    $this->currentUser->set(User::fromUserArray($target_user_data));
                    $this->preferencesService
                        ->updateParam('reset_password_forbidden_until', time() + 60 * 60);
                    $this->currentUser->set($saveCurrentUser);

                    $this->activityService->record('user', (int) $user_id_raw, 'reset_password_failure_too_many');
                }
                // Not $this->errors: this branch redirects to
                // identification.php (see __invoke()'s own
                // ! isset($_SESSION['reset_password_code']) guard, now
                // deliberately still allowed to fire here since no
                // password_form_error is queued) -- $this->errors is local
                // to this request and would be discarded by that redirect.
                // $_SESSION['page_errors'] is the same flash channel
                // CommentsController's own moderate/redirect flow uses;
                // HtmlService::flushMessageMode() merges it in regardless of
                // which controller's flushPageMessages() call reads it, so
                // identification.php's own render picks this up for real.
                if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                    $_SESSION['page_errors'] = [];
                }
                $_SESSION['page_errors'][] = $this->lang->t('Too many attempts, please try later..');
                return false;
            }

            if ($has_valid_user_id) {
                $this->activityService->record('user', (int) $user_id_raw, 'reset_password_failure_code');
            }
            $this->errors['password_form_error'] = $this->lang->t('Invalid verification code');
            return false;
        }

        // verify code success
        unset($_SESSION['reset_password_code']);

        if (! $has_valid_user_id) {
            $this->errors['password_form_error'] = $this->lang->t('Invalid verification code');
            return false;
        }
        $user_id = UserId::from((int) $user_id_raw);

        $saveCurrentUser = $this->currentUser->get();
        $target_user_data = $this->userService->buildUser($user_id);
        // Same CurrentUser identity-switch requirement as the lockout branch
        // above -- PreferencesService::deleteParam() writes onto
        // CurrentUser::get()->id.
        $this->currentUser->set(User::fromUserArray($target_user_data));
        $this->preferencesService
            ->deleteParam('reset_password_forbidden_until');

        $targetUser = $this->currentUser->get();
        $_SESSION['valid_reset_password_code'] = [
            'user_id' => $user_id->value,
            'username' => $targetUser->username?->value,
            'email' => $targetUser->email?->value,
            'language' => $targetUser->language->value,
        ];
        $status = $targetUser->status->value;
        $has_no_email = ! $targetUser->email instanceof Email;
        $this->username = $targetUser->username?->value;
        $this->currentUser->set($saveCurrentUser);

        // fallback check: don't send mail when user is guest, generic or
        // doesn't have email
        if ($this->accessControl->isAGuest($status) || $this->accessControl->isGeneric($status) || $has_no_email) {
            $this->errors['password_form_error'] = $this->lang->t('Password reset is not allowed for this user');
            return false;
        }

        return true;
    }

    /**
     * checks the activation key: does it match the expected pattern? is it
     * linked to a user? is this user allowed to reset his password?
     *
     * The return value is always false or the matching row's user_id (a
     * NOT NULL int primary key, via ActivationKeyRow's own UserId
     * narrowing) -- matches resetPasswordKey()'s own already-real return
     * type.
     *
     * @return false|int user_id if OK, false otherwise
     */
    private function checkPasswordResetKey(?string $reset_key): false|int
    {
        $key = $reset_key ?? '';
        if (! (bool) preg_match('/^[a-z0-9]{20}$/i', $key)) {
            $this->errors['password_page_error'] = $this->lang->t('Invalid key');
            return false;
        }

        $user_id = null;
        foreach ($this->userService->getPendingActivationKeyRows() as $activationKeyRow) {
            if ($activationKeyRow->activationKey === '') {
                continue;
            }
            if ($this->passwordService->verify($key, $activationKeyRow->activationKey)) {
                if ($this->accessControl->isAGuest($activationKeyRow->status) or $this->accessControl->isGeneric($activationKeyRow->status)) {
                    $this->errors['password_page_error'] = $this->lang->t('Password reset is not allowed for this user');
                    return false;
                }

                $user_id = $activationKeyRow->userId->value;
                break;
            }
        }

        if ($user_id === null) {
            $this->errors['password_page_error'] = $this->lang->t('Invalid key');
            return false;
        }

        return $user_id;
    }

    /**
     * checks the passwords, checks that user is allowed to reset his
     * password, update password, fills $this->errors and PageState's infos.
     */
    private function resetPassword(): bool
    {
        $new_password = $this->request->newPassword;
        $password_conf = $this->request->passwordConf;

        if ($new_password !== $password_conf) {
            $this->errors['password_form_error'] = $this->lang->t('The passwords do not match');
            return false;
        }

        $reset_password_key_result = $this->resetPasswordKey();
        $user_id = (bool) $reset_password_key_result ? $reset_password_key_result : $this->resetPasswordCode();

        if (! is_numeric($user_id)) {
            $this->errors['password_form_error'] = $this->lang->t('Invalid key or code');
            return false;
        }

        $conn = DbConnection::build();

        $this->userService->updateAccountFields(
            UserId::from($user_id),
            null,
            $this->passwordService->hash($new_password),
            null,
        );

        $reset_session = $_SESSION['valid_reset_password_code'] ?? null;
        $reset_session_email = is_array($reset_session) ? ($reset_session['email'] ?? null) : null;
        if (is_array($reset_session) and is_string($reset_session_email) and $reset_session_email !== '') {
            $reset_language = $reset_session['language'] ?? '';
            $reset_language = is_string($reset_language) ? $reset_language : '';
            $this->mailService
                ->switchLangTo($reset_language);

            $reset_user_id = $reset_session['user_id'] ?? null;
            $reset_user_id_int = is_numeric($reset_user_id) ? (int) $reset_user_id : 0;
            $api_keys = $this->apiKeyService
                ->getAvailable($reset_user_id_int);
            $nb_of_apikeys = (bool) $api_keys ? count($api_keys) : 0;

            $reset_username = $reset_session['username'] ?? '';
            $reset_username = is_string($reset_username) ? $reset_username : '';
            $template_mail = $this->mailService
                ->generateSuccessResetPasswordMail($reset_username, $nb_of_apikeys);

            // is_string($reset_session_email)/!== '' above already
            // guarantees this is a non-empty string.
            $reset_email = $reset_session_email;
            $this->mailService
                ->mail($reset_email, $template_mail->toArray());

            $this->mailService
                ->switchLangBack();
        }
        unset($_SESSION['valid_reset_password_code']);

        $this->activityService->record('user', $user_id, 'reset_password_success');

        $this->pageState->addInfo($this->lang->t('Your password has been reset'));
        $this->pageState->addInfo('<a href="' . $this->urlService->getRootUrl() . 'identification.php">' . $this->lang->t('Login') . '</a>');

        return true;
    }

    private function resetPasswordKey(): false|int
    {
        // Read directly from the request, not the guest-nulled $key:
        // this method is only ever reached via the submit-processing path
        // (resetPassword(), called from __invoke()'s own "process form"
        // block), which runs strictly before __invoke() computes the
        // nulled effective key below -- same execution order the original
        // relied on (its unset($_GET['key']) mutation happened later in
        // the method body than this call chain).
        if ($this->request->key === null) {
            return false;
        }

        $user_id = $this->checkPasswordResetKey($this->request->key);

        if (! is_int($user_id)) {
            return false;
        }

        $conn = DbConnection::build();
        $this->authService->deactivatePasswordResetKey($user_id);
        $this->authService->deactivateUserAuthKeys($user_id);
        return $user_id;
    }

    /**
     * $_SESSION['valid_reset_password_code']['user_id'] is only ever
     * written as a real int (processPasswordRequest(), its sole writer) --
     * narrowed defensively rather than trusted, since this is still a
     * round-trip through session state.
     */
    private function resetPasswordCode(): false|int
    {
        $state = $_SESSION['valid_reset_password_code'] ?? null;
        if (! is_array($state)) {
            return false;
        }

        $user_id = $state['user_id'] ?? null;

        return is_int($user_id) ? $user_id : false;
    }
}
