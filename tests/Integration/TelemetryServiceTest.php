<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use ReflectionMethod;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;
use Piwigo\Db\Tables;
use Piwigo\Telemetry\TelemetryService;

final class TelemetryServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private TelemetryService $service;

    private Connection $conn;

    private ConfigRepository $configRepo;

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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->configRepo = $this->buildConfigRepo($this->conn);
        $this->service = new TelemetryService(EntityManagerFactory::build($this->conn), $this->configRepo);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->configRepo->deleteByParam('telemetry_install_id');
        parent::tearDown();
    }

    public function test_resolve_install_id_generates_and_persists_a_new_value(): void
    {
        self::assertNull($this->configRepo->find('telemetry_install_id'));

        $installId = $this->service->resolveInstallId();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $installId);

        // Read back through a fresh repository/entity manager -- proves the
        // write reached the database, not just this request's identity map.
        // `value` is a real JSON column (ConfigEntry's own docblock), and
        // resolveInstallId() stores it json_encode()'d -- the raw row
        // value is the JSON-quoted form, not the bare install id.
        $fresh = $this->buildConfigRepo(DbConnection::build());
        $entry = $fresh->find('telemetry_install_id');
        self::assertNotNull($entry);
        self::assertSame(json_encode($installId), $entry->value);
    }

    public function test_resolve_install_id_is_stable_across_calls(): void
    {
        $first = $this->service->resolveInstallId();
        $second = $this->service->resolveInstallId();

        self::assertSame($first, $second);
    }

    public function test_resolve_install_id_reuses_an_existing_row_instead_of_regenerating(): void
    {
        // upsert() expects an already JSON-encoded value (real `value`
        // column type), matching what resolveInstallId() itself writes.
        $encoded = json_encode('deadbeefdeadbeefdeadbeefdeadbeef');
        self::assertIsString($encoded);
        $this->configRepo->upsert('telemetry_install_id', $encoded);

        $service = new TelemetryService(EntityManagerFactory::build($this->conn), $this->buildConfigRepo(DbConnection::build()));

        self::assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $service->resolveInstallId());
    }

    public function test_build_payload_reports_real_fixture_counts(): void
    {
        $imageCount = $this->scalarCount(Tables::images());
        $categoryCount = $this->scalarCount(Tables::categories());
        $userCount = $this->scalarCount(Tables::users());
        $commentCount = $this->scalarCount(Tables::comments());

        $payload = $this->service->buildPayload();

        self::assertSame($imageCount, $payload->gallery->imageCount);
        self::assertSame($categoryCount, $payload->gallery->categoryCount);
        self::assertSame($userCount, $payload->gallery->userCount);
        self::assertSame($commentCount, $payload->gallery->commentCount);
    }

    public function test_build_payload_reports_a_real_mysql_server_version(): void
    {
        $payload = $this->service->buildPayload();

        self::assertSame($this->dbDriver === 'pgsql' ? 'pgsql' : 'mysql', $payload->database->driver);
        self::assertNotSame('', $payload->database->serverVersion);
    }

    public function test_build_payload_reports_the_real_running_php_version(): void
    {
        $payload = $this->service->buildPayload();

        self::assertSame(PHP_VERSION, $payload->environment->phpVersion);
    }

    public function test_build_payload_never_leaks_a_site_url_or_admin_email(): void
    {
        $payload = $this->service->buildPayload();

        $encoded = (string) json_encode($payload->toArray());

        self::assertStringNotContainsString('@', $encoded);
        self::assertStringNotContainsString('http://', $encoded);
        self::assertStringNotContainsString('https://', $encoded);
    }

    /**
     * detectDriverLabel()'s own real DB connection (see setUp()) is always
     * MySQL in this environment, so the mariadb/pgsql/unknown branches
     * below can only be reached with a stub Connection returning the
     * corresponding Doctrine platform directly -- Connection is a plain
     * (non-final) class, and detectDriverLabel() itself never touches
     * anything else on it besides getDatabasePlatform(), so a bare stub
     * of just that one method is enough. Reflection reaches the private
     * method directly since none of its 3 sibling branches are reachable
     * through buildPayload() in this environment either.
     *
     * Item 15 audit: detectDriverLabel() now reaches the connection via
     * `$this->em->getConnection()` -- the stub Connection is wrapped in a
     * stub EntityManagerInterface whose only expectation is returning it.
     */
    public function test_detect_driver_label_recognizes_mariadb(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new TelemetryService($em, $this->configRepo);
        $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

        self::assertSame('mariadb', $method->invoke($service));
    }

    public function test_detect_driver_label_recognizes_postgresql(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new TelemetryService($em, $this->configRepo);
        $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

        self::assertSame('pgsql', $method->invoke($service));
    }

    public function test_detect_driver_label_falls_back_to_unknown_for_an_unrecognized_platform(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new TelemetryService($em, $this->configRepo);
        $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

        self::assertSame('unknown', $method->invoke($service));
    }

    private function scalarCount(string $table): int
    {
        $count = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . $table)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function buildConfigRepo(Connection $conn): ConfigRepository
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentialsTestFactory::get()));

        $repo = $em->getRepository(ConfigEntry::class);

        return $repo;
    }
}
