<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Piwigo\Db\DbConnection;
use Throwable;

/**
 * Proves the `unsigned`-removal migration (`categories.id`/`tags.id`/
 * `groups.id`/`sites.id`, the four columns it retyped to matching signed
 * types on both providers) is behaviorally identical on MySQL and
 * Postgres, not just declared-identically. A matching column type is not
 * proof of matching behavior: a relaxed `sql_mode` would let MySQL
 * silently clamp an out-of-range value to the column's max instead of
 * rejecting the insert, which would still "succeed" and could easily go
 * unnoticed. This doubles as an indirect regression guard for the
 * `DbConnection::SQL_MODE` pin itself.
 *
 * One transaction per table, rolled back in `finally` regardless of
 * outcome -- on Postgres, the first statement's failure alone aborts the
 * transaction (unlike MySQL, which only fails that one statement), so
 * `rollBack()` after the failed insert is required either way, not just
 * tidy.
 */
final class IntegerCeilingParityTest extends IntegrationTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    /**
     * @param non-empty-string $table  unquoted -- `groups` is a reserved
     *   word as of MySQL 8.0.2, so this quotes it per-platform via
     *   Connection::quoteSingleIdentifier() rather than hardcoding
     *   backticks, which Postgres doesn't use.
     * @param non-empty-string $idColumn
     * @param string $extraColumns  e.g. ', galleries_url' -- always starts with ', '
     */
    private function assertCeilingEnforced(
        string $table,
        string $idColumn,
        int $max,
        string $extraColumns = '',
        string $extraValueAtMax = '',
        string $extraValueOverMax = '',
    ): void {
        $quotedTable = $this->conn->quoteSingleIdentifier($table);
        $this->conn->beginTransaction();

        try {
            $this->conn->executeStatement(
                "INSERT INTO {$quotedTable} ({$idColumn}{$extraColumns}) VALUES ({$max}{$extraValueAtMax})"
            );
            // mysqli returns a native int for an integer column's raw
            // fetch, pgsql returns a string -- the point under test is
            // the stored value, not the driver's own PHP-type mapping
            // convention, so narrow before comparing as text.
            $stored = $this->conn->fetchOne("SELECT {$idColumn} FROM {$quotedTable} WHERE {$idColumn} = {$max}");
            if (! is_int($stored) && ! is_string($stored)) {
                self::fail("{$table}.{$idColumn} fetchOne() must return an int or string, got " . get_debug_type($stored));
            }

            self::assertSame((string) $max, (string) $stored, "{$table}.{$idColumn} must accept its max signed value unclamped");

            $rejected = null;

            try {
                $this->conn->executeStatement(
                    'INSERT INTO ' . $quotedTable . ' (' . $idColumn . $extraColumns . ') VALUES (' . ($max + 1) . $extraValueOverMax . ')'
                );
            } catch (Throwable $e) {
                $rejected = $e;
            }

            self::assertInstanceOf(
                DbalException::class,
                $rejected,
                "{$table}.{$idColumn} = max + 1 must be rejected with a real exception, not silently clamped"
            );
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testCategoriesIdEnforcesTheIntegerCeilingIdentically(): void
    {
        $this->assertCeilingEnforced('categories', 'id', 2147483647);
    }

    public function testTagsIdEnforcesTheIntegerCeilingIdentically(): void
    {
        $this->assertCeilingEnforced('tags', 'id', 2147483647);
    }

    public function testGroupsIdEnforcesTheIntegerCeilingIdentically(): void
    {
        $this->assertCeilingEnforced('groups', 'id', 2147483647);
    }

    /**
     * `sites.galleries_url` carries a UNIQUE constraint and a `''`
     * default -- both inserts would otherwise collide on the same empty
     * string within the same transaction, throwing for the wrong reason
     * (the unique constraint, not the integer ceiling this test targets),
     * so each gets its own explicit, distinct value.
     */
    public function testSitesIdEnforcesTheIntegerCeilingIdentically(): void
    {
        $this->assertCeilingEnforced(
            'sites',
            'id',
            32767,
            ', galleries_url',
            ", 'ceiling-test-max'",
            ", 'ceiling-test-over-max'"
        );
    }
}
