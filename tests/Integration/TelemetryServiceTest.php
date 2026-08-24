<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use LogicException;
use Override;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Telemetry\TelemetryService;
use ReflectionMethod;

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

    public function testResolveInstallIdGeneratesAndPersistsANewValue(): void
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

    public function testResolveInstallIdIsStableAcrossCalls(): void
    {
        $first = $this->service->resolveInstallId();
        $second = $this->service->resolveInstallId();

        self::assertSame($first, $second);
    }

    public function testResolveInstallIdReusesAnExistingRowInsteadOfRegenerating(): void
    {
        // upsert() expects an already JSON-encoded value (real `value`
        // column type), matching what resolveInstallId() itself writes.
        $encoded = json_encode('deadbeefdeadbeefdeadbeefdeadbeef');
        self::assertIsString($encoded);
        $this->configRepo->upsert('telemetry_install_id', $encoded);

        $service = new TelemetryService(EntityManagerFactory::build($this->conn), $this->buildConfigRepo(DbConnection::build()));

        self::assertSame('deadbeefdeadbeefdeadbeefdeadbeef', $service->resolveInstallId());
    }

    public function testBuildPayloadReportsRealFixtureCounts(): void
    {
        $imageCount = $this->scalarCount('images');
        $categoryCount = $this->scalarCount('categories');
        $userCount = $this->scalarCount('users');
        $commentCount = $this->scalarCount('comments');

        $payload = $this->service->buildPayload();

        self::assertSame($imageCount, $payload->gallery->imageCount);
        self::assertSame($categoryCount, $payload->gallery->categoryCount);
        self::assertSame($userCount, $payload->gallery->userCount);
        self::assertSame($commentCount, $payload->gallery->commentCount);
    }

    public function testBuildPayloadReportsARealMysqlServerVersion(): void
    {
        $payload = $this->service->buildPayload();

        self::assertSame($this->dbDriver === 'pgsql' ? 'pgsql' : 'mysql', $payload->database->driver);
        self::assertNotSame('', $payload->database->serverVersion);
    }

    public function testBuildPayloadReportsTheRealRunningPhpVersion(): void
    {
        $payload = $this->service->buildPayload();

        self::assertSame(PHP_VERSION, $payload->environment->phpVersion);
    }

    public function testBuildPayloadNeverLeaksASiteUrlOrAdminEmail(): void
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
     * detectDriverLabel() reaches the connection via
     * `$this->em->getConnection()` -- the stub Connection is wrapped in a
     * stub EntityManagerInterface whose only expectation is returning it.
     */
    public function testDetectDriverLabelRecognizesMariadb(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')
            ->willReturn(new MariaDBPlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')
            ->willReturn($conn);

        $service = new TelemetryService($em, $this->configRepo);
        $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

        self::assertSame('mariadb', $method->invoke($service));
    }

    public function testDetectDriverLabelRecognizesPostgresql(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')
            ->willReturn(new PostgreSQLPlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')
            ->willReturn($conn);

        $service = new TelemetryService($em, $this->configRepo);
        $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

        self::assertSame('pgsql', $method->invoke($service));
    }

    public function testDetectDriverLabelFallsBackToUnknownForAnUnrecognizedPlatform(): void
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')
            ->willReturn(new SQLitePlatform());
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')
            ->willReturn($conn);

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

        $repo = TypedRepository::narrow($em->getRepository(ConfigEntry::class), ConfigRepository::class);

        return $repo;
    }
}
