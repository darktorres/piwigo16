<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Search\SearchRepository;

/**
 * Same fixture shape as CategoryRepositoryTest: images 1-5 (image_category
 * assigns 1,2,3 to category 1 and 4,5 to category 2), tags 1 "nature", 2
 * "travel", 3 "family" (image_tag: image 1 has all 3 tags, images 2/3 have
 * tag 1 only). `piwigo_search` starts empty.
 *
 * findRulesByIds()'s own `! is_numeric($row['id'] ?? null)` `continue`
 * branch is NOT chased here: `id` is `piwigo_search`'s NOT NULL
 * AUTO_INCREMENT primary key, always a native int under this project's
 * DBAL driver, so that branch is unreachable through any real fetched row
 * -- purely defensive, same shape as the SKIP LIST's documented
 * HttpClientService-only residuals.
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

        CurrentConfig::reset();
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
        $this->repo->insertSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-abcdefghij', null);

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
        $this->repo->insertSearch(['q' => 'travel'], '2026-07-12 00:00:00', 1, 'psk-20260712-klmnopqrst', null);

        self::assertSame(1, $this->repo->countByUuid('psk-20260712-klmnopqrst'));
    }

    public function test_insert_search_returns_the_new_autoincrement_id(): void
    {
        $id = $this->repo->insertSearch(['q' => 'family'], '2026-07-12 00:00:00', null, 'psk-20260712-uvwxyzabcd', null);

        self::assertGreaterThan(0, $id);

        $row = $this->repo->findOneByClause('id = ?', [$id]);
        self::assertNotNull($row);
        self::assertNull($row->createdBy);
        self::assertNull($row->forkedFrom);
    }

    public function test_insert_search_stores_forked_from(): void
    {
        $parentId = $this->repo->insertSearch(['q' => 'parent'], '2026-07-12 00:00:00', 1, 'psk-20260712-parentuuid', null);
        $childId = $this->repo->insertSearch(['q' => 'child'], '2026-07-12 00:00:00', 1, 'psk-20260712-childuuidx', $parentId);

        $row = $this->repo->findOneByClause('id = ?', [$childId]);
        self::assertNotNull($row);
        self::assertSame($parentId, $row->forkedFrom);
    }

    public function test_find_rules_by_ids_returns_decoded_rules_keyed_by_id(): void
    {
        $firstId = $this->repo->insertSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook01', null);
        $secondId = $this->repo->insertSearch(['q' => 'travel', 'fields' => ['allwords' => ['words' => ['travel']]]], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook02', null);

        $rules = $this->repo->findRulesByIds([$firstId, $secondId]);

        self::assertSame(['q' => 'nature'], $rules[$firstId]);
        self::assertSame(['q' => 'travel', 'fields' => ['allwords' => ['words' => ['travel']]]], $rules[$secondId]);
    }

    public function test_find_rules_by_ids_returns_empty_array_for_an_empty_id_list(): void
    {
        self::assertSame([], $this->repo->findRulesByIds([]));
    }

    public function test_find_rules_by_ids_omits_ids_with_no_matching_row(): void
    {
        self::assertSame([], $this->repo->findRulesByIds([999999]));
    }

    public function test_now_returns_a_non_empty_datetime_string(): void
    {
        $now = $this->repo->now();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
    }

    public function test_find_rules_by_ids_decodes_a_null_rules_column_to_null(): void
    {
        // insertSearch() always json_encode()s $rules (never a literal SQL
        // NULL) -- the only way to exercise the `rules` column's real
        // NULLable-JSON shape (schema: `rules json DEFAULT NULL`) is a raw
        // insert bypassing that method.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::search() . ' (rules, created_on, created_by, search_uuid, forked_from) VALUES (NULL, ?, ?, ?, NULL)',
            ['2026-07-12 00:00:00', 1, 'psk-20260712-nullrules1']
        );
        $id = (int) $this->conn->lastInsertId();

        $rules = $this->repo->findRulesByIds([$id]);

        self::assertNull($rules[$id]);
    }

    public function test_get_db_version_returns_a_non_empty_version_string(): void
    {
        $version = $this->repo->getDbVersion();

        self::assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function test_query_rows_returns_every_value_cast_to_string(): void
    {
        $rows = $this->repo->queryRows('SELECT id, name FROM ' . Tables::tags() . ' WHERE id = 1');

        self::assertSame([['id' => '1', 'name' => 'nature']], $rows);
    }

    public function test_query_rows_returns_an_empty_list_for_no_match(): void
    {
        self::assertSame([], $this->repo->queryRows('SELECT id, name FROM ' . Tables::tags() . ' WHERE id = 99999'));
    }

    public function test_query_keyed_column_returns_values_keyed_by_the_key_column(): void
    {
        $result = $this->repo->queryKeyedColumn('SELECT id, name FROM ' . Tables::tags() . ' ORDER BY id', 'id', 'name');

        self::assertSame([1 => 'nature', 2 => 'travel', 3 => 'family'], $result);
    }

    public function test_query_column_returns_a_plain_list_of_values(): void
    {
        $names = $this->repo->queryColumn('SELECT name FROM ' . Tables::tags() . ' ORDER BY id', 'name');

        self::assertSame(['nature', 'travel', 'family'], $names);
    }

    /**
     * SQL-modernization audit: queryRows()/queryKeyedColumn()/queryColumn()
     * gained optional $params/$types (Search\SearchFilterRenderer's own
     * conversion needs it) -- this and the two tests below are the first
     * direct coverage of that widening.
     */
    public function test_query_rows_binds_named_parameters(): void
    {
        $rows = $this->repo->queryRows(
            'SELECT id, name FROM ' . Tables::tags() . ' WHERE id IN (:ids) ORDER BY id',
            ['ids' => [1, 2]],
            ['ids' => ArrayParameterType::INTEGER],
        );

        self::assertSame([['id' => '1', 'name' => 'nature'], ['id' => '2', 'name' => 'travel']], $rows);
    }

    public function test_query_keyed_column_binds_named_parameters(): void
    {
        $result = $this->repo->queryKeyedColumn(
            'SELECT id, name FROM ' . Tables::tags() . ' WHERE id = :id',
            'id',
            'name',
            ['id' => 3],
        );

        self::assertSame([3 => 'family'], $result);
    }

    public function test_query_column_binds_named_parameters(): void
    {
        $names = $this->repo->queryColumn(
            'SELECT name FROM ' . Tables::tags() . ' WHERE id = :id',
            'name',
            ['id' => 2],
        );

        self::assertSame(['travel'], $names);
    }
}
