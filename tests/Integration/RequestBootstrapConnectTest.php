<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Doctrine\DBAL\Connection;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Db\Tables;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;

/**
 * Piwigo\Bootstrap\RequestBootstrap::connect() -- Phase 2 of the HTTP boot
 * sequence. Never called directly by any existing Unit/Integration test
 * before this file (only reachable, until now, through the Browser suite's
 * real HTTP requests via bootEntryPoint() -> connect()); safe to call
 * standalone here, same "call the phase directly with hand-built
 * preconditions" contract RequestBootstrapConfigureTest/
 * RequestBootstrapFinalizeTest already establish for configure()/
 * finalize() -- connect() does no template-rendering/language-loading work
 * of its own (finalize()'s job), so every precondition it needs (a booted
 * Kernel, real DB credentials, the config table's own current state) can
 * be set up by hand.
 *
 * Covers real branches the Browser suite's fixture state never naturally
 * exercises:
 *  - the DB-unreachable catch -> HtmlService::fatalError() (a wrong
 *    password fails fast, unlike an unreachable host/IP -- same
 *    DbCredentials::seed() convention InstallServiceTest's own
 *    installDbConnect() wrong-password case uses).
 *  - the fresh-install "piwigo_installed_version was never set" stamp.
 *  - the "installed version differs from AppInfo::VERSION" autoupdate
 *    activity-log record + re-stamp -- the fixture's own default value
 *    ('16.3.0') happens to already equal AppInfo::VERSION, so nothing
 *    naturally exercises this branch without a deliberate mismatch.
 *  - the "last_major_update was never set" stamp.
 *  - the order_by_inside_category_custom override.
 *  - the LoungeMaintenance::needsEmptying() -> ImageService::emptyLounge()
 *    call site itself (LoungeMaintenanceTest.php already covers
 *    needsEmptying() in isolation; this proves the real call site in
 *    connect() is wired to it).
 */
final class RequestBootstrapConnectTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ConfigService $configService;

    /**
     * @var array<string, string> original PIWIGO_DB_* env values, restored
     *   in tearDown() -- DbCredentials::seed() mutates real process
     *   env vars via putenv(), which would otherwise leak a bad/throwaway
     *   credential into every later test in this shared process (same
     *   reasoning as InstallServiceTest's own $originalDbEnv).
     */
    private array $originalDbEnv = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // pgsql support pass: PIWIGO_DB_DRIVER/PIWIGO_DB_PORT added for
        // completeness -- see InstallWizardTest/InstallServiceTest's own
        // docblocks for the real live-confirmed bug this exact list
        // missing these two keys caused elsewhere (a permanently leaked
        // env var corrupting every later Integration test class in the
        // same process). This class's own seed() call below doesn't
        // currently touch either key, but capturing them here too closes
        // the same class of gap before a future edit could reintroduce it.
        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }

        // Kernel is already booted by parent::setUp() with this exact same
        // dirname(__DIR__, 2) root -- no need to boot (or bind Paths) again.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        // A private, throwaway ConfigService used only to arrange DB-level
        // config state before each real connect() call -- connect() itself
        // always resolves its own ConfigService from the container (see
        // its own body), never this instance.
        $this->configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current());

        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbCredentialsTestFactory::get()->seed($this->originalDbEnv);

        // connect()'s own first statement is ErrorCollector::installIfConfigured()
        // -- every test below reaches it regardless of what happens
        // afterwards, so every test leaves a real set_error_handler()
        // active unless undone here (same "restore immediately" discipline
        // InstallBootstrapTest's own docblock documents). setUp() always
        // calls Kernel::boot(), so the container (and these instances) is
        // always available here.
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if ($errorCollector instanceof ErrorCollector) {
            if ($errorCollector->isActive()) {
                restore_error_handler();
            }
            $errorCollector->reset();
        }

        EventDispatcherTestFactory::get()->reset();
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        if ($installationFlag instanceof InstallationFlag) {
            $installationFlag->reset();
        }
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if ($currentLogger instanceof CurrentLogger) {
            $currentLogger->reset();
        }
        unset($_REQUEST['method']);

        parent::tearDown();
    }

    // ------------------------------------------------------------ DB failure

    public function test_connect_shows_a_fatal_error_page_when_the_database_is_unreachable(): void
    {
        // A wrong password fails fast (a real driver auth-failure reply)
        // instead of blocking on a real ~60s connect-timeout the way an
        // unreachable host/IP would -- same reasoning as
        // InstallServiceTest::test_installDbConnect_returns_null_and_records_an_error_for_a_wrong_password.
        DbCredentialsTestFactory::get()->seed([
            'PIWIGO_DB_HOST' => $this->dbHost,
            'PIWIGO_DB_USER' => $this->dbUser,
            'PIWIGO_DB_PASSWORD' => $this->dbPass . '-definitely-wrong',
            'PIWIGO_DB_BASE' => $this->dbName,
            'PIWIGO_DB_PREFIX' => $this->dbPrefix,
        ]);

        try {
            RequestBootstrap::connect();
            self::fail('connect() should have thrown ResponseReadyException.');
        } catch (ResponseReadyException $e) {
            $response = $e->response();
            self::assertSame(500, $response->getStatusCode());
            // Specific content, not just "some non-empty string" -- proves
            // the real driver exception message made it through
            // Lang::t($e->getMessage()) into the fatalError() page body.
            // Real wording differs per driver -- MySQL's mysqli says
            // "Access denied", Postgres says "password authentication
            // failed" (confirmed live against the real server).
            self::assertStringContainsString(
                $this->dbDriver === 'pgsql' ? 'password authentication failed' : 'Access denied',
                (string) $response->getBody()
            );
        }
    }

    // ------------------------------------------------- deferred first-run work

    public function test_connect_stamps_a_fresh_installed_version_and_last_major_update_applies_the_custom_order_and_empties_the_lounge(): void
    {
        // Fresh-install state: neither row has ever been written.
        $this->configService->confDeleteParam('piwigo_installed_version');
        $this->configService->confDeleteParam('last_major_update');
        // A real custom ORDER BY fragment for the "inside category" listing.
        $this->configService->confUpdateParam('order_by_inside_category_custom', 'ORDER BY id DESC');
        // Lounge: active, a short max duration, one photo aged well past it
        // -- same recipe as LoungeMaintenanceTest's own
        // test_needsEmptying_is_true_once_the_oldest_lounge_photo_exceeds_the_max_duration.
        $this->configService->confUpdateParam('lounge_active', true);
        $this->configService->confUpdateParam('lounge_max_duration', 60);

        // Anchored on Env::now() rather than the DB server's own real
        // NOW(), matching the real bug fixed in
        // LoungeMaintenance::needsEmptying()/ImageRepository::
        // findOldestLoungeAgeInfo() itself (2026-08-01): the two clock
        // sources agreed only as long as real wall-clock time stayed close
        // to a frozen PIWIGO_TEST_NOW, and drifted apart the moment it
        // didn't.
        $dateAvailable = $this->conn->fetchOne('SELECT date_available FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsString($dateAvailable);
        $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
            [Env::now()->modify('-1 hour')->format('Y-m-d H:i:s')]
        );
        $this->conn->executeStatement('INSERT INTO ' . Tables::lounge() . ' (image_id, category_id) VALUES (1, 1)');

        try {
            RequestBootstrap::connect();

            // Neither confUpdateParam() call for piwigo_installed_version
            // (line 406 here, line 413 for the elseif branch below) passes
            // updateGlobal: true -- unlike last_major_update/
            // orderByInsideCategory just below, the in-memory
            // CurrentConfig::piwigoInstalledVersion() property is
            // deliberately left holding whatever loadConfFromDb() hydrated
            // it to at the start of this same connect() call, so the DB
            // row itself (not the property) is the real proof this branch
            // ran.
            $storedVersion = $this->conn->fetchOne(
                'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
                ['piwigo_installed_version']
            );
            self::assertIsString($storedVersion);
            self::assertSame(AppInfo::VERSION, json_decode($storedVersion, true));
            self::assertNotNull(CurrentConfig::current()->lastMajorUpdate());
            self::assertSame('ORDER BY id DESC', CurrentConfig::current()->orderByInsideCategory());
            // The real, definitive proof LoungeMaintenance::needsEmptying()
            // -> ImageService::emptyLounge() actually ran: the lounge row
            // deleteLoungeUpTo() removes is gone.
            $loungeCount = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::lounge());
            self::assertSame('0', is_scalar($loungeCount) ? (string) $loungeCount : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET date_available = ? WHERE id = 1',
                [$dateAvailable]
            );
            $this->configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
            $this->configService->confUpdateParam('last_major_update', '2026-07-26 23:10:35');
            $this->configService->confDeleteParam('order_by_inside_category_custom');
            $this->configService->confUpdateParam('lounge_active', true);
            $this->configService->confDeleteParam('lounge_max_duration');
        }
    }

    public function test_connect_records_an_autoupdate_activity_and_restamps_the_version_when_it_differs_from_appinfo(): void
    {
        $this->configService->confUpdateParam('piwigo_installed_version', '16.0.0');
        // connect() resolves its own ConfigService through the DI
        // container's shared EntityManager, a different instance from
        // $this->configService's own standalone one above -- without
        // this, that shared instance's identity map still holds
        // whatever ConfigEntry object an earlier test's own connect()
        // call already loaded (e.g. AppInfo::VERSION from the fresh-
        // install test above), so CurrentConfig::piwigoInstalledVersion()
        // reads that stale, already-current value instead of the '16.0.0'
        // just written -- the version-differs branch never triggers, and
        // the DB is never restamped. See
        // feedback_entitymanagerfactory_not_memoized_needs_accessor.
        InfrastructureAccessor::entityManager()->clear();

        try {
            RequestBootstrap::connect();

            // Same reasoning as the fresh-install test above: confUpdateParam()
            // here (line 413) never passes updateGlobal: true, so the DB
            // row -- not CurrentConfig::piwigoInstalledVersion() -- is what
            // proves the re-stamp really happened.
            $storedVersion = $this->conn->fetchOne(
                'SELECT value FROM ' . Tables::config() . ' WHERE param = ?',
                ['piwigo_installed_version']
            );
            self::assertIsString($storedVersion);
            self::assertSame(AppInfo::VERSION, json_decode($storedVersion, true));

            $details = $this->conn->fetchOne(
                'SELECT details FROM ' . Tables::activity() . ' WHERE object = ? AND object_id = ? AND action = ? ORDER BY activity_id DESC LIMIT 1',
                ['system', ActivitySystem::Core, 'autoupdate']
            );
            self::assertIsString($details);
            $decoded = json_decode($details, true);
            self::assertIsArray($decoded);
            self::assertSame('16.0.0', $decoded['from_version']);
            self::assertSame(AppInfo::VERSION, $decoded['to_version']);
        } finally {
            // activity.details is a genuine json column on MySQL (LIKE
            // works directly) but jsonb on Postgres, which rejects LIKE
            // against it outright ("operator does not exist: jsonb ~~
            // unknown") -- needs an explicit ::text cast there.
            $detailsColumn = $this->dbDriver === 'pgsql' ? 'details::text' : 'details';
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::activity() . ' WHERE object = ? AND object_id = ? AND action = ? AND ' . $detailsColumn . ' LIKE ?',
                ['system', ActivitySystem::Core, 'autoupdate', '%16.0.0%']
            );
            $this->configService->confUpdateParam('piwigo_installed_version', '16.3.0');
        }
    }
}
