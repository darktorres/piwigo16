<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
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
 * Every redirect() in this file happens before any rendering starts, so
 * all of the "process form"/"key and action" business logic stays outside
 * the captured closure -- same exit()-based-termination limitation as
 * every other controller this phase.
 */
final class PasswordController implements ControllerInterface
{
    /**
     * Field-keyed, controller-local -- read by specific key
     * ('password_page_error'/'password_form_error'/'login_page_error') in
     * password.tpl, a different shape than PageState::$errors' plain
     * list<string>. Shared across the private handler methods below via
     * this property since they're all called from the same __invoke().
     *
     * @var array<string, mixed>
     */
    private array $errors = [];

    /**
     * Shared across __invoke()/processPasswordRequest() (the latter is
     * called from the former) -- same "multiple methods of this class"
     * shape as $errors above.
     */
    private ?string $action = null;

    private ?string $username = null;

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        trigger_notify('loc_begin_password');

        new \Piwigo\Validation\InputValidator()
            ->validate('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');
        $action_param = is_string($_GET['action'] ?? null) ? $_GET['action'] : null;

        // ------------------------------------------------------- process form
        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new \Piwigo\Html\HtmlService());

            if ($action_param === 'lost') {
                if ($this->processVerificationCode()) {
                    \Piwigo\Core\PageState::current()->addInfo(l10n('If your account exists, a verification code has been sent to your email address.'));
                    $this->action = 'lost_code';
                }
            }

            if ($action_param === 'lost_code') {
                if ($this->processPasswordRequest()) {
                    \Piwigo\Core\PageState::current()->addInfo(l10n('Verification successful! You can now choose a new password.'));
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
                $userdata = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->getUserData((int) $user_id, false);
                $userdata_username = $userdata['username'] ?? null;
                $this->username = is_string($userdata_username) ? $userdata_username : '';
                $template->assign('key', $_GET['key']);
                $first_login = new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->hasAlreadyLoggedIn((int) $user_id);

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
                $gallery_home_url = get_gallery_home_url();
                redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
            }
        }

        if ($this->action === 'lost' and ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $gallery_home_url = get_gallery_home_url();
            redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
        }

        if ($this->action === 'lost_code' and ! isset($_SESSION['reset_password_code'])) {
            $gallery_home_url = get_gallery_home_url();
            $gallery_home_url = is_string($gallery_home_url) ? $gallery_home_url : '';
            redirect($gallery_home_url . 'identification.php');
        }

        if ($this->action === 'lost' and isset($_SESSION['reset_password_code'])) {
            $this->action = 'lost_code';
        }

        $formErrors = $this->errors;
        $action = $this->action;
        $username = $this->username;
        $body = LegacyRenderCapture::capture(static function () use ($first_login, $formErrors, $action, $username): void {
            /**
             * @var array<string, mixed>
             */
            global $user;
            global $title;
            $template = \Piwigo\Template\CurrentTemplate::get();

            $title = l10n('Password Reset');
            if ($action === 'lost') {
                $title = l10n('Forgot your password?');

                if (isset($_POST['username_or_email']) and is_string($_POST['username_or_email'])) {
                    $template->assign('username_or_email', htmlspecialchars(stripslashes($_POST['username_or_email'])));
                }
            } elseif ($action === 'reset' and $first_login) {
                $title = l10n('Welcome');
                $template->assign('is_first_login', true);
            }

            \Piwigo\Core\PageState::current()->setBodyId('thePasswordPage');

            $template->set_filenames([
                'password' => 'password.tpl',
            ]);
            $template->assign(
                [
                    'title' => $title,
                    'form_action' => get_root_url() . 'password.php',
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
                    ->render();
            }

            // Load language if cookie is set from login/register/password
            // pages
            $cookie_lang = $_COOKIE['lang'] ?? null;
            if (is_string($cookie_lang) and \Piwigo\Users\CurrentUser::get()->language !== $cookie_lang) {
                if (! array_key_exists($cookie_lang, \Piwigo\Lang\LangService::getLanguages())) {
                    new HtmlService()
                        ->fatalError('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
                }

                $user['language'] = $cookie_lang;
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
            trigger_notify('loc_end_password');
            new HtmlService()
                ->flushPageMessages();
            new HtmlService()
                ->flushKeyedErrors($formErrors);
            $template->pparse('password');
            \Piwigo\Bootstrap\PageTail::render();
        });

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
            $this->errors['password_form_error'] = l10n('Invalid username or email');
            return false;
        }

        // retrievies user by email is not try by username
        $user_id_raw = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->getUserIdByEmail($username_or_email);

        if (! is_numeric($user_id_raw)) {
            $user_id_raw = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->getUserId($username_or_email);
        }

        // when no user is found, we assign guest_id instead of stopping.
        // this lets the function behave identically for unknown users,
        // preventing username/email enumeration through timing or responses.
        $is_user_found = is_numeric($user_id_raw);
        if ($is_user_found) {
            $user_id = $user_id_raw;
        } else {
            $guest_id = \Piwigo\Config\Config::guestId();
            $user_id = $guest_id;
        }

        $userdata = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->getUserData($user_id, false);

        $status = $userdata['status'] ?? '';
        $status = is_string($status) ? $status : '';

        if ($is_user_found) {
            // block early for generic or guest user because we don't
            // consider theses users has sensible for username/email
            // enumeration
            if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                $this->errors['password_form_error'] = l10n('Password reset is not allowed for this user');
                return false;
            }

            // check lockout
            $preferences = $userdata['preferences'] ?? null;
            if (
                is_array($preferences)
                and isset($preferences['reset_password_forbidden_until'])
                and $preferences['reset_password_forbidden_until'] > time()
            ) {
                $this->errors['password_form_error'] = l10n('Too many attempts, please try later..');
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
        new MailService()
            ->switchLangTo($language);
        $user_code = \Piwigo\Auth\AuthService::generateUserCode();
        $template_mail = new MailService()
            ->generateCodeVerificationMail($user_code['code']);
        // $skip_mail already covers $email === null/''), so $email is
        // provably a non-empty string here.
        if (! $skip_mail) {
            new MailService()
                ->mail($email, $template_mail);
        }
        new MailService()
            ->switchLangBack();

        $_SESSION['reset_password_code'] = [
            'secret' => $user_code['secret'],
            'attempts' => 0,
            'user_id' => $is_user_found ? $user_id : null,
            'created_at' => time(),
            'ttl' => min(\Piwigo\Config\Config::passwordResetCodeDuration(), 900), // max 15 min
        ];

        return true;
    }

    /**
     * checks the validity of input parameters, fills $this->errors
     */
    private function processPasswordRequest(): bool
    {
        /**
         * @var array<string, mixed>
         */
        global $user;

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
            $this->errors['password_form_error'] = l10n('Code expired');
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
                    $save_user = $user;
                    $saveCurrentUser = \Piwigo\Users\CurrentUser::get();
                    $user = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->buildUser((int) $user_id_raw, false);
                    // PreferencesService writes onto CurrentUser::get()->id
                    // (Legacy Coupling Retirement Track A batch A3), so the
                    // temporary identity switch above must be mirrored here
                    // too, or the preference would land on the ORIGINAL
                    // requester instead of the locked-out target user.
                    \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));
                    new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))->updateParam('reset_password_forbidden_until', time() + 60 * 60);
                    $user = $save_user;
                    \Piwigo\Users\CurrentUser::set($saveCurrentUser);

                    new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))->record('user', (int) $user_id_raw, 'reset_password_failure_too_many');
                }
                $this->errors['login_page_error'] = l10n('Too many attempts, please try later..');
                return false;
            }

            if ($has_valid_user_id) {
                new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))->record('user', (int) $user_id_raw, 'reset_password_failure_code');
            }
            $this->errors['password_form_error'] = l10n('Invalid verification code');
            return false;
        }

        // verify code success
        unset($_SESSION['reset_password_code']);

        if (! $has_valid_user_id) {
            $this->errors['password_form_error'] = l10n('Invalid verification code');
            return false;
        }
        $user_id = (int) $user_id_raw;

        $save_user = $user;
        $saveCurrentUser = \Piwigo\Users\CurrentUser::get();
        $user = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->buildUser($user_id, false);
        // Same CurrentUser identity-switch requirement as the lockout branch
        // above -- PreferencesService::deleteParam() writes onto
        // CurrentUser::get()->id.
        \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));
        new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))->deleteParam('reset_password_forbidden_until');

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
        $user = $save_user;
        \Piwigo\Users\CurrentUser::set($saveCurrentUser);

        // fallback check: don't send mail when user is guest, generic or
        // doesn't have email
        if (\Piwigo\Auth\AccessControl::isAGuest($status) || \Piwigo\Auth\AccessControl::isGeneric($status) || $has_no_email) {
            $this->errors['password_form_error'] = l10n('Password reset is not allowed for this user');
            return false;
        }

        return true;
    }

    /**
     * checks the activation key: does it match the expected pattern? is it
     * linked to a user? is this user allowed to reset his password?
     *
     * @return mixed (user_id if OK, false otherwise)
     */
    private function checkPasswordResetKey(mixed $reset_key): mixed
    {
        $key = is_string($reset_key) ? $reset_key : '';
        if (! (bool) preg_match('/^[a-z0-9]{20}$/i', $key)) {
            $this->errors['password_page_error'] = l10n('Invalid key');
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
        $result = \Piwigo\Db\MysqliDb::query($query);
        $user_id = null;
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $activation_key = $row['activation_key'] ?? null;
            if (! is_string($activation_key)) {
                continue;
            }
            if (new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build()))->verify($key, $activation_key)) {
                $status = $row['status'] ?? '';
                if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                    $this->errors['password_page_error'] = l10n('Password reset is not allowed for this user');
                    return false;
                }

                $user_id = $row['user_id'];
                break;
            }
        }

        if (! is_numeric($user_id) || (int) $user_id === 0) {
            $this->errors['password_page_error'] = l10n('Invalid key');
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
            $this->errors['password_form_error'] = l10n('The passwords do not match');
            return false;
        }

        $reset_password_key_result = $this->resetPasswordKey();
        $user_id = (bool) $reset_password_key_result ? $reset_password_key_result : $this->resetPasswordCode();

        if (! is_numeric($user_id)) {
            $this->errors['password_form_error'] = l10n('Invalid key or code');
            return false;
        }

        // see validate_mail_address() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();

        \Piwigo\Db\MysqliDb::singleUpdate(
            Tables::users(),
            [
                $user_fields['password'] => new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build()))->hash($new_password),
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
            new MailService()
                ->switchLangTo($reset_language);

            $reset_user_id = $reset_session['user_id'] ?? null;
            $reset_user_id_str = is_numeric($reset_user_id) ? (string) $reset_user_id : '';
            $api_keys = new \Piwigo\Auth\ApiKeyService(new \Piwigo\Mail\MailService())
                ->getAvailable($reset_user_id_str);
            $nb_of_apikeys = (bool) $api_keys ? count($api_keys) : 0;

            $reset_username = $reset_session['username'] ?? '';
            $reset_username = is_string($reset_username) ? $reset_username : '';
            $template_mail = new MailService()
                ->generateSuccessResetPasswordMail($reset_username, $nb_of_apikeys);

            // is_string($reset_session_email)/!== '' above already
            // guarantees this is a non-empty string.
            $reset_email = $reset_session_email;
            new MailService()
                ->mail($reset_email, $template_mail);

            new MailService()
                ->switchLangBack();
        }
        unset($_SESSION['valid_reset_password_code']);

        new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))->record('user', (int) $user_id, 'reset_password_success');

        \Piwigo\Core\PageState::current()->addInfo(l10n('Your password has been reset'));
        \Piwigo\Core\PageState::current()->addInfo('<a href="' . get_root_url() . 'identification.php">' . l10n('Login') . '</a>');

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

        new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->deactivatePasswordResetKey((int) $user_id);
        new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService())->deactivateUserAuthKeys((int) $user_id);
        return $user_id;
    }

    private function resetPasswordCode(): mixed
    {
        $state = $_SESSION['valid_reset_password_code'] ?? null;
        if (! is_array($state)) {
            return false;
        }

        return $state['user_id'] ?? false;
    }
}
