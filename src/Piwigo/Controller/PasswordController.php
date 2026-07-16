<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Template\Template;
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
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $page, $template, $user;

        // $page['infos'] is always initialized to an array by
        // common.inc.php, but that isn't visible across the include()
        // boundary -- narrow it once here so every append below type-checks.
        $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        trigger_notify('loc_begin_password');

        check_input_parameter('action', $_GET, false, '/^(lost|reset|lost_code|reset_end|none)$/');
        $action_param = is_string($_GET['action'] ?? null) ? $_GET['action'] : null;

        // ------------------------------------------------------- process form
        if (isset($_POST['submit'])) {
            check_pwg_token();

            if ($action_param === 'lost') {
                if ($this->processVerificationCode()) {
                    $page['infos'][] = l10n('If your account exists, a verification code has been sent to your email address.');
                    $page['action'] = 'lost_code';
                }
            }

            if ($action_param === 'lost_code') {
                if ($this->processPasswordRequest()) {
                    $page['infos'][] = l10n('Verification successful! You can now choose a new password.');
                    $page['action'] = 'reset';
                }
            }

            if ($action_param === 'reset') {
                if ($this->resetPassword()) {
                    $page['action'] = 'reset_end';
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
                $userdata = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getUserData((int) $user_id, false);
                $page['username'] = $userdata['username'];
                $template->assign('key', $_GET['key']);
                $first_login = (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build())))->hasAlreadyLoggedIn((int) $user_id);

                if (! isset($page['action'])) {
                    $page['action'] = 'reset';
                }
            } else {
                $page['action'] = 'none';
            }
        }

        if (! isset($page['action'])) {
            if ($action_param === null) {
                $page['action'] = 'lost';
            } elseif (in_array($action_param, ['lost', 'lost_code', 'reset', 'none'], true)) {
                $page['action'] = $action_param;
            }
        }

        if (($page['action'] ?? null) === 'reset') {
            if ((! isset($_GET['key']) and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isGeneric())) and ! isset($_SESSION['valid_reset_password_code'])) {
                $gallery_home_url = get_gallery_home_url();
                redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
            }
        }

        if (($page['action'] ?? null) === 'lost' and ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $gallery_home_url = get_gallery_home_url();
            redirect(is_string($gallery_home_url) ? $gallery_home_url : '');
        }

        if (($page['action'] ?? null) === 'lost_code' and ! isset($_SESSION['reset_password_code'])) {
            $gallery_home_url = get_gallery_home_url();
            $gallery_home_url = is_string($gallery_home_url) ? $gallery_home_url : '';
            redirect($gallery_home_url . 'identification.php');
        }

        if (($page['action'] ?? null) === 'lost' and isset($_SESSION['reset_password_code'])) {
            $page['action'] = 'lost_code';
        }

        $body = LegacyRenderCapture::capture(static function () use ($first_login): void {
            /**
             * @var array<string, mixed> $page
             * @var Template $template
             * @var array<string, mixed> $user
             */
            global $page, $template, $user, $title;

            $title = l10n('Password Reset');
            if (($page['action'] ?? null) === 'lost') {
                $title = l10n('Forgot your password?');

                if (isset($_POST['username_or_email']) and is_string($_POST['username_or_email'])) {
                    $template->assign('username_or_email', htmlspecialchars(stripslashes($_POST['username_or_email'])));
                }
            } elseif (($page['action'] ?? null) === 'reset' and $first_login) {
                $title = l10n('Welcome');
                $template->assign('is_first_login', true);
            }

            $page['body_id'] = 'thePasswordPage';

            $template->set_filenames([
                'password' => 'password.tpl',
            ]);
            $template->assign(
                [
                    'title' => $title,
                    'form_action' => get_root_url() . 'password.php',
                    'action' => $page['action'],
                    'username' => $page['username'] ?? $user['username'],
                    'PWG_TOKEN' => get_pwg_token(),
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
            if (is_string($cookie_lang) and $user['language'] !== $cookie_lang) {
                if (! array_key_exists($cookie_lang, get_languages())) {
                    fatal_error('[Hacking attempt] the input parameter "' . $cookie_lang . '" is not valid');
                }

                $user['language'] = $cookie_lang;
                load_language('common.lang', '', [
                    'language' => $cookie_lang,
                ]);
            }

            $language_options = [];
            foreach (get_languages() as $language_code => $language_name) {
                $language_options[$language_code] = $language_name;
            }

            $template->assign([
                'language_options' => $language_options,
                'current_language' => $user['language'],
            ]);

            $user_language_for_help = $user['language'] ?? '';
            $user_language_for_help = is_string($user_language_for_help) ? $user_language_for_help : '';
            if (str_starts_with($user_language_for_help, 'fr')) {
                $help_link = 'https://upstream.example.invalid/help/fr/';
            } else {
                $help_link = 'https://upstream.example.invalid/help/';
            }

            $template->assign('HELP_LINK', $help_link);

            include PHPWG_ROOT_PATH . 'include/page_header.php';
            trigger_notify('loc_end_password');
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('password');
            include PHPWG_ROOT_PATH . 'include/page_tail.php';
        });

        return ResponseFactory::html($body);
    }

    /**
     * checks the validity of input parameters, fills $page['errors'] and
     * $page['infos'] and send an email with the verification code
     */
    private function processVerificationCode(): bool
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         */
        global $page, $conf;

        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

        if (isset($_SESSION['reset_password_code'])) {
            return true;
        }

        // empty param
        $username_or_email_raw = $_POST['username_or_email'] ?? '';
        $username_or_email = is_string($username_or_email_raw) ? trim($username_or_email_raw) : '';
        if ($username_or_email === '') {
            $page['errors']['password_form_error'] = l10n('Invalid username or email');
            return false;
        }

        // retrievies user by email is not try by username
        $user_id_raw = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getUserIdByEmail($username_or_email);

        if (! is_numeric($user_id_raw)) {
            $user_id_raw = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getUserId($username_or_email);
        }

        // when no user is found, we assign guest_id instead of stopping.
        // this lets the function behave identically for unknown users,
        // preventing username/email enumeration through timing or responses.
        $is_user_found = is_numeric($user_id_raw);
        if ($is_user_found) {
            $user_id = $user_id_raw;
        } else {
            $guest_id = $conf['guest_id'] ?? null;
            $user_id = is_numeric($guest_id) ? (int) $guest_id : 0;
        }

        $userdata = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getUserData($user_id, false);

        $status = $userdata['status'] ?? '';
        $status = is_string($status) ? $status : '';

        if ($is_user_found) {
            // block early for generic or guest user because we don't
            // consider theses users has sensible for username/email
            // enumeration
            if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                $page['errors']['password_form_error'] = l10n('Password reset is not allowed for this user');
                return false;
            }

            // check lockout
            $preferences = $userdata['preferences'] ?? null;
            if (
                is_array($preferences)
                and isset($preferences['reset_password_forbidden_until'])
                and $preferences['reset_password_forbidden_until'] > time()
            ) {
                $page['errors']['password_form_error'] = l10n('Too many attempts, please try later..');
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
            'ttl' => min($conf['password_reset_code_duration'], 900), // max 15 min
        ];

        return true;
    }

    /**
     * checks the validity of input parameters, fills $page['errors'] and
     * $page['infos']
     */
    private function processPasswordRequest(): bool
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $user
         */
        global $page, $user;

        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

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
            $page['errors']['password_form_error'] = l10n('Code expired');
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
                    $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->buildUser((int) $user_id_raw, false);
                    (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->updateParam('reset_password_forbidden_until', time() + 60 * 60);
                    $user = $save_user;

                    pwg_activity('user', (int) $user_id_raw, 'reset_password_failure_too_many');
                }
                $page['errors']['login_page_error'] = l10n('Too many attempts, please try later..');
                return false;
            }

            if ($has_valid_user_id) {
                pwg_activity('user', (int) $user_id_raw, 'reset_password_failure_code');
            }
            $page['errors']['password_form_error'] = l10n('Invalid verification code');
            return false;
        }

        // verify code success
        unset($_SESSION['reset_password_code']);

        if (! $has_valid_user_id) {
            $page['errors']['password_form_error'] = l10n('Invalid verification code');
            return false;
        }
        $user_id = (int) $user_id_raw;

        $save_user = $user;
        $user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->buildUser($user_id, false);
        (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->deleteParam('reset_password_forbidden_until');

        $_SESSION['valid_reset_password_code'] = [
            'user_id' => $user_id,
            'username' => $user['username'],
            'email' => $user['email'],
            'language' => $user['language'],
        ];
        $status = $user['status'] ?? '';
        $status = is_string($status) ? $status : '';
        $user_email = $user['email'] ?? null;
        $has_no_email = ! is_string($user_email) || $user_email === '';
        $page['username'] = $user['username'];
        $user = $save_user;

        // fallback check: don't send mail when user is guest, generic or
        // doesn't have email
        if (\Piwigo\Auth\AccessControl::isAGuest($status) || \Piwigo\Auth\AccessControl::isGeneric($status) || $has_no_email) {
            $page['errors']['password_form_error'] = l10n('Password reset is not allowed for this user');
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
        /** @var array<string, mixed> $page */
        global $page;

        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

        $key = is_string($reset_key) ? $reset_key : '';
        if (! (bool) preg_match('/^[a-z0-9]{20}$/i', $key)) {
            $page['errors']['password_page_error'] = l10n('Invalid key');
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
        $result = pwg_query($query);
        $user_id = null;
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $activation_key = $row['activation_key'] ?? null;
            if (! is_string($activation_key)) {
                continue;
            }
            if ((new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())))->verify($key, $activation_key)) {
                $status = $row['status'] ?? '';
                if (\Piwigo\Auth\AccessControl::isAGuest($status) or \Piwigo\Auth\AccessControl::isGeneric($status)) {
                    $page['errors']['password_page_error'] = l10n('Password reset is not allowed for this user');
                    return false;
                }

                $user_id = $row['user_id'];
                break;
            }
        }

        if (! is_numeric($user_id) || (int) $user_id === 0) {
            $page['errors']['password_page_error'] = l10n('Invalid key');
            return false;
        }

        return $user_id;
    }

    /**
     * checks the passwords, checks that user is allowed to reset his
     * password, update password, fills $page['errors'] and $page['infos'].
     */
    private function resetPassword(): bool
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         */
        global $page, $conf;

        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
        $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

        $new_password_raw = $_POST['use_new_pwd'] ?? '';
        $new_password = is_string($new_password_raw) ? $new_password_raw : '';
        $password_conf_raw = $_POST['passwordConf'] ?? '';
        $password_conf = is_string($password_conf_raw) ? $password_conf_raw : '';

        if ($new_password !== $password_conf) {
            $page['errors']['password_form_error'] = l10n('The passwords do not match');
            return false;
        }

        $reset_password_key_result = $this->resetPasswordKey();
        $user_id = (bool) $reset_password_key_result ? $reset_password_key_result : $this->resetPasswordCode();

        if (! is_numeric($user_id)) {
            $page['errors']['password_form_error'] = l10n('Invalid key or code');
            return false;
        }

        // see validate_mail_address() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        single_update(
            Tables::users(),
            [
                $user_fields['password'] => (new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())))->hash($new_password),
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
            $api_keys = (new \Piwigo\Auth\ApiKeyService(new \Piwigo\Mail\MailService()))->getAvailable($reset_user_id_str);
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

        pwg_activity('user', (int) $user_id, 'reset_password_success');

        $page['infos'][] = l10n('Your password has been reset');
        $page['infos'][] = '<a href="' . get_root_url() . 'identification.php">' . l10n('Login') . '</a>';

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

        (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build())))->deactivatePasswordResetKey((int) $user_id);
        (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build())))->deactivateUserAuthKeys((int) $user_id);
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
