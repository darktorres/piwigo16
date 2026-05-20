<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Latte\Runtime\Html;
use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Mail\MailService;
use Piwigo\Session\Session;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final readonly class PasswordService
{
    public function __construct(
        private AuthService $authService,
        private MailService $mailService,
        private PermissionService $permissionService,
        private PreferencesService $preferencesService,
        private Session $session,
        private UrlGenerator $urlGenerator,
        private UserRepository $userRepository,
        private UserService $userService,
        private ActivityLogger $activityLogger,
    ) {
    }

    public function processVerificationCode(): bool
    {
        $logger = LoggerRegistry::current();
        if ($this->session->resetPasswordCode !== null) {
            return true;
        }

        $username_or_email = StringUtil::inputString('username_or_email', '', $_POST);
        if ($username_or_email === null || $username_or_email === '') {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid username or email'));
            return false;
        }

        $user_id = $this->userService->getUseridByEmail($username_or_email);
        if (!is_numeric($user_id)) {
            $user_id = $this->userService->getUserid($username_or_email);
        }

        $is_user_found = is_numeric($user_id);
        if (!$is_user_found) {
            $user_id = Config::guestId();
        }

        $userdata = $this->userService->getuserdata($is_user_found ? (int) $user_id : Config::guestId(), false);
        if ($userdata === false) {
            $userdata = ['status' => 'guest', 'language' => $this->userService->getDefaultLanguage(), 'email' => ''];
        }

        $statusRaw         = $userdata['status'] ?? null;
        $status            = is_string($statusRaw) ? (UserStatus::tryFrom($statusRaw) ?? UserStatus::Guest) : UserStatus::Guest;
        $userdata_language = isset($userdata['language']) && is_string($userdata['language']) ? $userdata['language'] : $this->userService->getDefaultLanguage();
        $userdata_email    = isset($userdata['email'])    && is_string($userdata['email']) ? $userdata['email'] : '';

        if ($is_user_found) {
            if ($this->permissionService->isAGuest($status) || $this->permissionService->isGeneric($status)) {
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

        $skip_mail     = !$is_user_found || $userdata_email === '';
        $this->mailService->switchLangTo($userdata_language);
        $user_code     = $this->userService->generateUserCode();
        $template_mail = $this->mailService->pwgGenerateCodeVerificationMail(is_string($user_code['code'] ?? null) ? $user_code['code'] : '');
        if (!$skip_mail) {
            $this->mailService->pwgMail($userdata_email, $template_mail);
        }
        $this->mailService->switchLangBack();

        $this->session->resetPasswordCode = [
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
        $state = $this->session->resetPasswordCode;
        if (!$state) {
            return true;
        }

        if (time() > $state['created_at'] + $state['ttl']) {
            $this->session->resetPasswordCode = null;
            PageState::current()->addKeyedError('password_form_error', Lang::t('Code expired'));
            return false;
        }

        $session_code     = $this->session->resetPasswordCode ?? [];
        $current_attempts = is_numeric($session_code['attempts'] ?? 0) ? (int) ($session_code['attempts'] ?? 0) : 0;
        $current_attempts++;
        $session_code['attempts']         = $current_attempts;
        $this->session->resetPasswordCode = $session_code;

        $user_code = StringUtil::inputString('user_code', '', $_POST);
        $is_valid  = ($user_code !== null && $user_code !== '') && preg_match('/^\d{6}$/', $user_code) && $this->userService->verifyUserCode($state['secret'], $user_code);

        if (!$is_valid) {
            if ($current_attempts >= 3) {
                $this->session->resetPasswordCode = null;
                if (isset($state['user_id']) && $state['user_id'] !== 0) {
                    $state_user_id = $state['user_id'];
                    $save_user     = CurrentUser::get()->rawAttributes;
                    CurrentUser::setRawAttributes($this->userService->buildUser($state_user_id, false));
                    $this->preferencesService->userprefsUpdateParam('reset_password_forbidden_until', time() + 60 * 60);
                    CurrentUser::setRawAttributes($save_user);
                    $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $state_user_id, ActivityAction::ResetPasswordFailureTooMany));
                }
                PageState::current()->addKeyedError('login_page_error', Lang::t('Too many attempts, please try later..'));
                return false;
            }
            if (isset($state['user_id']) && $state['user_id'] !== 0) {
                $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $state['user_id'], ActivityAction::ResetPasswordFailureCode));
            }
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid verification code'));
            return false;
        }

        $user_id = $state['user_id'];
        $this->session->resetPasswordCode = null;

        if ($user_id === null || $user_id === 0) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid verification code'));
            return false;
        }

        $save_user = CurrentUser::get()->rawAttributes;
        $temp_user = $this->userService->buildUser($user_id, false);
        CurrentUser::setRawAttributes($temp_user);
        $this->preferencesService->userprefsDeleteParam('reset_password_forbidden_until');

        $temp_username = is_string($temp_user['username'] ?? null) ? $temp_user['username'] : '';
        $temp_email    = is_string($temp_user['email'] ?? null) ? $temp_user['email'] : '';
        $temp_language = is_string($temp_user['language'] ?? null) ? $temp_user['language'] : '';
        $statusRaw     = $temp_user['status'] ?? null;
        $status        = is_string($statusRaw) ? UserStatus::tryFrom($statusRaw) : null;
        $has_no_email  = $temp_email === '';

        $this->session->validResetPasswordCode = [
            'user_id'  => $user_id,
            'username' => $temp_username,
            'email'    => $temp_email,
            'language' => $temp_language,
        ];
        CurrentUser::setRawAttributes($save_user);

        if ($this->permissionService->isAGuest($status) || $this->permissionService->isGeneric($status) || $has_no_email) {
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
        foreach ($this->userRepository->findByActiveActivationKey() as $row) {
            $activation_key  = is_string($row['activation_key'] ?? null) ? $row['activation_key'] : '';
            $row_statusRaw   = $row['status'] ?? null;
            $row_status      = is_string($row_statusRaw) ? UserStatus::tryFrom($row_statusRaw) : null;
            if (password_verify($reset_key, $activation_key)) {
                if ($this->permissionService->isAGuest($row_status) || $this->permissionService->isGeneric($row_status)) {
                    PageState::current()->addKeyedError('password_page_error', Lang::t('Password reset is not allowed for this user'));
                    return false;
                }
                $user_id = is_numeric($row['user_id']) ? (int) $row['user_id'] : null;
                break;
            }
        }

        if ($user_id === null || $user_id === 0) {
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

        $keyResult = $this->resetPasswordKey();
        $user_id = ($keyResult !== false && $keyResult !== 0) ? $keyResult : $this->resetPasswordCode();

        if (!is_numeric($user_id)) {
            PageState::current()->addKeyedError('password_form_error', Lang::t('Invalid key or code'));
            return false;
        }

        $this->userRepository->updateUserById(
            Tables::users(),
            Config::userFields()->id,
            $user_id,
            [Config::userFields()->password => password_hash(is_string($newPwd = $_POST['use_new_pwd'] ?? null) ? $newPwd : '', PASSWORD_BCRYPT)],
        );

        /** @var array{user_id: int|null, username: string, email: string, language: string}|null $valid_reset_code */
        $valid_reset_code = $this->session->validResetPasswordCode;
        if ($valid_reset_code !== null && $valid_reset_code['email'] !== '') {
            $this->mailService->switchLangTo($valid_reset_code['language']);
            $reset_user_id   = $valid_reset_code['user_id'] !== null ? (string) $valid_reset_code['user_id'] : '';
            $api_keys        = $this->userService->getAvailableApiKey($reset_user_id);
            $nb_of_apikeys   = $api_keys !== false ? count($api_keys) : 0;
            $template_mail   = $this->mailService->pwgGenerateSuccessResetPasswordMail($valid_reset_code['username'], $nb_of_apikeys);
            $this->mailService->pwgMail($valid_reset_code['email'], $template_mail);
            $this->mailService->switchLangBack();
        }
        $this->session->validResetPasswordCode = null;

        $this->activityLogger->log(new ActivityEvent(ActivityObject::User, $user_id, ActivityAction::ResetPasswordSuccess));
        PageState::current()->addInfo(Lang::t('Your password has been reset'));
        PageState::current()->addInfo(new Html('<a href="' . $this->urlGenerator->identification() . '">' . Lang::t('Login') . '</a>'));

        return true;
    }

    public function resetPasswordKey(): int|false
    {
        $key = StringUtil::inputString('key', null, $_GET);
        if ($key === null) {
            return false;
        }
        $user_id = $this->checkPasswordResetKey($key);
        if (!is_numeric($user_id)) {
            return false;
        }
        $this->authService->deactivatePasswordResetKey($user_id);
        $this->authService->deactivateUserAuthKeys($user_id);
        return $user_id;
    }

    public function resetPasswordCode(): int|false
    {
        $code = $this->session->validResetPasswordCode;
        if ($code === null) {
            return false;
        }
        $user_id = $code['user_id'] ?? false;
        if ($user_id === false || !is_numeric($user_id)) {
            return false;
        }
        return (int) $user_id;
    }
}
