<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Cache\CachePools;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ArrayHelper;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\Env;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\ThemeCatalog;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Event\User\DeleteUser;
use Piwigo\Event\User\RegisterUser;
use Piwigo\Event\User\RegisterUserCheck;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\EffectiveForbiddenCategoriesCache;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\Projection\ActivationKeyRow;
use Piwigo\Validation\InputValidator;
use SensitiveParameter;

/**
 * User domain business logic: registration, login/email lookup,
 * case-insensitive username validation, default-user-info reads.
 * Constructor-injects UserRepository + GroupRepository (registration
 * assigns default groups, the same real dependency that put Group in
 * L2aCoreDomain in the first place).
 *
 * Constructor-injects MailerInterface (Piwigo\Core) rather than depending
 * on Piwigo\Mail\MailService directly -- Mail lives in L3Presentation
 * (constructs Piwigo\Template\Template), and L2aCoreDomain may not depend
 * upward on L3; see deptrac.yaml's own comment on the Mail namespace
 * entry and MailerInterface's own docblock.
 *
 * Implements DefaultLanguageProviderInterface (Piwigo\Core) so
 * Piwigo\Core\Lang::load() (L1Infrastructure, static) can resolve the
 * DB-configured default language without depending on this class
 * directly -- see that interface's own docblock.
 */
final readonly class UserService implements DefaultLanguageProviderInterface
{
    public function __construct(
        private Lang $lang,
        private UserRepository $repo,
        private GroupRepository $groupRepo,
        private MailerInterface $mailer,
        private ActivityLoggerInterface $activityLogger,
        private HtmlRenderingInterface $htmlRenderer,
        private Connection $conn,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private InstallationFlag $installationFlag,
        private ProcessCache $processCache,
        private Paths $paths,
    ) {}

    /**
     * Built from this class's own already-required currentUser/
     * currentConfig -- AccessLevelChecker has no RedirectServiceInterface
     * dependency of its own, so this needs no container resolve at all.
     */
    private function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker($this->currentUser, $this->currentConfig);
    }

    /**
     * Container resolve, not a constructor property -- used only inside
     * categoryService()'s own throwaway construction below; a required
     * param here would ripple across every one of this class's own real
     * `new UserService(...)` construction sites for a single caller.
     */
    private function translator(): Translator
    {
        $translator = Kernel::container()->get(Translator::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Container returned an unexpected type for ' . Translator::class);
        }

        return $translator;
    }

    /**
     * The same PermissionService recipe was repeated verbatim at 3 call
     * sites in this file. Not a constructor param -- $conn is already
     * available, and readonly class means no memoized property, so this
     * is a plain (non-memoized) DRY extraction, not a caching
     * optimization.
     */
    private function permissionService(): PermissionService
    {
        return new PermissionService(new PermissionRepository(EntityManagerFactory::build($this->conn)), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($this->conn), $this->currentConfig), $this->currentUser, $this->filterState(), $this->accessLevelChecker());
    }

    /**
     * Same reasoning as translator() above. Falls back to a fresh,
     * uninitialised instance when `Kernel::boot()` hasn't run, matching
     * `FilterState`'s own former `isInitializedStatic()` shim's identical
     * pre-boot fallback.
     */
    private function filterState(): FilterState
    {
        if (Kernel::isBooted()) {
            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            return $filterState;
        }

        return new FilterState();
    }

    /**
     * Same reasoning as permissionService() above -- gap-closure Stage 4b
     * (docs/plan/gap-closure-p0-p23.md) added a 2nd `new CategoryService(new
     * CategoryRepository($this->conn), $this->permissionService())` call to
     * this file's own getUserData(), repeating the existing one verbatim.
     */
    private function categoryService(): CategoryService
    {
        return new CategoryService($this->lang, new CategoryRepository(EntityManagerFactory::build($this->conn), $this->currentConfig), $this->permissionService(), $this->currentConfig, $this->eventDispatcher, $this->translator(), $this->accessLevelChecker());
    }

    /**
     * Same reasoning as permissionService() above -- the same
     * PasswordService recipe was repeated verbatim at 2 call sites.
     */
    private function passwordService(): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), $this->deploymentPolicy);
    }

    /**
     * Checks if an email is well formed and not already in use. Returns an
     * error message, or '' when the address is fine / not required.
     */
    public function validateMailAddress(?UserId $userId, ?string $mailAddress): string
    {

        $isEmpty = $mailAddress === null || $mailAddress === '';
        if (
            $isEmpty
            && ! ($this->currentConfig->obligatoryUserMailAddress() && in_array(PageFilterHelper::scriptBasename($this->currentConfig), ['register', 'profile'], true))
        ) {
            return '';
        }

        $email = InputValidator::checkEmailFormat($mailAddress) ? Email::tryFrom($mailAddress) : null;
        if ($email === null) {
            return $this->lang->t('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if ($this->installationFlag->isActive() && ! $isEmpty) {
            if ($this->repo->emailExists($email, $userId)) {
                return $this->lang->t('this email address is already in use');
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

        $username = Username::tryFrom($login);
        if ($this->installationFlag->isActive() && $username !== null) {
            if ($this->repo->usernameExistsCaseInsensitive($username)) {
                return $this->lang->t('this login is already used');
            }
        }

        return '';
    }

    /**
     * Searches for a user with the same username in a different case.
     */
    public function searchCaseUsername(string $username): string
    {
        $usernameLower = strtolower($username);
        $byLower = [];
        foreach ($this->repo->findAllUsernames() as $existing) {
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

    public function getUserId(Username $username): ?UserId
    {
        return $this->repo->findIdByUsername($username);
    }

    public function getUserIdByEmail(Email $email): ?UserId
    {
        return $this->repo->findIdByEmail($email);
    }

    /**
     * The stripslashes() call here is a real, already-established
     * precedent in this same file, not legacy cruft -- stripslashes()
     * only ever removes chars, so a value that was already a valid
     * Username (it came from this exact column) stays valid.
     */
    public function getUsername(UserId $userId): ?Username
    {
        $username = $this->repo->findUsernameById($userId);

        return $username === null ? null : Username::from(stripslashes($username->value));
    }

    /**
     * Username keyed by id for $userIds -- Admin\BatchManagerUnitPageRenderer's
     * own "who uploaded each of these photos" lookup.
     *
     * @param list<string> $userIds
     * @return array<int|string, string>
     */
    public function getUsernamesByIds(array $userIds): array
    {
        $rows = $this->repo->findUsernamesByIds($userIds);

        $usernames = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $username = $row['username'];
            if ((is_int($id) || is_string($id)) && is_string($username)) {
                $usernames[$id] = $username;
            }
        }

        return $usernames;
    }

    /**
     * Deletes a user and every trace of it (sessions, cache rows, activity
     * log entry).
     */
    public function deleteUser(UserId $userId): void
    {
        $this->repo->deleteUser($userId);
        $this->sessionService->deleteUserSessions($userId->value);
        $this->eventDispatcher->dispatchNotify(new DeleteUser($userId->value));
        $this->activityLogger->record('user', $userId->value, 'delete');
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
        #[SensitiveParameter]
        string $password,
        ?string $mailAddress,
        UrlServiceInterface $urlService,
        bool $notifyAdmin = true,
        bool $notifyUser = false
    ): array {

        $errors = [];
        $duplicateUsername = false;

        if ($login === '') {
            $errors[] = $this->lang->t('Please, enter a login');
        }
        if (preg_match('/^.* $/', $login) === 1) {
            $errors[] = $this->lang->t('login mustn\'t end with a space character');
        }
        if (preg_match('/^ .*$/', $login) === 1) {
            $errors[] = $this->lang->t('login mustn\'t start with a space character');
        }
        $loginUsername = Username::tryFrom($login);
        if ($loginUsername !== null && $this->getUserId($loginUsername) !== null) {
            $duplicateUsername = true;
        }
        if ($login !== strip_tags($login)) {
            $errors[] = $this->lang->t('html tags are not allowed in login');
        }

        $mailError = $this->validateMailAddress(null, $mailAddress);
        if ($mailError !== '') {
            $errors[] = $mailError;
        }

        if ($this->currentConfig->insensitiveCaseLogon() && ! $duplicateUsername) {
            if ($this->validateLoginCase($login) !== '') {
                $duplicateUsername = true;
            }
        }

        $errorsAfterTrigger = $this->eventDispatcher->dispatchChange(new RegisterUserCheck(
            $errors,
            [
                'username' => $login,
                'password' => $password,
                'email' => $mailAddress,
            ]
        ))->errors;
        $errors = array_values(array_filter($errorsAfterTrigger, is_string(...)));

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

        $userId = $this->repo->insertUser(
            $login,
            $this->passwordService()
                ->hash($password),
            $mailAddress,
        );

        // Real bug fixed here: this used to call
        // $this->groupRepo->addMembers($userId, $defaultGroupIds) directly,
        // passing the arguments in the wrong shape entirely --
        // addMembers(GroupId $groupId, list<UserId> $userIds) adds many
        // users to ONE group, but the call needs the opposite (add ONE new
        // user to EACH of several default groups). That wrote
        // (group_id, user_id) = ($userId, each default group's id) to
        // user_group -- backwards. Confirmed against 16.x-rewrite's
        // correct ['user_id' => $userId, 'group_id' => $groupId] per
        // default group; no test exercised registration + default-group
        // assignment together, so this was never caught. Looping per
        // group (addMembers() already loops per-user for one group)
        // matches the repository's existing contract without changing its
        // shape.
        $defaultGroupIds = $this->groupRepo->findDefaultGroupIds();
        foreach ($defaultGroupIds as $groupId) {
            $this->groupRepo->addMembers($groupId, [$userId]);
        }

        $override = [];
        if ($this->currentConfig->browserLanguage() && ($language = $this->getBrowserLanguage()) !== false) {
            $override['language'] = $language;
        }

        $this->createUserInfos([$userId], $override);

        $emailAdminOnNewUserSetting = $this->currentConfig->emailAdminOnNewUser();
        if ($notifyAdmin && $emailAdminOnNewUserSetting !== 'none') {
            $this->notifyAdminsOfNewRegistration($userId, $login, $mailAddress, $urlService);
        }

        if ($notifyUser && InputValidator::checkEmailFormat($mailAddress)) {
            assert($mailAddress !== null);
            $this->sendWelcomeEmail($login, $mailAddress, $urlService);
        }

        $this->eventDispatcher->dispatchNotify(new RegisterUser([
            'id' => $userId->value,
            'username' => $login,
            'email' => $mailAddress,
        ]));

        $this->activityLogger->record('user', $userId->value, 'add');

        return [
            'userId' => $userId->value,
            'errors' => [],
            'duplicateUsername' => false,
        ];
    }

    /**
     * @param list<UserId> $userIds
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

        $availablePermissionLevels = $this->currentConfig->availablePermissionLevels();
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
        $webmasterId = (string) $this->currentConfig->webmasterId();
        $guestId = (string) $this->currentConfig->guestId();
        $defaultUserId = (string) $this->currentConfig->defaultUserId();

        foreach ($userIds as $userId) {
            $level = $defaultUser['level'] ?? 0;
            $userIdStr = (string) $userId->value;
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
     * UserInfo::toArray()'s own shape, minus the 5 keys unset below
     * (user_id/status/registration_date/last_visit/last_visit_from_history
     * -- caller-specific, re-added by createUserInfos() itself).
     *
     * @return array{nb_image_page: int, language: string, expand: bool, show_nb_comments: bool, show_nb_hits: bool, recent_period: int, theme: string, enabled_high: bool, level: int, activation_key: ?string, activation_key_expire: ?string, lastmodified: string, preferences: array<string, mixed>|null}|false false if the default user row
     *   doesn't exist
     */
    public function getDefaultUserInfo(): array|false
    {
        // Reads the just-computed value directly rather than a
        // set()-then-get() round trip through $this->processCache.
        if ($this->processCache->has('default_user')) {
            $defaultUserCached = $this->processCache->get('default_user');
        } else {
            $defaultUserId = UserId::from($this->currentConfig->defaultUserId());

            $row = $this->repo->findDefaultUserInfoRow($defaultUserId);
            if ($row !== null) {
                $rowArray = $row->toArray();
                unset($rowArray['user_id'], $rowArray['status'], $rowArray['registration_date'], $rowArray['last_visit'], $rowArray['last_visit_from_history']);
                $this->processCache->set('default_user', $rowArray);
                $defaultUserCached = $rowArray;
            } else {
                $this->processCache->set('default_user', false);
                $defaultUserCached = false;
            }
        }

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
        /** @var array{nb_image_page: int, language: string, expand: bool, show_nb_comments: bool, show_nb_hits: bool, recent_period: int, theme: string, enabled_high: bool, level: int, activation_key: ?string, activation_key_expire: ?string, lastmodified: string, preferences: array<string, mixed>|null} $defaultUserCached */
        return $defaultUserCached;
    }

    /**
     * Generic dynamic-key reader over getDefaultUserInfo()'s own now-typed
     * shape -- $valueName is a runtime key name, so the real value type
     * varies per call (both real callers request 'theme'/'language',
     * always strings, but the method itself isn't limited to those 2
     * keys), same rationale as ConfigService::confGetParam().
     */
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
        $existing = $this->repo->findByUsernameCaseInsensitive($login);
        if ($existing === null || $existing['email'] === '' || ! InputValidator::checkEmailFormat($existing['email'])) {
            return;
        }

        $gallery_title = $this->currentConfig->galleryTitle();

        $this->mailer->mail(
            $existing['email'],
            [
                'subject' => '[' . $gallery_title . '] ' . $this->lang->t('Registration'),
                'content' => $this->lang->args([
                    $this->lang->buildArgs('Someone tried to create an account on %s using your username.', $gallery_title),
                    $this->lang->buildArgs('', ''),
                    $this->lang->buildArgs('If this was you, you already have an account -- try logging in or resetting your password instead.', ''),
                    $this->lang->buildArgs('If this was not you, no action is needed.', ''),
                ]),
                'content_format' => 'text/plain',
            ]
        );
    }

    private function notifyAdminsOfNewRegistration(UserId $userId, string $login, ?string $mailAddress, UrlServiceInterface $urlService): void
    {

        $adminUrl = $urlService->getAbsoluteRootUrl() . 'admin.php?page=user_list&user_id=' . $userId->value;

        $keyargsContent = [
            $this->lang->buildArgs('User: %s', stripslashes($login)),
            $this->lang->buildArgs('Email: %s', $mailAddress),
            $this->lang->buildArgs(''),
            $this->lang->buildArgs('Admin: %s', $adminUrl),
        ];

        $groupId = null;
        $emailAdminOnNewUser = $this->currentConfig->emailAdminOnNewUser();
        if (preg_match('/^group:(\d+)$/', $emailAdminOnNewUser, $matches) === 1) {
            $groupId = $matches[1];
        }

        $this->mailer->mailNotificationAdmins(
            $this->lang->buildArgs('Registration of %s', stripslashes($login)),
            $keyargsContent,
            true,
            $groupId
        );
    }

    private function sendWelcomeEmail(string $login, string $mailAddress, UrlServiceInterface $urlService): void
    {

        $length = mt_rand(10, 15);
        $keyargsContent = [
            $this->lang->buildArgs('Hello %s,', stripslashes($login)),
            $this->lang->buildArgs('Thank you for registering at %s!', $this->currentConfig->galleryTitle()),
            $this->lang->buildArgs('', ''),
            $this->lang->buildArgs('Here are your connection settings', ''),
            $this->lang->buildArgs('', ''),
            $this->lang->buildArgs('Link: %s', $urlService->getAbsoluteRootUrl()),
            $this->lang->buildArgs('Username: %s', stripslashes($login)),
            $this->lang->buildArgs('Password: %s', str_repeat('*', $length)),
            $this->lang->buildArgs('Email: %s', $mailAddress),
            $this->lang->buildArgs('', ''),
            $this->lang->buildArgs('If you think you\'ve received this email in error, please contact us at %s', $this->repo->getWebmasterMailAddress()),
        ];

        $gallery_title = $this->currentConfig->galleryTitle();

        $this->mailer->mail(
            $mailAddress,
            [
                'subject' => '[' . $gallery_title . '] ' . $this->lang->t('Registration'),
                'content' => $this->lang->args($keyargsContent),
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
     * Same "full user data bag" rationale as User::toUserArray()/
     * $rawAttributes -- a growing merge of many heterogeneous sources
     * (UserInfo row, computed theme/status fields, ...), not a single
     * reusable domain shape.
     *
     * @return array<string, mixed>
     */
    public function buildUser(UserId $userId): array
    {

        $user = [];
        $user['id'] = $userId->value;
        $user = array_merge($user, $this->getUserData($userId));

        $userStatusValue = $user['status'] ?? null;
        if (is_numeric($user['id']) and (int) $user['id'] === $this->currentConfig->guestId()
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
        if (! isset($user['theme_name']) or ! is_string($theme) or ! ThemeCatalog::checkThemeInstalled($theme, $this->paths, $this->currentConfig)) {
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
    public function getUserData(UserId $userId): array
    {
        // retrieve basic user data
        $row = $this->repo->fetchBasicUserRow($userId);
        if ($row === false) {
            throw new Exception('UserService::getUserData(): no such user_id ' . $userId->value);
        }

        // retrieve additional user data ?
        if ($this->deploymentPolicy->externalAuthentification) {
            // Gap-closure Stage 4g: dropped the LEFT JOIN onto user_cache --
            // it never affected this COUNT(1) (a LEFT JOIN can only ever
            // add exactly 0 or 1 matching row per ui row, and GROUP BY
            // ui.user_id collapses that back to 1 either way), and nothing
            // here selects any of its columns.
            $counter = $this->repo->countUserInfosRows($userId);
            if ($counter !== 1) {
                $this->createUserInfos([$userId]);
            }
        }

        // retrieve user info. Gap-closure Stage 4g: dropped the LEFT JOIN
        // onto user_cache (`uc.*`) -- getUserData() no longer reads any of
        // its columns, and nothing writes that table any more either
        // (deleted alongside this same stage's lock/wait/503 mechanism).
        $user_infos_row = $this->repo->fetchUserInfosWithThemeName($userId);
        if ($user_infos_row === false) {
            throw new Exception('UserService::getUserData(): user_infos fetch failed for user_id ' . $userId->value);
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

        // enabled_high/expand/last_visit_from_history/show_nb_comments/
        // show_nb_hits (user_infos) don't need an explicit re-cast here --
        // UserRepository::fetchUserInfosWithThemeName() selects the full
        // UserInfoEntity via DQL, whose mapped `boolean` columns already
        // hydrate as real PHP bools, not the raw tinyint a DBAL row gave.

        // Kept out of $userdata: ArrayHelper::safeJsonDecode()'s own return
        // type is array<int|string, mixed>, and merging that into
        // $userdata here would widen every other key's inferred type to
        // mixed for the remainder of this function. Merged back in just
        // before the final return instead.
        //
        // Real bug found live (2026-08-04, via a failing AdminShellTest
        // purge-logic assertion): this used to only accept a raw JSON
        // *string*, matching fetchUserInfosWithThemeName()'s old raw-DBAL
        // return shape. Item 16H converted that method to real DQL
        // UserInfoEntity hydration, whose `preferences` column maps as
        // Doctrine's native `json` type -- already a decoded PHP array by
        // the time it reaches here, never a string anymore. is_string()
        // was therefore always false post-conversion, silently discarding
        // every real user's preferences on every single login (test and
        // production alike) -- confirmed live via CurrentUser::get()
        // ->preferences coming back `[]` immediately after a real login,
        // despite the DB row genuinely holding real preference data.
        // ArrayHelper::safeJsonDecode() already accepts array|string
        // (passes an array straight through unchanged), so widening the
        // gate to match its own accepted type is the real fix, not adding
        // a second decode path.
        $preferences_raw = $userdata['preferences'];
        $preferences = ! self::emptyValue($preferences_raw) && (is_array($preferences_raw) || is_string($preferences_raw))
            ? ArrayHelper::safeJsonDecode($preferences_raw)
            : [];

        // No lock/wait mechanism coordinates computation of this
        // snapshot -- a deliberate tradeoff, not an oversight. Every real
        // column comes from an independent cache-pool-backed computation,
        // each already safe for uncoordinated concurrent access (a PSR-6
        // cache-pool miss just triggers an independent recompute per
        // request, no shared mutable row to corrupt). A burst of
        // concurrent requests for the same just-invalidated user can each
        // independently recompute the cheap permission snapshot rather
        // than one computing while others wait.
        $effective_status = $userdata['status'];
        assert(is_string($effective_status));
        $effective_level_raw = $userdata['level'] ?? '0';
        // DBAL returns user_infos.level (a tinyint column) as a native
        // int, not the mysqli-style string this file's own older $level
        // reads elsewhere assumed -- EffectiveForbiddenCategoriesCache
        // accepts either.
        $effective_level = is_int($effective_level_raw) || is_string($effective_level_raw) ? $effective_level_raw : '0';

        $effective = new EffectiveForbiddenCategoriesCache(
            $this->accessLevelChecker(),
            $this->permissionService(),
            $this->categoryService(),
            new PermissionRepository(EntityManagerFactory::build($this->conn)),
            CachePools::effectivePermissions()
        )->getForUser($userId->value, $effective_status, $effective_level);

        $userdata['forbidden_categories'] = $effective['forbiddenCategories'];
        $userdata['image_access_type'] = $effective['imageAccessType'];
        $userdata['image_access_list'] = $effective['imageAccessList'];
        $userdata['nb_total_images'] = $effective['nbTotalImages'];
        // Gap-closure Stage 4e: Filter\FilterService's own separate,
        // differently-scoped (recent-period-filtered) last_photo_date
        // computation is untouched -- see EffectiveForbiddenCategoriesCache's
        // own docblock for why these aren't the same value.
        $userdata['last_photo_date'] = $effective['lastPhotoDate'];

        $userdata['preferences'] = $preferences;

        return $userdata;
    }

    /**
     * Deletes favorites of the current user if they're not allowed to see
     * them.
     */
    public function checkUserFavorites(): void
    {
        $user = $this->currentUser->get();

        if ($user->forbiddenCategories === '') {
            return;
        }

        // $filter['visible_categories'] and $filter['visible_images']
        // must be not used because filter <> restriction
        // retrieving images allowed : belonging to at least one authorized
        // category
        $authorizeds = $this->repo->findAuthorizedFavoriteImageIds(
            $user->id,
            $this->permissionService()
                ->getPermissionCriteria()
        );

        $favorites = $this->repo->findFavoriteImageIds($user->id);

        $to_deletes = array_diff($favorites, $authorizeds);
        if (count($to_deletes) > 0) {
            $this->repo->deleteFavoritesForImages($user->id, array_values($to_deletes));
        }
    }

    /**
     * @return list<int>
     */
    public function getFavoriteImageIds(UserId $userId): array
    {
        return $this->repo->findFavoriteImageIds($userId);
    }

    /**
     * Adds a single favorite -- Controller\PictureController's own
     * "add_to_favorites" action. $ignoreDuplicate: see
     * UserRepository::addFavorite()'s own docblock.
     */
    public function addFavorite(UserId $userId, int $imageId, bool $ignoreDuplicate = false): void
    {
        $this->repo->addFavorite($userId, $imageId, $ignoreDuplicate);
    }

    /**
     * `users` row insert with an explicit, caller-chosen id --
     * Admin\Integrity\C13yInternal's own "recreate a missing guest/
     * default/webmaster user row, with its exact known id" repair step.
     */
    public function insertUserRow(UserId $id, string $username, ?string $password): UserId
    {
        return $this->repo->insertUserWithId($id, $username, $password);
    }

    /**
     * @param list<UserId> $userIds
     */
    public function updateStatusForUsers(array $userIds, string $status): void
    {
        $this->repo->updateStatusForUsers($userIds, $status);
    }

    /**
     * Removes a single favorite -- Controller\PictureController's own
     * "remove_from_favorites" action.
     */
    public function removeFavorite(UserId $userId, int $imageId): void
    {
        $this->repo->deleteFavoritesForImages($userId, [$imageId]);
    }

    /**
     * Whether $imageId is already among $userId's favorites --
     * Controller\PictureController's own favorite-icon toggle state.
     */
    public function isFavorite(UserId $userId, int $imageId): bool
    {
        return $this->repo->isFavorite($userId, $imageId);
    }

    /**
     * @return array<int|string, mixed> keyed by id
     */
    public function getAllUsernamesById(): array
    {
        return $this->repo->findAllUsernamesById();
    }

    /**
     * Removes every favorite for $userId -- Section\SectionPopulator's own
     * "remove_all_from_favorites" action.
     */
    public function removeAllFavorites(UserId $userId): void
    {
        $this->repo->deleteAllFavorites($userId);
    }

    /**
     * @return list<string|null>
     */
    public function getVisibleFavoriteImageIds(UserId $userId, PermissionCriteria $criteria, string $orderBySql): array
    {
        return $this->repo->findVisibleFavoriteImageIds($userId, $criteria, $orderBySql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getVisibleFavoriteImages(UserId $userId, PermissionCriteria $criteria, string $orderBySql): array
    {
        return $this->repo->findVisibleFavoriteImages($userId, $criteria, $orderBySql);
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
        if (ThemeCatalog::checkThemeInstalled($theme, $this->paths, $this->currentConfig)) {
            return $theme;
        }

        // let's find the first available theme
        $active_themes = array_keys(ThemeCatalog::getPwgThemes($this->eventDispatcher, $this->paths, $this->currentConfig, $this->lang));
        return isset($active_themes[0]) ? (string) $active_themes[0] : 'default';
    }

    /**
     * Returns the default language.
     */
    #[Override]
    public function getDefaultLanguage(): string
    {
        $language = $this->getDefaultUserValue('language', AppInfo::DEFAULT_LANGUAGE);
        return is_string($language) ? $language : AppInfo::DEFAULT_LANGUAGE;
    }

    /**
     * @return array<string, int> keyed by theme
     */
    public function getThemeUsageCounts(): array
    {
        return $this->repo->findThemeUsageCounts();
    }

    /**
     * @return array<string, int> keyed by language
     */
    public function getLanguageUsageCounts(): array
    {
        return $this->repo->findLanguageUsageCounts();
    }

    /**
     * Returns the current (logged-in or guest) user's language preference,
     * per DefaultLanguageProviderInterface::getCurrentLanguage()'s own
     * docblock.
     */
    #[Override]
    public function getCurrentLanguage(): ?string
    {
        return $this->currentUser->isInitialized() ? $this->currentUser->get()
            ->language : null;
    }

    /**
     * Persists a `user_infos` field update for a single user --
     * Controller\ProfileController's "sync the DB row to the just-switched
     * `lang` cookie" step and Controller\ProfileFormHandler's own profile
     * form save, both single-user specializations of
     * UserRepository::updateInfosForUsers()'s bulk shape.
     *
     * @param array<string, mixed> $updates
     */
    public function updateInfosForUser(UserId $userId, array $updates): void
    {
        $this->repo->updateInfosForUsers([$userId], $updates);
    }

    /**
     * Persists a `users` (account) field update for a single user --
     * Controller\ProfileFormHandler's own profile form save
     * (username/email/password, whichever changed).
     */
    public function updateAccountFields(UserId $userId, ?string $username, ?string $password, ?string $mailAddress): void
    {
        $this->repo->updateAccountFields($userId, $username, $password, $mailAddress);
    }

    /**
     * Tries to find the browser language among available languages.
     */
    public function getBrowserLanguage(): false|string
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
        foreach (LangService::getLanguages($this->paths) as $language_code => $language_name) {
            $lowercase_full = strtolower($language_code);
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
     * Returns the SQL WHERE condition for recent photos/albums for the
     * current user.
     *
     * Further SQL-modernization audit, Item 11: now returns a SqlCondition
     * instead of a bare string -- $last_photo_date is bound via its own
     * parameter rather than spliced into SqlDialect::
     * getRecentPeriodExpression()'s returned expression (see that method's
     * own docblock for the placeholder-passthrough contract this relies
     * on).
     */
    public function getRecentPhotosCondition(string $dbField): SqlCondition
    {
        $user = $this->currentUser->get();
        if (! isset($user->rawAttributes['last_photo_date'])) {
            return new SqlCondition('0=1');
        }

        // A raw user_infos DB value -- numeric string or int in practice,
        // but rawAttributes is untyped mixed storage. SQL-modernization
        // audit: the previous fallback kept a non-numeric string as-is
        // (`is_string($recent_period) ? $recent_period : 0`), which would
        // have spliced raw, unquoted string content straight into
        // SqlDialect::getRecentPeriodExpression()'s SQL fragment below --
        // that method's own $period param is now `int`-only, and a
        // corrupted/non-numeric value correctly falls back to 0 like any
        // other malformed input, rather than being passed through.
        $recent_period = $user->rawAttributes['recent_period'] ?? null;
        $recent_period = is_numeric($recent_period) ? (int) $recent_period : 0;

        $last_photo_date = $user->rawAttributes['last_photo_date'];
        $last_photo_date = is_string($last_photo_date) ? $last_photo_date : '';

        return new SqlCondition(
            $dbField . '>=LEAST('
              . SqlDialect::getRecentPeriodExpression($recent_period)
              . ',' . SqlDialect::getRecentPeriodExpression(1, ':recentLastPhotoDate') . ')',
            [
                'recentLastPhotoDate' => $last_photo_date,
            ],
            [
                'recentLastPhotoDate' => ParameterType::STRING,
            ],
        );
    }

    /**
     * Register in the user session, the "context" of the last 10 viewed
     * images. The one real caller (PictureController) gets $sectionUrl/
     * $imageId from SectionContextRegistry::current() right after
     * SectionPopulator::populate() runs.
     */
    public function saveEditContext(?string $sectionUrl, int|string|null $imageId): void
    {
        if (! $this->accessLevelChecker()->isAdmin() or $sectionUrl === null or $imageId === null) {
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
     *    @option int[] group_id (optional)
     *
     * $params is Ws-method-parameter-shaped raw input (see the assert()
     * calls below already narrowing individual keys).
     *
     * @return mixed[]
     */
    public function checkAndSaveUserInfos(array $params, PageState $pageState): array
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

        $updates_infos = [];
        $username_update = null;
        $email_update = null;
        $password_update = null;
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
            if ($this->getUsername(UserId::from($user_ids[0])) === null) {
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
                $username_param_vo = Username::tryFrom($username_param);
                $user_id = $username_param_vo === null ? null : $this->getUserId($username_param_vo);
                if ($user_id !== null and $user_id->value !== $user_ids[0]) {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => $this->lang->t('this login is already used'),
                        ],
                    ];
                }
                if ($username_param !== strip_tags($username_param)) {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => $this->lang->t('html tags are not allowed in login'),
                        ],
                    ];
                }
                $username_update = $username_param;
            }

            if (! self::emptyValue($params['email'] ?? null)) {
                $email_param = $params['email'] ?? null;
                assert(is_string($email_param));
                if (($error = $this->validateMailAddress(UserId::from($user_ids[0]), $email_param)) !== '') {
                    return [
                        'error' => [
                            'code' => WsError::INVALID_PARAM,
                            'message' => $error,
                        ],
                    ];
                }
                $email_update = $email_param;
            }

            if (! self::emptyValue($params['password'] ?? null)) {
                if (! $this->accessLevelChecker()->isWebmaster()) {
                    $password_protected_users = [$this->currentConfig->guestId()];

                    $admin_ids = array_map(
                        static fn (UserId $id): string => (string) $id->value,
                        $this->repo->findAdminIds(includeWebmaster: true)
                    );

                    $current_user_id_str = (string) $this->currentUser->get()
                        ->id->value;

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
                $password_update = $this->passwordService()
                    ->hash($password_param);
            }
        }

        if (! self::emptyValue($params['status'] ?? null)) {
            $status_param = $params['status'] ?? null;
            if (in_array($status_param, ['webmaster', 'admin'], true) and ! $this->accessLevelChecker()->isWebmaster()) {
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
                    $this->currentUser->get()
                        ->id->value,
                    $this->currentConfig->guestId(),
                    $this->currentConfig->webmasterId(),
                ],
                is_scalar(...)
            );

            // an admin can't change status of other admin/webmaster
            if ($this->currentUser->get()->status === UserStatus::Admin) {
                $protected_users = array_merge(
                    $protected_users,
                    array_map(static fn (UserId $id): int => $id->value, $this->repo->findAdminIds(includeWebmaster: true))
                );
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
            $available_permission_levels = $this->currentConfig->availablePermissionLevels();
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
            if (! in_array($language_param, array_keys(LangService::getLanguages($this->paths)), true)) {
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
            if (! in_array($theme_param, array_keys(ThemeCatalog::getPwgThemes($this->eventDispatcher, $this->paths, $this->currentConfig, $this->lang)), true)) {
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
        $this->repo->updateAccountFields(UserId::from($user_ids[0]), $username_update, $password_update, $email_update);

        $authService = new AuthService(
            new AuthRepository(EntityManagerFactory::build($this->conn)),
            $this->activityLogger,
            $this->htmlRenderer,
            $this->passwordService(),
            new CookieService(),
            EntityManagerFactory::build($this->conn)->getRepository(UserFailedLoginEntity::class),
            $this->sessionService,
            $this->eventDispatcher,
            $pageState,
            $this->currentUser,
            $this->currentConfig,
            $this->paths,
        );

        if ($password_update !== null) {
            $authService->deactivateUserAuthKeys($user_ids[0]);
        }

        if ($email_update !== null) {
            $authService->deactivatePasswordResetKey($user_ids[0]);
        }

        if (isset($update_status) and count($user_ids_for_status) > 0) {
            $this->repo->updateStatusForUsers(
                array_values(array_map(static fn (int $id): UserId => UserId::from($id), $user_ids_for_status)),
                $update_status
            );

            // we delete sessions, ie disconnect, for users if status becomes "guest".
            // It's like deactivating the user.
            if ($update_status === 'guest') {
                foreach ($user_ids_for_status as $user_id_for_status) {
                    $this->sessionService->deleteUserSessions($user_id_for_status);
                }
            }
        }

        if (count($updates_infos) > 0) {
            $this->repo->updateInfosForUsers(
                array_map(static fn (int $id): UserId => UserId::from($id), $user_ids),
                $updates_infos
            );
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

            $this->groupRepo->removeAllMembershipsForUsers(
                array_map(static fn (int $id): UserId => UserId::from($id), $user_ids)
            );

            // we remove all provided groups that do not really exist
            $group_ids = array_map(
                static fn (GroupId $id): int => $id->value,
                $this->groupRepo->findExistingIds(
                    array_values(array_filter(array_map(GroupId::tryFrom(...), $group_ids_param)))
                )
            );

            // if only -1 (a group id that can't exist) is in the list, then no
            // group is associated

            if (count($group_ids) > 0) {
                $memberIds = array_map(static fn (int $id): UserId => UserId::from($id), $user_ids);
                foreach ($group_ids as $group_id) {
                    $this->groupRepo->addMembers(GroupId::from($group_id), $memberIds);
                }
            }
        }

        PermissionCacheInvalidator::invalidate();

        $this->activityLogger->record('user', $user_ids, 'edit');

        $account_updates = array_filter([
            'username' => $username_update,
            'password' => $password_update,
            'mail_address' => $email_update,
        ], static fn (?string $v): bool => $v !== null);

        return [
            'user_id' => $params['user_id'],
            'infos' => $updates_infos,
            'account' => $account_updates,
        ];
    }

    /**
     * Synchronize base users list and related users list.
     *
     * Compares and synchronizes the base users table (`Tables::users()`)
     * with its child tables (`user_infos`/`user_access`/`user_group`,
     * plus `user_mail_notification`/`user_feed`): each base user must be
     * present in child tables, users in child tables not present in the
     * base table must be deleted.
     *
     * Touches only user-related tables and internally constructs
     * {@see createUserInfos()} -- belongs here, not
     * `Piwigo\Category\CategoryService`.
     */
    public function syncUsers(): void
    {
        $baseUsers = $this->repo->findAllUserIds();
        $infosUsers = $this->repo->findDistinctUserIdsInMappedTable(UserRelatedTable::UserInfos);

        // users present in $baseUsers and not in $infosUsers must be added
        $toCreate = array_values(array_diff($baseUsers, $infosUsers));

        if (count($toCreate) > 0) {
            $this->createUserInfos($toCreate);
        }

        // users present in user related tables must be present in the base user
        // table. user_mail_notification/user_feed stay on the raw-table-name
        // method permanently -- see UserRelatedTable's own docblock.
        $rawTables = [
            Tables::userMailNotification(),
            Tables::userFeed(),
        ];
        foreach ($rawTables as $table) {
            $toDelete = array_values(array_diff(
                $this->repo->findDistinctUserIdsInTable($table),
                $baseUsers
            ));

            if (count($toDelete) > 0) {
                $this->repo->deleteUsersFromTable($table, $toDelete);
            }
        }

        $mappedTables = [
            UserRelatedTable::UserInfos,
            UserRelatedTable::UserAccess,
            UserRelatedTable::UserGroup,
        ];
        foreach ($mappedTables as $table) {
            $toDelete = array_values(array_diff(
                $this->repo->findDistinctUserIdsInMappedTable($table),
                $baseUsers
            ));

            if (count($toDelete) > 0) {
                $this->repo->deleteUsersFromMappedTable($table, $toDelete);
            }
        }
    }

    /**
     * @return list<ActivationKeyRow>
     */
    public function getPendingActivationKeyRows(): array
    {
        return $this->repo->findPendingActivationKeyRows();
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, ?string>
     */
    public function getStatusByIds(array $ids): array
    {
        return $this->repo->findStatusByIds($ids);
    }

    public function getTotalUserCount(): int
    {
        return $this->repo->countAllUsers();
    }

    public function getRegistrationDateById(int $userId): ?string
    {
        return $this->repo->findRegistrationDateById($userId);
    }

    public function getMinRegistrationDateAfter(string $afterDate): ?string
    {
        return $this->repo->findMinRegistrationDateAfter($afterDate);
    }

    /**
     * @return list<int>
     */
    public function getAdminIds(bool $includeWebmaster = true): array
    {
        return array_map(static fn (UserId $id): int => $id->value, $this->repo->findAdminIds($includeWebmaster));
    }

    public function getUsernameById(UserId $userId): ?Username
    {
        return $this->repo->findUsernameById($userId);
    }

    /**
     * @return list<string>
     */
    public function getDistinctRegistrationYearMonths(): array
    {
        return $this->repo->findDistinctRegistrationYearMonths();
    }

    /**
     * @return array<string, int>
     */
    public function getUserCountsByStatus(int $excludeUserId): array
    {
        return $this->repo->findUserCountsByStatus($excludeUserId);
    }

    /**
     * @return array<int, int>
     */
    public function getUserCountsByLevel(int $excludeUserId): array
    {
        return $this->repo->findUserCountsByLevel($excludeUserId);
    }

    /**
     * @return list<string>
     */
    public function getUserIdsExcludingStatus(string $excludedStatus): array
    {
        return $this->repo->findUserIdsExcludingStatus($excludedStatus);
    }

    /**
     * @param  list<int|string>  $userIds
     * @return list<array<string, mixed>>
     */
    public function getNotificationRecipientsByIds(array $userIds): array
    {
        return $this->repo->findNotificationRecipientsByIds($userIds);
    }

    /**
     * @param  array<string, string>  $displayColumns
     * @return PaginatedResult<array<string, mixed>>
     */
    public function getListForWs(
        array $displayColumns,
        bool $includeLastVisitFromHistory,
        UserListCriteria $criteria,
        string $orderBy,
        bool $includeTotalCount,
        ?int $limit,
        int $offset
    ): PaginatedResult {
        return $this->repo->findListForWs($displayColumns, $includeLastVisitFromHistory, $criteria, $orderBy, $includeTotalCount, $limit, $offset);
    }
}
