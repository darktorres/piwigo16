<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Search\SearchRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see SearchRepository}. Also guards the
 * F11-b FULLTEXT regression on piwigo_tags(name) (the qsearch tags
 * scope) by exercising the MATCH() query directly.
 */
final class SearchRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private SearchRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new SearchRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertSearchRow_round_trips_via_findSearchRow(): void
    {
        $uuid    = 'pksrch-12345678901234'; // CHAR(23) max
        $newId   = $this->repo->insertSearchRow([
            'search_uuid' => $uuid,
            'rules'       => json_encode(['fields' => ['photo' => ['words' => ['test']]]]),
            'created_on'  => '2026-05-18 12:00:00',
            'created_by'  => 1,
        ]);
        self::assertGreaterThan(0, $newId);

        $row = $this->repo->findSearchRow('search_uuid', $uuid);
        self::assertIsArray($row);
        self::assertSame($newId, $row['id']);
    }

    public function test_findSearchRow_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->findSearchRow('search_uuid', 'nonexistent-uuid'));
    }

    public function test_orderImageIds_returns_input_subset_in_canonical_order(): void
    {
        // Order by id ASC reproduces the input order for our fixture
        // (ids 1..5 already monotonic).
        $ordered = $this->repo->orderImageIds([5, 3, 1, 4, 2], 'ORDER BY id ASC');
        self::assertSame([1, 2, 3, 4, 5], $ordered);
    }

    /**
     * F11-b regression guard: tags.name FULLTEXT index must be queryable.
     * The qsearch tags scope splices `MATCH(name) AGAINST(...)` and pre-F11-b
     * this 500'd with "Can't find FULLTEXT index matching the column list".
     */
    public function test_fulltext_index_on_tags_name_is_queryable(): void
    {
        $row = $this->conn->executeQuery(
            "SHOW INDEX FROM piwigo_tags WHERE Key_name = 'tags_ft_name'"
        )->fetchAssociative();

        self::assertIsArray($row, 'tags_ft_name FULLTEXT index must exist (F11-b)');
        self::assertSame('FULLTEXT', $row['Index_type']);

        // MATCH() must not error. Result may be empty (no tag named "nature"
        // tokenises to 'nature' under default ft_min_word_len) — the
        // important guarantee is that the engine accepts the clause (no
        // SQL exception thrown).
        $this->conn->executeQuery(
            "SELECT id FROM piwigo_tags WHERE MATCH(name) AGAINST('nature' IN BOOLEAN MODE)"
        )->fetchAllAssociative();
    }
}
