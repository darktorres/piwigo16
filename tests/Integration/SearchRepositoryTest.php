<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\CategoryIdUppercats;
use Piwigo\Search\SearchRepository;

/**
 * Same fixture shape as CategoryRepositoryTest: images 1-5 (image_category
 * assigns 1,2,3 to category 1 and 4,5 to category 2), tags 1 "nature", 2
 * "travel", 3 "family" (image_tag: image 1 has all 3 tags, images 2/3 have
 * tag 1 only). `search` starts empty.
 *
 * findSavedSearchRulesByIds()'s own `! is_numeric($row['id'] ?? null)`
 * `continue` branch is NOT chased here: `id` is `search`'s NOT
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

    public function testFindSavedSearchByUuidReturnsNullForNoMatch(): void
    {
        self::assertNull($this->repo->findSavedSearchByUuid('no-such-uuid'));
    }

    public function testFindSavedSearchByUuidReturnsTheMatchingRow(): void
    {
        $this->repo->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-abcdefghij', null);

        $row = $this->repo->findSavedSearchByUuid('psk-20260712-abcdefghij');

        self::assertNotNull($row);
        self::assertSame('psk-20260712-abcdefghij', $row->searchUuid);
    }

    public function testFindIdsByClauseReturnsAListOfInts(): void
    {
        $ids = $this->repo->findIdsByClause('id', 'images i', 'id > ?', [0]);
        sort($ids);

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testFindIdsByClauseReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findIdsByClause('id', 'images i', 'id > ?', [99999]));
    }

    public function testFindRowsByClauseReturnsFullRows(): void
    {
        $rows = $this->repo->findRowsByClause('tags', 'name = ?', ['nature']);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame('nature', $rows[0]['name']);
    }

    public function testFindRowsByClauseReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findRowsByClause('tags', 'name = ?', ['no-such-tag']));
    }

    public function testFindRowsByClauseStripsTsvPrefixedColumns(): void
    {
        // The real `tsv_search`/`tsv_author` columns this filter exists for
        // are Postgres-only generated columns, so on any other driver the
        // stripping code has nothing to act on and goes unverified. A
        // derived table synthesizes a tsv_-prefixed column on every driver,
        // exercising the real filter rather than a stand-in for it -- the
        // method takes $fromSql as a raw fragment precisely so callers can
        // name whatever relation they like.
        $rows = $this->repo->findRowsByClause(
            '(SELECT id, name, 1 AS tsv_fake FROM tags WHERE id = ?) t',
            '',
            [1]
        );

        self::assertCount(1, $rows);
        self::assertArrayHasKey('name', $rows[0]);
        self::assertArrayNotHasKey('tsv_fake', $rows[0]);
    }

    public function testQuoteEscapesAValueForSafeInlineEmbedding(): void
    {
        // [SEC-18] real driver escaping (Connection::quote()), not
        // addslashes() -- the quoted value must round-trip safely when
        // embedded directly into a WHERE fragment (not bound via ?).
        $quoted = $this->repo->quote("o'brien\" --");

        $row = $this->conn->executeQuery("SELECT {$quoted} AS val")
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame("o'brien\" --", $row['val']);
    }

    public function testCountSavedSearchByUuidReturnsZeroForUnknownUuid(): void
    {
        self::assertSame(0, $this->repo->countSavedSearchByUuid('no-such-uuid'));
    }

    public function testCountSavedSearchByUuidReturnsOneAfterInsert(): void
    {
        $this->repo->insertSavedSearch([
            'q' => 'travel',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-klmnopqrst', null);

        self::assertSame(1, $this->repo->countSavedSearchByUuid('psk-20260712-klmnopqrst'));
    }

    public function testInsertSavedSearchReturnsTheNewAutoincrementId(): void
    {
        $id = $this->repo->insertSavedSearch([
            'q' => 'family',
        ], '2026-07-12 00:00:00', null, 'psk-20260712-uvwxyzabcd', null);

        self::assertGreaterThan(0, $id);

        $row = $this->repo->findSavedSearchById($id);
        self::assertNotNull($row);
        self::assertNull($row->createdBy);
        self::assertNull($row->forkedFrom);
    }

    public function testInsertSavedSearchStoresForkedFrom(): void
    {
        $parentId = $this->repo->insertSavedSearch([
            'q' => 'parent',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-parentuuid', null);
        $childId = $this->repo->insertSavedSearch([
            'q' => 'child',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-childuuidx', $parentId);

        $row = $this->repo->findSavedSearchById($childId);
        self::assertNotNull($row);
        self::assertSame($parentId, $row->forkedFrom);
    }

    public function testFindSavedSearchRulesByIdsReturnsDecodedRulesKeyedById(): void
    {
        $firstId = $this->repo->insertSavedSearch([
            'q' => 'nature',
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook01', null);
        $secondId = $this->repo->insertSavedSearch([
            'q' => 'travel',
            'fields' => [
                'allwords' => [
                    'words' => ['travel'],
                ],
            ],
        ], '2026-07-12 00:00:00', 1, 'psk-20260712-bulklook02', null);

        $rules = $this->repo->findSavedSearchRulesByIds([$firstId, $secondId]);

        self::assertSame([
            'q' => 'nature',
        ], $rules[$firstId]);
        self::assertSame([
            'q' => 'travel',
            'fields' => [
                'allwords' => [
                    'words' => ['travel'],
                ],
            ],
        ], $rules[$secondId]);
    }

    public function testFindSavedSearchRulesByIdsReturnsEmptyArrayForAnEmptyIdList(): void
    {
        self::assertSame([], $this->repo->findSavedSearchRulesByIds([]));
    }

    public function testFindSavedSearchRulesByIdsOmitsIdsWithNoMatchingRow(): void
    {
        self::assertSame([], $this->repo->findSavedSearchRulesByIds([999999]));
    }

    public function testFindSavedSearchRulesByIdsDecodesANullRulesColumnToNull(): void
    {
        // insertSavedSearch() always writes a real array via persist()
        // (never a literal SQL NULL) -- the only way to exercise the
        // `rules` column's real NULLable-JSON shape (schema: `rules json
        // DEFAULT NULL`) is a raw insert bypassing that method.
        $this->conn->executeStatement(
            'INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES (NULL, ?, ?, ?, NULL)',
            ['2026-07-12 00:00:00', 1, 'psk-20260712-nullrules1']
        );
        $id = (int) $this->conn->lastInsertId();

        $rules = $this->repo->findSavedSearchRulesByIds([$id]);

        self::assertNull($rules[$id]);
    }

    public function testGetDbVersionReturnsANonEmptyVersionString(): void
    {
        $version = $this->repo->getDbVersion();

        self::assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    /**
     * `SearchFilterRenderer`'s own filter-sidebar blocks are the only
     * real callers of the 4 typed DQL methods below.
     */
    public function testCountImagesGroupedByReturnsCountsOrderedDesc(): void
    {
        $this->conn->executeStatement("UPDATE images SET author = 'Ansel Adams' WHERE id IN (1, 2)");
        $this->conn->executeStatement("UPDATE images SET author = 'Dorothea Lange' WHERE id = 3");

        try {
            $rows = $this->repo->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL'), true);

            self::assertSame([
                [
                    'author' => 'Ansel Adams',
                    'counter' => 2,
                ],
                [
                    'author' => 'Dorothea Lange',
                    'counter' => 1,
                ],
            ], $rows);
        } finally {
            $this->conn->executeStatement('UPDATE images SET author = NULL WHERE id IN (1, 2, 3)');
        }
    }

    public function testCountImagesGroupedByReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL')));
    }

    public function testFindDistinctImageRowsReturnsTheRequestedExtraColumns(): void
    {
        $rows = $this->repo->findDistinctImageRows(
            ['i.ratingScore AS rating_score'],
            new SqlCondition('i.id = :id', [
                'id' => 1,
            ]),
        );

        self::assertSame([[
            'id' => 1,
            'rating_score' => 4.5,
        ]], $rows);
    }

    public function testFindDistinctImageRowsReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findDistinctImageRows(['i.ratingScore AS rating_score'], new SqlCondition('i.id = :id', [
            'id' => 99999,
        ])));
    }

    public function testFindDistinctImageColumnValuesReturnsGroupedOrderedValues(): void
    {
        // every fixture image shares height 150 -- collapses to one row via
        // this method's own GROUP BY.
        $values = $this->repo->findDistinctImageColumnValues('i.height', new SqlCondition(''));

        self::assertSame(['150'], $values);
    }

    public function testFindCategoryIdsAndUppercatsReturnsMatchingRows(): void
    {
        $rows = $this->repo->findCategoryIdsAndUppercats(
            new SqlCondition('c.id IN (:ids)', [
                'ids' => [1, 2],
            ], [
                'ids' => ArrayParameterType::INTEGER,
            ]),
        );

        usort($rows, static fn (CategoryIdUppercats $a, CategoryIdUppercats $b): int => $a->id->value <=> $b->id->value);

        self::assertEquals([
            new CategoryIdUppercats(CategoryId::from(1), '1'),
            new CategoryIdUppercats(CategoryId::from(2), '1,2'),
        ], $rows);
    }

    public function testFindCategoryIdsAndUppercatsReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findCategoryIdsAndUppercats(new SqlCondition('c.id = :id', [
            'id' => 99999,
        ])));
    }
}
