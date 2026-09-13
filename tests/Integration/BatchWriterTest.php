<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware;
use Override;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\StatementCountingLogger;
use Throwable;

/**
 * BatchWriter's happy paths (singleInsert()/massInsert()/singleUpdate()/
 * the plain SET-to-NULL branch of updateRow()) are already fully exercised
 * by its ~20 real call sites across Category/Image/Admin/Controller/
 * Mail. This file closes the specific branches none of those happen to
 * hit: singleInsert()'s empty-$data guard, the rollback+rethrow path in
 * both massInsert() and massUpdate() (a real UNIQUE constraint violation
 * forced mid-batch, not a mock), updateRow()'s SKIP_EMPTY-skips-the-field
 * branch and its all-fields-skipped early return, and the WHERE ... IS
 * NULL branch built from a non-scalar/unset $where value.
 *
 * Uses its own disposable scratch table (dropped/recreated per test)
 * rather than any real Piwigo table -- BatchWriter is generic over
 * table/column names, so a real table would only add unrelated fixture
 * coupling.
 */
final class BatchWriterTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private const string TABLE = 'batchwriter_test_scratch';

    private Connection $conn;

    private BatchWriter $writer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // This class runs unwrapped, real-commit tests against the
        // shared fixture DB -- marks the shared trust flag dirty so any
        // DbTransactionTestOverride-wrapped class running after this one
        // knows it can't skip its own reimport. See
        // IntegrationTestCase::$sharedFixtureKnownPristine's own docblock.
        IntegrationTestCase::markSharedFixtureDirty();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->conn = DbConnection::build();
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        // Real bug found live -- `UNIQUE KEY
        // uniq_name (...)` is MySQL's own inline-table-constraint
        // shorthand, and `ENGINE=InnoDB` doesn't exist as a concept on
        // Postgres at all ("syntax error at or near 'KEY'"). The
        // standard `CONSTRAINT ... UNIQUE (...)` form is real SQL
        // grammar both platforms accept identically; the engine clause
        // is dropped for Postgres (this is a disposable scratch table,
        // and MySQL's own default engine is already InnoDB).
        $engineSuffix = $this->dbDriver === 'pgsql' ? '' : ' ENGINE=InnoDB';
        $this->conn->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT NOT NULL PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . 'note VARCHAR(50) NULL, '
            . 'CONSTRAINT uniq_name UNIQUE (name)'
            . ')' . $engineSuffix
        );
        $this->writer = new BatchWriter($this->conn);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    /**
     * @return list<array{id: int, name: string, note: string|null}>
     */
    private function fetchAllRows(): array
    {
        /** @var list<array{id: int, name: string, note: string|null}> $rows */
        $rows = $this->conn->fetchAllAssociative('SELECT id, name, note FROM ' . self::TABLE . ' ORDER BY id');

        return $rows;
    }

    public function testSingleInsertIsANoOpForEmptyData(): void
    {
        $this->writer->singleInsert(self::TABLE, []);

        self::assertSame([], $this->fetchAllRows());
    }

    public function testSingleUpdateIsANoOpForEmptyData(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (20, 'unchanged', 'stays-put')"
        );

        // updateRow()'s own `if ($data === []) { return; }` guard, reached
        // directly via singleUpdate() -- massUpdate()'s own per-row
        // $updateData is only ever built from $dbfields['update'], so an
        // empty 'update' list would hit this same guard indirectly, but
        // singleUpdate() is the direct, minimal way in.
        $this->writer->singleUpdate(self::TABLE, [], [
            'id' => 20,
        ]);

        self::assertSame([
            [
                'id' => 20,
                'name' => 'unchanged',
                'note' => 'stays-put',
            ],
        ], $this->fetchAllRows());
    }

    public function testMassInsertRollsBackTheWholeBatchAndRethrowsOnAMidBatchUniqueViolation(): void
    {
        $thrown = null;

        try {
            $this->writer->massInsert(self::TABLE, ['id', 'name'], [
                [
                    'id' => 10,
                    'name' => 'first-name',
                ],
                [
                    'id' => 11,
                    'name' => 'first-name',
                ], // duplicate `name` -> unique violation
            ]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        // The first row's own insert succeeded before the second one failed
        // -- an empty table afterward proves the transaction really rolled
        // both back, not just the failing statement.
        self::assertSame([], $this->fetchAllRows());
    }

    public function testMassUpdateRollsBackTheWholeBatchAndRethrowsOnAMidBatchUniqueViolation(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (1, 'alpha', NULL), (2, 'beta', NULL)"
        );

        $thrown = null;

        try {
            $this->writer->massUpdate(self::TABLE, [
                'primary' => ['id'],
                'update' => ['name'],
            ], [
                [
                    'id' => 1,
                    'name' => 'zeta',
                ],
                [
                    'id' => 2,
                    'name' => 'zeta',
                ], // duplicate `name` -> unique violation
            ]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        self::assertSame([
            [
                'id' => 1,
                'name' => 'alpha',
                'note' => null,
            ],
            [
                'id' => 2,
                'name' => 'beta',
                'note' => null,
            ],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateWithSkipEmptySkipsAnEmptyFieldEntirelyInsteadOfNullingIt(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (5, 'kappa', 'original-note')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            [
                'name' => '',
                'note' => 'updated-note',
            ],
            [
                'id' => 5,
            ],
            BatchWriter::SKIP_EMPTY
        );

        self::assertSame([
            [
                'id' => 5,
                'name' => 'kappa',
                'note' => 'updated-note',
            ],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateWithSkipEmptyAndOnlyOneAllEmptyFieldIssuesNoQueryAtAll(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (6, 'lambda', 'stays-put')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            [
                'note' => '',
            ],
            [
                'id' => 6,
            ],
            BatchWriter::SKIP_EMPTY
        );

        self::assertSame([
            [
                'id' => 6,
                'name' => 'lambda',
                'note' => 'stays-put',
            ],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateBuildsAnIsNullWhereClauseForANullWhereValueAndOnlyMatchesThatRow(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (7, 'row-seven', NULL), (8, 'row-eight', 'has-note')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            [
                'name' => 'updated-seven',
            ],
            [
                'note' => null,
            ]
        );

        self::assertSame([
            [
                'id' => 7,
                'name' => 'updated-seven',
                'note' => null,
            ],
            [
                'id' => 8,
                'name' => 'row-eight',
                'note' => 'has-note',
            ],
        ], $this->fetchAllRows());
    }

    /**
     * Asserts a column/table name containing a literal backtick
     * character is correctly escaped, not silently broken -- the one
     * real behavioral difference between the old hand-rolled
     * protectColumnName() (`` '`' . $name . '`' ``, no escaping of an
     * embedded backtick) and AbstractMySQLPlatform::
     * quoteSingleIdentifier() (`` '`' . str_replace('`', '``', $name) .
     * '`' ``). Not a live vulnerability (every real caller passes a
     * fixed, code-controlled name), but the framework method's own real
     * correctness gain over the code it replaced, worth proving directly
     * rather than trusting it because the happy-path tests above pass.
     *
     * Only exercises `updateRow()` (via singleUpdate()'s SET and WHERE
     * sides), not singleInsert()/massInsert() -- those 2 derive their
     * bound-*parameter* name directly from the raw column key (`':' .
     * $key`), a real, separate, narrower limitation (any column name
     * that isn't itself a valid bound-parameter token -- not just a
     * backtick -- breaks there), which is about placeholder-name
     * generation, not identifier *quoting* in the SQL text.
     * updateRow()'s own SET/WHERE placeholders are counter-based
     * (`'set' . $i++` / `'where' . $j++`), never derived from the column
     * name, so they're unaffected either way -- the real, common case
     * every actual BatchWriter caller today exercises.
     *
     * Uses its own disposable scratch table/column (both named with an
     * embedded quote-identifier-delimiter character) rather than
     * self::TABLE, since creating them needs the doubled-delimiter
     * DDL-quoting convention itself; seeded via a raw INSERT rather than
     * BatchWriter::singleInsert(), to keep this test isolated from the
     * unrelated limitation above.
     *
     * The fixture DDL routes through the same real
     * `Connection::getDatabasePlatform()->quoteSingleIdentifier()` call
     * BatchWriter::protectColumnName() itself uses, rather than
     * hand-rolling the quoting convention a second time here -- this is
     * exactly the framework method under test, so using it to build the
     * fixture too is the honest, self-consistent way to prove it (not a
     * weaker test: the assertions below still independently verify
     * BatchWriter's real behavior against these names, this only changes
     * how the *fixture* is quoted, matching whichever character each
     * real platform actually uses for identifier delimiting -- MySQL's
     * backtick or Postgres's double-quote; MySQL's own `ENGINE=InnoDB`
     * has no Postgres equivalent either, so the DDL itself is built
     * per-platform too).
     */
    public function testSingleUpdateCorrectlyEscapesATableAndColumnNameContainingALiteralBacktickOnBothTheSetAndWhereSides(): void
    {
        $platform = $this->conn->getDatabasePlatform();
        $delimiter = $this->dbDriver === 'pgsql' ? '"' : '`';
        $table = 'batchwriter_test_scratch_back' . $delimiter . 'tick';
        $column = 'na' . $delimiter . 'me';
        $quotedTable = $platform->quoteSingleIdentifier($table);
        $quotedColumn = $platform->quoteSingleIdentifier($column);

        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $quotedTable);
        $engineSuffix = $this->dbDriver === 'pgsql' ? '' : ' ENGINE=InnoDB';
        $this->conn->executeStatement(
            'CREATE TABLE ' . $quotedTable . ' ('
            . 'id INT NOT NULL PRIMARY KEY, '
            . $quotedColumn . ' VARCHAR(50) NULL'
            . ')' . $engineSuffix
        );

        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . $quotedTable . ' (id, ' . $quotedColumn . ") VALUES (1, 'target'), (2, 'other')"
            );

            // WHERE-side: match via the backtick column, change a plain one.
            $this->writer->singleUpdate($table, [
                'id' => 99,
            ], [
                $column => 'target',
            ]);

            self::assertSame(
                [
                    1 => false,
                    99 => true,
                    2 => true,
                ],
                [
                    1 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 1'),
                    99 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 99'),
                    2 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 2'),
                ],
                'only the row matching the backtick-named WHERE column must have been updated'
            );

            // SET-side: change the backtick column's own value.
            $this->writer->singleUpdate($table, [
                $column => 'updated',
            ], [
                'id' => 99,
            ]);

            self::assertSame(
                'updated',
                $this->conn->fetchOne('SELECT ' . $quotedColumn . ' FROM ' . $quotedTable . ' WHERE id = 99')
            );
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $quotedTable);
        }
    }

    /**
     * `massInsert()`/`massUpdate()` now issue one real multi-row statement
     * per chunk (500 rows) instead of one per row -- the tests below cover
     * exactly the behavior that only exists because of that change: chunk
     * boundaries, the whole-call (every chunk) transaction still rolling
     * back an already-executed earlier chunk, and the searched-`CASE`
     * per-row-per-column `SKIP_EMPTY`/composite-key/`IS NULL` semantics
     * staying correct when multiple rows share one batched statement.
     */
    public function testMassInsertSpansMultipleChunksAndAUniqueViolationInALaterChunkRollsBackAnEarlierChunkToo(): void
    {
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => 'chunked-name' . $i,
            ];
        }
        // Row 501 lands in the second chunk (chunk size 500) and collides
        // with row 1's `name`, already in chunk 1 -- the chunk that will
        // have already executed successfully by the time this one fails.
        $rows[] = [
            'id' => 501,
            'name' => 'chunked-name1',
        ];

        $thrown = null;

        try {
            $this->writer->massInsert(self::TABLE, ['id', 'name'], $rows);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        // Every row gone, not just row 501 -- proves the whole-call
        // transaction rolled back chunk 1's own already-executed INSERT
        // too, not just chunk 2's failing statement.
        self::assertSame([], $this->fetchAllRows());
    }

    public function testMassUpdateSpansMultipleChunksAndAUniqueViolationInALaterChunkRollsBackAnEarlierChunkToo(): void
    {
        $seedValues = [];
        for ($i = 1; $i <= 501; $i++) {
            $seedValues[] = "({$i}, 'orig-name{$i}', NULL)";
        }
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (id, name, note) VALUES ' . implode(',', $seedValues)
        );

        $updates = [];
        for ($i = 1; $i <= 500; $i++) {
            $updates[] = [
                'id' => $i,
                'name' => 'new-name' . $i,
            ];
        }
        // Row 501 lands in the second chunk and collides with row 1's NEW
        // name from chunk 1, which will already have been executed (not
        // yet committed -- still inside the whole-call transaction) by
        // the time chunk 2 fails.
        $updates[] = [
            'id' => 501,
            'name' => 'new-name1',
        ];

        $thrown = null;

        try {
            $this->writer->massUpdate(self::TABLE, [
                'primary' => ['id'],
                'update' => ['name'],
            ], $updates);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        // Spot-check rows from BOTH chunks still hold their original
        // names -- proves chunk 1's own already-executed UPDATE got
        // rolled back too, not just chunk 2's failing statement.
        self::assertSame('orig-name1', $this->conn->fetchOne('SELECT name FROM ' . self::TABLE . ' WHERE id = 1'));
        self::assertSame('orig-name500', $this->conn->fetchOne('SELECT name FROM ' . self::TABLE . ' WHERE id = 500'));
        self::assertSame('orig-name501', $this->conn->fetchOne('SELECT name FROM ' . self::TABLE . ' WHERE id = 501'));
    }

    /**
     * The one genuinely new correctness risk in the searched-`CASE`
     * design: a `SKIP_EMPTY` column's `WHEN` list only contains branches
     * for rows that actually set it, so a row skipping it must fall
     * through to `ELSE <column>` (its own current value) even though
     * *other* rows in the very same batched statement do set that column.
     */
    public function testMassUpdateWithSkipEmptyAppliesPerRowPerColumnWithinTheSameBatchedStatement(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (40, 'orig-name-40', 'orig-note-40'), (41, 'orig-name-41', 'orig-note-41')"
        );

        $this->writer->massUpdate(self::TABLE, [
            'primary' => ['id'],
            'update' => ['name', 'note'],
        ], [
            [
                'id' => 40,
                'name' => '', // skipped for row 40
                'note' => 'updated-note-40',
            ],
            [
                'id' => 41,
                'name' => 'updated-name-41',
                'note' => '', // skipped for row 41
            ],
        ], BatchWriter::SKIP_EMPTY);

        self::assertSame([
            [
                'id' => 40,
                'name' => 'orig-name-40',
                'note' => 'updated-note-40',
            ],
            [
                'id' => 41,
                'name' => 'updated-name-41',
                'note' => 'orig-note-41',
            ],
        ], $this->fetchAllRows());
    }

    /**
     * `image_category`'s own real shape (`ImageRepository::
     * massUpdateImageCategoryRanks()`) -- the one real composite-`primary`
     * caller, so the searched-`CASE`/`AND`/`OR` predicate needs proving
     * against a genuine 2-column key, not just the single-column shape
     * every other real call site uses.
     */
    public function testMassUpdateSupportsACompositePrimaryKeyAcrossMultipleRowsInOneBatch(): void
    {
        $table = 'batchwriter_test_composite';
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $table);
        $this->conn->executeStatement(
            'CREATE TABLE ' . $table . ' (a_id INT NOT NULL, b_id INT NOT NULL, rnk INT NOT NULL, PRIMARY KEY (a_id, b_id))'
        );

        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . $table . ' (a_id, b_id, rnk) VALUES (1,1,0), (1,2,0), (2,1,0)'
            );

            $this->writer->massUpdate($table, [
                'primary' => ['a_id', 'b_id'],
                'update' => ['rnk'],
            ], [
                [
                    'a_id' => 1,
                    'b_id' => 1,
                    'rnk' => 10,
                ],
                [
                    'a_id' => 1,
                    'b_id' => 2,
                    'rnk' => 20,
                ],
                [
                    'a_id' => 2,
                    'b_id' => 1,
                    'rnk' => 30,
                ],
            ]);

            $rankOneOne = $this->conn->fetchOne('SELECT rnk FROM ' . $table . ' WHERE a_id=1 AND b_id=1');
            $rankOneTwo = $this->conn->fetchOne('SELECT rnk FROM ' . $table . ' WHERE a_id=1 AND b_id=2');
            $rankTwoOne = $this->conn->fetchOne('SELECT rnk FROM ' . $table . ' WHERE a_id=2 AND b_id=1');
            self::assertIsNumeric($rankOneOne);
            self::assertIsNumeric($rankOneTwo);
            self::assertIsNumeric($rankTwoOne);
            self::assertSame(10, (int) $rankOneOne);
            self::assertSame(20, (int) $rankOneTwo);
            self::assertSame(30, (int) $rankTwoOne);
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * Batched sibling of {@see testSingleUpdateBuildsAnIsNullWhereClauseForANullWhereValueAndOnlyMatchesThatRow()} --
     * a null-valued key row and a real-valued key row in the very same
     * batched statement, proving the `IS NULL` branch and the `= :param`
     * branch coexist correctly across rows sharing one `CASE`/`WHERE`.
     */
    public function testMassUpdateBuildsAnIsNullWhereClauseInBatchedFormAlongsideARealValuedRow(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (50, 'row-fifty', NULL), (51, 'row-fifty-one', 'has-note')"
        );

        // 'note' (nullable, not the real PRIMARY KEY) stands in as the
        // matching column here -- same technique the single-row sibling
        // test above already uses, since massUpdate()'s own 'primary'
        // list is whichever columns identify a row, not necessarily the
        // table's real PK.
        $this->writer->massUpdate(self::TABLE, [
            'primary' => ['note'],
            'update' => ['name'],
        ], [
            [
                'note' => null,
                'name' => 'updated-fifty',
            ],
            [
                'note' => 'has-note',
                'name' => 'updated-fifty-one',
            ],
        ]);

        self::assertSame([
            [
                'id' => 50,
                'name' => 'updated-fifty',
                'note' => null,
            ],
            [
                'id' => 51,
                'name' => 'updated-fifty-one',
                'note' => 'has-note',
            ],
        ], $this->fetchAllRows());
    }

    public function testMassInsertWithIgnoreSkipsOnlyDuplicateRowsWithinTheSameChunk(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (60, 'existing-name', NULL)"
        );

        $added = $this->writer->massInsert(self::TABLE, ['id', 'name'], [
            [
                'id' => 61,
                'name' => 'existing-name', // duplicate -> silently skipped
            ],
            [
                'id' => 62,
                'name' => 'brand-new-name',
            ],
        ], [
            'ignore' => true,
        ]);

        $countSixtyOne = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id = 61');
        $countSixtyTwo = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id = 62');
        self::assertIsNumeric($countSixtyOne);
        self::assertIsNumeric($countSixtyTwo);
        self::assertSame(0, (int) $countSixtyOne);
        self::assertSame(1, (int) $countSixtyTwo);
        self::assertSame('existing-name', $this->conn->fetchOne('SELECT name FROM ' . self::TABLE . ' WHERE id = 60'));
        // The core new contract Caddie\CaddieRepository::addElements()/
        // Group\GroupRepository::addMembers() depend on: the returned
        // count is how many rows the DB actually inserted, excluding the
        // one skipped as a duplicate -- not the 2 rows attempted.
        self::assertSame(1, $added);
    }

    /**
     * `massInsert()`'s return value (added for
     * {@see \Piwigo\Caddie\CaddieRepository::addElements()}, which needs
     * "how many rows were actually newly added" without a separate
     * existence check) must reflect real inserted rows across every
     * chunk, not just the last one.
     */
    public function testMassInsertReturnsTheTotalRowCountActuallyInsertedAcrossEveryChunk(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => 'count-name' . $i,
            ];
        }

        $added = $this->writer->massInsert(self::TABLE, ['id', 'name'], $rows);

        self::assertSame(1200, $added);
    }

    /**
     * Regression proof for the batching itself, not just its correctness:
     * a future accidental revert of `massInsert()`/`massUpdate()` to a
     * per-row loop would still pass every correctness test above (it's
     * still functionally correct, just slow) -- this fails immediately
     * instead, by counting real round trips via a
     * `Doctrine\DBAL\Logging\Middleware`-wrapped connection built from the
     * same `DbConnection::params()` this class's own `$this->conn`
     * already uses.
     */
    public function testMassInsertAndMassUpdateIssueOneStatementPerChunkNotOnePerRow(): void
    {
        $logger = new StatementCountingLogger();
        $config = new Configuration();
        $config->setMiddlewares([new Middleware($logger)]);
        $loggedConn = DriverManager::getConnection(DbConnection::params(), $config);
        $loggedWriter = new BatchWriter($loggedConn);

        try {
            $rows = [];
            for ($i = 1; $i <= 1200; $i++) {
                $rows[] = [
                    'id' => $i,
                    'name' => 'rt-name' . $i,
                ];
            }
            $loggedWriter->massInsert(self::TABLE, ['id', 'name'], $rows);
            // ceil(1200 / 500) = 3 real statements, not 1200.
            self::assertSame(3, $logger->executedStatementCount);

            $logger->executedStatementCount = 0;
            $updates = [];
            foreach ($rows as $row) {
                $updates[] = [
                    'id' => $row['id'],
                    'name' => 'rt-updated-' . $row['id'],
                ];
            }
            $loggedWriter->massUpdate(self::TABLE, [
                'primary' => ['id'],
                'update' => ['name'],
            ], $updates);
            self::assertSame(3, $logger->executedStatementCount);
        } finally {
            $loggedConn->close();
        }
    }
}
