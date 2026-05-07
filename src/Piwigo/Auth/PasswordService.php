<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Mail\MailService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final class PasswordService
{
    public function processVerificationCode(): bool
    {
        $logger = LoggerRegistry::current();
        if (isset($_SESSION['reset_password_code'])) {
            return true;
        }

        $username_or_email = StringUtil::get()->inputString('username_or_email', '', $_POST);
        if (empty($username_or_email)) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid username or email'));
            return false;
        }

        $user_id = UserService::get()->getUseridByEmail($username_or_email);
        if (!is_numeric($user_id)) {
            $user_id = UserService::get()->getUserid($username_or_email);
        }

        $is_user_found = is_numeric($user_id);
        if (!$is_user_found) {
            $user_id = Config::guestId();
        }

        $userdata = UserService::get()->getuserdata($user_id, false);
        if ($userdata === false) {
            $userdata = ['status' => 'guest', 'language' => UserService::get()->getDefaultLanguage(), 'email' => ''];
        }

        $status            = isset($userdata['status'])   && is_scalar($userdata['status']) ? (string) $userdata['status'] : 'guest';
        $userdata_language = isset($userdata['language']) && is_scalar($userdata['language']) ? (string) $userdata['language'] : UserService::get()->getDefaultLanguage();
        $userdata_email    = isset($userdata['email'])    && is_scalar($userdata['email']) ? (string) $userdata['email'] : '';

        if ($is_user_found) {
            if (PermissionService::get()->isAGuest($status) || PermissionService::get()->isGeneric($status)) {
                PageState::current()->addKeyedError('password_form_error', Lang::t('Password reset is not allowed for this user'));
                return false;
            }
            $userdata_prefs = is_array($userdata['preferences'] ?? null) ? $userdata['preferences'] : [];
            if (isset($userdata_prefs['reset_password_forbidden_until'])
                && is_numeric($userdata_prefs['reset_password_forbidden_until'])
                && (int) $userdata_prefs['reset_password_forbidden_until'] > time()
            ) {
                PageState::current()->addKeyedError('password_form_error', Lang::t('Too many attempts, please try later..'));
                return false;
            }
        }

        $skip_mail     = !$is_user_found || empty($userdata_email);
        ServiceLocator::get(MailService::class)->switchLangTo($userdata_language);
        $user_code     = ServiceLocator::get(UserService::class)->generateUserCode();
        $template_mail = ServiceLocator::get(MailService::class)->pwgGenerateCodeVerificationMail(is_scalar($user_code['code'] ?? '') ? (string) ($user_code['code'] ?? '') : '');
        if (!$skip_mail) {
            ServiceLocator::get(MailService::class)->pwgMail($userdata_email, $template_mail);
        }
        ServiceLocator::get(MailService::class)->switchLangBack();

        $_SESSION['reset_password_code'] = [
            'secret'     => $user_code['secret'],
            'attempts'   => 0,
            'user_id'    => $is_user_found ? $user_id : null,
            'created_at' => time(),
            'ttl'        => min(Config::passwordResetCodeDuration(), 900),
        ];

        return true;
    }

    public function processPasswordRequest(): bool
    {
        /** @var array{secret: string, attempts: int, user_id: int|null, created_at: int, ttl: int}|null $state */
        $state = is_array($_SESSION['reset_password_code'] ?? null) ? $_SESSION['reset_password_code'] : null;
        if (!$state) {
            return true;
        }

        if (time() > $state['created_at'] + $state['ttl']) {
            unset($_SESSION['reset_password_code']);
            PageState::current()->addKeyedError('password_form_error', Lang::t('Code expired'));
            return false;
        }

        /** @var array<string, mixed> $session_code */
        $session_code     = is_array($_SESSION['reset_password_code'] ?? null) ? $_SESSION['reset_password_code'] : [];
        $current_attempts = is_numeric($session_code['attempts'] ?? 0) ? (int) ($session_code['attempts'] ?? 0) : 0;
        $current_attempts++;
        $session_code['attempts']         = $current_attempts;
        $_SESSION['reset_password_code']  = $session_code;

        $user_code = StringUtil::get()->inputString('user_code', '', $_POST);
        $is_valid  = !empty($user_code) && preg_match('/^\d{6}$/', $user_code) && ServiceLocator::get(UserService::class)->verifyUserCode($state['secret'], $user_code);

        if (!$is_valid) {
            if ($current_attempts >= 3) {
                unset($_SESSION['reset_password_code']);
                if (!empty($state['user_id'])) {
                    $state_user_id = (int) $state['user_id'];
                    $save_user     = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
                    CurrentUser::setRawAttributes(UserService::get()->buildUser($state_user_id, false));
                    PreferencesService::get()->userprefsUpdateParam('reset_password_forbidden_until', time() + 60 * 60);
                    CurrentUser::setRawAttributes($save_user);
                    ServiceLocator::get(Util::class)->pwgActivity('user', $state_user_id, 'reset_password_failure_too_many');
                }
                PageState::current()->addKeyedError('login_page_error', Lang::t('Too many attempts, please try later..'));
                return false;
            }
            if (!empty($state['user_id'])) {
                ServiceLocator::get(Util::class)->pwgActivity('user', (int) $state['user_id'], 'reset_password_failure_code');
            }
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid verification code'));
            return false;
        }

        $user_id = $state['user_id'];
        unset($_SESSION['reset_password_code']);

        if (empty($user_id)) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid verification code'));
            return false;
        }

        $save_user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $temp_user = UserService::get()->buildUser((int) $user_id, false);
        CurrentUser::setRawAttributes($temp_user);
        PreferencesService::get()->userprefsDeleteParam('reset_password_forbidden_until');

        $temp_username = is_scalar($temp_user['username'] ?? null) ? (string) $temp_user['username'] : '';
        $temp_email    = is_scalar($temp_user['email']    ?? null) ? (string) $temp_user['email'] : '';
        $temp_language = is_scalar($temp_user['language'] ?? null) ? (string) $temp_user['language'] : '';
        $status        = is_scalar($temp_user['status']   ?? null) ? (string) $temp_user['status'] : '';
        $has_no_email  = empty($temp_email);

        $_SESSION['valid_reset_password_code'] = [
            'user_id'  => $user_id,
            'username' => $temp_username,
            'email'    => $temp_email,
            'language' => $temp_language,
        ];
        if (is_array($GLOBALS['page'])) {
            $GLOBALS['page']['username'] = $temp_username;
        }
        CurrentUser::setRawAttributes($save_user);

        if (PermissionService::get()->isAGuest($status) || PermissionService::get()->isGeneric($status) || $has_no_email) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Password reset is not allowed for this user'));
            return false;
        }

        return true;
    }

    public function checkPasswordResetKey(string $reset_key): int|false
    {
        if (!preg_match('/^[a-z0-9]{20}$/i', $reset_key)) {
            PageState::current()->addKeyedError('password_page_error', Lang::t('Invalid key'));
            return false;
        }

        $user_id = null;
        foreach (ServiceLocator::get(UserRepository::class)->findByActiveActivationKey() as $row) {
            $activation_key = is_scalar($row['activation_key'] ?? null) ? (string) $row['activation_key'] : '';
            $row_status     = is_scalar($row['status']         ?? null) ? (string) $row['status'] : '';
            if (password_verify($reset_key, $activation_key)) {
                if (PermissionService::get()->isAGuest($row_status) || PermissionService::get()->isGeneric($row_status)) {
                    PageState::current()->addKeyedError('password_page_error', Lang::t('Password reset is not allowed for this user'));
                    return false;
                }
                $user_id = is_numeric($row['user_id']) ? (int) $row['user_id'] : null;
                break;
            }
        }

        if (empty($user_id)) {
            PageState::current()->addKeyedError('password_page_error', Lang::t('Invalid key'));
            return false;
        }

        return $user_id;
    }

    public function resetPassword(): bool
    {
        if ($_POST['use_new_pwd'] != $_POST['passwordConf']) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('The passwords do not match'));
            return false;
        }

        $user_id = $this->resetPasswordKey() ?: $this->resetPasswordCode();

        if (!is_numeric($user_id)) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid key or code'));
            return false;
        }

        Dml::singleUpdate(
            Tables::users(),
            [Config::userFields()['password'] => password_hash(is_scalar($_POST['use_new_pwd']) ? (string) $_POST['use_new_pwd'] : '', PASSWORD_BCRYPT)],
            [Config::userFields()['id'] => $user_id]
        );

        /** @var array{user_id: int|null, username: string, email: string, language: string}|null $valid_reset_code */
        $valid_reset_code = is_array($_SESSION['valid_reset_password_code'] ?? null) ? $_SESSION['valid_reset_password_code'] : null;
        if ($valid_reset_code !== null && !empty($valid_reset_code['email'])) {
            ServiceLocator::get(MailService::class)->switchLangTo($valid_reset_code['language']);
            $reset_user_id   = $valid_reset_code['user_id'] !== null ? (string) $valid_reset_code['user_id'] : '';
            $api_keys        = ServiceLocator::get(UserService::class)->getAvailableApiKey($reset_user_id);
            $nb_of_apikeys   = $api_keys ? count($api_keys) : 0;
            $template_mail   = ServiceLocator::get(MailService::class)->pwgGenerateSuccessResetPasswordMail($valid_reset_code['username'], $nb_of_apikeys);
            ServiceLocator::get(MailService::class)->pwgMail($valid_reset_code['email'], $template_mail);
            ServiceLocator::get(MailService::class)->switchLangBack();
        }
        unset($_SESSION['valid_reset_password_code']);

        ServiceLocator::get(Util::class)->pwgActivity('user', $user_id, 'reset_password_success');
        PageState::current()->addInfo(Lang::t('Your password has been reset'));
        PageState::current()->addInfo('<a href="' . ServiceLocator::get(UrlGenerator::class)->identification() . '">' . Lang::t('Login') . '</a>');

        return true;
    }

    public function resetPasswordKey(): int|false
    {
        $key = StringUtil::get()->inputString('key', null, $_GET);
        if ($key === null) {
            return false;
        }
        $user_id = $this->checkPasswordResetKey($key);
        if (!is_numeric($user_id)) {
            return false;
        }
        ServiceLocator::get(AuthService::class)->deactivatePasswordResetKey((int) $user_id);
        ServiceLocator::get(AuthService::class)->deactivateUserAuthKeys((int) $user_id);
        return (int) $user_id;
    }

    public function resetPasswordCode(): int|false
    {
        if (!isset($_SESSION['valid_reset_password_code'])) {
            return false;
        }
        /** @var array<string, mixed> $code */
        $code    = is_array($_SESSION['valid_reset_password_code']) ? $_SESSION['valid_reset_password_code'] : [];
        $user_id = $code['user_id'] ?? false;
        if ($user_id === false || !is_numeric($user_id)) {
            return false;
        }
        return (int) $user_id;
    }
}
