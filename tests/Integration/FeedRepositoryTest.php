<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use DateTimeImmutable;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedRepository;

final class FeedRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private FeedRepository $repo;

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

        $this->repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(FeedEntity::class), FeedRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbConnection::build()->executeStatement(
            "DELETE FROM user_feed WHERE id LIKE 'p17-test-%'"
        );

        parent::tearDown();
    }

    public function testExistsByIdReturnsFalseWhenUnused(): void
    {
        self::assertFalse($this->repo->existsById('p17-test-' . bin2hex(random_bytes(20))));
    }

    public function testInsertThenExistsByIdRoundTrips(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));

        $this->repo->insert($id, 1);

        self::assertTrue($this->repo->existsById($id));
    }

    public function testFindByIdReturnsTheUserIdWithANullLastCheckRightAfterInsert(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));
        $this->repo->insert($id, 1);

        $row = $this->repo->findById($id);

        self::assertNotNull($row);
        self::assertSame(1, $row->userId);
        self::assertNull($row->lastCheck);
    }

    public function testFindByIdReturnsNullWhenUnused(): void
    {
        self::assertNull($this->repo->findById('p17-test-' . bin2hex(random_bytes(20))));
    }

    public function testUpdateLastCheckThenFindByIdRoundTrips(): void
    {
        $id = 'p17-test-' . bin2hex(random_bytes(20));
        $this->repo->insert($id, 1);
        $lastCheck = new DateTimeImmutable('2024-03-05 12:34:56');

        $this->repo->updateLastCheck($id, $lastCheck);

        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertNotNull($row->lastCheck);
        self::assertSame($lastCheck->format('Y-m-d H:i:s'), $row->lastCheck->format('Y-m-d H:i:s'));
    }

    public function testUpdateLastCheckOnAnUnknownIdIsASilentNoOp(): void
    {
        $unknownId = 'p17-test-' . bin2hex(random_bytes(20));

        // Should neither throw nor create a row -- findById() confirms the
        // id genuinely stays absent afterward.
        $this->repo->updateLastCheck($unknownId, new DateTimeImmutable('2024-03-05 12:34:56'));

        self::assertNull($this->repo->findById($unknownId));
    }
}
