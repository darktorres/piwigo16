<?php

declare(strict_types=1);

// UserService calls several real, stable, already-migrated free functions
// that need more bootstrap (Lang, plugin-event-system, full $_SERVER-driven
// script_basename()) than this isolated integration test wants to depend
// on. Same "minimal stub to load standalone" pattern as
// tests/Unit/PasswordHashTest.php / tests/Integration/AuthServiceTest.php.
namespace {
    // validateMailAddress()/validateLoginCase() both gate their real
    // DB-uniqueness check on defined('PHPWG_INSTALLED') -- a genuine
    // "skip during the install wizard, before there's even a DB" guard in
    // the original code, faithfully preserved. Define it so the tests
    // below exercise the real check.
    if (! defined('PHPWG_INSTALLED')) {
        define('PHPWG_INSTALLED', true);
    }

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

    // trigger_change() is always available now via composer autoload.files
    // (src/Piwigo/PluginConfig/functions.php), a pure passthrough with no
    // handlers registered, so no local stub is needed.

    // No get_browser_language() stub: Config::browserLanguage() is
    // overridden to false below, and registerUser()'s own `Config::
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
    use Piwigo\Config\Config;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
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

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            Config::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            Config::override('user_fields', ['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address']);
            Config::override('obligatory_user_mail_address', false);
            Config::override('insensitive_case_logon', false);
            Config::override('browser_language', false);
            Config::override('email_admin_on_new_user', 'none');
            Config::override('gallery_title', 'Test Gallery');
            Config::override('webmaster_id', 999999);
            Config::override('guest_id', 2);
            Config::override('default_user_id', 2);
            Config::override('available_permission_levels', [0, 1, 2, 4, 8]);

            $this->conn = DbConnection::build();
            $this->service = new UserService(new UserRepository($this->conn), new GroupRepository($this->conn), new MailService(), new ActivityService(new ActivityRepository($this->conn)), new HtmlService(), $this->conn);
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
            $result = $this->service->registerUser('', 'password123', null);

            self::assertNull($result['userId']);
            self::assertNotSame([], $result['errors']);
            self::assertFalse($result['duplicateUsername']);
        }

        public function test_register_user_sets_duplicate_username_without_revealing_it_in_errors(): void
        {
            // 'guest' (fixture) has no email on file, so the SEC-31 notice
            // email is never attempted here.
            $result = $this->service->registerUser('guest', 'password123', null);

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

            $this->service->registerUser('guest', 'password123', null);

            $countAfter = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::users())
                ->executeQuery()
                ->fetchOne();

            self::assertSame($countBefore, $countAfter);
        }
    }
}
