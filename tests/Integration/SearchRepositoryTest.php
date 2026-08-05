<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\SearchRepository;

/**
 * Same fixture shape as CategoryRepositoryTest: images 1-5 (image_category
 * assigns 1,2,3 to category 1 and 4,5 to category 2), tags 1 "nature", 2
 * "travel", 3 "family" (image_tag: image 1 has all 3 tags, images 2/3 have
 * tag 1 only). `piwigo_search` starts empty.
 *
 * findSavedSearchRulesByIds()'s own `! is_numeric($row['id'] ?? null)`
 * `continue` branch is NOT chased here: `id` is `piwigo_search`'s NOT
 * NULL AUTO_INCREMENT primary key, always a native int, so that branch is
 * unreachable through any real fetched row -- purely defensive, same
 * shape as the SKIP LIST's documented HttpClientService-only residuals.
 */
final class SearchRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SearchRepository $repo;

    private Connection $conn;

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
        $this->repo = new SearchRepository(EntityManagerFactory::build($this->conn));
    }

    public function test_find_saved_search_by_uuid_returns_null_for_no_match(): void
    {
        self::assertNull($this->repo->findSavedSearchByUuid('no-such-uuid'));
    }

    public function test_find_saved_search_by_uuid_returns_the_matching_row(): void
    {
        $this->repo->insertSavedSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-abcdefghij', null);

        $row = $this->repo->findSavedSearchByUuid('psk-20260712-abcdefghij');

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

    public function test_count_saved_search_by_uuid_returns_zero_for_unknown_uuid(): void
    {
        self::assertSame(0, $this->repo->countSavedSearchByUuid('no-such-uuid'));
    }

    public function test_count_saved_search_by_uuid_returns_one_after_insert(): void
    {
        $this->repo->insertSavedSearch(['q' => 'travel'], '2026-07-12 00:00:00', 1, 'psk-20260712-klmnopqrst', null);

        self::assertSame(1, $this->repo->countSavedSearchByUuid('psk-20260712-klmnopqrst'));
    }

    public function test_insert_saved_search_returns_the_new_autoincrement_id(): void
    {
        $id = $this->repo->insertSavedSearch(['q' => 'family'], '2026-07-12 00:00:00', null, 'psk-20260712-uvwxyzabcd', null);

        self::assertGreaterThan(0, $id);

        $row = $this->repo->findSavedSearchById($id);
        self::assertNotNull($row);
        self::assertNull($row->createdBy);
        self::assertNull($row->forkedFrom);
    }

    public function test_insert_saved_search_stores_forked_from(): void
    {
        $parentId = $this->repo->insertSavedSearch(['q' => 'parent'], '2026-07-12 00:00:00', 1, 'psk-20260712-parentuuid', null);
        $childId = $this->repo->insertSavedSearch(['q' => 'child'], '2026-07-12 00:00:00', 1, 'psk-20260712-childuuidx', $parentId);

        $row = $this->repo->findSavedSearchById($childId);
        self::assertNotNull($row);
        self::assertSame($parentId, $row->forkedFrom);
    }

    public function test_find_saved_search_rules_by_ids_returns_decoded_rules_keyed_by_id(): void
    {
        $firstId = $this->repo->insertSavedSearch(['q' => 'nature'], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook01', null);
        $secondId = $this->repo->insertSavedSearch(['q' => 'travel', 'fields' => ['allwords' => ['words' => ['travel']]]], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook02', null);

        $rules = $this->repo->findSavedSearchRulesByIds([$firstId, $secondId]);

        self::assertSame(['q' => 'nature'], $rules[$firstId]);
        self::assertSame(['q' => 'travel', 'fields' => ['allwords' => ['words' => ['travel']]]], $rules[$secondId]);
    }

    public function test_find_saved_search_rules_by_ids_returns_empty_array_for_an_empty_id_list(): void
    {
        self::assertSame([], $this->repo->findSavedSearchRulesByIds([]));
    }

    public function test_find_saved_search_rules_by_ids_omits_ids_with_no_matching_row(): void
    {
        self::assertSame([], $this->repo->findSavedSearchRulesByIds([999999]));
    }

    public function test_find_saved_search_rules_by_ids_decodes_a_null_rules_column_to_null(): void
    {
        // insertSavedSearch() always writes a real array via persist()
        // (never a literal SQL NULL) -- the only way to exercise the
        // `rules` column's real NULLable-JSON shape (schema: `rules json
        // DEFAULT NULL`) is a raw insert bypassing that method.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::search() . ' (rules, created_on, created_by, search_uuid, forked_from) VALUES (NULL, ?, ?, ?, NULL)',
            ['2026-07-12 00:00:00', 1, 'psk-20260712-nullrules1']
        );
        $id = (int) $this->conn->lastInsertId();

        $rules = $this->repo->findSavedSearchRulesByIds([$id]);

        self::assertNull($rules[$id]);
    }

    public function test_get_db_version_returns_a_non_empty_version_string(): void
    {
        $version = $this->repo->getDbVersion();

        self::assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    /**
     * Further SQL-modernization audit, Item 15H: `queryRows()`/
     * `queryKeyedColumn()`/`queryColumn()` retired once
     * `SearchFilterRenderer`'s own filter-sidebar blocks (their only real
     * callers) converted to the 4 typed DQL methods below.
     */
    public function test_count_images_grouped_by_returns_counts_ordered_desc(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET author = 'Ansel Adams' WHERE id IN (1, 2)");
        $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET author = 'Dorothea Lange' WHERE id = 3");

        try {
            $rows = $this->repo->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL'), true);

            self::assertSame([
                ['author' => 'Ansel Adams', 'counter' => 2],
                ['author' => 'Dorothea Lange', 'counter' => 1],
            ], $rows);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET author = NULL WHERE id IN (1, 2, 3)');
        }
    }

    public function test_count_images_grouped_by_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL')));
    }

    public function test_find_distinct_image_rows_returns_the_requested_extra_columns(): void
    {
        $rows = $this->repo->findDistinctImageRows(
            ['i.ratingScore AS rating_score'],
            new SqlCondition('i.id = :id', ['id' => 1]),
        );

        self::assertSame([['id' => 1, 'rating_score' => 4.5]], $rows);
    }

    public function test_find_distinct_image_rows_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findDistinctImageRows(['i.ratingScore AS rating_score'], new SqlCondition('i.id = :id', ['id' => 99999])));
    }

    public function test_find_distinct_image_column_values_returns_grouped_ordered_values(): void
    {
        // every fixture image shares height 150 -- collapses to one row via
        // this method's own GROUP BY.
        $values = $this->repo->findDistinctImageColumnValues('i.height', new SqlCondition(''));

        self::assertSame(['150'], $values);
    }

    public function test_find_category_ids_and_uppercats_returns_matching_rows(): void
    {
        $rows = $this->repo->findCategoryIdsAndUppercats(
            new SqlCondition('c.id IN (:ids)', ['ids' => [1, 2]], ['ids' => ArrayParameterType::INTEGER]),
        );

        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        self::assertSame([
            ['id' => 1, 'uppercats' => '1'],
            ['id' => 2, 'uppercats' => '1,2'],
        ], $rows);
    }

    public function test_find_category_ids_and_uppercats_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findCategoryIdsAndUppercats(new SqlCondition('c.id = :id', ['id' => 99999])));
    }
}
