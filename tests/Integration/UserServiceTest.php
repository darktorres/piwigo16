<?php

declare(strict_types=1);

// UserService calls several real, stable, already-migrated free functions
// that need more bootstrap (Lang, plugin-event-system, full $_SERVER-driven
// script_basename()) than this isolated integration test wants to depend
// on. Same "minimal stub to load standalone" pattern as
// tests/Unit/PasswordHashTest.php / tests/Integration/AuthServiceTest.php.
namespace {
    // validateMailAddress()/validateLoginCase() both gate their real
    // DB-uniqueness check on Piwigo\Core\InstallationFlag::isActive() -- a
    // genuine "skip during the install wizard, before there's even a DB"
    // guard in the original code, faithfully preserved. Marked in this
    // class's own setUp() (IntegrationTestCase::tearDown() already resets
    // it) so the tests below exercise the real check.

    if (! function_exists('l10n')) {
        function l10n(string $key, mixed ...$args): string
        {
            return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
        }
    }

    // email_check_format()/script_basename() stubs removed -- UserService's
    // real call sites now retarget directly to Piwigo\Validation\
    // InputValidator::checkEmailFormat()/Piwigo\Core\PageFilterHelper::
    // scriptBasename() (P23 batch 8d), so a same-named bare-function stub
    // is unreachable dead code, not a spy any real call site depends on.

    // trigger_change() calls go directly through the real
    // Piwigo\PluginConfig\EventDispatcher::get() singleton now, a pure
    // passthrough with no handlers registered, so no local stub is needed.

    // No get_browser_language() stub: CurrentConfig::browserLanguage() is
    // overridden to false below, and registerUser()'s own `CurrentConfig::
    // browserLanguage() && (... = get_browser_language()) !== false` check
    // short-circuits on the left operand, so the real
    // (unstubbed) function is never actually called by these tests. A
    // stub here would only exist to satisfy PHPStan, and a same-named
    // global-namespace redeclaration does the opposite: PHPStan's
    // whole-project analysis conflates it with the real
    // include/functions_user.inc.php definition, corrupting the inferred
    // signature used when checking UserService.php itself.
}

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
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
     * path calls pwg_activity()/trigger_notify(), which need the legacy
     * $mysqli dblayer connection this lightweight harness doesn't
     * bootstrap -- live-verified separately instead, same limitation as
     * GroupService (see its own test class docblock).
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
            $this->service = new UserService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), new MailService(), new ActivityService(new ActivityRepository($this->conn)), new HtmlService(), $this->conn);
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
