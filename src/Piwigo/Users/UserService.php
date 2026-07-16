<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\MailerInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;

/**
 * User domain business logic: registration, login/email lookup,
 * case-insensitive username validation, default-user-info reads.
 * Constructor-injects UserRepository + GroupRepository (registration
 * assigns default groups, the same real dependency that put Group in
 * L2aCoreDomain in the first place).
 *
 * P23 batch 8c: constructor-injects MailerInterface (Piwigo\Core) rather
 * than depending on Piwigo\Mail\MailService directly -- Mail lives in
 * L3Presentation (constructs Piwigo\Template\Template), and L2aCoreDomain
 * may not depend upward on L3; see deptrac.yaml's own comment on the Mail
 * namespace entry and MailerInterface's own docblock.
 *
 * P23 batch 8d: implements DefaultLanguageProviderInterface (Piwigo\Core)
 * so Piwigo\Core\Lang::load() (L1Infrastructure, static) can resolve the
 * DB-configured default language without depending on this class
 * directly -- see that interface's own docblock.
 */
final class UserService implements DefaultLanguageProviderInterface
{
    public function __construct(
        private readonly UserRepository $repo,
        private readonly GroupRepository $groupRepo,
        private readonly MailerInterface $mailer,
        private readonly ActivityLoggerInterface $activityLogger,
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
            && ! ((bool) $conf['obligatory_user_mail_address'] && in_array(\Piwigo\Core\PageFilterHelper::scriptBasename(), ['register', 'profile'], true))
        ) {
            return '';
        }

        if (! \Piwigo\Validation\InputValidator::checkEmailFormat($mailAddress)) {
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
     * Ported from admin/include/functions.php's get_username() (P23 batch
     * 8d), unchanged logic (including the stripslashes() call -- a real,
     * already-established precedent in this same file, not legacy cruft).
     */
    public function getUsername(int $userId): false|string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $username = $this->repo->findUsernameById($userId, $user_fields['id'], $user_fields['username']);

        return $username === null ? false : stripslashes($username);
    }

    /**
     * Deletes a user and every trace of it (sessions, cache rows, activity
     * log entry). Ported from admin/include/functions.php's delete_user()
     * (P23 batch 8d), unchanged logic.
     */
    public function deleteUser(int $userId): void
    {
        $this->repo->deleteUser($userId);
        SessionService::get()->deleteUserSessions($userId);
        trigger_notify('delete_user', $userId);
        $this->activityLogger->record('user', $userId, 'delete');
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
            $user_fields['password'] => (new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository(\Piwigo\Db\DbConnection::build())))->hash($password),
            $user_fields['email'] => $mailAddress,
        ]);

        $defaultGroupIds = $this->groupRepo->findDefaultGroupIds();
        if ($defaultGroupIds !== []) {
            $this->groupRepo->addMembers($userId, $defaultGroupIds);
        }

        $override = [];
        if ((bool) $conf['browser_language'] && ($language = $this->getBrowserLanguage()) !== false) {
            $override['language'] = $language;
        }

        $this->createUserInfos([$userId], $override);

        $emailAdminOnNewUserSetting = $conf['email_admin_on_new_user'] ?? 'none';
        $emailAdminOnNewUserSetting = is_scalar($emailAdminOnNewUserSetting) ? (string) $emailAdminOnNewUserSetting : 'none';
        if ($notifyAdmin && $emailAdminOnNewUserSetting !== 'none') {
            $this->notifyAdminsOfNewRegistration($userId, $login, $mailAddress);
        }

        if ($notifyUser && \Piwigo\Validation\InputValidator::checkEmailFormat($mailAddress)) {
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

        $this->activityLogger->record('user', $userId, 'add');

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
        if ($existing === null || $existing['email'] === '' || ! \Piwigo\Validation\InputValidator::checkEmailFormat($existing['email'])) {
            return;
        }

        $gallery_title = $conf['gallery_title'] ?? '';
        $gallery_title = is_string($gallery_title) ? $gallery_title : '';

        $this->mailer->mail(
            $existing['email'],
            [
                'subject' => '[' . $gallery_title . '] ' . l10n('Registration'),
                'content' => Lang::args([
                    Lang::buildArgs('Someone tried to create an account on %s using your username.', $gallery_title),
                    Lang::buildArgs('', ''),
                    Lang::buildArgs('If this was you, you already have an account -- try logging in or resetting your password instead.', ''),
                    Lang::buildArgs('If this was not you, no action is needed.', ''),
                ]),
                'content_format' => 'text/plain',
            ]
        );
    }

    private function notifyAdminsOfNewRegistration(int $userId, string $login, ?string $mailAddress): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $adminUrl = get_absolute_root_url() . 'admin.php?page=user_list&user_id=' . $userId;

        $keyargsContent = [
            Lang::buildArgs('User: %s', stripslashes($login)),
            Lang::buildArgs('Email: %s', $mailAddress),
            Lang::buildArgs(''),
            Lang::buildArgs('Admin: %s', $adminUrl),
        ];

        $groupId = null;
        $emailAdminOnNewUser = $conf['email_admin_on_new_user'] ?? '';
        $emailAdminOnNewUser = is_scalar($emailAdminOnNewUser) ? (string) $emailAdminOnNewUser : '';
        if (preg_match('/^group:(\d+)$/', $emailAdminOnNewUser, $matches) === 1) {
            $groupId = $matches[1];
        }

        $this->mailer->mailNotificationAdmins(
            Lang::buildArgs('Registration of %s', stripslashes($login)),
            $keyargsContent,
            true,
            $groupId
        );
    }

    private function sendWelcomeEmail(string $login, string $mailAddress): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $length = mt_rand(10, 15);
        $keyargsContent = [
            Lang::buildArgs('Hello %s,', stripslashes($login)),
            Lang::buildArgs('Thank you for registering at %s!', $conf['gallery_title']),
            Lang::buildArgs('', ''),
            Lang::buildArgs('Here are your connection settings', ''),
            Lang::buildArgs('', ''),
            Lang::buildArgs('Link: %s', get_absolute_root_url()),
            Lang::buildArgs('Username: %s', stripslashes($login)),
            Lang::buildArgs('Password: %s', str_repeat('*', $length)),
            Lang::buildArgs('Email: %s', $mailAddress),
            Lang::buildArgs('', ''),
            Lang::buildArgs('If you think you\'ve received this email in error, please contact us at %s', (new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))->getWebmasterMailAddress()),
        ];

        $gallery_title = $conf['gallery_title'] ?? '';
        $gallery_title = is_string($gallery_title) ? $gallery_title : '';

        $this->mailer->mail(
            $mailAddress,
            [
                'subject' => '[' . $gallery_title . '] ' . l10n('Registration'),
                'content' => Lang::args($keyargsContent),
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

    /**
     * Finds informations related to the user identifier. Same as
     * getUserData() but with additional guest-normalization + theme checks.
     *
     * @return array<string, mixed>
     */
    public function buildUser(int $userId, bool $useCache = true): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $user = [];
        $user['id'] = $userId;
        $user = array_merge($user, $this->getUserData($userId, $useCache));

        if ($user['id'] == $conf['guest_id'] and $user['status'] != 'guest') {
            $user['status'] = 'guest';
            $internal_status = $user['internal_status'] ?? [];
            if (! is_array($internal_status)) {
                $internal_status = [];
            }
            $internal_status['guest_must_be_guest'] = true;
            $user['internal_status'] = $internal_status;
        }

        // Check user theme. 2 possible problems:
        // 1. the user_infos.theme was not found in the themes table, thus themes.name is null
        // 2. the theme is not really installed on the filesystem
        $theme = $user['theme'] ?? null;
        // deliberately bare, not ThemeCatalog::checkThemeInstalled() --
        // tests/Integration/ExtensionLifecycleTest.php spies on this exact
        // call via same-namespace function shadowing (its own isolated
        // bootstrap doesn't load pwg_query(), which getDefaultTheme()'s
        // own fallback branch below would need if this check ever fell
        // through to it for real), same "one narrow, structurally-forced
        // exception" shape as pwg_activity()'s CategoryAdminService
        // exception.
        if (! isset($user['theme_name']) or ! is_string($theme) or ! check_theme_installed($theme)) {
            $user['theme'] = $this->getDefaultTheme();
            $user['theme_name'] = $user['theme'];
        }

        return $user;
    }

    /**
     * Finds informations related to the user identifier.
     *
     * @return array<string, mixed>
     */
    public function getUserData(int $userId, bool $useCache = false): array
    {
        /**
         * @var array<string, mixed> $conf
         * @var Logger $logger
         */
        global $conf, $logger;

        // see validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        // retrieve basic user data
        $query = '
SELECT ';
        $is_first = true;
        foreach ($user_fields as $pwgfield => $dbfield) {
            if ($is_first) {
                $is_first = false;
            } else {
                $query .= '
     , ';
            }
            $query .= $dbfield . ' AS ' . $pwgfield;
        }
        $query .= '
  FROM ' . Tables::users() . '
  WHERE ' . $user_fields['id'] . ' = \'' . $userId . '\'';

        $row = pwg_db_fetch_assoc(pwg_query($query));
        if ($row === false || $row === null) {
            throw new \Exception('UserService::getUserData(): no such user_id ' . $userId);
        }

        // retrieve additional user data ?
        if ((bool) $conf['external_authentification']) {
            $query = '
SELECT
    COUNT(1) AS counter
  FROM ' . Tables::userInfos() . ' AS ui
    LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
    LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
  WHERE ui.user_id = ' . $userId . '
  GROUP BY ui.user_id
;';
            $counter_row = pwg_db_fetch_row(pwg_query($query));
            $counter = $counter_row !== null ? $counter_row[0] : 0;
            if ($counter != 1) {
                $this->createUserInfos([$userId]);
            }
        }

        // retrieve user info
        $query = '
SELECT
    ui.*,
    uc.*,
    t.name AS theme_name
  FROM ' . Tables::userInfos() . ' AS ui
    LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
    LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
  WHERE ui.user_id = ' . $userId . '
;';

        $result = pwg_query($query);
        $user_infos_row = pwg_db_fetch_assoc($result);
        if ($user_infos_row === false || $user_infos_row === null) {
            throw new \Exception('UserService::getUserData(): user_infos fetch failed for user_id ' . $userId);
        }

        // then merge basic + additional user data
        $userdata = array_merge($row, $user_infos_row);

        foreach ($userdata as &$value) {
            // If the field is true or false, the variable is transformed into a boolean value.
            if ($value == 'true') {
                $value = true;
            } elseif ($value == 'false') {
                $value = false;
            }
        }
        unset($value);

        // Kept out of $userdata: unserialize()'s own return type is native
        // mixed, and merging a mixed value into $userdata here would widen
        // every other key's inferred type to mixed for the remainder of this
        // function. Merged back in just before the final return instead.
        $preferences_raw = $userdata['preferences'];
        $preferences = ! empty($preferences_raw) && is_string($preferences_raw)
            ? unserialize($preferences_raw)
            : [];

        if ($useCache) {
            $generate_user_cache = false;
            $cache_generation_token_name = 'generate_user_cache-u' . $userId;
            $exec_code = substr(sha1(random_bytes(1000)), 0, 4);
            $logger_msg_prefix = '[' . __METHOD__ . '][exec_code=' . $exec_code . '][user_id=' . $userId . '] ';

            if (! isset($userdata['need_update'])
                or ! is_bool($userdata['need_update'])
                or $userdata['need_update'] == true) {
                $logger->info($logger_msg_prefix . 'needs user_cache to be rebuilt');

                $exec_id = \Piwigo\Core\UniqueExecLock::begins($cache_generation_token_name);
                if ($exec_id === false) {
                    $logger->info($logger_msg_prefix . 'starts to wait for another request to build user_cache');
                    $user_cache_waiting_start_time = \Piwigo\Core\TimingHelper::getMoment();
                    for ($k = 0; $k < 20; $k++) {
                        sleep(1);

                        $query = '
SELECT
   COUNT(*)
  FROM ' . Tables::userCache() . '
  WHERE user_id=' . $userId . '
;';
                        $row = pwg_db_fetch_row(pwg_query($query));
                        assert($row !== null);
                        [$nb_cache_lines] = $row;

                        $logger_msg = $logger_msg_prefix . 'user_cache generation waiting k=' . $k . ' ';
                        $waiting_time = \Piwigo\Core\TimingHelper::getElapsedTime($user_cache_waiting_start_time, \Piwigo\Core\TimingHelper::getMoment());

                        if ($nb_cache_lines > 0) {
                            $logger->info($logger_msg . 'user_cache rebuilt, after waiting ' . $waiting_time);
                            return $this->getUserData($userId, false);
                        }
                        if (! \Piwigo\Core\UniqueExecLock::isRunning($cache_generation_token_name)) {
                            $logger->info($logger_msg . 'user_cache rebuilt but has been reset since, give it another try, after waiting ' . $waiting_time);
                            return $this->getUserData($userId, true);
                        } else {
                            $logger->info($logger_msg . 'user_cache not ready yet, after waiting ' . $waiting_time);
                        }
                    }

                    $logger->info($logger_msg_prefix . 'user_cache generation waiting has timed out after ' . \Piwigo\Core\TimingHelper::getElapsedTime($user_cache_waiting_start_time, \Piwigo\Core\TimingHelper::getMoment()));
                    set_status_header(503, 'Service Unavailable');
                    @header('Retry-After: 900');
                    header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                    echo l10n('Rebuilding user cache takes long. Please, come back later.');
                    echo str_repeat(' ', 512); // IE6 doesn't error output if below a size
                    exit();
                } else {
                    $generate_user_cache = true;
                }
            }

            if ($generate_user_cache) {
                $user_cache_generation_start_time = \Piwigo\Core\TimingHelper::getMoment();
                $cache_update_time = time();
                $userdata['cache_update_time'] = $cache_update_time;

                // Set need update are done
                $need_update = false;
                $userdata['need_update'] = $need_update;

                $status = $userdata['status'];
                assert(is_string($status));

                $categoryConn = DbConnection::build();
                $forbidden_categories = new PermissionService(
                    new PermissionRepository($categoryConn),
                    new GroupRepository($categoryConn)
                )->getForbiddenCategories($userId, $status);
                $userdata['forbidden_categories'] = $forbidden_categories;

                $level = $userdata['level'] ?? '0';
                assert(is_string($level));

                /* now we build the list of forbidden images (this list does not contain
                images that are not in at least an authorized category)*/
                $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE category_id NOT IN (' . $forbidden_categories . ')
    AND level>' . $level;
                $forbidden_ids = query2array($query, null, 'id');

                if (empty($forbidden_ids)) {
                    $forbidden_ids[] = 0;
                }
                $image_access_type = 'NOT IN'; // TODO maybe later
                $userdata['image_access_type'] = $image_access_type;
                $image_access_list = implode(',', $forbidden_ids);
                $userdata['image_access_list'] = $image_access_list;

                $query = '
SELECT COUNT(DISTINCT(image_id)) as total
  FROM ' . Tables::imageCategory() . '
  WHERE category_id NOT IN (' . $forbidden_categories . ')
    AND image_id ' . $image_access_type . ' (' . $image_access_list . ')';
                $row = pwg_db_fetch_row(pwg_query($query));
                assert($row !== null);
                [$nb_total_images] = $row;
                assert($nb_total_images !== null);
                $userdata['nb_total_images'] = $nb_total_images;

                // now we update user cache categories
                // CategoryService::getComputedCategories() takes $userdata by
                // reference and is declared with a generic array<string,
                // mixed> shape, so PHPStan can no longer track any of
                // $userdata's per-key types after this call -- every
                // subsequent read below goes through a freshly-narrowed
                // local variable instead of re-reading $userdata.
                $user_cache_cats = new CategoryService(
                    new CategoryRepository($categoryConn),
                    new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
                )->getComputedCategories($userdata, null);
                if (! AccessControl::isAdmin($status)) { // for non admins we forbid categories with no image (feature 1053)
                    $forbidden_ids = [];
                    foreach ($user_cache_cats as $cat) {
                        if ($cat['count_images'] == 0) {
                            $cat_id = $cat['cat_id'];
                            assert(is_string($cat_id));
                            $forbidden_ids[] = $cat_id;
                            CategoryService::removeComputedCategory($user_cache_cats, $cat);
                        }
                    }
                    if (! empty($forbidden_ids)) {
                        if (empty($forbidden_categories)) {
                            $forbidden_categories = implode(',', $forbidden_ids);
                        } else {
                            $forbidden_categories .= ',' . implode(',', $forbidden_ids);
                        }
                        $userdata['forbidden_categories'] = $forbidden_categories;
                    }
                }

                $last_photo_date = $userdata['last_photo_date'];
                assert($last_photo_date === null || is_string($last_photo_date));

                // delete user cache
                $query = '
DELETE FROM ' . Tables::userCacheCategories() . '
  WHERE user_id = ' . $userId;
                pwg_query($query);

                // Due to concurrency issues, we ask MySQL to ignore errors on
                // insert. This may happen when cache needs refresh and that Piwigo is
                // called "very simultaneously".
                mass_inserts(
                    Tables::userCacheCategories(),
                    [
                        'user_id', 'cat_id',
                        'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories',
                    ],
                    // mass_inserts() only reads values (row shape/data), never
                    // this array's own keys -- CategoryService::
                    // getComputedCategories() keys by cat_id (int|string, a
                    // raw DB fetch value) for the removeComputedCategory()
                    // lookups above, not relevant here
                    array_values($user_cache_cats),
                    [
                        'ignore' => true,
                    ]
                );

                // update user cache
                $query = '
DELETE FROM ' . Tables::userCache() . '
  WHERE user_id = ' . $userId;
                pwg_query($query);

                // boolean_to_string() only returns non-string when its input
                // isn't a bool (@return mixed in
                // dblayer/functions_mysqli.inc.php); $need_update is always a
                // real bool here, so the result is guaranteed to be a string.
                $need_update_str = boolean_to_string($need_update);
                assert(is_string($need_update_str));

                // for the same reason as user_cache_categories, we ignore error on
                // this insert
                $query = '
INSERT IGNORE INTO ' . Tables::userCache() . '
  (user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,
    last_photo_date,
    image_access_type, image_access_list)
  VALUES
  (' . $userId . ',\'' . $need_update_str . '\','
  . $cache_update_time . ',\''
  . $forbidden_categories . '\',' . $nb_total_images . ',' .
  (empty($last_photo_date) ? 'NULL' : '\'' . $last_photo_date . '\'') .
  ',\'' . $image_access_type . '\',\'' . $image_access_list . '\')';
                pwg_query($query);

                \Piwigo\Core\UniqueExecLock::ends($cache_generation_token_name);
                $logger->info($logger_msg_prefix . 'user_cache generated, executed in ' . \Piwigo\Core\TimingHelper::getElapsedTime($user_cache_generation_start_time, \Piwigo\Core\TimingHelper::getMoment()));
            }
        }

        $userdata['preferences'] = $preferences;

        return $userdata;
    }

    /**
     * Deletes favorites of the current user if they're not allowed to see
     * them.
     */
    public function checkUserFavorites(): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        if ($user['forbidden_categories'] == '') {
            return;
        }

        // user_infos.id (primary key, NOT NULL): a raw DB fetch value is a
        // numeric string, buildUser() may also set it as int -- either way
        // it's always scalar and safe to interpolate into SQL below.
        $user_id_val = $user['id'];
        $user_id_str = is_scalar($user_id_val) ? (string) $user_id_val : '0';

        // $filter['visible_categories'] and $filter['visible_images']
        // must be not used because filter <> restriction
        // retrieving images allowed : belonging to at least one authorized
        // category
        $query = '
SELECT DISTINCT f.image_id
  FROM ' . Tables::favorites() . ' AS f INNER JOIN ' . Tables::imageCategory() . ' AS ic
    ON f.image_id = ic.image_id
  WHERE f.user_id = ' . $user_id_str . '
  ' . new PermissionService(new PermissionRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))->getSqlConditionFandF(
            [
                'forbidden_categories' => 'ic.category_id',
            ],
            'AND'
        ) . '
;';
        $authorizeds = query2array($query, null, 'image_id');

        $query = '
SELECT image_id
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $user_id_str . '
;';
        $favorites = query2array($query, null, 'image_id');

        $to_deletes = array_diff($favorites, $authorizeds);
        if (count($to_deletes) > 0) {
            $query = '
DELETE FROM ' . Tables::favorites() . '
  WHERE image_id IN (' . implode(',', $to_deletes) . ')
    AND user_id = ' . $user_id_str . '
;';
            pwg_query($query);
        }
    }

    /**
     * Returns the default theme. If the default theme is not available it
     * returns the first available one.
     */
    public function getDefaultTheme(): string
    {
        $theme = $this->getDefaultUserValue('theme', AppInfo::DEFAULT_TEMPLATE);
        if (! is_string($theme)) {
            $theme = AppInfo::DEFAULT_TEMPLATE;
        }
        // deliberately bare -- same ExtensionLifecycleTest spy dependency
        // as checkAndSaveUserInfos()'s call above.
        if (check_theme_installed($theme)) {
            return $theme;
        }

        // let's find the first available theme
        $active_themes = array_keys(\Piwigo\Core\ThemeCatalog::getPwgThemes());
        return isset($active_themes[0]) ? (string) $active_themes[0] : 'default';
    }

    /**
     * Returns the default language.
     */
    #[\Override]
    public function getDefaultLanguage(): string
    {
        $language = $this->getDefaultUserValue('language', AppInfo::DEFAULT_LANGUAGE);
        return is_string($language) ? $language : AppInfo::DEFAULT_LANGUAGE;
    }

    /**
     * Tries to find the browser language among available languages.
     */
    public function getBrowserLanguage(): false|int|string
    {
        $language_header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        if (! is_string($language_header) || $language_header === '') {
            return false;
        }

        // case insensitive match
        // 'en-US;q=0.9, fr-CH, kok-IN;q=0.7' => 'en_us;q=0.9, fr_ch, kok_in;q=0.7'
        $language_header = strtolower(str_replace('-', '_', $language_header));
        $match_pattern = '/(([a-z]{1,8})(?:_[a-z0-9]{1,8})*)\s*(?:;\s*q\s*=\s*([01](?:\.[0-9]{0,3})?))?/';
        $matches = null;
        preg_match_all($match_pattern, $language_header, $matches);
        $accept_languages_full = $matches[1];  // ['en-us', 'fr-ch', 'kok-in']
        $accept_languages_short = $matches[2];  // ['en', 'fr', 'kok']
        if (! (bool) count($accept_languages_full)) {
            return false;
        }

        // if the quality value is absent for an language, use 1 as the default
        $q_values = $matches[3];  // ['0.9', '', '0.7']
        foreach ($q_values as $i => $q_value) {
            $q_values[$i] = ($q_values[$i] === '') ? 1 : floatval($q_values[$i]);
        }

        // since quick sort is not stable,
        // sort by $indices explicitly after sorting by $q_values
        $indices = range(1, count($q_values));
        array_multisort(
            $q_values,
            SORT_DESC,
            SORT_NUMERIC,
            $indices,
            SORT_ASC,
            SORT_NUMERIC,
            $accept_languages_full,
            $accept_languages_short
        );

        // list all enabled language codes in the Piwigo installation
        // in both full and short forms, and case insensitive
        $languages_available = [];
        foreach (\Piwigo\Lang\LangService::getLanguages() as $language_code => $language_name) {
            $lowercase_full = strtolower((string) $language_code);
            $lowercase_parts = explode('_', $lowercase_full, 2);
            $lowercase_prefix = $lowercase_parts[0];
            $languages_available[$lowercase_full] = $language_code;
            $languages_available[$lowercase_prefix] = $language_code;
        }

        foreach ($q_values as $i => $q_value) {
            // if the exact language variant is present, make sure it's chosen
            // en-US;q=0.9 => en_us => en_US
            if (array_key_exists($accept_languages_full[$i], $languages_available)) {
                return $languages_available[$accept_languages_full[$i]];
            }
            // only in case that an exact match was not available,
            // should we fallback to other variants in the same language family
            // fr_CH => fr => fr_FR
            if (array_key_exists($accept_languages_short[$i], $languages_available)) {
                return $languages_available[$accept_languages_short[$i]];
            }
        }

        return false;
    }

    /**
     * Returns sql WHERE condition for recent photos/albums for current
     * user.
     */
    public function getRecentPhotosSql(string $dbField): string
    {
        /** @var array<string, mixed> $user */
        global $user;
        if (! isset($user['last_photo_date'])) {
            return '0=1';
        }

        // same narrowing as get_icon()'s $recent_period handling in
        // functions.inc.php: a raw user_infos DB value, numeric string or int
        $recent_period = $user['recent_period'] ?? null;
        $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);

        $last_photo_date = $user['last_photo_date'];
        $last_photo_date = is_string($last_photo_date) ? $last_photo_date : '';

        return $dbField . '>=LEAST('
          . pwg_db_get_recent_period_expression($recent_period)
          . ',' . pwg_db_get_recent_period_expression(1, $last_photo_date) . ')';
    }

    /**
     * Register in the user session, the "context" of the last 10 viewed
     * images.
     *
     * @since 16
     */
    public function saveEditContext(): void
    {
        /** @var array<string, mixed> $page */
        global $page;

        if (! AccessControl::isAdmin() or ! isset($page['section_url']) or ! isset($page['image_id'])) {
            return;
        }

        // $page['image_id'] is int|numeric-string (include/section_init.inc.php
        // sets it from a URL token via is_numeric(), or the literal int 0),
        // $page['section_url'] always a string.
        $image_id = $page['image_id'];
        if (! is_int($image_id) && ! (is_string($image_id) && is_numeric($image_id))) {
            return;
        }
        $image_id = (int) $image_id;
        $section_url = $page['section_url'];
        $section_url = is_string($section_url) ? $section_url : '';

        $edit_context = $_SESSION['edit_context'] ?? null;
        if (! is_array($edit_context)) {
            $edit_context = [];
        }

        // the $page['section_url'] is set in the include/section_init script. It
        // contains the URL describing the "context" of the photo. Examples:
        //
        // * /198/list/2,69,198
        // * /198/category/18801-yes_man
        // * /198/tags/27-city_nantes/28-city_rennes
        // * /198/search/psk-20251103-lqCHHAFSZY/posted-monthly-list-2025-3
        //
        // same photo #198 in different context. We need it to propose the best
        // return page on the photo edit page in the administration.

        // let's add the item on top of previous registered values and keep only the last 10 values
        $_SESSION['edit_context'] = array_slice([
            $image_id => $section_url,
        ] + $edit_context, 0, 10, true);
    }

    /**
     * Returns the "context" of the requested image.
     *
     * @since 16
     */
    public function getEditContext(int $imageId): false|string|null
    {
        $edit_context = $_SESSION['edit_context'] ?? null;
        if (! is_array($edit_context) || ! isset($edit_context[$imageId])) {
            return false;
        }

        $value = $edit_context[$imageId];
        if (! is_string($value)) {
            return false;
        }

        return preg_replace('/^\/' . $imageId . '\//', '', $value);
    }

    /**
     * Check all user infos and save parameters.
     *
     * @since 16
     * @param mixed[] $params
     *    @option string username (optional)
     *    @option string password (optional)
     *    @option string email (optional)
     *    @option string status (optional)
     *    @option int level (optional)
     *    @option string language (optional)
     *    @option string theme (optional)
     *    @option int nb_image_page (optional)
     *    @option int recent_period (optional)
     *    @option bool expand (optional)
     *    @option bool show_nb_comments (optional)
     *    @option bool show_nb_hits (optional)
     *    @option bool enabled_high (optional)
     * @return mixed[]
     */
    public function checkAndSaveUserInfos(array $params): array
    {
        if (isset($params['username'])) {
            $username_check = $params['username'];
            assert(is_string($username_check));
            if (strlen(str_replace(' ', '', $username_check)) == 0) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'Name field must not be empty',
                    ],
                ];
            }
        }

        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         */
        global $conf, $user;

        // see validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $updates = $updates_infos = [];
        $update_status = null;
        $user_ids_for_status = [];

        // real callers (ws_users_setInfo/ws_users_setPreferences) always pass
        // 'user_id' as a list of ints (WS_TYPE_ID-coerced) or numeric strings
        // (the global $user['id'] raw DB value); normalize once here so every
        // usage below is a well-typed int.
        assert(is_array($params['user_id']));
        $user_ids = [];
        foreach ($params['user_id'] as $raw_user_id) {
            assert(is_int($raw_user_id) || (is_string($raw_user_id) && is_numeric($raw_user_id)));
            $user_ids[] = (int) $raw_user_id;
        }

        if (count($user_ids) == 1) {
            if ($this->getUsername($user_ids[0]) === false) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'This user does not exist.',
                    ],
                ];
            }

            if (! empty($params['username'])) {
                $username_param = $params['username'];
                assert(is_string($username_param));
                $user_id = $this->getUserId($username_param);
                if ((bool) $user_id and $user_id != $user_ids[0]) {
                    return [
                        'error' => [
                            'code' => WS_ERR_INVALID_PARAM,
                            'message' => l10n('this login is already used'),
                        ],
                    ];
                }
                if ($username_param != strip_tags($username_param)) {
                    return [
                        'error' => [
                            'code' => WS_ERR_INVALID_PARAM,
                            'message' => l10n('html tags are not allowed in login'),
                        ],
                    ];
                }
                $updates[$user_fields['username']] = $username_param;
            }

            if (! empty($params['email'])) {
                $email_param = $params['email'];
                assert(is_string($email_param));
                if (($error = $this->validateMailAddress($user_ids[0], $email_param)) != '') {
                    return [
                        'error' => [
                            'code' => WS_ERR_INVALID_PARAM,
                            'message' => $error,
                        ],
                    ];
                }
                $updates[$user_fields['email']] = $email_param;
            }

            if (! empty($params['password'])) {
                if (! AccessControl::isWebmaster()) {
                    $password_protected_users = [$conf['guest_id']];

                    $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
                    $admin_ids = query2array($query, null, 'user_id');

                    // user_infos.id (primary key, NOT NULL): a raw DB fetch
                    // value is a numeric string, buildUser() may also set it as
                    // int -- either way it's always scalar and string-castable.
                    $current_user_id_val = $user['id'];
                    $current_user_id_str = is_scalar($current_user_id_val) ? (string) $current_user_id_val : '0';

                    // we add all admin+webmaster users BUT the user herself
                    $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id_str]));

                    if (in_array($user_ids[0], $password_protected_users)) {
                        return [
                            'error' => [
                                'code' => 403,
                                'message' => 'Only webmasters can change password of other "webmaster/admin" users',
                            ],
                        ];
                    }
                }

                $password_param = $params['password'];
                assert(is_string($password_param));
                $updates[$user_fields['password']] = new PasswordService(new PasswordRepository(DbConnection::build()))->hash($password_param);
            }
        }

        if (! empty($params['status'])) {
            if (in_array($params['status'], ['webmaster', 'admin']) and ! AccessControl::isWebmaster()) {
                return [
                    'error' => [
                        'code ' => 403,
                        'message' => 'Only webmasters can grant "webmaster/admin" status',
                    ],
                ];
            }

            if (! in_array($params['status'], ['guest', 'generic', 'normal', 'admin', 'webmaster'])) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'Invalid status',
                    ],
                ];
            }

            // user['id']/conf's guest_id/webmaster_id are always scalar (raw DB
            // fetch value / int config values) and string-castable.
            $protected_users = array_filter(
                [
                    $user['id'],
                    $conf['guest_id'],
                    $conf['webmaster_id'],
                ],
                is_scalar(...)
            );

            // an admin can't change status of other admin/webmaster
            if ($user['status'] == 'admin') {
                $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
                $protected_users = array_merge($protected_users, query2array($query, null, 'user_id'));
            }

            // status update query is separated from the rest as not applying to the same
            // set of users (current, guest and webmaster can't be changed)
            $user_ids_for_status = array_diff($user_ids, array_filter($protected_users, is_scalar(...)));

            $status_param = $params['status'];
            assert(is_string($status_param));
            $update_status = $status_param;
        }

        if (! empty($params['level']) or @$params['level'] === 0) {
            // $conf['available_permission_levels'] defaults to [0, 1, 2, 4, 8]
            // (see include/config_default.inc.php), always an array
            $available_permission_levels = $conf['available_permission_levels'];
            $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];
            if (! in_array($params['level'], $available_permission_levels)) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'Invalid level',
                    ],
                ];
            }
            $updates_infos['level'] = $params['level'];
        }

        if (! empty($params['language'])) {
            if (! in_array($params['language'], array_keys(\Piwigo\Lang\LangService::getLanguages()))) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'Invalid language',
                    ],
                ];
            }
            $updates_infos['language'] = $params['language'];
        }

        if (! empty($params['theme'])) {
            if (! in_array($params['theme'], array_keys(\Piwigo\Core\ThemeCatalog::getPwgThemes()))) {
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => 'Invalid theme',
                    ],
                ];
            }
            $updates_infos['theme'] = $params['theme'];
        }

        if (! empty($params['nb_image_page'])) {
            $updates_infos['nb_image_page'] = $params['nb_image_page'];
        }

        if (! empty($params['recent_period']) or @$params['recent_period'] === 0) {
            $updates_infos['recent_period'] = $params['recent_period'];
        }

        if (! empty($params['expand']) or @$params['expand'] === false) {
            $updates_infos['expand'] = boolean_to_string($params['expand']);
        }

        if (! empty($params['show_nb_comments']) or @$params['show_nb_comments'] === false) {
            $updates_infos['show_nb_comments'] = boolean_to_string($params['show_nb_comments']);
        }

        if (! empty($params['show_nb_hits']) or @$params['show_nb_hits'] === false) {
            $updates_infos['show_nb_hits'] = boolean_to_string($params['show_nb_hits']);
        }

        if (! empty($params['enabled_high']) or @$params['enabled_high'] === false) {
            $updates_infos['enabled_high'] = boolean_to_string($params['enabled_high']);
        }

        // perform updates
        single_update(
            Tables::users(),
            $updates,
            [
                $user_fields['id'] => $user_ids[0],
            ]
        );

        $authService = new AuthService(new AuthRepository(DbConnection::build()), $this->activityLogger);

        if (isset($updates[$user_fields['password']])) {
            $authService->deactivateUserAuthKeys($user_ids[0]);
        }

        if (isset($updates[$user_fields['email']])) {
            $authService->deactivatePasswordResetKey($user_ids[0]);
        }

        if (isset($update_status) and count($user_ids_for_status) > 0) {
            $query = '
UPDATE ' . Tables::userInfos() . ' SET
    status = "' . $update_status . '"
  WHERE user_id IN(' . implode(',', array_map(strval(...), $user_ids_for_status)) . ')
;';
            pwg_query($query);

            // we delete sessions, ie disconnect, for users if status becomes "guest".
            // It's like deactivating the user.
            if ($update_status == 'guest') {
                foreach ($user_ids_for_status as $user_id_for_status) {
                    SessionService::get()->deleteUserSessions($user_id_for_status);
                }
            }
        }

        if (count($updates_infos) > 0) {
            $query = '
UPDATE ' . Tables::userInfos() . ' SET ';

            $first = true;
            foreach ($updates_infos as $field => $value) {
                if (! $first) {
                    $query .= ', ';
                } else {
                    $first = false;
                }
                assert(is_scalar($value));
                $query .= $field . ' = "' . $value . '"';
            }

            $query .= '
  WHERE user_id IN(' . implode(',', array_map(strval(...), $user_ids)) . ')
;';
            pwg_query($query);
        }

        // manage association to groups
        if (! empty($params['group_id'])) {
            $group_id_param = $params['group_id'];
            assert(is_array($group_id_param));
            $group_ids_param = [];
            foreach ($group_id_param as $raw_group_id) {
                assert(is_int($raw_group_id) || (is_string($raw_group_id) && is_numeric($raw_group_id)));
                $group_ids_param[] = (int) $raw_group_id;
            }

            $query = '
DELETE
  FROM ' . Tables::userGroup() . '
  WHERE user_id IN (' . implode(',', array_map(strval(...), $user_ids)) . ')
;';
            pwg_query($query);

            // we remove all provided groups that do not really exist
            $query = '
SELECT
    id
  FROM `' . Tables::groups() . '`
  WHERE id IN (' . implode(',', array_map(strval(...), $group_ids_param)) . ')
;';
            $group_ids = query2array($query, null, 'id');

            // if only -1 (a group id that can't exist) is in the list, then no
            // group is associated

            if (count($group_ids) > 0) {
                $inserts = [];

                foreach ($group_ids as $group_id) {
                    foreach ($user_ids as $user_id) {
                        $inserts[] = [
                            'user_id' => $user_id,
                            'group_id' => $group_id,
                        ];
                    }
                }

                mass_inserts(Tables::userGroup(), array_keys($inserts[0]), $inserts);
            }
        }

        UserCacheInvalidator::invalidate();

        $this->activityLogger->record('user', $user_ids, 'edit');

        return [
            'user_id' => $params['user_id'],
            'infos' => $updates_infos,
            'account' => $updates,
        ];
    }

    /**
     * Synchronize base users list and related users list.
     *
     * Compares and synchronizes the base users table (`Tables::users()`)
     * with its child tables (`Tables::userInfos()`, USER_ACCESS,
     * USER_CACHE, USER_GROUP): each base user must be present in child
     * tables, users in child tables not present in base table must be
     * deleted.
     *
     * P23 batch 8d file 3: physically grouped with the Categories domain
     * in `admin/include/functions.php` (file-position, not real domain),
     * but touches only user-related tables and internally constructs
     * {@see createUserInfos()} -- belongs here, not
     * `Piwigo\Category\CategoryService`.
     */
    public function syncUsers(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $userFields = $conf['user_fields'];
        $userIdField = is_array($userFields) && is_string($userFields['id'] ?? null) ? $userFields['id'] : 'id';

        $baseUsers = $this->repo->findAllUserIds($userIdField);
        $infosUsers = $this->repo->findDistinctUserIdsInTable(Tables::userInfos());

        // users present in $baseUsers and not in $infosUsers must be added
        $toCreate = array_diff($baseUsers, $infosUsers);

        if (count($toCreate) > 0) {
            $this->createUserInfos($toCreate);
        }

        // users present in user related tables must be present in the base user
        // table
        $tables = [
            Tables::userMailNotification(),
            Tables::userFeed(),
            Tables::userInfos(),
            Tables::userAccess(),
            Tables::userCache(),
            Tables::userCacheCategories(),
            Tables::userGroup(),
        ];

        foreach ($tables as $table) {
            $toDelete = array_diff(
                $this->repo->findDistinctUserIdsInTable($table),
                $baseUsers
            );

            if (count($toDelete) > 0) {
                $this->repo->deleteUsersFromTable($table, $toDelete);
            }
        }
    }
}
