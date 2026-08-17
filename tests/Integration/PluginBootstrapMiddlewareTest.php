<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SortRenderer;
use Piwigo\Http\Middleware\PluginBootstrapMiddleware;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Http\Middleware\PluginBootstrapMiddleware` is the real, direct
 * successor of the second half of `Bootstrap\RequestBootstrap::connect()`
 * (workstream C3 Phase 1) -- plugin registry boot through
 * lounge-emptying, minus the `Admin\LoadedPlugins` repopulation glue
 * (see `LoadedPluginsMiddlewareTest` for that). Replaces the equivalent
 * cases from the now-deleted `RequestBootstrapConnectTest`'s
 * `testConnectStampsAFreshInstalledVersionAndLastMajorUpdateAppliesTheCustomOrderAndEmptiesTheLounge()`/
 * `testConnectRecordsAnAutoupdateActivityAndRestampsTheVersionWhenItDiffersFromAppinfo()`,
 * ported onto the new middleware boundary rather than mechanically moved
 * (Plan 3's own "Test portability correction").
 *
 * Unlike `connect()` itself, this middleware no longer calls
 * `ConfigService::loadConfFromDb()` (that moved to `ConfigBootstrapMiddleware`,
 * earlier in the real pipeline) -- so each test here hydrates
 * `CurrentConfig` directly via the same container-shared `ConfigService`
 * this middleware itself injects, the minimal real precondition this
 * middleware actually depends on, rather than running the whole earlier
 * middleware chain just to reach it.
 */
final class PluginBootstrapMiddlewareTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ConfigService $configService;

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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        // The container-shared ConfigService -- the same instance
        // PluginBootstrapMiddleware itself injects, so writing through it
        // here (both to arrange DB state via confUpdateParam()/
        // confDeleteParam() and to hydrate CurrentConfig via
        // loadConfFromDb(), the same call ConfigBootstrapMiddleware makes
        // earlier in the real pipeline) is visible to the middleware under
        // test.
        $configService = Kernel::container()->get(ConfigService::class);
        self::assertInstanceOf(ConfigService::class, $configService);
        $this->configService = $configService;

        // PluginBootstrapMiddleware doesn't call CurrentConfigService::set()
        // itself (ConfigBootstrapMiddleware, earlier in the real pipeline,
        // owns that) -- LoungeMaintenance::needsEmptying() ->
        // PermissionCacheInvalidator reaches CurrentConfigService::get()
        // transitively, so this middleware genuinely depends on that
        // earlier step having already run. Set it here, the same real
        // precondition ConfigBootstrapMiddleware itself establishes.
        CurrentConfigServiceTestFactory::get()->set($this->configService);

        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        EventDispatcherTestFactory::get()->reset();
        unset($_REQUEST['method']);

        parent::tearDown();
    }

    public function testProcessStampsAFreshInstalledVersionAndLastMajorUpdateAppliesTheCustomOrderAndEmptiesTheLounge(): void
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
        // NOW() -- LoungeMaintenance::needsEmptying()/ImageRepository::
        // findOldestLoungeAgeInfo() compute lounge-photo age against
        // Env::now(), so this test's own frozen PIWIGO_TEST_NOW must stay
        // close to real wall-clock time for the two clock sources to agree.
        $dateAvailable = $this->conn->fetchOne('SELECT date_available FROM images WHERE id = 1');
        self::assertIsString($dateAvailable);
        $this->conn->executeStatement('DELETE FROM lounge');
        $this->conn->executeStatement(
            'UPDATE images SET date_available = ? WHERE id = 1',
            [Env::now()->modify('-1 hour')->format('Y-m-d H:i:s')]
        );
        $this->conn->executeStatement('INSERT INTO lounge (image_id, category_id) VALUES (1, 1)');

        // Hydrates CurrentConfig from the DB state just arranged above --
        // the same call ConfigBootstrapMiddleware makes earlier in the
        // real pipeline, before this middleware ever runs.
        $this->configService->loadConfFromDb();

        try {
            $this->process();

            // Neither confUpdateParam() call for piwigo_installed_version
            // passes updateGlobal: true -- unlike last_major_update/
            // orderByInsideCategory just below, the in-memory
            // CurrentConfig::piwigoInstalledVersion() property is
            // deliberately left holding whatever loadConfFromDb() hydrated
            // it to at the start of this same test, so the DB row itself
            // (not the property) is the real proof this branch ran.
            $storedVersion = $this->conn->fetchOne(
                'SELECT value FROM config WHERE param = ?',
                ['piwigo_installed_version']
            );
            self::assertIsString($storedVersion);
            self::assertSame(AppInfo::VERSION, json_decode($storedVersion, true));
            self::assertNotNull(CurrentConfigTestFactory::get()->lastMajorUpdate);
            self::assertSame('ORDER BY id DESC', new SortRenderer($this->conn)->toSql(CurrentConfigTestFactory::get()->orderByInsideCategory));
            // The real, definitive proof LoungeMaintenance::needsEmptying()
            // -> ImageService::emptyLounge() actually ran: the lounge row
            // deleteLoungeUpTo() removes is gone.
            $loungeCount = $this->conn->fetchOne('SELECT COUNT(*) FROM lounge');
            self::assertSame('0', (string) $loungeCount);
        } finally {
            $this->conn->executeStatement('DELETE FROM lounge');
            $this->conn->executeStatement(
                'UPDATE images SET date_available = ? WHERE id = 1',
                [$dateAvailable]
            );
            $this->configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
            $this->configService->confUpdateParam('last_major_update', '2026-07-26 23:10:35');
            $this->configService->confDeleteParam('order_by_inside_category_custom');
            $this->configService->confUpdateParam('lounge_active', true);
            $this->configService->confDeleteParam('lounge_max_duration');
        }
    }

    public function testProcessRecordsAnAutoupdateActivityAndRestampsTheVersionWhenItDiffersFromAppinfo(): void
    {
        $this->configService->confUpdateParam('piwigo_installed_version', '16.0.0');
        // The middleware resolves its own ConfigService/EntityManager
        // through the DI container's shared EntityManager -- without this,
        // that shared instance's identity map still holds whatever
        // ConfigEntry object an earlier test's own loadConfFromDb() call
        // already loaded (e.g. AppInfo::VERSION from the fresh-install
        // test above), so CurrentConfig::piwigoInstalledVersion() reads
        // that stale, already-current value instead of the '16.0.0' just
        // written -- the version-differs branch never triggers, and the
        // DB is never restamped.
        InfrastructureAccessor::entityManager()->clear();
        $this->configService->loadConfFromDb();

        try {
            $this->process();

            // Same reasoning as the fresh-install test above:
            // confUpdateParam() here never passes updateGlobal: true, so
            // the DB row -- not CurrentConfig::piwigoInstalledVersion() --
            // is what proves the re-stamp really happened.
            $storedVersion = $this->conn->fetchOne(
                'SELECT value FROM config WHERE param = ?',
                ['piwigo_installed_version']
            );
            self::assertIsString($storedVersion);
            self::assertSame(AppInfo::VERSION, json_decode($storedVersion, true));

            $details = $this->conn->fetchOne(
                'SELECT details FROM activity WHERE object = ? AND object_id = ? AND action = ? ORDER BY activity_id DESC LIMIT 1',
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
            // unknown") -- needs an explicit ::text cast there. Built via
            // createQueryBuilder() with real named placeholders (matching
            // GroupServiceTest::fetchUserEditActivityAssociatedWith()'s own
            // identical-purpose query), not a raw executeStatement() string
            // with positional `?` placeholders -- concatenating `::text`
            // next to `?` placeholders in a raw string reads as a `:text`
            // *named* placeholder to phpstan-dba's SQL parser, a real,
            // unrelated-to-Postgres string ambiguity that mis-flags the
            // query as missing a bound value.
            $detailsColumn = $this->dbDriver === 'pgsql' ? 'details::text' : 'details';
            $this->conn->createQueryBuilder()
                ->delete('activity')
                ->where('object = :object')
                ->andWhere('object_id = :objectId')
                ->andWhere('action = :action')
                ->andWhere($detailsColumn . ' LIKE :pattern')
                ->setParameter('object', 'system')
                ->setParameter('objectId', ActivitySystem::Core)
                ->setParameter('action', 'autoupdate')
                ->setParameter('pattern', '%16.0.0%')
                ->executeStatement();
            $this->configService->confUpdateParam('piwigo_installed_version', AppInfo::VERSION);
        }
    }

    private function process(): void
    {
        $middleware = Kernel::container()->get(PluginBootstrapMiddleware::class);
        self::assertInstanceOf(PluginBootstrapMiddleware::class, $middleware);

        $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class() implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
