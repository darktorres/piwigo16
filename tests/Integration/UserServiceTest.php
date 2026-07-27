<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\UserRepository;
    use Piwigo\Users\UserService;

    /**
     * Covers validation/lookup (fully self-contained) and
     * registerUser()'s two early-return paths -- a real validation error,
     * and [SEC-31]'s duplicate-username path (tested against a fixture
     * user with no email on file, so notifyExistingAccountOfDuplicateRegistration()
     * short-circuits before ever calling MailerInterface::mail() -- this
     * harness shouldn't attempt a real mail send). registerUser()'s full SUCCESS
     * path calls ActivityLoggerInterface::record() and
     * EventDispatcher::triggerNotify() -- both fully DBAL/in-memory now, no
     * legacy $mysqli dependency; test_register_user_adds_the_new_user_to_default_groups()
     * below exercises it now, added as a regression test for a real bug
     * found during the Group domain VO integration (see
     * UserService::registerUser()'s own inline comment) -- the success
     * path's OTHER effects (admin/user notification emails,
     * EventDispatcher::triggerNotify(), ActivityLoggerInterface::record())
     * still aren't independently asserted here; live-verified separately,
     * same limitation as GroupService (see its own test class docblock).
     */
    final class UserServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private UserService $service;

        private Connection $conn;

        #[\Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();
            \Piwigo\Core\InstallationFlag::mark();

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::setUserFields(['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address']);
            CurrentConfig::setObligatoryUserMailAddress(false);
            CurrentConfig::setInsensitiveCaseLogon(false);
            CurrentConfig::setBrowserLanguage(false);
            CurrentConfig::setEmailAdminOnNewUser('none');
            CurrentConfig::setGalleryTitle('Test Gallery');
            CurrentConfig::setWebmasterId(999999);
            CurrentConfig::setGuestId(2);
            CurrentConfig::setDefaultUserId(2);
            CurrentConfig::setAvailablePermissionLevels([0, 1, 2, 4, 8]);

            $this->conn = DbConnection::build();
            $this->service = new UserService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), new MailService(), new ActivityService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)), new HtmlService(), $this->conn);
        }

        public function test_validate_mail_address_accepts_an_unused_address(): void
        {
            self::assertSame('', $this->service->validateMailAddress(null, 'never-used-' . bin2hex(random_bytes(4)) . '@example.test'));
        }

        public function test_validate_mail_address_rejects_a_malformed_address(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'not-an-email'));
        }

        public function test_validate_mail_address_rejects_an_address_already_in_use(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'fixture_admin@example.test'));
        }

        public function test_validate_mail_address_excludes_the_given_user_id(): void
        {
            self::assertSame('', $this->service->validateMailAddress(1, 'fixture_admin@example.test'));
        }

        public function test_validate_login_case_rejects_an_existing_username_any_case(): void
        {
            self::assertNotSame('', $this->service->validateLoginCase('FIXTURE_ADMIN'));
        }

        public function test_validate_login_case_accepts_a_free_username(): void
        {
            self::assertSame('', $this->service->validateLoginCase('never-used-' . bin2hex(random_bytes(4))));
        }

        public function test_search_case_username_returns_the_stored_casing(): void
        {
            self::assertSame('fixture_admin', $this->service->searchCaseUsername('FIXTURE_ADMIN'));
        }

        public function test_get_user_id_finds_a_fixture_user(): void
        {
            self::assertSame(1, $this->service->getUserId('fixture_admin'));
            self::assertFalse($this->service->getUserId('does-not-exist'));
        }

        public function test_get_user_id_by_email_finds_a_fixture_user(): void
        {
            self::assertSame(1, $this->service->getUserIdByEmail('fixture_admin@example.test'));
        }

        public function test_get_default_user_info_and_value(): void
        {
            $info = $this->service->getDefaultUserInfo();
            self::assertIsArray($info);

            $language = $this->service->getDefaultUserValue('language', 'fallback');
            self::assertNotSame('fallback', $language);
        }

        public function test_register_user_rejects_an_empty_login(): void
        {
            $result = $this->service->registerUser('', 'password123', null, new UrlService(new HtmlService()));

            self::assertNull($result['userId']);
            self::assertNotSame([], $result['errors']);
            self::assertFalse($result['duplicateUsername']);
        }

        public function test_register_user_sets_duplicate_username_without_revealing_it_in_errors(): void
        {
            // 'guest' (fixture) has no email on file, so the SEC-31 notice
            // email is never attempted here.
            $result = $this->service->registerUser('guest', 'password123', null, new UrlService(new HtmlService()));

            self::assertNull($result['userId']);
            self::assertTrue($result['duplicateUsername']);
            self::assertSame([], $result['errors'], 'the duplicate-login message must never appear in errors');
        }

        public function test_register_user_duplicate_username_does_not_insert_a_new_row(): void
        {
            $countBefore = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::users())
                ->executeQuery()
                ->fetchOne();

            $this->service->registerUser('guest', 'password123', null, new UrlService(new HtmlService()));

            $countAfter = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::users())
                ->executeQuery()
                ->fetchOne();

            self::assertSame($countBefore, $countAfter);
        }

        /**
         * Regression test for a real bug found during the Group domain VO
         * integration: registerUser() used to call
         * $this->groupRepo->addMembers($userId, $defaultGroupIds) directly
         * -- addMembers(GroupId $groupId, list<UserId> $userIds) adds many
         * users to ONE group, so that call wrote
         * (group_id, user_id) = ($userId, each default group's id) to
         * user_group, backwards. No test exercised registration + a real
         * default group together before this, which is exactly why it
         * went uncaught -- the fixture itself has zero is_default groups
         * (confirmed: all 3 fixture groups are is_default=0), so this test
         * creates its own.
         */
        public function test_register_user_adds_the_new_user_to_default_groups(): void
        {
            $groupRepo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class);
            $defaultGroupId = $groupRepo->insert('p18-regression-' . bin2hex(random_bytes(4)), true);

            $login = 'p18-regression-' . bin2hex(random_bytes(4));
            $result = $this->service->registerUser($login, 'password123', null, new UrlService(new HtmlService()));

            self::assertNotNull($result['userId']);
            $userId = $result['userId'];

            $members = $groupRepo->findMemberUserIds($defaultGroupId);
            self::assertSame([$userId], array_map(static fn (UserId $id): int => $id->value, $members));

            // The bug wrote (group_id, user_id) = ($userId, $defaultGroupId)
            // into user_group -- swapped columns, not just a missing row.
            // Confirm no such row exists.
            $swappedRowCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::userGroup())
                ->where('group_id = :userId')
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($swappedRowCount) ? (int) $swappedRowCount : -1);

            $groupRepo->delete([$defaultGroupId]);
        }

        /**
         * Real gap this closes: neither this class nor UserRepositoryTest
         * called getUserData()/buildUser() at all before gap-closure Stage
         * 4b -- a real TypeError (user_infos.level arrives as a native int
         * via DBAL, not the mysqli-style string
         * EffectiveForbiddenCategoriesCache::getForUser() first assumed)
         * shipped through PHPStan/Unit/Arch/Integration and was only
         * caught by the Browser suite hitting a real dev-server process.
         * Gap-closure Stage 4g deleted the `$useCache` parameter entirely
         * (it only ever gated the legacy lock/wait/503 regeneration block,
         * itself deleted the same stage) -- one test now covers the single
         * remaining code path.
         */
        public function test_build_user_populates_effective_permission_fields(): void
        {
            $user = $this->service->buildUser(1);

            self::assertIsString($user['forbidden_categories']);
            self::assertSame('NOT IN', $user['image_access_type']);
            self::assertIsString($user['image_access_list']);
            self::assertIsString($user['nb_total_images']);
            self::assertIsString($user['last_photo_date']);
        }
    }
}
