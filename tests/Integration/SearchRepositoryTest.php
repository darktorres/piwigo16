<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Search\SearchRepository;

/**
 * Same fixture shape as CategoryRepositoryTest: images 1-5 (image_category
 * assigns 1,2,3 to category 1 and 4,5 to category 2), tags 1 "nature", 2
 * "travel", 3 "family" (image_tag: image 1 has all 3 tags, images 2/3 have
 * tag 1 only). `piwigo_search` starts empty.
 */
final class SearchRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SearchRepository $repo;

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

        $this->conn = DbConnection::build();
        $this->repo = new SearchRepository($this->conn);
    }

    public function test_find_one_by_clause_returns_null_for_no_match(): void
    {
        self::assertNull($this->repo->findOneByClause('search_uuid = ?', ['no-such-uuid']));
    }

    public function test_find_one_by_clause_returns_the_matching_row(): void
    {
        $this->repo->insertSearch(serialize(['q' => 'nature']), '2026-07-12 00:00:00', 1, 'psk-20260712-abcdefghij', null);

        $row = $this->repo->findOneByClause('search_uuid = ?', ['psk-20260712-abcdefghij']);

        self::assertNotNull($row);
        self::assertSame('psk-20260712-abcdefghij', $row->searchUuid);
    }

    public function test_find_ids_by_clause_returns_a_list_of_ints(): void
    {
        $ids = $this->repo->findIdsByClause('id', Tables::images() . ' i', 'id > ?', [0]);
        sort($ids);

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_find_ids_by_clause_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findIdsByClause('id', Tables::images() . ' i', 'id > ?', [99999]));
    }

    public function test_find_rows_by_clause_returns_full_rows(): void
    {
        $rows = $this->repo->findRowsByClause(Tables::tags(), 'name = ?', ['nature']);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame('nature', $rows[0]['name']);
    }

    public function test_find_rows_by_clause_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findRowsByClause(Tables::tags(), 'name = ?', ['no-such-tag']));
    }

    public function test_quote_escapes_a_value_for_safe_inline_embedding(): void
    {
        // [SEC-18] real driver escaping (Connection::quote()), not
        // addslashes() -- the quoted value must round-trip safely when
        // embedded directly into a WHERE fragment (not bound via ?).
        $quoted = $this->repo->quote("o'brien\" --");

        $row = $this->conn->executeQuery("SELECT {$quoted} AS val")->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame("o'brien\" --", $row['val']);
    }

    public function test_count_by_uuid_returns_zero_for_unknown_uuid(): void
    {
        self::assertSame(0, $this->repo->countByUuid('no-such-uuid'));
    }

    public function test_count_by_uuid_returns_one_after_insert(): void
    {
        $this->repo->insertSearch(serialize(['q' => 'travel']), '2026-07-12 00:00:00', 1, 'psk-20260712-klmnopqrst', null);

        self::assertSame(1, $this->repo->countByUuid('psk-20260712-klmnopqrst'));
    }

    public function test_insert_search_returns_the_new_autoincrement_id(): void
    {
        $id = $this->repo->insertSearch(serialize(['q' => 'family']), '2026-07-12 00:00:00', null, 'psk-20260712-uvwxyzabcd', null);

        self::assertGreaterThan(0, $id);

        $row = $this->repo->findOneByClause('id = ?', [$id]);
        self::assertNotNull($row);
        self::assertNull($row->createdBy);
        self::assertNull($row->forkedFrom);
    }

    public function test_insert_search_stores_forked_from(): void
    {
        $parentId = $this->repo->insertSearch(serialize(['q' => 'parent']), '2026-07-12 00:00:00', 1, 'psk-20260712-parentuuid', null);
        $childId = $this->repo->insertSearch(serialize(['q' => 'child']), '2026-07-12 00:00:00', 1, 'psk-20260712-childuuidx', $parentId);

        $row = $this->repo->findOneByClause('id = ?', [$childId]);
        self::assertNotNull($row);
        self::assertSame($parentId, $row->forkedFrom);
    }

    public function test_now_returns_a_non_empty_datetime_string(): void
    {
        $now = $this->repo->now();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
    }
}
