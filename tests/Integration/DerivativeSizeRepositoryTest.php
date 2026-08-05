<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeSizeEntity;
use Piwigo\Image\DerivativeSizeRepository;

/**
 * DerivativeSizeRepository's own syncEnabled()/syncDisabled() (both
 * routed through the private syncPartition()) are already exercised for
 * their happy path by ImageStdParamsTest (ImageStdParams::set_and_save()/
 * set_and_save_disabled() -> save() -> these two methods). This file
 * closes the 2 branches that path never reaches: syncPartition()'s own
 * defensive `enabled` mismatch guard, and the "delete a row that's no
 * longer in the wanted set" branch of its own cleanup loop (save() always
 * calls syncEnabled()/syncDisabled() with the *complete* current map, so
 * a real caller's own delete-by-omission case -- a previously-synced
 * row that's absent from a later call -- never happens through that path
 * alone).
 *
 * Snapshots and restores every real `derivative_size` row the same way
 * ImageStdParamsTest does -- the table is shared, mutable fixture state
 * other Integration suites also read.
 */
final class DerivativeSizeRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private DerivativeSizeRepository $repo;

    /**
     * @var list<array<string, mixed>>
     */
    private array $originalRows;

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

        $this->conn = DbConnection::build();
        $this->originalRows = $this->conn->fetchAllAssociative('SELECT * FROM ' . Tables::derivativeSize());

        $repo = EntityManagerFactory::build($this->conn)->getRepository(DerivativeSizeEntity::class);
        self::assertInstanceOf(DerivativeSizeRepository::class, $repo);
        $this->repo = $repo;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());
        foreach ($this->originalRows as $row) {
            $this->conn->insert(Tables::derivativeSize(), $row);
        }

        parent::tearDown();
    }

    private function newSize(string $name, int $enabled): DerivativeSizeEntity
    {
        return new DerivativeSizeEntity(
            name: $name,
            enabled: $enabled,
            maxWidth: 100,
            maxHeight: 100,
            maxCrop: '0.0000',
            minWidth: null,
            minHeight: null,
            sharpen: '0.0000',
            lastModTime: 1_785_000_000,
        );
    }

    public function test_sync_enabled_throws_when_given_an_entity_that_is_not_actually_enabled(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('syncPartition(): every entity must already have enabled=1');

        $this->repo->syncEnabled([$this->newSize('p17-test-mismatch', enabled: 0)]);
    }

    public function test_sync_disabled_throws_when_given_an_entity_that_is_actually_enabled(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('syncPartition(): every entity must already have enabled=0');

        $this->repo->syncDisabled([$this->newSize('p17-test-mismatch', enabled: 1)]);
    }

    public function test_sync_enabled_removes_a_previously_enabled_row_that_is_absent_from_the_new_set(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());

        $this->repo->syncEnabled([
            $this->newSize('p17-test-keep', enabled: 1),
            $this->newSize('p17-test-drop', enabled: 1),
        ]);
        $namesAfterFirstSync = array_map(static fn (DerivativeSizeEntity $e): string => $e->name, $this->repo->findAllEnabled());
        sort($namesAfterFirstSync);
        self::assertSame(['p17-test-drop', 'p17-test-keep'], $namesAfterFirstSync);

        // Second call omits 'p17-test-drop' -- syncPartition()'s own
        // cleanup loop must remove that now-unwanted row (its own
        // `enabled = 1` partition only) while leaving 'p17-test-keep'
        // alone.
        $this->repo->syncEnabled([
            $this->newSize('p17-test-keep', enabled: 1),
        ]);

        $namesAfterSecondSync = array_map(static fn (DerivativeSizeEntity $e): string => $e->name, $this->repo->findAllEnabled());
        self::assertSame(['p17-test-keep'], $namesAfterSecondSync);
    }

    public function test_sync_disabled_removes_a_previously_disabled_row_that_is_absent_from_the_new_set_without_touching_the_enabled_partition(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::derivativeSize());

        $this->repo->syncEnabled([$this->newSize('p17-test-enabled-untouched', enabled: 1)]);
        $this->repo->syncDisabled([
            $this->newSize('p17-test-disabled-keep', enabled: 0),
            $this->newSize('p17-test-disabled-drop', enabled: 0),
        ]);

        $this->repo->syncDisabled([
            $this->newSize('p17-test-disabled-keep', enabled: 0),
        ]);

        $disabledNames = array_map(static fn (DerivativeSizeEntity $e): string => $e->name, $this->repo->findAllDisabled());
        self::assertSame(['p17-test-disabled-keep'], $disabledNames);

        // The unrelated enabled partition must survive both syncDisabled()
        // calls untouched -- this is the entire point of partitioning the
        // cleanup-delete by `enabled`.
        $enabledNames = array_map(static fn (DerivativeSizeEntity $e): string => $e->name, $this->repo->findAllEnabled());
        self::assertSame(['p17-test-enabled-untouched'], $enabledNames);
    }
}
