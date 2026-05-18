<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Search\SearchFilterViewRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see SearchFilterViewRepository}.
 * Verifies the JSON round-trip on the saved-search filter configs.
 *
 * Uses Tables::searchFilterView() which reads Config::dbPrefix() —
 * seed Config in setUp.
 */
final class SearchFilterViewRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private SearchFilterViewRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        Config::loadArray(['db_prefix' => 'piwigo_']);
        $this->conn = $this->newDbalConnection();
        $this->repo = new SearchFilterViewRepository($this->conn);

        // Fixture may seed filter-view defaults from install; truncate
        // for deterministic test state.
        $this->conn->executeStatement('TRUNCATE TABLE piwigo_search_filter_view');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
        $this->conn->close();
    }

    public function test_hasAny_returns_false_when_empty(): void
    {
        self::assertFalse($this->repo->hasAny());
    }

    public function test_replaceAll_then_listAll_round_trips(): void
    {
        $this->repo->replaceAll([
            'date'    => ['access' => 'all', 'default' => true],
            'tags'    => ['access' => 'admin', 'default' => false],
            'authors' => false,
        ]);

        $loaded = $this->repo->listAll();

        self::assertEquals(['access' => 'all', 'default' => true], $loaded['date']);
        self::assertEquals(['access' => 'admin', 'default' => false], $loaded['tags']);
        self::assertFalse($loaded['authors']);
    }

    public function test_replaceAll_is_atomic_overwrite(): void
    {
        $this->repo->replaceAll(['date' => ['access' => 'all']]);
        self::assertTrue($this->repo->hasAny());

        $this->repo->replaceAll(['tags' => ['access' => 'admin']]);

        $loaded = $this->repo->listAll();
        self::assertArrayNotHasKey('date', $loaded, 'previous entry deleted');
        self::assertArrayHasKey('tags', $loaded);
    }

    public function test_deleteAll_clears_table(): void
    {
        $this->repo->replaceAll(['date' => ['x' => 1]]);
        $this->repo->deleteAll();

        self::assertFalse($this->repo->hasAny());
    }

    public function test_replaceAll_skips_empty_name(): void
    {
        $this->repo->replaceAll([
            ''       => ['skip' => true],
            'kept'   => ['x' => 1],
        ]);

        $loaded = $this->repo->listAll();
        self::assertArrayNotHasKey('', $loaded);
        self::assertArrayHasKey('kept', $loaded);
    }
}
