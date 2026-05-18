<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * Schema-level maintenance operations on the Piwigo MySQL database
 * (SHOW TABLES, REPAIR, OPTIMIZE, ALTER TABLE … ORDER BY primary-key).
 *
 * Unlike a domain Repository this class owns DDL/maintenance commands
 * rather than CRUD on a specific table; it still extends
 * {@see AbstractRepository} to share the Connection + table-prefix
 * plumbing.
 */
final class DbMaintenanceRepository extends AbstractRepository
{
    /**
     * Return all Piwigo-prefixed table names (SHOW TABLES LIKE 'piwigo_%').
     *
     * @return list<string>
     */
    public function findAllPiwigoTableNames(): array
    {
        $rows = $this->conn->executeQuery(
            'SHOW TABLES LIKE ?',
            [$this->tablePrefix . '%'],
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $rows);
    }

    /** REPAIR TABLE for every supplied table. */
    /** @param list<string> $tableNames */
    public function repairTables(array $tableNames): void
    {
        if ($tableNames === []) {
            return;
        }
        $this->conn->executeStatement('REPAIR TABLE ' . implode(', ', $tableNames));
    }

    /** OPTIMIZE TABLE for every supplied table. */
    /** @param list<string> $tableNames */
    public function optimizeTables(array $tableNames): void
    {
        if ($tableNames === []) {
            return;
        }
        $this->conn->executeStatement('OPTIMIZE TABLE ' . implode(', ', $tableNames));
    }

    /**
     * Return primary-key column names for $tableName (DESC introspection,
     * filtered to rows where Key='PRI').
     *
     * @return list<string>
     */
    public function findPrimaryKeyColumns(string $tableName): array
    {
        $cols = [];
        foreach ($this->conn->executeQuery('DESC ' . $tableName)->fetchAllAssociative() as $row) {
            if (($row['Key'] ?? null) === 'PRI') {
                $cols[] = is_string($row['Field'] ?? null) ? $row['Field'] : '';
            }
        }
        return $cols;
    }

    /** ALTER TABLE $tableName ORDER BY $primaryKeys. */
    /** @param list<string> $primaryKeys */
    public function reorderTableByPrimaryKeys(string $tableName, array $primaryKeys): void
    {
        if ($primaryKeys === []) {
            return;
        }
        $this->conn->executeStatement('ALTER TABLE ' . $tableName . ' ORDER BY ' . implode(', ', $primaryKeys));
    }

}
