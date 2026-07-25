<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\UserCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\EffectiveForbiddenCategoriesCache;
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
final readonly class UserService implements DefaultLanguageProviderInterface
{
    public function __construct(
        private UserRepository $repo,
        private GroupRepository $groupRepo,
        private MailerInterface $mailer,
        private ActivityLoggerInterface $activityLogger,
        private HtmlRenderingInterface $htmlRenderer,
        private Connection $conn,
    ) {}

    /**
     * Phase 1k DI-chain audit: the same PermissionService recipe was
     * repeated verbatim at 3 call sites in this file. Not a constructor
     * param -- $conn is already available, and readonly class means no
     * memoized property, so this is a plain (non-memoized) DRY extraction,
     * not a caching optimization.
     */
    private function permissionService(): PermissionService
    {
        return new PermissionService(new PermissionRepository($this->conn), new GroupRepository($this->conn), new CategoryRepository($this->conn));
    }

    /**
     * Same reasoning as permissionService() above -- gap-closure Stage 4b
     * (docs/plan/gap-closure-p0-p23.md) added a 2nd `new CategoryService(new
     * CategoryRepository($this->conn), $this->permissionService())` call to
     * this file's own getUserData(), repeating the existing one verbatim.
     */
    private function categoryService(): CategoryService
    {
        return new CategoryService(new CategoryRepository($this->conn), $this->permissionService());
    }

    /**
     * Same reasoning as permissionService() above -- the same
     * PasswordService recipe was repeated verbatim at 2 call sites.
     */
    private function passwordService(): PasswordService
    {
        return new PasswordService(new PasswordRepository($this->conn));
    }

    /**
     * Checks if an email is well formed and not already in use. Returns an
     * error message, or '' when the address is fine / not required.
     */
    public function validateMailAddress(?int $userId, ?string $mailAddress): string
    {

        $isEmpty = $mailAddress === null || $mailAddress === '';
        if (
            $isEmpty
            && ! (\Piwigo\Config\CurrentConfig::obligatoryUserMailAddress() && in_array(\Piwigo\Core\PageFilterHelper::scriptBasename(), ['register', 'profile'], true))
        ) {
            return '';
        }

        if (! \Piwigo\Validation\InputValidator::checkEmailFormat($mailAddress)) {
            return Lang::t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (\Piwigo\Core\InstallationFlag::isActive() && ! $isEmpty) {
            /** @var array<string, string> $user_fields */
            $user_fields = \Piwigo\Config\CurrentConfig::userFields();

            if ($this->repo->emailExists($mailAddress, $user_fields['email'], $user_fields['id'], $userId)) {
                return Lang::t('this email address is already in use');
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

        if (\Piwigo\Core\InstallationFlag::isActive()) {
            /** @var array<string, string> $user_fields */
            $user_fields = \Piwigo\Config\CurrentConfig::userFields();

            if ($this->repo->usernameExistsCaseInsensitive($login, $user_fields['username'])) {
                return Lang::t('this login is already used');
            }
        }

        return '';
    }

    /**
     * Searches for a user with the same username in a different case.
     */
    public function searchCaseUsername(string $username): string
    {

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

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

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        return $this->repo->findIdByUsername($username, $user_fields['id'], $user_fields['username']);
    }

    public function getUserIdByEmail(string $email): int|false
    {

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        return $this->repo->findIdByEmail($email, $user_fields['id'], $user_fields['email']);
    }

    /**
     * Ported from admin/include/functions.php's get_username() (P23 batch
     * 8d), unchanged logic (including the stripslashes() call -- a real,
     * already-established precedent in this same file, not legacy cruft).
     */
    public function getUsername(int $userId): false|string
    {

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

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
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('delete_user', $userId);
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
        UrlServiceInterface $urlService,
        bool $notifyAdmin = true,
        bool $notifyUser = false
    ): array {

        $errors = [];
        $duplicateUsername = false;

        if ($login === '') {
            $errors[] = Lang::t('Please, enter a login');
        }
        if (preg_match('/^.* $/', $login) === 1) {
            $errors[] = Lang::t('login mustn\'t end with a space character');
        }
        if (preg_match('/^ .*$/', $login) === 1) {
            $errors[] = Lang::t('login mustn\'t start with a space character');
        }
        if ($this->getUserId($login) !== false) {
            $duplicateUsername = true;
        }
        if ($login !== strip_tags($login)) {
            $errors[] = Lang::t('html tags are not allowed in login');
        }

        $mailError = $this->validateMailAddress(null, $mailAddress);
        if ($mailError !== '') {
            $errors[] = $mailError;
        }

        if (\Piwigo\Config\CurrentConfig::insensitiveCaseLogon() && ! $duplicateUsername) {
            if ($this->validateLoginCase($login) !== '') {
                $duplicateUsername = true;
            }
        }

        $errorsAfterTrigger = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
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
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $userId = $this->repo->insertUser([
            $user_fields['username'] => $login,
            $user_fields['password'] => new \Piwigo\Auth\PasswordService(new \Piwigo\Auth\PasswordRepository($this->conn))->hash($password),
            $user_fields['email'] => $mailAddress,
        ]);

        $defaultGroupIds = $this->groupRepo->findDefaultGroupIds();
        if ($defaultGroupIds !== []) {
            $this->groupRepo->addMembers($userId, $defaultGroupIds);
        }

        $override = [];
        if (\Piwigo\Config\CurrentConfig::browserLanguage() && ($language = $this->getBrowserLanguage()) !== false) {
            $override['language'] = $language;
        }

        $this->createUserInfos([$userId], $override);

        $emailAdminOnNewUserSetting = \Piwigo\Config\CurrentConfig::emailAdminOnNewUser();
        if ($notifyAdmin && $emailAdminOnNewUserSetting !== 'none') {
            $this->notifyAdminsOfNewRegistration($userId, $login, $mailAddress, $urlService);
        }

        if ($notifyUser && \Piwigo\Validation\InputValidator::checkEmailFormat($mailAddress)) {
            assert($mailAddress !== null);
            $this->sendWelcomeEmail($login, $mailAddress, $urlService);
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify(
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

        if ($userIds === []) {
            return;
        }

        $defaultUser = $this->getDefaultUserInfo();
        if ($defaultUser === false) {
            $defaultUser = [];
        }

        if ($overrideValues !== null) {
            $defaultUser = array_merge($defaultUser, $overrideValues);
        }

        $availablePermissionLevels = \Piwigo\Config\CurrentConfig::availablePermissionLevels();
        // CurrentConfig::webmasterId()/guestId()/defaultUserId() are declared
        // non-nullable `int` with their own safe hardcoded fallback
        // defaults (2/1/guest_id respectively, matching config_default.
        // inc.php) -- gating on CurrentConfig::has() first was wrong: on any
        // no-boot request path (e.g. install.php, which never runs
        // Kernel::boot()/ConfigLoader) these keys are never explicitly
        // loaded into Config's backing store, so has() is false and every
        // user silently fell through to 'normal' status, including the
        // webmaster and guest accounts install.php itself just created.
        // Found live via a real fixture-regen run, not assumed.
        $webmasterId = (string) \Piwigo\Config\CurrentConfig::webmasterId();
        $guestId = (string) \Piwigo\Config\CurrentConfig::guestId();
        $defaultUserId = (string) \Piwigo\Config\CurrentConfig::defaultUserId();

        foreach ($userIds as $userId) {
            $level = $defaultUser['level'] ?? 0;
            $userIdStr = (string) $userId;
            if ($userIdStr === $webmasterId) {
                $status = 'webmaster';
                $level = $availablePermissionLevels !== []
                    ? max($availablePermissionLevels)
                    : 0;
            } elseif ($userIdStr === $guestId || $userIdStr === $defaultUserId) {
                $status = 'guest';
            } else {
                $status = 'normal';
            }

            $row = array_merge(
                $defaultUser,
                [
                    'status' => $status,
                    // Env::now() respects the frozen test-mode clock the
                    // same way pwg_activity()'s own timestamp does --
                    // real behavior outside test mode is unaffected.
                    'registration_date' => Env::now()
                        ->format('Y-m-d H:i:s'),
                    // Otherwise relies on the schema's own DEFAULT
                    // CURRENT_TIMESTAMP, which reads the real DB-server
                    // clock -- invisible to Env::now()'s freeze, same
                    // reasoning as registration_date above.
                    'lastmodified' => Env::now()
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
    public function getDefaultUserInfo(): array|false
    {
        if (! \Piwigo\Core\ProcessCache::has('default_user')) {
            $defaultUserId = \Piwigo\Config\CurrentConfig::defaultUserId();

            $row = $this->repo->findDefaultUserInfoRow($defaultUserId);
            if ($row !== null) {
                $rowArray = $row->toArray();
                unset($rowArray['user_id'], $rowArray['status'], $rowArray['registration_date'], $rowArray['last_visit'], $rowArray['last_visit_from_history']);
                \Piwigo\Core\ProcessCache::set('default_user', $rowArray);
            } else {
                \Piwigo\Core\ProcessCache::set('default_user', false);
            }
        }

        $defaultUserCached = \Piwigo\Core\ProcessCache::get('default_user');
        if (! is_array($defaultUserCached)) {
            return false;
        }

        // Used to take a $convertStr param, converting expand/
        // show_nb_comments/show_nb_hits/enabled_high from the table's own
        // enum('true','false') string form via a generic `$value ===
        // 'true'` scan -- retired along with the Stage 1a retype:
        // {@see \Piwigo\Users\Projection\UserInfo::fromRow()} already
        // returns those 4 as real bool, once, before this array is even
        // cached, so there's nothing left to conditionally convert.
        /** @var array<string, mixed> $defaultUserCached */
        return $defaultUserCached;
    }

    public function getDefaultUserValue(string $valueName, mixed $default): mixed
    {
        $defaultUser = $this->getDefaultUserInfo();
        if ($defaultUser === false || self::emptyValue($defaultUser[$valueName] ?? null)) {
            return $default;
        }

        return $defaultUser[$valueName];
    }

    private function notifyExistingAccountOfDuplicateRegistration(string $login, ?string $mailAddress): void
    {

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $existing = $this->repo->findByUsernameCaseInsensitive($login, $user_fields['id'], $user_fields['username'], $user_fields['email']);
        if ($existing === null || $existing['email'] === '' || ! \Piwigo\Validation\InputValidator::checkEmailFormat($existing['email'])) {
            return;
        }

        $gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();

        $this->mailer->mail(
            $existing['email'],
            [
                'subject' => '[' . $gallery_title . '] ' . Lang::t('Registration'),
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

    private function notifyAdminsOfNewRegistration(int $userId, string $login, ?string $mailAddress, UrlServiceInterface $urlService): void
    {

        $adminUrl = $urlService->getAbsoluteRootUrl() . 'admin.php?page=user_list&user_id=' . $userId;

        $keyargsContent = [
            Lang::buildArgs('User: %s', stripslashes($login)),
            Lang::buildArgs('Email: %s', $mailAddress),
            Lang::buildArgs(''),
            Lang::buildArgs('Admin: %s', $adminUrl),
        ];

        $groupId = null;
        $emailAdminOnNewUser = \Piwigo\Config\CurrentConfig::emailAdminOnNewUser();
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

    private function sendWelcomeEmail(string $login, string $mailAddress, UrlServiceInterface $urlService): void
    {

        $length = mt_rand(10, 15);
        $keyargsContent = [
            Lang::buildArgs('Hello %s,', stripslashes($login)),
            Lang::buildArgs('Thank you for registering at %s!', \Piwigo\Config\CurrentConfig::galleryTitle()),
            Lang::buildArgs('', ''),
            Lang::buildArgs('Here are your connection settings', ''),
            Lang::buildArgs('', ''),
            Lang::buildArgs('Link: %s', $urlService->getAbsoluteRootUrl()),
            Lang::buildArgs('Username: %s', stripslashes($login)),
            Lang::buildArgs('Password: %s', str_repeat('*', $length)),
            Lang::buildArgs('Email: %s', $mailAddress),
            Lang::buildArgs('', ''),
            Lang::buildArgs('If you think you\'ve received this email in error, please contact us at %s', new \Piwigo\Users\UserRepository($this->conn)->getWebmasterMailAddress()),
        ];

        $gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();

        $this->mailer->mail(
            $mailAddress,
            [
                'subject' => '[' . $gallery_title . '] ' . Lang::t('Registration'),
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

        $user = [];
        $user['id'] = $userId;
        $user = array_merge($user, $this->getUserData($userId, $useCache));

        $userStatusValue = $user['status'] ?? null;
        if (is_numeric($user['id']) and (int) $user['id'] === \Piwigo\Config\CurrentConfig::guestId()
            and (! is_string($userStatusValue) or $userStatusValue !== 'guest')) {
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
        if (! isset($user['theme_name']) or ! is_string($theme) or ! \Piwigo\Core\ThemeCatalog::checkThemeInstalled($theme)) {
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
        $logger = \Piwigo\Core\CurrentLogger::get();

        // see validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

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

        $row = $this->conn->fetchAssociative($query);
        if ($row === false) {
            throw new \Exception('UserService::getUserData(): no such user_id ' . $userId);
        }

        // retrieve additional user data ?
        if (\Piwigo\Config\DeploymentPolicy::current()->externalAuthentification) {
            $query = '
SELECT
    COUNT(1) AS counter
  FROM ' . Tables::userInfos() . ' AS ui
    LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
    LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
  WHERE ui.user_id = ' . $userId . '
  GROUP BY ui.user_id
;';
            $counter_row = $this->conn->fetchNumeric($query);
            $counter = $counter_row !== false && is_numeric($counter_row[0]) ? (int) $counter_row[0] : 0;
            if ($counter !== 1) {
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

        $user_infos_row = $this->conn->fetchAssociative($query);
        if ($user_infos_row === false) {
            throw new \Exception('UserService::getUserData(): user_infos fetch failed for user_id ' . $userId);
        }

        // then merge basic + additional user data
        $userdata = array_merge($row, $user_infos_row);

        foreach ($userdata as &$value) {
            // If the field is true or false, the variable is transformed into a boolean value.
            if (is_scalar($value) && (string) $value === 'true') {
                $value = true;
            } elseif (is_scalar($value) && (string) $value === 'false') {
                $value = false;
            }
        }
        unset($value);

        // Docs/PLAN-REPLAY-AUDIT.md gap-closure, User domain Stage 1a (+
        // UserCache domain Stage 1a for need_update): enabled_high/expand/
        // last_visit_from_history/show_nb_comments/show_nb_hits
        // (user_infos) and need_update (user_cache, joined into this same
        // $userdata array) are all real tinyint columns now -- the
        // generic true/false-string scan above only ever matched the
        // *old* enum('true','false') representation, so it silently
        // stops converting these to bool (DBAL/mysqli returns a native
        // int for a tinyint column). Named explicitly instead of
        // pattern-matched by value, same fix as
        // CategoryService::getCategoryInfo(). need_update specifically
        // feeds the `! is_bool($userdata['need_update'])` check just
        // below -- left un-normalized, every request would wrongly think
        // the cache always needs rebuilding.
        foreach (['enabled_high', 'expand', 'last_visit_from_history', 'show_nb_comments', 'show_nb_hits', 'need_update'] as $k) {
            if (isset($userdata[$k])) {
                $userdata[$k] = (bool) $userdata[$k];
            }
        }

        // Kept out of $userdata: ArrayHelper::safeJsonDecode()'s own return
        // type is array<int|string, mixed>, and merging that into
        // $userdata here would widen every other key's inferred type to
        // mixed for the remainder of this function. Merged back in just
        // before the final return instead.
        $preferences_raw = $userdata['preferences'];
        $preferences = ! self::emptyValue($preferences_raw) && is_string($preferences_raw)
            ? \Piwigo\Core\ArrayHelper::safeJsonDecode($preferences_raw)
            : [];

        if ($useCache) {
            $generate_user_cache = false;
            $cache_generation_token_name = 'generate_user_cache-u' . $userId;
            $exec_code = substr(sha1(random_bytes(1000)), 0, 4);
            $logger_msg_prefix = '[' . __METHOD__ . '][exec_code=' . $exec_code . '][user_id=' . $userId . '] ';

            if (! isset($userdata['need_update'])
                or ! is_bool($userdata['need_update'])
                or $userdata['need_update']) {
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
                        $row = $this->conn->fetchNumeric($query);
                        assert($row !== false);
                        $nb_cache_lines = $row[0] ?? null;

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
                    $this->htmlRenderer->setStatusHeader(503, 'Service Unavailable');
                    @header('Retry-After: 900');
                    header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                    echo Lang::t('Rebuilding user cache takes long. Please, come back later.');
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

                $forbidden_categories = $this->permissionService()
                    ->getForbiddenCategories($userId, $status);
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
                $forbidden_ids = array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    array_column($this->conn->fetchAllAssociative($query), 'id')
                );

                if ($forbidden_ids === []) {
                    $forbidden_ids[] = '0';
                }
                $image_access_type = 'NOT IN';
                $userdata['image_access_type'] = $image_access_type;
                $image_access_list = implode(',', $forbidden_ids);
                $userdata['image_access_list'] = $image_access_list;

                $query = '
SELECT COUNT(DISTINCT(image_id)) as total
  FROM ' . Tables::imageCategory() . '
  WHERE category_id NOT IN (' . $forbidden_categories . ')
    AND image_id ' . $image_access_type . ' (' . $image_access_list . ')';
                $row = $this->conn->fetchNumeric($query);
                assert($row !== false);
                $nb_total_images = $row[0] ?? null;
                $nb_total_images = is_scalar($nb_total_images) ? (string) $nb_total_images : '0';
                $userdata['nb_total_images'] = $nb_total_images;

                // now we update user cache categories
                $computed_categories = $this->categoryService()
                    ->getComputedCategories($userdata, null);
                $user_cache_cats = $computed_categories['categories'];
                if (! AccessControl::isAdmin($status)) { // for non admins we forbid categories with no image (feature 1053)
                    $forbidden_ids = [];
                    foreach ($user_cache_cats as $cat) {
                        if ((is_numeric($cat['count_images']) ? (int) $cat['count_images'] : 0) === 0) {
                            $cat_id = $cat['cat_id'];
                            assert(is_string($cat_id));
                            $forbidden_ids[] = $cat_id;
                            CategoryService::removeComputedCategory($user_cache_cats, $cat);
                        }
                    }
                    if ($forbidden_ids !== []) {
                        if ($forbidden_categories === '') {
                            $forbidden_categories = implode(',', $forbidden_ids);
                        } else {
                            $forbidden_categories .= ',' . implode(',', $forbidden_ids);
                        }
                        $userdata['forbidden_categories'] = $forbidden_categories;
                    }
                }

                $last_photo_date = $computed_categories['lastPhotoDate'];
                assert($last_photo_date === null || is_string($last_photo_date));

                // delete user cache
                $query = '
DELETE FROM ' . Tables::userCacheCategories() . '
  WHERE user_id = ' . $userId;
                $this->conn->executeStatement($query);

                // Due to concurrency issues, we ask MySQL to ignore errors on
                // insert. This may happen when cache needs refresh and that Piwigo is
                // called "very simultaneously".
                new BatchWriter($this->conn)
                    ->massInsert(
                        Tables::userCacheCategories(),
                        [
                            'user_id', 'cat_id',
                            'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories',
                        ],
                        // BatchWriter::massInsert() only reads values (row shape/data), never
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
                $this->conn->executeStatement($query);

                // need_update is a real tinyint(1) column now (UserCache
                // domain Stage 1a) -- a numeric literal, not the old
                // enum('true','false') string; SqlDialect::booleanToInt()
                // only returns non-int when its input isn't a bool,
                // $need_update is always a real bool here, so the result
                // is guaranteed to be an int.
                $need_update_int = SqlDialect::booleanToInt($need_update);
                assert(is_int($need_update_int));

                // for the same reason as user_cache_categories, we ignore error on
                // this insert
                $query = '
INSERT IGNORE INTO ' . Tables::userCache() . '
  (user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,
    last_photo_date,
    image_access_type, image_access_list)
  VALUES
  (' . $userId . ',' . $need_update_int . ','
  . $cache_update_time . ',\''
  . $forbidden_categories . '\',' . $nb_total_images . ',' .
  (self::emptyValue($last_photo_date) ? 'NULL' : '\'' . $last_photo_date . '\'') .
  ',\'' . $image_access_type . '\',\'' . $image_access_list . '\')';
                $this->conn->executeStatement($query);

                \Piwigo\Core\UniqueExecLock::ends($cache_generation_token_name);
                $logger->info($logger_msg_prefix . 'user_cache generated, executed in ' . \Piwigo\Core\TimingHelper::getElapsedTime($user_cache_generation_start_time, \Piwigo\Core\TimingHelper::getMoment()));
            }
        }

        // Gap-closure Stage 4b/4c/4d (docs/plan/gap-closure-p0-p23.md):
        // overwrite the (possibly stale, $useCache-gated) values read from
        // `user_cache` above with a fresh cache-pool-backed computation,
        // unconditionally -- the real cutover for these 4 columns. The
        // `$useCache` block above still runs and still writes to
        // `user_cache` until Stage 4g removes it outright; nothing
        // meaningfully reads that write's own output anymore after this.
        $effective_status = $userdata['status'];
        assert(is_string($effective_status));
        $effective_level_raw = $userdata['level'] ?? '0';
        // DBAL returns user_infos.level (a tinyint column) as a native
        // int, not the mysqli-style string this file's own older $level
        // reads elsewhere assumed -- EffectiveForbiddenCategoriesCache
        // accepts either.
        $effective_level = is_int($effective_level_raw) || is_string($effective_level_raw) ? $effective_level_raw : '0';

        $effective = new EffectiveForbiddenCategoriesCache(
            $this->permissionService(),
            $this->categoryService(),
            $this->conn,
            \Piwigo\Cache\CachePools::effectivePermissions()
        )->getForUser($userId, $effective_status, $effective_level);

        $userdata['forbidden_categories'] = $effective['forbiddenCategories'];
        $userdata['image_access_type'] = $effective['imageAccessType'];
        $userdata['image_access_list'] = $effective['imageAccessList'];
        $userdata['nb_total_images'] = $effective['nbTotalImages'];

        $userdata['preferences'] = $preferences;

        return $userdata;
    }

    /**
     * Deletes favorites of the current user if they're not allowed to see
     * them.
     */
    public function checkUserFavorites(): void
    {
        $currentUser = CurrentUser::get();

        if ($currentUser->forbiddenCategories === '') {
            return;
        }

        $user_id_str = (string) $currentUser->id;

        // $filter['visible_categories'] and $filter['visible_images']
        // must be not used because filter <> restriction
        // retrieving images allowed : belonging to at least one authorized
        // category
        $query = '
SELECT DISTINCT f.image_id
  FROM ' . Tables::favorites() . ' AS f INNER JOIN ' . Tables::imageCategory() . ' AS ic
    ON f.image_id = ic.image_id
  WHERE f.user_id = ' . $user_id_str . '
  ' . $this->permissionService()->getSqlConditionFandF(
            [
                'forbidden_categories' => 'ic.category_id',
            ],
            'AND'
        ) . '
;';
        $authorizeds = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($this->conn->fetchAllAssociative($query), 'image_id')
        );

        $query = '
SELECT image_id
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $user_id_str . '
;';
        $favorites = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($this->conn->fetchAllAssociative($query), 'image_id')
        );

        $to_deletes = array_diff($favorites, $authorizeds);
        if (count($to_deletes) > 0) {
            $query = '
DELETE FROM ' . Tables::favorites() . '
  WHERE image_id IN (' . implode(',', $to_deletes) . ')
    AND user_id = ' . $user_id_str . '
;';
            $this->conn->executeStatement($query);
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
        if (\Piwigo\Core\ThemeCatalog::checkThemeInstalled($theme)) {
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
     * Returns the current (logged-in or guest) user's language preference,
     * per DefaultLanguageProviderInterface::getCurrentLanguage()'s own
     * docblock.
     */
    #[\Override]
    public function getCurrentLanguage(): ?string
    {
        return CurrentUser::isInitialized() ? CurrentUser::get()->language : null;
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
            $accept_language_full = strtolower($accept_languages_full[$i]);
            if (array_key_exists($accept_language_full, $languages_available)) {
                return $languages_available[$accept_language_full];
            }
            // only in case that an exact match was not available,
            // should we fallback to other variants in the same language family
            // fr_CH => fr => fr_FR
            $accept_language_short = strtolower($accept_languages_short[$i]);
            if (array_key_exists($accept_language_short, $languages_available)) {
                return $languages_available[$accept_language_short];
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
        $currentUser = CurrentUser::get();
        if (! isset($currentUser->rawAttributes['last_photo_date'])) {
            return '0=1';
        }

        // same narrowing as get_icon()'s $recent_period handling in
        // functions.inc.php: a raw user_infos DB value, numeric string or int
        $recent_period = $currentUser->rawAttributes['recent_period'] ?? null;
        $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);

        $last_photo_date = $currentUser->rawAttributes['last_photo_date'];
        $last_photo_date = is_string($last_photo_date) ? $last_photo_date : '';

        return $dbField . '>=LEAST('
          . SqlDialect::getRecentPeriodExpression($recent_period)
          . ',' . SqlDialect::getRecentPeriodExpression(1, $last_photo_date) . ')';
    }

    /**
     * Register in the user session, the "context" of the last 10 viewed
     * images.
     *
     * @since 16
     */
    /**
     * Legacy Coupling Retirement Track A batch A5.2e: $sectionUrl/
     * $imageId are explicit params instead of `global $page['section_url']`/
     * `['image_id']` -- the one real caller (PictureController) already
     * has both from SectionContextRegistry::current() right after
     * SectionPopulator::populate() runs.
     */
    public function saveEditContext(?string $sectionUrl, int|string|null $imageId): void
    {
        if (! AccessControl::isAdmin() or $sectionUrl === null or $imageId === null) {
            return;
        }

        if (! is_int($imageId) && ! is_numeric($imageId)) {
            return;
        }
        $image_id = (int) $imageId;
        $section_url = $sectionUrl;

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
            if (strlen(str_replace(' ', '', $username_check)) === 0) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'Name field must not be empty',
                    ],
                ];
            }
        }

        // see validateMailAddress() for why this is string=>string
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $updates = $updates_infos = [];
        $update_status = null;
        $user_ids_for_status = [];

        // real callers (ws_users_setInfo/ws_users_setPreferences) always pass
        // 'user_id' as a list of ints (WsParamType::ID-coerced) or numeric strings
        // (the global $user['id'] raw DB value); normalize once here so every
        // usage below is a well-typed int.
        assert(is_array($params['user_id']));
        $user_ids = [];
        foreach ($params['user_id'] as $raw_user_id) {
            assert(is_int($raw_user_id) || (is_string($raw_user_id) && is_numeric($raw_user_id)));
            $user_ids[] = (int) $raw_user_id;
        }

        if (count($user_ids) === 1) {
            if ($this->getUsername($user_ids[0]) === false) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'This user does not exist.',
                    ],
                ];
            }

            if (! self::emptyValue($params['username'] ?? null)) {
                $username_param = $params['username'];
                assert(is_string($username_param));
                $user_id = $this->getUserId($username_param);
                if ((bool) $user_id and $user_id !== $user_ids[0]) {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => Lang::t('this login is already used'),
                        ],
                    ];
                }
                if ($username_param !== strip_tags($username_param)) {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => Lang::t('html tags are not allowed in login'),
                        ],
                    ];
                }
                $updates[$user_fields['username']] = $username_param;
            }

            if (! self::emptyValue($params['email'] ?? null)) {
                $email_param = $params['email'] ?? null;
                assert(is_string($email_param));
                if (($error = $this->validateMailAddress($user_ids[0], $email_param)) !== '') {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => $error,
                        ],
                    ];
                }
                $updates[$user_fields['email']] = $email_param;
            }

            if (! self::emptyValue($params['password'] ?? null)) {
                if (! AccessControl::isWebmaster()) {
                    $password_protected_users = [\Piwigo\Config\CurrentConfig::guestId()];

                    $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
                    $admin_ids = array_map(
                        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                        array_column($this->conn->fetchAllAssociative($query), 'user_id')
                    );

                    $current_user_id_str = (string) CurrentUser::get()->id;

                    // we add all admin+webmaster users BUT the user herself
                    $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id_str]));

                    if (in_array((string) $user_ids[0], array_map(strval(...), $password_protected_users), true)) {
                        return [
                            'error' => [
                                'code' => 403,
                                'message' => 'Only webmasters can change password of other "webmaster/admin" users',
                            ],
                        ];
                    }
                }

                $password_param = $params['password'] ?? null;
                assert(is_string($password_param));
                $updates[$user_fields['password']] = $this->passwordService()
                    ->hash($password_param);
            }
        }

        if (! self::emptyValue($params['status'] ?? null)) {
            $status_param = $params['status'] ?? null;
            if (in_array($status_param, ['webmaster', 'admin'], true) and ! AccessControl::isWebmaster()) {
                return [
                    'error' => [
                        'code ' => 403,
                        'message' => 'Only webmasters can grant "webmaster/admin" status',
                    ],
                ];
            }

            if (! in_array($status_param, ['guest', 'generic', 'normal', 'admin', 'webmaster'], true)) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'Invalid status',
                    ],
                ];
            }

            // conf's guest_id/webmaster_id are always scalar (int config
            // values) and string-castable.
            $protected_users = array_filter(
                [
                    CurrentUser::get()->id,
                    \Piwigo\Config\CurrentConfig::guestId(),
                    \Piwigo\Config\CurrentConfig::webmasterId(),
                ],
                is_scalar(...)
            );

            // an admin can't change status of other admin/webmaster
            if (CurrentUser::get()->status === UserStatus::Admin) {
                $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
                $protected_users = array_merge($protected_users, array_column($this->conn->fetchAllAssociative($query), 'user_id'));
            }

            // status update query is separated from the rest as not applying to the same
            // set of users (current, guest and webmaster can't be changed)
            $user_ids_for_status = array_diff($user_ids, array_filter($protected_users, is_scalar(...)));

            $update_status = $status_param;
        }

        if (! self::emptyValue($params['level'] ?? null) or @($params['level'] ?? null) === 0) {
            $level_param = $params['level'] ?? null;
            // \Piwigo\Config\CurrentConfig::availablePermissionLevels() defaults to [0, 1, 2, 4, 8]
            // (see include/config_default.inc.php), always an array
            $available_permission_levels = \Piwigo\Config\CurrentConfig::availablePermissionLevels();
            if (! in_array(is_numeric($level_param) ? (int) $level_param : null, $available_permission_levels, true)) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'Invalid level',
                    ],
                ];
            }
            $updates_infos['level'] = $level_param;
        }

        if (! self::emptyValue($params['language'] ?? null)) {
            $language_param = $params['language'] ?? null;
            if (! in_array($language_param, array_keys(\Piwigo\Lang\LangService::getLanguages()), true)) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'Invalid language',
                    ],
                ];
            }
            $updates_infos['language'] = $language_param;
        }

        if (! self::emptyValue($params['theme'] ?? null)) {
            $theme_param = $params['theme'] ?? null;
            if (! in_array($theme_param, array_keys(\Piwigo\Core\ThemeCatalog::getPwgThemes()), true)) {
                return [
                    'error' => [
                        'code' => WsError::INVALID_PARAM,
                        'message' => 'Invalid theme',
                    ],
                ];
            }
            $updates_infos['theme'] = $theme_param;
        }

        if (! self::emptyValue($params['nb_image_page'] ?? null)) {
            $updates_infos['nb_image_page'] = $params['nb_image_page'] ?? null;
        }

        if (! self::emptyValue($params['recent_period'] ?? null) or @($params['recent_period'] ?? null) === 0) {
            $updates_infos['recent_period'] = $params['recent_period'] ?? null;
        }

        if (! self::emptyValue($params['expand'] ?? null) or @($params['expand'] ?? null) === false) {
            $updates_infos['expand'] = SqlDialect::booleanToInt($params['expand'] ?? null);
        }

        if (! self::emptyValue($params['show_nb_comments'] ?? null) or @($params['show_nb_comments'] ?? null) === false) {
            $updates_infos['show_nb_comments'] = SqlDialect::booleanToInt($params['show_nb_comments'] ?? null);
        }

        if (! self::emptyValue($params['show_nb_hits'] ?? null) or @($params['show_nb_hits'] ?? null) === false) {
            $updates_infos['show_nb_hits'] = SqlDialect::booleanToInt($params['show_nb_hits'] ?? null);
        }

        if (! self::emptyValue($params['enabled_high'] ?? null) or @($params['enabled_high'] ?? null) === false) {
            $updates_infos['enabled_high'] = SqlDialect::booleanToInt($params['enabled_high'] ?? null);
        }

        // perform updates
        new BatchWriter($this->conn)
            ->singleUpdate(
                Tables::users(),
                $updates,
                [
                    $user_fields['id'] => $user_ids[0],
                ]
            );

        $authService = new AuthService(
            new AuthRepository($this->conn),
            $this->activityLogger,
            $this->htmlRenderer,
            $this->passwordService(),
            new CookieService(),
        );

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
            $this->conn->executeStatement($query);

            // we delete sessions, ie disconnect, for users if status becomes "guest".
            // It's like deactivating the user.
            if ($update_status === 'guest') {
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
                $query .= $field . ' = "' . (string) $value . '"';
            }

            $query .= '
  WHERE user_id IN(' . implode(',', array_map(strval(...), $user_ids)) . ')
;';
            $this->conn->executeStatement($query);
        }

        // manage association to groups
        if (! self::emptyValue($params['group_id'] ?? null)) {
            $group_id_param = $params['group_id'] ?? null;
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
            $this->conn->executeStatement($query);

            // we remove all provided groups that do not really exist
            $query = '
SELECT
    id
  FROM `' . Tables::groups() . '`
  WHERE id IN (' . implode(',', array_map(strval(...), $group_ids_param)) . ')
;';
            $group_ids = array_column($this->conn->fetchAllAssociative($query), 'id');

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

                new BatchWriter($this->conn)
                    ->massInsert(Tables::userGroup(), array_keys($inserts[0]), $inserts);
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

        $userFields = \Piwigo\Config\CurrentConfig::userFields();
        $userIdField = $userFields['id'];

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
