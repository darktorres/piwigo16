<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Group\GroupRepository;

/**
 * User domain business logic: registration, login/email lookup,
 * case-insensitive username validation, default-user-info reads.
 * Constructor-injects UserRepository + GroupRepository (registration
 * assigns default groups, the same real dependency that put Group in
 * L2aCoreDomain in the first place).
 *
 * Calls Mail's free-function delegates (pwg_mail(),
 * pwg_mail_notification_admins()), not Piwigo\Mail\MailService directly --
 * Mail lives in L3Presentation (constructs Piwigo\Template\Template), and
 * L2aCoreDomain may not depend upward on L3; see deptrac.yaml's own
 * comment on the Mail namespace entry.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepository $repo,
        private readonly GroupRepository $groupRepo,
    ) {}

    /**
     * Checks if an email is well formed and not already in use. Returns an
     * error message, or '' when the address is fine / not required.
     */
    public function validateMailAddress(?int $userId, ?string $mailAddress): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $isEmpty = $mailAddress === null || $mailAddress === '';
        if (
            $isEmpty
            && ! ((bool) $conf['obligatory_user_mail_address'] && in_array(script_basename(), ['register', 'profile'], true))
        ) {
            return '';
        }

        if (! email_check_format($mailAddress)) {
            return l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (\defined('PHPWG_INSTALLED') && ! $isEmpty) {
            /** @var array<string, string> $user_fields */
            $user_fields = $conf['user_fields'];

            if ($this->repo->emailExists($mailAddress, $user_fields['email'], $user_fields['id'], $userId)) {
                return l10n('this email address is already in use');
            }
        }

        return '';
    }

    /**
     * Checks if a login is not already in use (case-insensitive
     * comparison). Returns an error message, or '' when it's free.
     */
    public function validateLoginCase(string $login): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (\defined('PHPWG_INSTALLED')) {
            /** @var array<string, string> $user_fields */
            $user_fields = $conf['user_fields'];

            if ($this->repo->usernameExistsCaseInsensitive($login, $user_fields['username'])) {
                return l10n('this login is already used');
            }
        }

        return '';
    }

    /**
     * Searches for a user with the same username in a different case.
     */
    public function searchCaseUsername(string $username): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $usernameLower = strtolower($username);
        $byLower = [];
        foreach ($this->repo->findAllUsernames($user_fields['username']) as $existing) {
            $byLower[$existing] = strtolower($existing);
        }

        // $usersFound is the set of accounts whose lowercased form matches
        // $username's lowercased form.
        $usersFound = array_keys($byLower, $usernameLower, true);
        if (count($usersFound) !== 1) { // If ambiguous, don't allow lowercase writing
            return $username;
        }

        return $usersFound[0];
    }

    public function getUserId(string $username): int|false
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        return $this->repo->findIdByUsername($username, $user_fields['id'], $user_fields['username']);
    }

    public function getUserIdByEmail(string $email): int|false
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        return $this->repo->findIdByEmail($email, $user_fields['id'], $user_fields['email']);
    }

    /**
     * Creates a new user. On a duplicate login, does NOT create an
     * account and does NOT reveal the collision in `errors` -- instead
     * sets `duplicateUsername: true` and (when the existing account has a
     * usable email on file) emails it a notice. [SEC-31] Callers that must
     * show the real "this login is already used" message to a trusted
     * operator (e.g. the admin-authenticated ws.users.add) can still
     * synthesize it themselves from `duplicateUsername`; the public
     * self-registration form (register.php) must not, and must also skip
     * auto-login when `duplicateUsername` is true (do not look the user
     * back up by username and log them into what may be someone else's
     * account).
     *
     * @return array{userId: int|null, errors: list<string>, duplicateUsername: bool}
     *   errors is real, showable validation errors -- never includes a
     *   duplicate-login message
     */
    public function registerUser(
        string $login,
        #[\SensitiveParameter]
        string $password,
        ?string $mailAddress,
        bool $notifyAdmin = true,
        bool $notifyUser = false
    ): array {
        /** @var array<string, mixed> $conf */
        global $conf;

        $errors = [];
        $duplicateUsername = false;

        if ($login === '') {
            $errors[] = l10n('Please, enter a login');
        }
        if (preg_match('/^.* $/', $login) === 1) {
            $errors[] = l10n('login mustn\'t end with a space character');
        }
        if (preg_match('/^ .*$/', $login) === 1) {
            $errors[] = l10n('login mustn\'t start with a space character');
        }
        if ($this->getUserId($login) !== false) {
            $duplicateUsername = true;
        }
        if ($login !== strip_tags($login)) {
            $errors[] = l10n('html tags are not allowed in login');
        }

        $mailError = $this->validateMailAddress(null, $mailAddress);
        if ($mailError !== '') {
            $errors[] = $mailError;
        }

        if ((bool) $conf['insensitive_case_logon'] && ! $duplicateUsername) {
            if ($this->validateLoginCase($login) !== '') {
                $duplicateUsername = true;
            }
        }

        $errorsAfterTrigger = trigger_change(
            'register_user_check',
            $errors,
            [
                'username' => $login,
                'password' => $password,
                'email' => $mailAddress,
            ]
        );
        $errors = is_array($errorsAfterTrigger) ? array_values(array_filter($errorsAfterTrigger, is_string(...))) : [];

        if ($errors !== [] || $duplicateUsername) {
            if ($duplicateUsername) {
                $this->notifyExistingAccountOfDuplicateRegistration($login, $mailAddress);
            }

            return [
                'userId' => null,
                'errors' => $errors,
                'duplicateUsername' => $duplicateUsername,
            ];
        }

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];
        $userId = $this->repo->insertUser([
            $user_fields['username'] => $login,
            $user_fields['password'] => pwg_password_hash($password),
            $user_fields['email'] => $mailAddress,
        ]);

        $defaultGroupIds = $this->groupRepo->findDefaultGroupIds();
        if ($defaultGroupIds !== []) {
            $this->groupRepo->addMembers($userId, $defaultGroupIds);
        }

        $override = [];
        if ((bool) $conf['browser_language'] && ($language = get_browser_language()) !== false) {
            $override['language'] = $language;
        }

        $this->createUserInfos([$userId], $override);

        $emailAdminOnNewUserSetting = $conf['email_admin_on_new_user'] ?? 'none';
        $emailAdminOnNewUserSetting = is_scalar($emailAdminOnNewUserSetting) ? (string) $emailAdminOnNewUserSetting : 'none';
        if ($notifyAdmin && $emailAdminOnNewUserSetting !== 'none') {
            $this->notifyAdminsOfNewRegistration($userId, $login, $mailAddress);
        }

        if ($notifyUser && email_check_format($mailAddress)) {
            assert($mailAddress !== null);
            $this->sendWelcomeEmail($login, $mailAddress);
        }

        trigger_notify(
            'register_user',
            [
                'id' => $userId,
                'username' => $login,
                'email' => $mailAddress,
            ]
        );

        pwg_activity('user', $userId, 'add');

        return [
            'userId' => $userId,
            'errors' => [],
            'duplicateUsername' => false,
        ];
    }

    /**
     * @param array<int|string, int|string> $userIds
     * @param array<string, mixed>|null $overrideValues
     */
    public function createUserInfos(array $userIds, ?array $overrideValues = null): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($userIds === []) {
            return;
        }

        $defaultUser = $this->getDefaultUserInfo(false);
        if ($defaultUser === false) {
            $defaultUser = [];
        }

        if ($overrideValues !== null) {
            $defaultUser = array_merge($defaultUser, $overrideValues);
        }

        $availablePermissionLevels = $conf['available_permission_levels'] ?? null;
        $webmasterId = isset($conf['webmaster_id']) && is_scalar($conf['webmaster_id']) ? (string) $conf['webmaster_id'] : null;
        $guestId = isset($conf['guest_id']) && is_scalar($conf['guest_id']) ? (string) $conf['guest_id'] : null;
        $defaultUserId = isset($conf['default_user_id']) && is_scalar($conf['default_user_id']) ? (string) $conf['default_user_id'] : null;

        foreach ($userIds as $userId) {
            $level = $defaultUser['level'] ?? 0;
            $userIdStr = (string) $userId;
            if ($webmasterId !== null && $userIdStr === $webmasterId) {
                $status = 'webmaster';
                $level = is_array($availablePermissionLevels) && $availablePermissionLevels !== []
                    ? max($availablePermissionLevels)
                    : 0;
            } elseif (($guestId !== null && $userIdStr === $guestId) || ($defaultUserId !== null && $userIdStr === $defaultUserId)) {
                $status = 'guest';
            } else {
                $status = 'normal';
            }

            $row = array_merge(
                $defaultUser,
                [
                    'status' => $status,
                    // pwg_now() respects the frozen test-mode clock the
                    // same way pwg_activity()'s own timestamp does --
                    // real behavior outside test mode is unaffected.
                    'registration_date' => pwg_now()
                        ->format('Y-m-d H:i:s'),
                    // Otherwise relies on the schema's own DEFAULT
                    // CURRENT_TIMESTAMP, which reads the real DB-server
                    // clock -- invisible to pwg_now()'s freeze, same
                    // reasoning as registration_date above.
                    'lastmodified' => pwg_now()
                        ->format('Y-m-d H:i:s'),
                    'level' => $level,
                ]
            );

            $this->repo->insertUserInfos([$userId], $row);
        }
    }

    /**
     * @return array<string, mixed>|false false if the default user row
     *   doesn't exist
     */
    public function getDefaultUserInfo(bool $convertStr = true): array|false
    {
        /**
         * @var array<string, mixed> $cache
         * @var array<string, mixed> $conf
         */
        global $cache, $conf;

        if (! isset($cache['default_user'])) {
            $defaultUserId = $conf['default_user_id'] ?? null;
            $defaultUserId = is_numeric($defaultUserId) ? (int) $defaultUserId : 0;

            $row = $this->repo->findDefaultUserInfoRow($defaultUserId);
            if ($row !== null) {
                unset($row['user_id'], $row['status'], $row['registration_date'], $row['last_visit'], $row['last_visit_from_history']);
                $cache['default_user'] = $row;
            } else {
                $cache['default_user'] = false;
            }
        }

        $defaultUserCached = $cache['default_user'];
        if (! is_array($defaultUserCached)) {
            return false;
        }

        /** @var array<string, mixed> $defaultUserCached */
        if (! $convertStr) {
            return $defaultUserCached;
        }

        $defaultUser = $defaultUserCached;
        foreach ($defaultUser as &$value) {
            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            }
        }
        unset($value);

        return $defaultUser;
    }

    public function getDefaultUserValue(string $valueName, mixed $default): mixed
    {
        $defaultUser = $this->getDefaultUserInfo(true);
        if ($defaultUser === false || self::emptyValue($defaultUser[$valueName] ?? null)) {
            return $default;
        }

        return $defaultUser[$valueName];
    }

    private function notifyExistingAccountOfDuplicateRegistration(string $login, ?string $mailAddress): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $existing = $this->repo->findByUsernameCaseInsensitive($login, $user_fields['id'], $user_fields['username'], $user_fields['email']);
        if ($existing === null || $existing['email'] === '' || ! email_check_format($existing['email'])) {
            return;
        }

        include_once \PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

        $gallery_title = $conf['gallery_title'] ?? '';
        $gallery_title = is_string($gallery_title) ? $gallery_title : '';

        pwg_mail(
            $existing['email'],
            [
                'subject' => '[' . $gallery_title . '] ' . l10n('Registration'),
                'content' => l10n_args([
                    get_l10n_args('Someone tried to create an account on %s using your username.', $gallery_title),
                    get_l10n_args('', ''),
                    get_l10n_args('If this was you, you already have an account -- try logging in or resetting your password instead.', ''),
                    get_l10n_args('If this was not you, no action is needed.', ''),
                ]),
                'content_format' => 'text/plain',
            ]
        );
    }

    private function notifyAdminsOfNewRegistration(int $userId, string $login, ?string $mailAddress): void
    {
        include_once \PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

        /** @var array<string, mixed> $conf */
        global $conf;

        $adminUrl = get_absolute_root_url() . 'admin.php?page=user_list&user_id=' . $userId;

        $keyargsContent = [
            get_l10n_args('User: %s', stripslashes($login)),
            get_l10n_args('Email: %s', $mailAddress),
            get_l10n_args(''),
            get_l10n_args('Admin: %s', $adminUrl),
        ];

        $groupId = null;
        $emailAdminOnNewUser = $conf['email_admin_on_new_user'] ?? '';
        $emailAdminOnNewUser = is_scalar($emailAdminOnNewUser) ? (string) $emailAdminOnNewUser : '';
        if (preg_match('/^group:(\d+)$/', $emailAdminOnNewUser, $matches) === 1) {
            $groupId = $matches[1];
        }

        pwg_mail_notification_admins(
            get_l10n_args('Registration of %s', stripslashes($login)),
            $keyargsContent,
            true,
            $groupId
        );
    }

    private function sendWelcomeEmail(string $login, string $mailAddress): void
    {
        include_once \PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';

        /** @var array<string, mixed> $conf */
        global $conf;

        $length = mt_rand(10, 15);
        $keyargsContent = [
            get_l10n_args('Hello %s,', stripslashes($login)),
            get_l10n_args('Thank you for registering at %s!', $conf['gallery_title']),
            get_l10n_args('', ''),
            get_l10n_args('Here are your connection settings', ''),
            get_l10n_args('', ''),
            get_l10n_args('Link: %s', get_absolute_root_url()),
            get_l10n_args('Username: %s', stripslashes($login)),
            get_l10n_args('Password: %s', str_repeat('*', $length)),
            get_l10n_args('Email: %s', $mailAddress),
            get_l10n_args('', ''),
            get_l10n_args('If you think you\'ve received this email in error, please contact us at %s', get_webmaster_mail_address()),
        ];

        $gallery_title = $conf['gallery_title'] ?? '';
        $gallery_title = is_string($gallery_title) ? $gallery_title : '';

        pwg_mail(
            $mailAddress,
            [
                'subject' => '[' . $gallery_title . '] ' . l10n('Registration'),
                'content' => l10n_args($keyargsContent),
                'content_format' => 'text/plain',
            ]
        );
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
