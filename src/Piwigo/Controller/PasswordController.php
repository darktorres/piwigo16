<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces password.php -- the "forgot password" verification-code +
 * reset-key flow. The legacy file's own 6 top-level functions
 * (process_verification_code()/process_password_request()/
 * check_password_reset_key()/reset_password()/reset_password_key()/
 * reset_password_code()) become private methods here instead of free
 * functions -- confirmed via a project-wide grep that nothing outside this
 * one file calls any of them (tools/triggers_list.php's own reference is
 * static trigger-name metadata, not a real call site).
 *
 * Every redirect() in this file happens before any rendering starts.
 *
 * Legacy Coupling Retirement Workstream D: converted off
 * LegacyRenderCapture's ob_start()/ob_get_contents() capture, same
 * pattern as AboutController -- see that class's own docblock for the
 * accumulator mechanics this relies on.
 */
final class PasswordController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * Field-keyed, controller-local -- read by specific key
     * ('password_page_error'/'password_form_error'/'login_page_error') in
     * password.tpl, a different shape than PageState::$errors' plain
     * list<string>. Shared across the private handler methods below via
     * this property since they're all called from the same __invoke().
     *
     * @var array<string, string>
     */
    private array $errors = [];

    /**
     * Shared across __invoke()/processPasswordRequest() (the latter is
     * called from the former) -- same "multiple methods of this class"
     * shape as $errors above.
     */
    private ?string $action = null;

    private ?string $username = null;

    private static function activityService(Connection $conn): \Piwigo\Activity\ActivityService
    {
        return \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService();
    }

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    private static function passwordService(): PasswordService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::passwordService();
    }

    private static function authService(): AuthService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::authService();
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_password');

        new \Piwigo\Validation\InputValidator()
            ->validate('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');
        $action_param = is_string($_GET['action'] ?? null) ? $_GET['action'] : null;

        // ------------------------------------------------------- process form
        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            if ($action_param === 'lost') {
                if ($this->processVerificationCode()) {
                    \Piwigo\Core\PageState::current()->addInfo(Lang::t('If your account exists, a verification code has been sent to your email address.'));
                    $this->action = 'lost_code';
                }
            }

            if ($action_param === 'lost_code') {
                if ($this->processPasswordRequest()) {
                    \Piwigo\Core\PageState::current()->addInfo(Lang::t('Verification successful! You can now choose a new password.'));
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

        // a connected user can't reset the password from a mail
        if (isset($_GET['key']) and ! \Piwigo\Auth\AccessControl::isAGuest()) {
            unset($_GET['key']);
        }

        if (isset($_GET['key']) and ! isset($_POST['submit'])) {
            $user_id = $this->checkPasswordResetKey($_GET['key']);
            if (is_numeric($user_id)) {
                $conn = DbConnection::build();
                $userdata = self::userService()->getUserData((int) $user_id);
                $userdata_username = $userdata['username'] ?? null;
                $this->username = is_string($userdata_username) ? $userdata_username : '';
                $template->assign('key', $_GET['key']);
                $first_login = self::authService()->hasAlreadyLoggedIn((int) $user_id);

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
            if ((! isset($_GET['key']) and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isGeneric())) and ! isset($_SESSION['valid_reset_password_code'])) {
                $gallery_home_url = $this->urlService->getGalleryHomeUrl();
                $this->redirectService->redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
            }
        }

        if ($this->action === 'lost' and ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $gallery_home_url = $this->urlService->getGalleryHomeUrl();
            $this->redirectService->redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
        }

        if ($this->action === 'lost_code' and ! isset($_SESSION['reset_password_code'])) {
            $gallery_home_url = $this->urlService->getGalleryHomeUrl();
            $gallery_home_url = is_string($gallery_home_url) ? $gallery_home_url : '';
            $this->redirectService->redirect($gallery_home_url . 'identification.php');
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
        $title = Lang::t('Password Reset');
        if ($action === 'lost') {
            $title = Lang::t('Forgot your password?');

            if (isset($_POST['username_or_email']) and is_string($_POST['username_or_email'])) {
                $template->assign('username_or_email', htmlspecialchars(stripslashes($_POST['username_or_email'])));
            }
        } elseif ($action === 'reset' and $first_login) {
            $title = Lang::t('Welcome');
            $template->assign('is_first_login', true);
        }

        \Piwigo\Core\PageState::current()->setBodyId('thePasswordPage');

        $template->set_filenames([
            'password' => 'password.tpl',
        ]);
        $template->assign(
            [
                'title' => $title,
                'form_action' => $urlService->getRootUrl() . 'password.php',
                'action' => $action,
                'username' => $username ?? \Piwigo\Users\CurrentUser::get()->username,
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        $hide_menu_on = $themeconf['hide_menu_on'] ?? null;
        if (! is_array($hide_menu_on) or ! in_array('thePasswordPage', $hide_menu_on, true)) {
            new MenubarRenderer()
                ->render($urlService);
        }

        // Load language if cookie is set from login/register/password
        // pages
        $cookie_lang = $_COOKIE['lang'] ?? null;
        if (is_string($cookie_lang) and \Piwigo\Users\CurrentUser::get()->language !== $cookie_lang) {
            if (! array_key_exists($cookie_lang, \Piwigo\Lang\LangService::getLanguages())) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
            }

            \Piwigo\Users\CurrentUser::updateLanguage($cookie_lang);
            Lang::load('common.lang', '', [
                'language' => $cookie_lang,
            ]);
        }

        $language_options = [];
        foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
            $language_options[$language_code] = $language_name;
        }

        $template->assign([
            'language_options' => $language_options,
            'current_language' => \Piwigo\Users\CurrentUser::get()->language,
        ]);

        if (str_starts_with(\Piwigo\Users\CurrentUser::get()->language, 'fr')) {
            $help_link = 'https://upstream.example.invalid/help/fr/';
        } else {
            $help_link = 'https://upstream.example.invalid/help/';
        }

        $template->assign('HELP_LINK', $help_link);

        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title);
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_password');
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushKeyedErrors($formErrors);
        $template->parse('password', false);
        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }

    /**
     * checks the validity of input parameters, fills $this->errors and
     * PageState's infos, and sends an email with the verification code
     */
    private function processVerificationCode(): bool
    {
        if (isset($_SESSION['reset_password_code'])) {
            return true;
        }

        // empty param
        $username_or_email_raw = $_POST['username_or_email'] ?? '';
        $username_or_email = is_string($username_or_email_raw) ? trim($username_or_email_raw) : '';
        if ($username_or_email === '') {
            $this->errors['password_form_error'] = Lang::t('Invalid username or email');
            return false;
        }

        $conn = DbConnection::build();

        // retrievies user by email is not try by username
        $user_id_raw = self::userService()->getUserIdByEmail($username_or_email);

        if (! is_numeric($user_id_raw)) {
            $user_id_raw = self::userService()->getUserId($username_or_email);
        }

        // when no user is found, we assign guest_id instead of stopping.
        // this lets the function behave identically for unknown users,
        // preventing username/email enumeration through timing or responses.
        $is_user_found = is_numeric($user_id_raw);
        if (is_numeric($user_id_raw)) {
            $user_id = $user_id_raw;
        } else {
            $guest_id = \Piwigo\Config\CurrentConfig::guestId();
            $user_id = $guest_id;
        }

        $userdata = self::userService()->getUserData($user_id);

        $status = $userdata['status'] ?? '';
        $status = is_string($status) ? $status : '';

        if ($is_user_found) {
            // block early for generic or guest user because we don't
            // consider theses users has sensible for username/email
            // enumeration
            if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                $this->errors['password_form_error'] = Lang::t('Password reset is not allowed for this user');
                return false;
            }

            // check lockout
            $preferences = $userdata['preferences'] ?? null;
            if (
                is_array($preferences)
                and isset($preferences['reset_password_forbidden_until'])
                and $preferences['reset_password_forbidden_until'] > time()
            ) {
                $this->errors['password_form_error'] = Lang::t('Too many attempts, please try later..');
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
        \Piwigo\Bootstrap\PresentationAccessor::mailService()
            ->switchLangTo($language);
        $user_code = \Piwigo\Auth\AuthService::generateUserCode();
        $template_mail = \Piwigo\Bootstrap\PresentationAccessor::mailService()
            ->generateCodeVerificationMail($user_code['code']);
        // $skip_mail already covers $email === null/''), so $email is
        // provably a non-empty string here.
        if (! $skip_mail) {
            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                ->mail($email, $template_mail);
        }
        \Piwigo\Bootstrap\PresentationAccessor::mailService()
            ->switchLangBack();

        $_SESSION['reset_password_code'] = [
            'secret' => $user_code['secret'],
            'attempts' => 0,
            'user_id' => $is_user_found ? $user_id : null,
            'created_at' => time(),
            'ttl' => min(\Piwigo\Config\CurrentConfig::passwordResetCodeDuration(), 900), // max 15 min
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
            $this->errors['password_form_error'] = Lang::t('Code expired');
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
        $user_code_raw = $_POST['user_code'] ?? '';
        $user_code = is_string($user_code_raw) ? trim($user_code_raw) : '';

        if (
            $user_code === '' // empty user code
            || ! (bool) preg_match('/^\d{6}$/', $user_code) // check digit 6
            || ! \Piwigo\Auth\AuthService::verifyUserCode($secret, $user_code)) { // verify user code
            $is_valid = false;
        }

        if (! $is_valid) {
            if ($attempts >= 3) {
                unset($_SESSION['reset_password_code']);
                // lockout account for 1hour
                if ($has_valid_user_id) {
                    $saveCurrentUser = \Piwigo\Users\CurrentUser::get();
                    $target_user_data = self::userService()->buildUser((int) $user_id_raw);
                    // PreferencesService writes onto CurrentUser::get()->id
                    // (Legacy Coupling Retirement Track A batch A3), so the
                    // identity must switch here too, or the preference
                    // would land on the ORIGINAL requester instead of the
                    // locked-out target user.
                    \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($target_user_data));
                    \Piwigo\Bootstrap\CoreDomainAccessor::preferencesService()
                        ->updateParam('reset_password_forbidden_until', time() + 60 * 60);
                    \Piwigo\Users\CurrentUser::set($saveCurrentUser);

                    self::activityService($conn)->record('user', (int) $user_id_raw, 'reset_password_failure_too_many');
                }
                $this->errors['login_page_error'] = Lang::t('Too many attempts, please try later..');
                return false;
            }

            if ($has_valid_user_id) {
                self::activityService($conn)->record('user', (int) $user_id_raw, 'reset_password_failure_code');
            }
            $this->errors['password_form_error'] = Lang::t('Invalid verification code');
            return false;
        }

        // verify code success
        unset($_SESSION['reset_password_code']);

        if (! $has_valid_user_id) {
            $this->errors['password_form_error'] = Lang::t('Invalid verification code');
            return false;
        }
        $user_id = (int) $user_id_raw;

        $saveCurrentUser = \Piwigo\Users\CurrentUser::get();
        $target_user_data = self::userService()->buildUser($user_id);
        // Same CurrentUser identity-switch requirement as the lockout branch
        // above -- PreferencesService::deleteParam() writes onto
        // CurrentUser::get()->id.
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($target_user_data));
        \Piwigo\Bootstrap\CoreDomainAccessor::preferencesService()
            ->deleteParam('reset_password_forbidden_until');

        $targetUser = \Piwigo\Users\CurrentUser::get();
        $_SESSION['valid_reset_password_code'] = [
            'user_id' => $user_id,
            'username' => $targetUser->username,
            'email' => $targetUser->email,
            'language' => $targetUser->language,
        ];
        $status = $targetUser->status->value;
        $has_no_email = $targetUser->email === '';
        $this->username = $targetUser->username;
        \Piwigo\Users\CurrentUser::set($saveCurrentUser);

        // fallback check: don't send mail when user is guest, generic or
        // doesn't have email
        if (\Piwigo\Auth\AccessControl::isAGuest($status) || \Piwigo\Auth\AccessControl::isGeneric($status) || $has_no_email) {
            $this->errors['password_form_error'] = Lang::t('Password reset is not allowed for this user');
            return false;
        }

        return true;
    }

    /**
     * checks the activation key: does it match the expected pattern? is it
     * linked to a user? is this user allowed to reset his password?
     *
     * $reset_key stays mixed -- raw $_GET['key'] at both real call sites,
     * flagged for Phase 4 (SEC-40/P27 Request DTOs). The return value is
     * always false or $row['user_id'] (a NOT NULL int primary key,
     * verified is_numeric() just before every return), never genuinely
     * mixed -- matches resetPasswordKey()'s own already-real return type.
     *
     * @return false|int|string user_id if OK, false otherwise
     */
    private function checkPasswordResetKey(mixed $reset_key): false|int|string
    {
        $key = is_string($reset_key) ? $reset_key : '';
        if (! (bool) preg_match('/^[a-z0-9]{20}$/i', $key)) {
            $this->errors['password_page_error'] = Lang::t('Invalid key');
            return false;
        }

        $query = '
SELECT
    user_id,
    status,
    activation_key
  FROM ' . Tables::userInfos() . '
  WHERE activation_key IS NOT NULL
    AND activation_key_expire > NOW()
;';
        $conn = DbConnection::build();
        $user_id = null;
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $activation_key = $row['activation_key'] ?? null;
            if (! is_string($activation_key)) {
                continue;
            }
            if (self::passwordService()->verify($key, $activation_key)) {
                $status = $row['status'] ?? null;
                $status = is_string($status) ? $status : '';
                if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                    $this->errors['password_page_error'] = Lang::t('Password reset is not allowed for this user');
                    return false;
                }

                $user_id = $row['user_id'];
                break;
            }
        }

        if (! is_numeric($user_id) || (int) $user_id === 0) {
            $this->errors['password_page_error'] = Lang::t('Invalid key');
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
        $new_password_raw = $_POST['use_new_pwd'] ?? '';
        $new_password = is_string($new_password_raw) ? $new_password_raw : '';
        $password_conf_raw = $_POST['passwordConf'] ?? '';
        $password_conf = is_string($password_conf_raw) ? $password_conf_raw : '';

        if ($new_password !== $password_conf) {
            $this->errors['password_form_error'] = Lang::t('The passwords do not match');
            return false;
        }

        $reset_password_key_result = $this->resetPasswordKey();
        $user_id = (bool) $reset_password_key_result ? $reset_password_key_result : $this->resetPasswordCode();

        if (! is_numeric($user_id)) {
            $this->errors['password_form_error'] = Lang::t('Invalid key or code');
            return false;
        }

        // see validate_mail_address() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $conn = DbConnection::build();

        new BatchWriter($conn)
            ->singleUpdate(
                Tables::users(),
                [
                    $user_fields['password'] => self::passwordService()->hash($new_password),
                ],
                [
                    $user_fields['id'] => $user_id,
                ]
            );

        $reset_session = $_SESSION['valid_reset_password_code'] ?? null;
        $reset_session_email = is_array($reset_session) ? ($reset_session['email'] ?? null) : null;
        if (is_array($reset_session) and is_string($reset_session_email) and $reset_session_email !== '') {
            $reset_language = $reset_session['language'] ?? '';
            $reset_language = is_string($reset_language) ? $reset_language : '';
            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                ->switchLangTo($reset_language);

            $reset_user_id = $reset_session['user_id'] ?? null;
            $reset_user_id_int = is_numeric($reset_user_id) ? (int) $reset_user_id : 0;
            $api_keys = \Piwigo\Bootstrap\CoreDomainAccessor::apiKeyService()
                ->getAvailable($reset_user_id_int);
            $nb_of_apikeys = (bool) $api_keys ? count($api_keys) : 0;

            $reset_username = $reset_session['username'] ?? '';
            $reset_username = is_string($reset_username) ? $reset_username : '';
            $template_mail = \Piwigo\Bootstrap\PresentationAccessor::mailService()
                ->generateSuccessResetPasswordMail($reset_username, $nb_of_apikeys);

            // is_string($reset_session_email)/!== '' above already
            // guarantees this is a non-empty string.
            $reset_email = $reset_session_email;
            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                ->mail($reset_email, $template_mail);

            \Piwigo\Bootstrap\PresentationAccessor::mailService()
                ->switchLangBack();
        }
        unset($_SESSION['valid_reset_password_code']);

        self::activityService($conn)->record('user', (int) $user_id, 'reset_password_success');

        \Piwigo\Core\PageState::current()->addInfo(Lang::t('Your password has been reset'));
        \Piwigo\Core\PageState::current()->addInfo('<a href="' . $this->urlService->getRootUrl() . 'identification.php">' . Lang::t('Login') . '</a>');

        return true;
    }

    private function resetPasswordKey(): false|float|int|string
    {
        if (! isset($_GET['key'])) {
            return false;
        }

        $user_id = $this->checkPasswordResetKey($_GET['key']);

        if (! is_numeric($user_id)) {
            return false;
        }

        $conn = DbConnection::build();
        self::authService()->deactivatePasswordResetKey((int) $user_id);
        self::authService()->deactivateUserAuthKeys((int) $user_id);
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
