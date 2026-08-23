<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Exception;
    use LogicException;
    use Override;
    use Piwigo\Activity\ActivityEntity;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Auth\PasswordService;
    use Piwigo\Category\CategoryService;
    use Piwigo\Common\ValueObject\Email;
    use Piwigo\Common\ValueObject\LangCode;
    use Piwigo\Common\ValueObject\ThemeId;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Common\ValueObject\Username;
    use Piwigo\Config\ConfigEntry;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\DeploymentPolicy;
    use Piwigo\Core\InstallationFlag;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\ProcessCache;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Feed\FeedEntity;
    use Piwigo\Feed\FeedRepository;
    use Piwigo\Group\GroupEntity;
    use Piwigo\Image\ImageEntity;
    use Piwigo\Mail\MailService;
    use Piwigo\Notification\NotificationByMailRepository;
    use Piwigo\Notification\UserMailNotificationEntity;
    use Piwigo\Permission\PermissionService;
    use Piwigo\Permission\SqlCondition;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Session\SessionEntity;
    use Piwigo\Session\SessionService;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentPathsTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\Projection\AccountFieldUpdates;
    use Piwigo\Users\Projection\DefaultUserInfo;
    use Piwigo\Users\User;
    use Piwigo\Users\UserInfoUpdateFailureReason;
    use Piwigo\Users\UserInfoUpdateInput;
    use Piwigo\Users\UserRepository;
    use Piwigo\Users\UserService;
    use Piwigo\Users\UserStatus;
    use ReflectionProperty;

    /**
     * Covers validation/lookup (fully self-contained) and
     * registerUser()'s two early-return paths -- a real validation error,
     * and the duplicate-username path (tested against a fixture user
     * with no email on file, so notifyExistingAccountOfDuplicateRegistration()
     * short-circuits before ever calling MailerInterface::mail() -- this
     * harness shouldn't attempt a real mail send). registerUser()'s full SUCCESS
     * path calls ActivityLoggerInterface::record() and
     * EventDispatcher::dispatch(new RegisterUser(...)) -- both fully
     * DBAL/in-memory, with no $mysqli dependency;
     * test_register_user_adds_the_new_user_to_default_groups() below
     * exercises it (see that test's own docblock for the addMembers()
     * argument-order invariant it guards) -- the success path's OTHER
     * effects (admin/user notification emails,
     * EventDispatcher::dispatch(new RegisterUser(...)),
     * ActivityLoggerInterface::record()) still aren't independently
     * asserted here; live-verified separately, same limitation as
     * GroupService (see its own test class docblock).
     */
    final class UserServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private UserService $service;

        private MailService $mailer;

        private Connection $conn;

        private ProcessCache $processCache;

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();
            Kernel::boot();
            $installationFlag = Kernel::container()->get(InstallationFlag::class);
            if (! $installationFlag instanceof InstallationFlag) {
                throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
            }
            $installationFlag->mark();
            $processCache = Kernel::container()->get(ProcessCache::class);
            if (! $processCache instanceof ProcessCache) {
                throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
            }
            $this->processCache = $processCache;

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $currentConfig->obligatoryUserMailAddress = false;
            $currentConfig->insensitiveCaseLogon = false;
            $currentConfig->browserLanguage = false;
            $currentConfig->emailAdminOnNewUser = 'none';
            $currentConfig->galleryTitle = 'Test Gallery';
            $currentConfig->webmasterId = 999999;
            $currentConfig->guestId = 2;
            $currentConfig->defaultUserId = 2;
            $currentConfig->availablePermissionLevels = [0, 1, 2, 4, 8];

            $this->conn = DbConnection::build();
            $mailer = Kernel::container()->get(MailService::class);
            self::assertInstanceOf(MailService::class, $mailer);
            $this->mailer = $mailer;
            $permissionService = Kernel::container()->get(PermissionService::class);
            self::assertInstanceOf(PermissionService::class, $permissionService);
            $categoryService = Kernel::container()->get(CategoryService::class);
            self::assertInstanceOf(CategoryService::class, $categoryService);
            $passwordService = Kernel::container()->get(PasswordService::class);
            self::assertInstanceOf(PasswordService::class, $passwordService);
            $this->service = new UserService(LangTestFactory::get(), new UserRepository(EntityManagerFactory::build($this->conn), new EventDispatcher(), CurrentConfigTestFactory::get()), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)), HtmlServiceTestFactory::build(), new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), new EventDispatcher(), new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), $installationFlag, $this->processCache, CurrentPathsTestFactory::get(), EntityManagerFactory::build($this->conn), $permissionService, $categoryService, $passwordService);

            // checkAndSaveUserInfos()'s own success path (any call that
            // doesn't return an early 'error') reaches
            // PermissionCacheInvalidator::invalidate() ->
            // CurrentConfigServiceTestFactory::get()->get() -- without this,
            // that throws a bare "CurrentConfigService not initialised"
            // LogicException.
            CurrentConfigServiceTestFactory::get()->set(new ConfigService(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class), $currentConfig));
        }

        /**
         * @param array<int<0, max>|string, mixed> $params
         */
        private function fetchOneInt(string $sql, array $params = []): int
        {
            $value = $this->conn->fetchOne($sql, $params);
            self::assertIsNumeric($value);

            return (int) $value;
        }

        // PHPStan tracks $_SESSION's constant-array shape flow-sensitively
        // within a single function -- unset()ing the same key twice in one
        // method (once up front, once in a finally) makes the second call a
        // provable-nonexistent-offset error, even though it's a genuinely
        // safe no-op at runtime. Routing both through a private method call
        // resets that tracking at the call boundary (PHPStan doesn't track
        // superglobal mutations across function calls).
        private function resetEditContext(): void
        {
            // $_SESSION is never auto-started in this CLI test process --
            // whichever test in this file happens to touch it first sees
            // it as null, not []. unset($_SESSION['edit_context']) alone is
            // a safe no-op either way, but a test asserting on $_SESSION's
            // own array-ness afterward needs a real array here.
            $_SESSION ??= [];
            unset($_SESSION['edit_context']);
        }

        public function testValidateMailAddressAcceptsAnUnusedAddress(): void
        {
            self::assertSame('', $this->service->validateMailAddress(null, 'never-used-' . bin2hex(random_bytes(4)) . '@example.test'));
        }

        public function testValidateMailAddressRejectsAMalformedAddress(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'not-an-email'));
        }

        public function testValidateMailAddressRejectsAnAddressAlreadyInUse(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'fixture_admin@example.test'));
        }

        public function testValidateMailAddressExcludesTheGivenUserId(): void
        {
            self::assertSame('', $this->service->validateMailAddress(UserId::from(1), 'fixture_admin@example.test'));
        }

        public function testValidateLoginCaseRejectsAnExistingUsernameAnyCase(): void
        {
            self::assertNotSame('', $this->service->validateLoginCase('FIXTURE_ADMIN'));
        }

        public function testValidateLoginCaseAcceptsAFreeUsername(): void
        {
            self::assertSame('', $this->service->validateLoginCase('never-used-' . bin2hex(random_bytes(4))));
        }

        public function testSearchCaseUsernameReturnsTheStoredCasing(): void
        {
            self::assertSame('fixture_admin', $this->service->searchCaseUsername('FIXTURE_ADMIN'));
        }

        public function testGetUserIdFindsAFixtureUser(): void
        {
            self::assertEquals(UserId::from(1), $this->service->getUserId(Username::from('fixture_admin')));
            self::assertNull($this->service->getUserId(Username::from('does-not-exist')));
        }

        public function testGetUserIdByEmailFindsAFixtureUser(): void
        {
            self::assertEquals(UserId::from(1), $this->service->getUserIdByEmail(Email::from('fixture_admin@example.test')));
        }

        public function testGetDefaultUserInfoAndValue(): void
        {
            $info = $this->service->getDefaultUserInfo();
            self::assertInstanceOf(DefaultUserInfo::class, $info);
            self::assertNotNull($info->language);
        }

        public function testRegisterUserRejectsAnEmptyLogin(): void
        {
            $result = $this->service->registerUser('', 'password123', null, UrlServiceTestFactory::build(), $this->mailer);

            self::assertNull($result->userId);
            self::assertNotSame([], $result->errors);
            self::assertFalse($result->duplicateUsername);
        }

        public function testRegisterUserSetsDuplicateUsernameWithoutRevealingItInErrors(): void
        {
            // 'guest' (fixture) has no email on file, so the duplicate-
            // account notice email is never attempted here.
            $result = $this->service->registerUser('guest', 'password123', null, UrlServiceTestFactory::build(), $this->mailer);

            self::assertNull($result->userId);
            self::assertTrue($result->duplicateUsername);
            self::assertSame([], $result->errors, 'the duplicate-login message must never appear in errors');
        }

        public function testRegisterUserDuplicateUsernameDoesNotInsertANewRow(): void
        {
            $countBefore = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('users')
                ->executeQuery()
                ->fetchOne();

            $this->service->registerUser('guest', 'password123', null, UrlServiceTestFactory::build(), $this->mailer);

            $countAfter = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('users')
                ->executeQuery()
                ->fetchOne();

            self::assertSame($countBefore, $countAfter);
        }

        /**
         * addMembers(GroupId $groupId, list<UserId> $userIds) adds many
         * users to ONE group -- registerUser() must call it with each
         * default group's id as $groupId and the new user's id as the
         * sole member, never the reverse. The fixture itself has zero
         * is_default groups (all 3 fixture groups are
         * is_default=0), so this test creates its own.
         */
        public function testRegisterUserAddsTheNewUserToDefaultGroups(): void
        {
            $groupRepo = EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class);
            $defaultGroupId = $groupRepo->insert('p18-regression-' . bin2hex(random_bytes(4)), true);

            $login = 'p18-regression-' . bin2hex(random_bytes(4));
            $result = $this->service->registerUser($login, 'password123', null, UrlServiceTestFactory::build(), $this->mailer);

            try {
                self::assertNotNull($result->userId);
                $userId = $result->userId;

                $members = $groupRepo->findMemberUserIds($defaultGroupId);
                self::assertSame([$userId], array_map(static fn (UserId $id): int => $id->value, $members));

                // The bug wrote (group_id, user_id) = ($userId, $defaultGroupId)
                // into user_group -- swapped columns, not just a missing row.
                // Confirm no such row exists.
                $swappedRowCount = $this->conn->createQueryBuilder()
                    ->select('COUNT(*)')
                    ->from('user_group')
                    ->where('group_id = :userId')
                    ->setParameter('userId', $userId)
                    ->executeQuery()
                    ->fetchOne();
                self::assertSame(0, is_numeric($swappedRowCount) ? (int) $swappedRowCount : -1);
            } finally {
                if ($result->userId !== null) {
                    $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$result->userId]);
                }
                $groupRepo->delete([$defaultGroupId]);
            }
        }

        /**
         * buildUser() populates the effective-permission fields on the
         * returned array; user_infos.level arrives as a native int via
         * DBAL, which EffectiveForbiddenCategoriesCache::getForUser() must
         * accept directly rather than as a string.
         */
        public function testBuildUserPopulatesEffectivePermissionFields(): void
        {
            $user = $this->service->buildUser(UserId::from(1));

            self::assertIsString($user['forbidden_categories']);
            self::assertSame('NOT IN', $user['image_access_type']);
            self::assertIsString($user['image_access_list']);
            self::assertIsString($user['nb_total_images']);
            self::assertIsString($user['last_photo_date']);
        }

        /**
         * Bootstrap\UserBootstrap::initialize()'s own pre-check before
         * calling buildUser() with a session's pwg_uid -- must return a
         * plain bool, not throw, for both a real and a nonexistent id.
         */
        public function testUserExistsReturnsTrueForAKnownUserAndFalseForAnUnknownOne(): void
        {
            self::assertTrue($this->service->userExists(UserId::from(1)));
            self::assertFalse($this->service->userExists(UserId::from(999999)));
        }

        public function testGetCurrentLanguageReturnsNullWhenCurrentUserIsNotInitialized(): void
        {
            CurrentUserTestFactory::get()->reset();

            self::assertNull($this->service->getCurrentLanguage());
        }

        public function testGetCurrentLanguageReturnsTheInitializedCurrentUsersOwnLanguage(): void
        {
            $user = $this->service->buildUser(UserId::from(1));
            CurrentUserTestFactory::get()->set(User::fromUserArray($user));

            try {
                self::assertSame($user['language'], $this->service->getCurrentLanguage());
            } finally {
                CurrentUserTestFactory::get()->reset();
            }
        }

        public function testGetUsernameReturnsTheRealUsernameForAKnownUser(): void
        {
            self::assertEquals(Username::from('fixture_admin'), $this->service->getUsername(UserId::from(1)));
        }

        public function testGetUsernameReturnsFalseForAnUnknownUser(): void
        {
            self::assertNull($this->service->getUsername(UserId::from(999999)));
        }

        public function testCheckAndSaveUserInfosRejectsAnEmptyUsername(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], username: '   '),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('Name field must not be empty', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsANonexistentUserId(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [999999]),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('This user does not exist.', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAUsernameAlreadyUsedByAnotherUser(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], username: 'fixture_admin'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame(LangTestFactory::get()->t('this login is already used'), $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAUsernameContainingHtmlTags(): void
        {
            // Username::from()'s own HTML-special character class (P44-H)
            // now rejects this before the method-local strip_tags() check
            // (deleted, since it could no longer fire with a different
            // outcome) ever gets there -- 'invalid login format', not the
            // former dedicated 'html tags are not allowed in login'.
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], username: '<b>evil</b>'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame(LangTestFactory::get()->t('invalid login format'), $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAUsernameContainingAnAmpersandOrQuoteWithNoAngleBrackets(): void
        {
            // The real gap this closes (P44-H), reached independently via
            // the profile-update path too: '&'/'"'/'\'' alone were never
            // caught by the pre-existing strip_tags()-based check (deleted
            // above) at all.
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], username: 'evil & "quoted\''),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame(LangTestFactory::get()->t('invalid login format'), $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAnInvalidEmail(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], email: 'not-an-email'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
        }

        public function testCheckAndSaveUserInfosRejectsAPasswordChangeByANonWebmasterForAProtectedUser(): void
        {
            // Target user 1 (fixture_admin) is 'webmaster' status, so it's
            // always protected against a non-webmaster's password change
            // regardless of who the current user is -- the default guest
            // current user (id 2, per CurrentConfig::guestId() above)
            // suffices, no CurrentUserTestFactory::get()->set() needed.
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [1], password: 'newpass123'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::Forbidden, $result->failureReason);
            self::assertSame('Only webmasters can change password of other "webmaster/admin" users', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosAllowsAPasswordChangeByAWebmaster(): void
        {
            $originalHash = $this->conn->fetchOne('SELECT password FROM users WHERE id = 4');
            self::assertIsString($originalHash);
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Webmaster));

            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [4], password: 'newpass123'),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                self::assertNotNull($result->account->password);
            } finally {
                CurrentUserTestFactory::get()->reset();
                $this->conn->executeStatement('UPDATE users SET password = ? WHERE id = 4', [$originalHash]);
            }
        }

        public function testCheckAndSaveUserInfosRejectsGrantingWebmasterStatusByANonWebmaster(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], status: 'webmaster'),
                PageStateTestFactory::get()
            );

            // Real bug found live: the old raw-array error shape used the
            // literal key 'code ' (trailing space) here, not 'code', so a
            // defensive `is_int($error['code'] ?? null)` narrowing check
            // silently fell back to the generic invalid-param error instead
            // of the intended 403 -- UserInfoUpdateFailureReason::Forbidden
            // has no string key to typo, so this now returns the correct
            // reason for real.
            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::Forbidden, $result->failureReason);
            self::assertSame('Only webmasters can grant "webmaster/admin" status', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAnInvalidStatusValue(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], status: 'not-a-real-status'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('Invalid status', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAnInvalidLevel(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], level: 99),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('Invalid level', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAnInvalidLanguage(): void
        {
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], language: 'xx-not-real'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('Invalid language', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosRejectsAnInvalidTheme(): void
        {
            // getPwgThemes() always includes AppInfo::DEFAULT_TEMPLATE
            // ('default') but nothing else, and the fixture's own themes
            // table is otherwise empty -- 'anything' stays a genuinely
            // invalid value without needing an implausible-sounding fake
            // theme name.
            $result = $this->service->checkAndSaveUserInfos(
                new UserInfoUpdateInput(userIds: [4], theme: 'anything'),
                PageStateTestFactory::get()
            );

            self::assertTrue($result->isFailure);
            self::assertSame(UserInfoUpdateFailureReason::InvalidInput, $result->failureReason);
            self::assertSame('Invalid theme', $result->failureMessage);
        }

        public function testCheckAndSaveUserInfosUpdatesUsernameAndEmailForASingleUser(): void
        {
            $before = $this->conn->fetchAssociative('SELECT username, mail_address FROM users WHERE id = 4');
            self::assertIsArray($before);
            $newLogin = 'temp-login-' . bin2hex(random_bytes(4));

            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [4], username: $newLogin, email: 'temp13@example.test'),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                self::assertSame([4], $result->userIds);
                self::assertSame([], $result->infos);
                self::assertEquals(new AccountFieldUpdates(
                    username: $newLogin,
                    mailAddress: 'temp13@example.test',
                ), $result->account);
                self::assertSame($newLogin, $result->account->username);
                self::assertNull($result->account->password);
                self::assertSame('temp13@example.test', $result->account->mailAddress);
                $after = $this->conn->fetchAssociative('SELECT username, mail_address FROM users WHERE id = 4');
                self::assertSame([
                    'username' => $newLogin,
                    'mail_address' => 'temp13@example.test',
                ], $after);
            } finally {
                $this->conn->executeStatement(
                    'UPDATE users SET username = ?, mail_address = ? WHERE id = 4',
                    [$before['username'], $before['mail_address']]
                );
            }
        }

        public function testCheckAndSaveUserInfosUpdatesEveryUserInfosFieldForASingleUser(): void
        {
            // getPwgThemes() always includes AppInfo::DEFAULT_TEMPLATE
            // ('default', the one real theme directory this repo ships)
            // regardless of the themes table's own content -- see that
            // method's own docblock -- so no themes row needs seeding
            // here for 'default' to validate.
            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(
                        userIds: [4],
                        level: 1,
                        language: 'en_UK',
                        theme: 'default',
                        nbImagePage: 20,
                        recentPeriod: 10,
                        expand: true,
                        showNbComments: true,
                        showNbHits: false,
                        enabledHigh: true,
                    ),
                    PageStateTestFactory::get()
                );

                $expectedInfos = [
                    'level' => 1,
                    'language' => 'en_UK',
                    'theme' => 'default',
                    'nb_image_page' => 20,
                    'recent_period' => 10,
                    'expand' => 1,
                    'show_nb_comments' => 1,
                    'show_nb_hits' => 0,
                    'enabled_high' => 1,
                ];
                self::assertFalse($result->isFailure);
                self::assertSame([4], $result->userIds);
                self::assertSame($expectedInfos, $result->infos);
                self::assertEquals(new AccountFieldUpdates(), $result->account);

                $after = $this->conn->fetchAssociative(
                    'SELECT level, language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits, enabled_high'
                    . ' FROM user_infos WHERE user_id = 4'
                );
                self::assertIsArray($after);
                // expand/show_nb_comments/show_nb_hits/enabled_high are
                // genuine boolean columns -- a raw fetch returns a native
                // PHP bool for them on Postgres, but a numeric 1/0 on
                // MySQL. $expectedInfos's own 1/0 convention is preserved
                // by normalizing $after's boolean-typed columns to match,
                // rather than changing $expectedInfos itself (also
                // compared above against $result->infos, real
                // application-level int data unaffected by this).
                foreach (['expand', 'show_nb_comments', 'show_nb_hits', 'enabled_high'] as $boolColumn) {
                    $after[$boolColumn] = (int) (bool) $after[$boolColumn];
                }
                self::assertSame($expectedInfos, $after);
            } finally {
                $boolLiterals = $this->dbDriver === 'pgsql'
                    ? [
                        'expand' => 'false',
                        'show_nb_comments' => 'false',
                        'show_nb_hits' => 'false',
                        'enabled_high' => 'true',
                    ]
                    : [
                        'expand' => '0',
                        'show_nb_comments' => '0',
                        'show_nb_hits' => '0',
                        'enabled_high' => '1',
                    ];
                $this->conn->executeStatement(
                    "UPDATE user_infos SET level = 0, language = 'en_UK', theme = 'default',"
                    . " nb_image_page = 15, recent_period = 7, expand = {$boolLiterals['expand']},"
                    . " show_nb_comments = {$boolLiterals['show_nb_comments']}, show_nb_hits = {$boolLiterals['show_nb_hits']},"
                    . " enabled_high = {$boolLiterals['enabled_high']}"
                    . ' WHERE user_id = 4'
                );
            }
        }

        public function testCheckAndSaveUserInfosMultiUserBranchSkipsUsernameEmailPasswordChecks(): void
        {
            try {
                // 'username' is set but count(user_ids) !== 1, so the
                // whole username/email/password validation block (and its
                // "already used" rejection) never runs at all -- only
                // 'level', a multi-user-safe field, applies.
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [3, 4], username: 'should-be-ignored', level: 2),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                self::assertSame([3, 4], $result->userIds);
                self::assertSame([
                    'level' => 2,
                ], $result->infos);
                self::assertEquals(new AccountFieldUpdates(), $result->account);

                $levels = $this->conn->fetchAllAssociative(
                    'SELECT user_id, level FROM user_infos WHERE user_id IN (3, 4) ORDER BY user_id'
                );
                self::assertSame([[
                    'user_id' => 3,
                    'level' => 2,
                ], [
                    'user_id' => 4,
                    'level' => 2,
                ]], $levels);
            } finally {
                $this->conn->executeStatement('UPDATE user_infos SET level = 0 WHERE user_id IN (3, 4)');
            }
        }

        public function testCheckAndSaveUserInfosStatusGuestDeletesSessionsForTheAffectedUsers(): void
        {
            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [4], status: 'guest'),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                $status = $this->conn->fetchOne('SELECT status FROM user_infos WHERE user_id = 4');
                self::assertSame('guest', $status);
            } finally {
                $this->conn->executeStatement("UPDATE user_infos SET status = 'normal' WHERE user_id = 4");
            }
        }

        public function testCheckAndSaveUserInfosAdminCannotChangeStatusOfAnotherAdminOrWebmaster(): void
        {
            // 'normal' isn't in the ['webmaster', 'admin'] granting-restricted
            // list, so an admin current user passes that first guard --
            // but CurrentUserTestFactory::get()->get()->status === UserStatus::Admin also
            // merges every real webmaster/admin user_id into
            // $protected_users, so target user 1 (webmaster) is silently
            // excluded from $user_ids_for_status: the call "succeeds"
            // (no error) yet user 1's status never actually changes.
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Admin));

            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [1], status: 'normal'),
                    PageStateTestFactory::get()
                );
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            self::assertFalse($result->isFailure);
            $status = $this->conn->fetchOne('SELECT status FROM user_infos WHERE user_id = 1');
            self::assertSame('webmaster', $status);
        }

        public function testCheckAndSaveUserInfosGroupIdRemovesAndReassignsGroupMembership(): void
        {
            // Fixture: user 4 (power_user) starts in group 3 only.
            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [4], groupIds: [1, 2]),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                $groups = $this->conn->fetchFirstColumn(
                    'SELECT group_id FROM user_group WHERE user_id = 4 ORDER BY group_id'
                );
                self::assertSame([1, 2], $groups);
            } finally {
                $this->conn->executeStatement('DELETE FROM user_group WHERE user_id = 4');
                $this->conn->executeStatement('INSERT INTO user_group (user_id, group_id) VALUES (4, 3)');
            }
        }

        public function testCheckAndSaveUserInfosGroupIdWithOnlyANonexistentGroupClearsMembershipWithoutReinserting(): void
        {
            try {
                $result = $this->service->checkAndSaveUserInfos(
                    new UserInfoUpdateInput(userIds: [4], groupIds: [999999]),
                    PageStateTestFactory::get()
                );

                self::assertFalse($result->isFailure);
                $groups = $this->conn->fetchFirstColumn('SELECT group_id FROM user_group WHERE user_id = 4');
                self::assertSame([], $groups);
            } finally {
                $this->conn->executeStatement('DELETE FROM user_group WHERE user_id = 4');
                $this->conn->executeStatement('INSERT INTO user_group (user_id, group_id) VALUES (4, 3)');
            }
        }

        public function testGetRecentPhotosConditionReturnsAFalseConditionWhenLastPhotoDateIsNotSet(): void
        {
            // Default guest CurrentUser (from IntegrationTestCase's own
            // setUp) has no 'last_photo_date' key in rawAttributes at all
            // -- only buildUser()'s own effective-permission enrichment
            // adds it (see the next test).
            self::assertEquals(SqlCondition::fromRawSql('0=1'), $this->service->getRecentPhotosCondition('i.date_available'));
        }

        public function testGetRecentPhotosConditionBuildsALeastExpressionWhenLastPhotoDateIsSet(): void
        {
            $user = $this->service->buildUser(UserId::from(1));
            CurrentUserTestFactory::get()->set(User::fromUserArray($user));

            try {
                $condition = $this->service->getRecentPhotosCondition('i.date_available');
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            // The exact SUBDATE(...) fragment is SqlDialectTest's own
            // territory (already covered there) -- this only confirms
            // UserService's own wiring reaches the real "set" branch, and
            // that $last_photo_date is bound rather than spliced.
            self::assertStringStartsWith('i.date_available>=LEAST(', (string) $condition->expr);
            self::assertStringEndsWith(')', (string) $condition->expr);
            self::assertArrayHasKey('recentLastPhotoDate', $condition->parameters);
        }

        /**
         * SqlDialect::getRecentPeriodExpression() requires a real `int` --
         * a non-numeric rawAttributes['recent_period'] falls back to 0 like
         * any other malformed input, never reaching the query verbatim.
         */
        public function testGetRecentPhotosConditionFallsBackToZeroDaysForANonNumericRecentPeriod(): void
        {
            CurrentUserTestFactory::get()->set(new User(
                id: UserId::from(1),
                username: Username::from('torres'),
                email: null,
                language: LangCode::from('en_UK'),
                theme: ThemeId::from('default'),
                status: UserStatus::Normal,
                enabledHigh: false,
                rawAttributes: [
                    'last_photo_date' => '2024-01-01',
                    'recent_period' => 'not-a-number',
                ],
            ));

            try {
                $condition = $this->service->getRecentPhotosCondition('i.date_available');
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            self::assertStringNotContainsString('not-a-number', (string) $condition->expr);
            // SqlDialect::getRecentPeriodExpression()'s own real Postgres
            // form is make_interval(days => 0), not SUBDATE's INTERVAL
            // literal -- already driver-aware in production code, this
            // assertion just wasn't updated to match.
            self::assertStringContainsString(
                $this->dbDriver === 'pgsql' ? 'make_interval(days => 0)' : 'INTERVAL 0 DAY',
                (string) $condition->expr
            );
            self::assertSame('2024-01-01', $condition->parameters['recentLastPhotoDate']);
        }

        public function testGetRecentPhotosDqlConditionReturnsAFalseConditionWhenLastPhotoDateIsNotSet(): void
        {
            self::assertEquals(SqlCondition::fromRawSql('0=1'), $this->service->getRecentPhotosDqlCondition('i.dateAvailable'));
        }

        public function testGetRecentPhotosDqlConditionBuildsAnOrExpressionWhenLastPhotoDateIsSet(): void
        {
            $user = $this->service->buildUser(UserId::from(1));
            CurrentUserTestFactory::get()->set(User::fromUserArray($user));

            try {
                $condition = $this->service->getRecentPhotosDqlCondition('i.dateAvailable');
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            // The mathematically-equivalent OR rewrite of the raw path's
            // LEAST(...) -- see getRecentPhotosDqlCondition()'s own
            // docblock for why -- each side using DATE_SUB(), the real
            // registered DQL function, not LEAST() (no DQL equivalent
            // exists).
            $expr = (string) $condition->expr;
            self::assertStringStartsWith('(i.dateAvailable >= DATE_SUB(', $expr);
            self::assertStringContainsString(' OR i.dateAvailable >= DATE_SUB(', $expr);
            self::assertStringEndsWith(')', $expr);
            self::assertArrayHasKey('recentLastPhotoDate', $condition->parameters);
        }

        public function testGetRecentPhotosDqlConditionAgreesWithTheRawConditionOnRealData(): void
        {
            // Real end-to-end proof the DQL rewrite selects the exact same
            // rows as the raw LEAST()-based fragment it replaces: both
            // conditions applied to the same real query, same bind values,
            // just DQL vs DBAL query builders.
            $user = $this->service->buildUser(UserId::from(1));
            CurrentUserTestFactory::get()->set(User::fromUserArray($user));

            try {
                $rawCondition = $this->service->getRecentPhotosCondition('date_available');
                $dqlCondition = $this->service->getRecentPhotosDqlCondition('i.dateAvailable');

                $rawQb = $this->conn->createQueryBuilder()
                    ->select('id')
                    ->from('images', 'i')
                    ->orderBy('id');
                $rawCondition->applyTo($rawQb);
                $rawIds = $rawQb->executeQuery()
                    ->fetchFirstColumn();

                $dqlQb = EntityManagerFactory::build($this->conn)
                    ->createQueryBuilder()
                    ->select('i.id')
                    ->from(ImageEntity::class, 'i')
                    ->orderBy('i.id');
                $dqlCondition->applyTo($dqlQb);
                $dqlIds = array_map(
                    static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
                    $dqlQb->getQuery()
                        ->getSingleColumnResult()
                );
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            self::assertNotSame([], $rawIds, 'fixture must have at least one image within the recent window for this test to be meaningful');
            self::assertSame(
                array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $rawIds),
                $dqlIds
            );
        }

        public function testSyncUsersCreatesMissingUserInfosForABaseUser(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('sync-orphan-user', NULL, NULL)"
            );
            $newUserId = (int) $this->conn->lastInsertId();

            try {
                self::assertSame(0, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM user_infos WHERE user_id = ?',
                    [$newUserId]
                ));

                $this->service->syncUsers(
                    $this->mailNotificationRepo(),
                    $this->feedRepo(),
                );

                self::assertSame(1, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM user_infos WHERE user_id = ?',
                    [$newUserId]
                ));
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$newUserId]);
            }
        }

        private function mailNotificationRepo(): NotificationByMailRepository
        {
            return EntityManagerFactory::build($this->conn)->getRepository(UserMailNotificationEntity::class);
        }

        private function feedRepo(): FeedRepository
        {
            return EntityManagerFactory::build($this->conn)->getRepository(FeedEntity::class);
        }

        public function testSyncUsersDeletesOrphanedChildRowsNotPresentInTheBaseTable(): void
        {
            // Every one of syncUsers()'s 5 child tables carries a real
            // ON DELETE CASCADE FK back to users in this schema, so
            // a genuine orphan can never arise through normal DB writes --
            // a plain INSERT with a nonexistent user_id
            // is rejected by the FK. Disabling FK checks just for this
            // insert reproduces the only real way this state has ever
            // existed in practice: a bulk import/migration that ran with
            // checks off. Covers all 5 of syncUsers()'s own tables, not
            // just the 2 DQL-mapped ones this test used to cover --
            // user_access (the 3rd DQL-mapped table) and
            // user_mail_notification/user_feed (both go through
            // UserRelatedTableSyncInterface, a real deptrac boundary) had
            // no coverage here at all before.
            $this->disableForeignKeyChecks($this->conn);
            $this->conn->executeStatement('INSERT INTO user_group (user_id, group_id) VALUES (777777, 1)');
            $this->conn->executeStatement('INSERT INTO user_infos (user_id) VALUES (777777)');
            $this->conn->executeStatement('INSERT INTO user_access (user_id, cat_id) VALUES (777777, 1)');
            $this->conn->executeStatement("INSERT INTO user_mail_notification (user_id, check_key, enabled) VALUES (777777, 'sync-orphan-nbm', 0)");
            $this->conn->executeStatement("INSERT INTO user_feed (id, user_id) VALUES ('sync-orphan-feed', 777777)");
            $this->enableForeignKeyChecks($this->conn);

            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM user_group WHERE user_id = 777777'));
            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM user_infos WHERE user_id = 777777'));
            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM user_access WHERE user_id = 777777'));
            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM user_mail_notification WHERE user_id = 777777'));
            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM user_feed WHERE user_id = 777777'));

            $this->service->syncUsers(
                $this->mailNotificationRepo(),
                $this->feedRepo(),
            );

            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM user_group WHERE user_id = 777777'));
            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM user_infos WHERE user_id = 777777'));
            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM user_access WHERE user_id = 777777'));
            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM user_mail_notification WHERE user_id = 777777'));
            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM user_feed WHERE user_id = 777777'));
        }

        public function testRegisterUserNotifiesAdminsOfANewRegistration(): void
        {
            // webmasterId is deliberately fake (999999, see setUp) for
            // every other test in this file -- MailService's own "from"
            // address resolves through it here, so it needs a real
            // fixture user (1, fixture_admin) just for this one call.
            // setUp() re-applies the fake default before every other
            // test, so no restore is needed.
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->emailAdminOnNewUser = 'all';
            $currentConfig->webmasterId = 1;
            $currentConfig->smtpHost = '127.0.0.1:1';
            $login = 'reg-notify-' . bin2hex(random_bytes(4));

            // notifyAdminsOfNewRegistration()'s own mail send
            // deterministically fails via the unreachable SMTP host (same
            // trick as NotificationByMailSenderTest/MailServiceTest -- see
            // their docblocks), which raises MailService::mail()'s own
            // E_USER_WARNING that failOnWarning="true" would otherwise
            // fail this test on. A plain @ does NOT stop PHPUnit's
            // ErrorHandler from surfacing it regardless, so a real no-op
            // error handler for the duration of this one call is the only
            // reliable way to swallow it.
            set_error_handler(static fn (): bool => true);
            try {
                $result = $this->service->registerUser($login, 'password123', null, UrlServiceTestFactory::build(), $this->mailer, true, false);
            } finally {
                restore_error_handler();
            }

            try {
                // The fire-and-forget mail attempt failing doesn't block
                // registration -- notifyAdminsOfNewRegistration() never
                // checks/propagates mailNotificationAdmins()'s own return
                // value.
                self::assertNotNull($result->userId);
                self::assertSame([], $result->errors);
            } finally {
                if ($result->userId !== null) {
                    $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$result->userId]);
                }
            }
        }

        public function testRegisterUserNotifiesAdminsOfANewRegistrationScopedToAGroup(): void
        {
            // Same reasoning/trick as
            // test_register_user_notifies_admins_of_a_new_registration()
            // above, but with the 'group:N' form of email_admin_on_new_user
            // -- exercises notifyAdminsOfNewRegistration()'s own
            // preg_match('/^group:(\d+)$/', ...) capture into $groupId.
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->emailAdminOnNewUser = 'group:5';
            $currentConfig->webmasterId = 1;
            $currentConfig->smtpHost = '127.0.0.1:1';
            $login = 'reg-notify-group-' . bin2hex(random_bytes(4));

            set_error_handler(static fn (): bool => true);
            try {
                $result = $this->service->registerUser($login, 'password123', null, UrlServiceTestFactory::build(), $this->mailer, true, false);
            } finally {
                restore_error_handler();
            }

            try {
                self::assertNotNull($result->userId);
                self::assertSame([], $result->errors);
            } finally {
                if ($result->userId !== null) {
                    $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$result->userId]);
                }
            }
        }

        public function testSearchCaseUsernameReturnsTheInputUnchangedWhenNoMatchExists(): void
        {
            self::assertSame('totally-unknown-name', $this->service->searchCaseUsername('totally-unknown-name'));
        }

        public function testRegisterUserRejectsALoginEndingWithASpace(): void
        {
            $result = $this->service->registerUser(
                'trailing-space-' . bin2hex(random_bytes(3)) . ' ',
                'password123',
                null,
                UrlServiceTestFactory::build(),
                $this->mailer
            );

            self::assertNull($result->userId);
            self::assertSame([LangTestFactory::get()->t('login mustn\'t end with a space character')], $result->errors);
        }

        public function testRegisterUserRejectsALoginStartingWithASpace(): void
        {
            $result = $this->service->registerUser(
                ' leading-space-' . bin2hex(random_bytes(3)),
                'password123',
                null,
                UrlServiceTestFactory::build(),
                $this->mailer
            );

            self::assertNull($result->userId);
            self::assertSame([LangTestFactory::get()->t('login mustn\'t start with a space character')], $result->errors);
        }

        public function testRegisterUserRejectsALoginWithHtmlTags(): void
        {
            // Username::from()'s own HTML-special character class (P44-H)
            // now rejects this via the 'invalid login format' check --
            // the former, separately-pushed 'html tags are not allowed in
            // login' error is deleted (dead code: any input it could ever
            // fire for necessarily contains '<', which the format check
            // above it always rejects too), so only one error is expected
            // now, not both.
            $result = $this->service->registerUser(
                '<b>tag-' . bin2hex(random_bytes(3)) . '</b>',
                'password123',
                null,
                UrlServiceTestFactory::build(),
                $this->mailer
            );

            self::assertNull($result->userId);
            self::assertSame([LangTestFactory::get()->t('invalid login format')], $result->errors);
        }

        public function testRegisterUserRejectsALoginContainingAnAmpersandOrQuoteWithNoAngleBrackets(): void
        {
            // The real gap this closes (P44-H): unlike '<b>evil</b>',
            // '&'/'"'/'\'' alone were never caught by the pre-existing
            // strip_tags()-based check (deleted above) at all -- only
            // Username::from()'s own new character-class restriction
            // rejects this.
            $result = $this->service->registerUser(
                'tag-' . bin2hex(random_bytes(3)) . '& "\'',
                'password123',
                null,
                UrlServiceTestFactory::build(),
                $this->mailer
            );

            self::assertNull($result->userId);
            self::assertSame([LangTestFactory::get()->t('invalid login format')], $result->errors);
        }

        public function testRegisterUserRejectsAnInvalidMailAddress(): void
        {
            $result = $this->service->registerUser(
                'valid-login-' . bin2hex(random_bytes(3)),
                'password123',
                'not-an-email',
                UrlServiceTestFactory::build(),
                $this->mailer
            );

            self::assertNull($result->userId);
            self::assertCount(1, $result->errors);
        }

        public function testRegisterUserMarksDuplicateWhenInsensitiveCaseLogonMatchesAnExistingLogin(): void
        {
            // 'GUEST' (uppercase) doesn't collide with the fixture's real
            // 'guest' row under this schema's binary username collation
            // (case-SENSITIVE), so getUserId() alone wouldn't catch it --
            // only insensitiveCaseLogon's own validateLoginCase() call
            // does. Reuses 'guest' specifically because it has no email on
            // file (same determinism reasoning as the existing
            // test_register_user_sets_duplicate_username_without_revealing_it_in_errors).
            CurrentConfigTestFactory::get()->insensitiveCaseLogon = true;

            $result = $this->service->registerUser('GUEST', 'password123', null, UrlServiceTestFactory::build(), $this->mailer);

            self::assertNull($result->userId);
            self::assertTrue($result->duplicateUsername);
            self::assertSame([], $result->errors);
        }

        public function testCreateUserInfosDoesNothingForAnEmptyUserIdList(): void
        {
            $countBefore = $this->fetchOneInt('SELECT COUNT(*) FROM user_infos');

            $this->service->createUserInfos([]);

            self::assertSame($countBefore, $this->fetchOneInt('SELECT COUNT(*) FROM user_infos'));
        }

        public function testCreateUserInfosAssignsWebmasterStatusAndTheMaxPermissionLevel(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('temp-webmaster-target', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            CurrentConfigTestFactory::get()->webmasterId = $tempId;

            try {
                $this->service->createUserInfos([UserId::from($tempId)]);

                $row = $this->conn->fetchAssociative(
                    'SELECT status, level FROM user_infos WHERE user_id = ?',
                    [$tempId]
                );
                self::assertSame([
                    'status' => 'webmaster',
                    'level' => 8,
                ], $row);
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testCreateUserInfosAssignsWebmasterStatusAndLevelZeroWhenNoPermissionLevelsAreConfigured(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('temp-webmaster-target-2', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            $currentConfig = CurrentConfigTestFactory::get();
            $currentConfig->webmasterId = $tempId;
            // availablePermissionLevels's own set hook treats an empty
            // array as "reset to the built-in default" ([0,1,2,4,8]), not
            // a real empty state, and
            // ReflectionProperty::setValue() invokes that hook rather than
            // bypassing it (it's the property's own write
            // path, not a separate setter method). setRawValue() writes the
            // backing storage directly, skipping the hook entirely -- the
            // only way left to reach a genuinely empty list.
            new ReflectionProperty(CurrentConfig::class, 'availablePermissionLevels')->setRawValue($currentConfig, []);

            try {
                $this->service->createUserInfos([UserId::from($tempId)]);

                $row = $this->conn->fetchAssociative(
                    'SELECT status, level FROM user_infos WHERE user_id = ?',
                    [$tempId]
                );
                self::assertSame([
                    'status' => 'webmaster',
                    'level' => 0,
                ], $row);
            } finally {
                $currentConfig->availablePermissionLevels = [0, 1, 2, 4, 8];
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testBuildUserForcesGuestStatusForTheConfiguredGuestId(): void
        {
            $this->conn->executeStatement("UPDATE user_infos SET status = 'normal' WHERE user_id = 2");

            try {
                $user = $this->service->buildUser(UserId::from(2));

                self::assertSame('guest', $user['status']);
                self::assertSame([
                    'guest_must_be_guest' => true,
                ], $user['internal_status']);
            } finally {
                $this->conn->executeStatement("UPDATE user_infos SET status = 'guest' WHERE user_id = 2");
            }
        }

        // buildUser()'s `! is_array($internal_status)` re-normalization
        // guard (the branch resetting a non-array $user['internal_status']
        // back to []) is not exercised above or anywhere else in this
        // file -- getUserData() (the only populator of $user before that
        // guard runs) never returns an 'internal_status' key: it's not a
        // real users/user_infos column, and CurrentConfig::userFields()'s
        // return type is a fixed 4-key shape (id/username/password/email)
        // with no way to alias an extra pwgfield onto it. There is no path
        // through buildUser()'s public contract that hands it a
        // pre-populated, non-array 'internal_status' to normalize -- same
        // "verified unreachable through the real API" shape as
        // UserRepositoryTest's findAdminIds() note.

        public function testGetThemeUsageCountsDelegatesToTheRepository(): void
        {
            $username = 'p24-longtail-theme-' . bin2hex(random_bytes(4));
            $theme = 'p24-longtail-theme-' . bin2hex(random_bytes(4));
            $this->conn->executeStatement(
                'INSERT INTO users (username, password, mail_address) VALUES (?, NULL, NULL)',
                [$username]
            );
            $tempId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                'INSERT INTO user_infos (user_id, theme) VALUES (?, ?)',
                [$tempId, $theme]
            );

            try {
                $counts = $this->service->getThemeUsageCounts();

                self::assertArrayHasKey($theme, $counts);
                self::assertSame(1, $counts[$theme]);
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testGetLanguageUsageCountsDelegatesToTheRepository(): void
        {
            // user_infos.language is LangCode-typed (strict `ll_RR`
            // shape) -- a random-but-shape-valid code avoids colliding
            // with real fixture data.
            $username = 'p24-longtail-lang-' . bin2hex(random_bytes(4));
            $language = chr(random_int(97, 122)) . chr(random_int(97, 122)) . '_' . chr(random_int(65, 90)) . chr(random_int(65, 90));
            $this->conn->executeStatement(
                'INSERT INTO users (username, password, mail_address) VALUES (?, NULL, NULL)',
                [$username]
            );
            $tempId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                'INSERT INTO user_infos (user_id, language) VALUES (?, ?)',
                [$tempId, $language]
            );

            try {
                $counts = $this->service->getLanguageUsageCounts();

                self::assertArrayHasKey($language, $counts);
                self::assertSame(1, $counts[$language]);
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testGetUserDataCreatesMissingUserInfosWhenExternalAuthentificationIsActive(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('sync-target-getdata', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            // A one-off instance with a non-default DeploymentPolicy --
            // $this->service is shared across every test in this file and
            // DeploymentPolicy is a constructor dependency, not a mutable
            // static, so it can't be flipped on the shared instance.
            $installationFlag = Kernel::container()->get(InstallationFlag::class);
            if (! $installationFlag instanceof InstallationFlag) {
                throw new LogicException('Container returned an unexpected type for ' . InstallationFlag::class);
            }
            $permissionService = Kernel::container()->get(PermissionService::class);
            self::assertInstanceOf(PermissionService::class, $permissionService);
            $categoryService = Kernel::container()->get(CategoryService::class);
            self::assertInstanceOf(CategoryService::class, $categoryService);
            $passwordService = Kernel::container()->get(PasswordService::class);
            self::assertInstanceOf(PasswordService::class, $passwordService);
            $service = new UserService(LangTestFactory::get(), new UserRepository(EntityManagerFactory::build($this->conn), new EventDispatcher(), CurrentConfigTestFactory::get()), EntityManagerFactory::build($this->conn)->getRepository(GroupEntity::class), new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)), HtmlServiceTestFactory::build(), new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()), new EventDispatcher(), new DeploymentPolicy(externalAuthentification: true), CurrentUserTestFactory::get(), CurrentConfigTestFactory::get(), $installationFlag, new ProcessCache(), CurrentPathsTestFactory::get(), EntityManagerFactory::build($this->conn), $permissionService, $categoryService, $passwordService);

            try {
                self::assertSame(0, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM user_infos WHERE user_id = ?',
                    [$tempId]
                ));

                $data = $service->getUserData(UserId::from($tempId));

                self::assertArrayHasKey('status', $data);
                self::assertSame(1, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM user_infos WHERE user_id = ?',
                    [$tempId]
                ));
                // fetchUserInfosWithThemeName() hydrates these 5 columns
                // as real PHP bools straight from UserInfoEntity's mapped
                // `boolean` type.
                foreach (['enabled_high', 'expand', 'last_visit_from_history', 'show_nb_comments', 'show_nb_hits'] as $key) {
                    self::assertIsBool($data[$key], "{$key} should be a real bool");
                }
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testGetUserDataThrowsForAUserIdAbsentFromTheUsersTable(): void
        {
            $this->expectException(Exception::class);
            $this->expectExceptionMessageIsOrContains('UserService::getUserData(): no such user_id 88888888');

            $this->service->getUserData(UserId::from(88888888));
        }

        public function testGetUserDataThrowsWhenTheUserInfosRowIsMissing(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('no-user-infos-target', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();

            try {
                $this->expectException(Exception::class);
                $this->expectExceptionMessageIsOrContains('UserService::getUserData(): user_infos fetch failed for user_id ' . $tempId);

                $this->service->getUserData(UserId::from($tempId));
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$tempId]);
            }
        }

        public function testGetUserDataCoercesALiteralTrueOrFalseScalarValueToARealBool(): void
        {
            // getUserData()'s generic true/false-string scan (see its own
            // inline comment) applies to every merged field. `users.username`
            // has no reserved-word restriction at this layer, so a literal
            // 'true'/'false' username is a real, if odd, way to reach it.
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('true', NULL, NULL)"
            );
            $trueId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('false', NULL, NULL)"
            );
            $falseId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement('INSERT INTO user_infos (user_id) VALUES (?), (?)', [$trueId, $falseId]);

            try {
                $trueData = $this->service->getUserData(UserId::from($trueId));
                $falseData = $this->service->getUserData(UserId::from($falseId));

                self::assertTrue($trueData['username']);
                self::assertFalse($falseData['username']);
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id IN (?, ?)', [$trueId, $falseId]);
            }
        }

        public function testGetUserDataPreservesRealNonEmptyPreferencesFromTheDb(): void
        {
            // fetchUserInfosWithThemeName()'s DQL UserInfoEntity hydration
            // makes its own `preferences` column arrive already decoded as
            // a PHP array (Doctrine's native `json` Type), not a raw JSON
            // string -- getUserData()'s own is_string() gate must not
            // discard a genuinely non-empty value arriving in that shape.
            $this->conn->executeStatement(
                "INSERT INTO users (username, password, mail_address) VALUES ('prefs-user', NULL, NULL)"
            );
            $userId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                "INSERT INTO user_infos (user_id, preferences) VALUES (?, '{\"show_whats_new_16\": false, \"admin_theme\": \"clear\"}')",
                [$userId]
            );

            try {
                $data = $this->service->getUserData(UserId::from($userId));

                // ksort(), not assertSame() directly -- MySQL's native JSON
                // type doesn't preserve insertion order on round-trip; this
                // test cares about the decoded key/value content, not the
                // incidental storage order.
                $preferences = $data['preferences'];
                self::assertIsArray($preferences);
                ksort($preferences);
                self::assertSame([
                    'admin_theme' => 'clear',
                    'show_whats_new_16' => false,
                ], $preferences);
            } finally {
                $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
            }
        }

        public function testCheckUserFavoritesDoesNothingWhenTheUserHasNoForbiddenCategories(): void
        {
            // Default guest CurrentUser's forbiddenCategories is '' --
            // the early-return guard, confirmed by asserting the
            // fixture's own favorites rows are untouched.
            $before = $this->conn->fetchFirstColumn('SELECT image_id FROM favorites WHERE user_id = 1 ORDER BY image_id');

            $this->service->checkUserFavorites();

            $after = $this->conn->fetchFirstColumn('SELECT image_id FROM favorites WHERE user_id = 1 ORDER BY image_id');
            self::assertSame($before, $after);
        }

        public function testCheckUserFavoritesDeletesAFavoriteThatFallsIntoAForbiddenCategory(): void
        {
            // Fixture: user 1's favorites are images 1, 3 (category 1)
            // and 5 (category 2). Forbidding category 2 makes image 5's
            // favorite unauthorized -- 1 and 3 stay untouched.
            $u1 = $this->service->buildUser(UserId::from(1));
            $u1['forbidden_categories'] = '2';
            CurrentUserTestFactory::get()->set(User::fromUserArray($u1));

            try {
                $this->service->checkUserFavorites();
            } finally {
                CurrentUserTestFactory::get()->reset();
            }

            $after = $this->conn->fetchFirstColumn('SELECT image_id FROM favorites WHERE user_id = 1 ORDER BY image_id');
            self::assertSame([1, 3], $after);

            $this->conn->executeStatement('DELETE FROM favorites WHERE user_id = 1');
            $this->conn->executeStatement('INSERT INTO favorites (user_id, image_id) VALUES (1,1),(1,3),(1,5)');
        }

        public function testGetDefaultThemeFallsBackToTheLiteralDefaultWhenNothingInstalledMatches(): void
        {
            // The fixture's own themes table is empty (same fact
            // SizingParams/ThemeCatalog tests elsewhere
            // in this suite already establish), so once the configured
            // default user's own theme also fails checkThemeInstalled(),
            // there's no installed theme left to fall back to at all --
            // the method's own final, hardcoded 'default' literal.
            $this->conn->executeStatement("UPDATE user_infos SET theme = 'nonexistent-theme-xyz' WHERE user_id = 2");

            try {
                self::assertSame('default', $this->service->getDefaultTheme());
            } finally {
                $this->conn->executeStatement("UPDATE user_infos SET theme = 'default' WHERE user_id = 2");
            }
        }

        public function testGetDefaultThemeCoercesANonStringCachedValueToTheLiteralDefault(): void
        {
            // $this->processCache (the same instance $this->service was
            // constructed with) is a plain per-request memoization cell --
            // DefaultUserInfo::fromArray() (read via getDefaultUserInfo())
            // narrows a non-string cached 'theme' value to '' rather than
            // trusting the docblock-only array shape, and getDefaultTheme()
            // itself falls back to AppInfo::DEFAULT_TEMPLATE for that empty
            // string. Poisoning the cache with a non-string 'theme' value is
            // the only way to reach that guard: without it, ThemeCatalog::
            // checkThemeInstalled() (a strictly-typed string param) would
            // receive an int and fatal with a TypeError.
            $this->processCache->set('default_user', [
                'nb_image_page' => 15,
                'language' => 'en_UK',
                'expand' => false,
                'show_nb_comments' => false,
                'show_nb_hits' => false,
                'recent_period' => 7,
                'theme' => 12345,
                'enabled_high' => true,
                'level' => 0,
                'activation_key' => null,
                'activation_key_expire' => null,
                'lastmodified' => '2026-01-01 00:00:00',
                'preferences' => null,
            ]);

            self::assertSame('default', $this->service->getDefaultTheme());
        }

        public function testGetBrowserLanguageReturnsFalseWhenNoHeaderIsPresent(): void
        {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

            self::assertFalse($this->service->getBrowserLanguage());
        }

        public function testGetBrowserLanguageReturnsFalseWhenTheHeaderHasNoParseableCodes(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = ';;;';

            try {
                self::assertFalse($this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function testGetBrowserLanguageMatchesTheExactFullForm(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-UK';

            try {
                self::assertSame('en_UK', $this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function testGetBrowserLanguageFallsBackToTheShortForm(): void
        {
            // 'en-XX' has no exact full-form match ('en_xx'), only the
            // short-form prefix 'en' does -- exercises the fallback
            // branch, not the exact-match one above.
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-XX';

            try {
                self::assertSame('en_UK', $this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function testGetBrowserLanguageReturnsFalseWhenNothingMatches(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zz-ZZ';

            try {
                self::assertFalse($this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function testSaveEditContextIgnoresANonNumericImageId(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Admin));
            $this->resetEditContext();

            try {
                $this->service->saveEditContext('/some/section', 'not-a-number');

                /**
                 * @psalm-suppress InvalidScalarArgument Psalm tracks
                 *   $_SESSION's literal shape across this test's own
                 *   prior assignments, marking 'edit_context' as a
                 *   possibly-undefined key -- a shape PHPUnit's
                 *   assertArrayNotHasKey() signature (plain
                 *   array<array-key, mixed>) doesn't accept, even though
                 *   the assertion itself is checking that exact absence.
                 */
                self::assertArrayNotHasKey('edit_context', $_SESSION);
            } finally {
                CurrentUserTestFactory::get()->reset();
                $this->resetEditContext();
            }
        }

        public function testSaveEditContextAndGetEditContextRoundTrip(): void
        {
            CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withStatus(UserStatus::Admin));
            $this->resetEditContext();

            try {
                $this->service->saveEditContext('/198/list/2,69,198', 198);

                self::assertSame([
                    '198' => '/198/list/2,69,198',
                ], $_SESSION['edit_context'] ?? null);
                // getEditContext() strips the leading "/{imageId}/" prefix
                // off the stored section URL.
                self::assertSame('list/2,69,198', $this->service->getEditContext(198));
            } finally {
                CurrentUserTestFactory::get()->reset();
                $this->resetEditContext();
            }
        }

        public function testGetEditContextReturnsFalseForAnUnknownImage(): void
        {
            $this->resetEditContext();

            self::assertFalse($this->service->getEditContext(999999));
        }

        public function testGetEditContextReturnsFalseForANonStringStoredValue(): void
        {
            $this->resetEditContext();
            $_SESSION['edit_context'] = [
                198 => [
                    'not' => 'a-string',
                ],
            ];

            try {
                self::assertFalse($this->service->getEditContext(198));
            } finally {
                $this->resetEditContext();
            }
        }
    }
}
