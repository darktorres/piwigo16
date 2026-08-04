<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;

/**
 * BatchWriter's happy paths (singleInsert()/massInsert()/singleUpdate()/
 * the plain SET-to-NULL branch of updateRow()) are already fully exercised
 * by its ~20 real call sites across Category/Image/Ws/Admin/Controller/
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

        $this->conn = DbConnection::build();
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->conn->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT NOT NULL PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . 'note VARCHAR(50) NULL, '
            . 'UNIQUE KEY uniq_name (name)'
            . ') ENGINE=InnoDB'
        );
        $this->writer = new BatchWriter($this->conn);
    }

    #[\Override]
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
        $this->writer->singleUpdate(self::TABLE, [], ['id' => 20]);

        self::assertSame([
            ['id' => 20, 'name' => 'unchanged', 'note' => 'stays-put'],
        ], $this->fetchAllRows());
    }

    public function testMassInsertRollsBackTheWholeBatchAndRethrowsOnAMidBatchUniqueViolation(): void
    {
        $thrown = null;

        try {
            $this->writer->massInsert(self::TABLE, ['id', 'name'], [
                ['id' => 10, 'name' => 'first-name'],
                ['id' => 11, 'name' => 'first-name'], // duplicate `name` -> unique violation
            ]);
        } catch (\Throwable $e) {
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
            $this->writer->massUpdate(self::TABLE, ['primary' => ['id'], 'update' => ['name']], [
                ['id' => 1, 'name' => 'zeta'],
                ['id' => 2, 'name' => 'zeta'], // duplicate `name` -> unique violation
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        self::assertSame([
            ['id' => 1, 'name' => 'alpha', 'note' => null],
            ['id' => 2, 'name' => 'beta', 'note' => null],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateWithSkipEmptySkipsAnEmptyFieldEntirelyInsteadOfNullingIt(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (5, 'kappa', 'original-note')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            ['name' => '', 'note' => 'updated-note'],
            ['id' => 5],
            BatchWriter::SKIP_EMPTY
        );

        self::assertSame([
            ['id' => 5, 'name' => 'kappa', 'note' => 'updated-note'],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateWithSkipEmptyAndOnlyOneAllEmptyFieldIssuesNoQueryAtAll(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (6, 'lambda', 'stays-put')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            ['note' => ''],
            ['id' => 6],
            BatchWriter::SKIP_EMPTY
        );

        self::assertSame([
            ['id' => 6, 'name' => 'lambda', 'note' => 'stays-put'],
        ], $this->fetchAllRows());
    }

    public function testSingleUpdateBuildsAnIsNullWhereClauseForANullWhereValueAndOnlyMatchesThatRow(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . self::TABLE . " (id, name, note) VALUES (7, 'row-seven', NULL), (8, 'row-eight', 'has-note')"
        );

        $this->writer->singleUpdate(
            self::TABLE,
            ['name' => 'updated-seven'],
            ['note' => null]
        );

        self::assertSame([
            ['id' => 7, 'name' => 'updated-seven', 'note' => null],
            ['id' => 8, 'name' => 'row-eight', 'note' => 'has-note'],
        ], $this->fetchAllRows());
    }

    /**
     * Item 16's own plan text specifically calls for "a column/table name
     * containing a literal backtick character, asserted as correctly
     * escaped, not silently broken" -- the one real behavioral difference
     * between the old hand-rolled protectColumnName() (`` '`' . $name .
     * '`' ``, no escaping of an embedded backtick) and
     * AbstractMySQLPlatform::quoteSingleIdentifier() (`` '`' .
     * str_replace('`', '``', $name) . '`' ``) this item's own docblock
     * names -- confirmed missing from every existing BatchWriter test
     * file. Not a live vulnerability (every real caller passes a fixed,
     * code-controlled name), but the framework method's own real
     * correctness gain over the code it replaced, worth proving directly
     * rather than trusting it because the happy-path tests above pass.
     *
     * Only exercises `updateRow()` (via singleUpdate()'s SET and WHERE
     * sides), not singleInsert()/massInsert() -- confirmed live that
     * those 2 derive their bound-*parameter* name directly from the raw
     * column key (`':' . $key`), a real, separate, narrower limitation
     * (any column name that isn't itself a valid bound-parameter token --
     * not just a backtick -- breaks there) that predates this item and is
     * out of Item 16's own scope (identifier *quoting* in the SQL text,
     * not placeholder-name generation). updateRow()'s own SET/WHERE
     * placeholders are counter-based (`'set' . $i++` / `'where' . $j++`),
     * never derived from the column name, so they're unaffected either
     * way -- the real, common case every actual BatchWriter caller today
     * exercises.
     *
     * Uses its own disposable scratch table/column (both named with an
     * embedded backtick) rather than self::TABLE, since creating them
     * needs the doubled-backtick DDL-quoting convention itself; seeded via
     * a raw INSERT rather than BatchWriter::singleInsert(), to keep this
     * test isolated from the unrelated limitation above.
     */
    public function testSingleUpdateCorrectlyEscapesATableAndColumnNameContainingALiteralBacktickOnBothTheSetAndWhereSides(): void
    {
        $table = 'batchwriter_test_scratch_back`tick';
        $column = 'na`me';
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $quotedColumn = '`' . str_replace('`', '``', $column) . '`';

        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $quotedTable);
        $this->conn->executeStatement(
            'CREATE TABLE ' . $quotedTable . ' ('
            . 'id INT NOT NULL PRIMARY KEY, '
            . $quotedColumn . ' VARCHAR(50) NULL'
            . ') ENGINE=InnoDB'
        );

        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . $quotedTable . ' (id, ' . $quotedColumn . ") VALUES (1, 'target'), (2, 'other')"
            );

            // WHERE-side: match via the backtick column, change a plain one.
            $this->writer->singleUpdate($table, ['id' => 99], [$column => 'target']);

            self::assertSame(
                [1 => false, 99 => true, 2 => true],
                [
                    1 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 1'),
                    99 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 99'),
                    2 => (bool) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE id = 2'),
                ],
                'only the row matching the backtick-named WHERE column must have been updated'
            );

            // SET-side: change the backtick column's own value.
            $this->writer->singleUpdate($table, [$column => 'updated'], ['id' => 99]);

            self::assertSame(
                'updated',
                $this->conn->fetchOne('SELECT ' . $quotedColumn . ' FROM ' . $quotedTable . ' WHERE id = 99')
            );
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $quotedTable);
        }
    }
}
