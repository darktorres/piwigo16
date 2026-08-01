<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

/**
 * Parameterized replacement for `MysqliDb::singleInsert()`/`massInserts()`/
 * `singleUpdate()`/`massUpdates()` (Legacy Coupling Retirement: DI+DBAL
 * migration, Phase 1a). Shared rather than duplicated per-repository --
 * these 4 methods back ~20 call sites across Category/Image/Ws/Admin/
 * Controller/Mail, not a single domain's concern.
 *
 * Deliberate simplification vs. the original `massUpdates()`: the legacy
 * version had two strategies -- N individual `UPDATE`s for under 10 rows,
 * or (>=10 rows) a `SHOW FULL COLUMNS` column-type introspection, a
 * `CREATE TABLE` scratch table, a bulk insert into it, one `UPDATE ...
 * JOIN` against it, then `DROP TABLE` -- a real but MySQL-specific,
 * DDL-heavy optimization for large batches. Reimplementing that exact
 * strategy with parameterized DBAL queries (no raw string interpolation)
 * is disproportionate complexity for this pass and doesn't obviously
 * port to the pgsql driver `DbCredentials::current()->driver` already allows. This
 * class always issues one parameterized `UPDATE` per row instead, for
 * every batch size, wrapped in a single transaction (which the original
 * never had at all -- every row was its own unguarded query, so a
 * mid-batch failure could already leave a batch half-applied; the
 * transaction here is a real correctness improvement, not just a
 * simplification). This trades away the temp-table strategy's
 * large-batch performance advantage for large N (many hundreds/
 * thousands of rows in one batch-manager operation) in exchange for
 * much simpler, safer, parameterized code. Revisit with real profiling
 * data if a large-batch `massUpdate()` call site turns out to be a
 * measured bottleneck -- no such measurement exists today.
 */
final readonly class BatchWriter
{
    // Every $data/$where/$datas value below is genuinely arbitrary by
    // design -- a generic column-name => value bag spanning ~20 call sites
    // across Category/Image/Ws/Admin/Controller/Mail, one column can be an
    // int, string, float, bool, or null depending on the target table.
    // Matches Doctrine\DBAL\Connection::executeStatement()'s own `array
    // $params` parameter (not typed any narrower by DBAL itself); every
    // value is is_scalar()-checked at its own use site below.

    public const int SKIP_EMPTY = 1;

    public function __construct(
        private Connection $conn,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array{ignore?: bool} $options
     */
    public function singleInsert(string $table, array $data, array $options = []): void
    {
        if ($data === []) {
            return;
        }

        // SQL-modernization audit (recursive-cuddling-squid.md): verified,
        // not a raw-string-splice defect -- {$ignore}/{$protectedTable}/
        // {$columnsSql}/{$placeholdersSql} below are all structural
        // (protected identifiers + bound-parameter placeholder syntax),
        // every real value flows through $params/executeStatement()'s own
        // binding. Stays on raw Connection rather than QueryBuilder::insert()
        // deliberately: DBAL's QueryBuilder has no INSERT IGNORE support
        // (confirmed against its own source), and IGNORE is a real,
        // load-bearing behavior for callers of this method (see
        // Permission\PermissionRepository::massInsertUserAccess()'s own
        // docblock on the same constraint).
        $ignore = ($options['ignore'] ?? false) ? 'IGNORE' : '';
        $columns = array_map(SqlDialect::protectColumnName(...), array_keys($data));
        $placeholders = array_map(static fn (string $key): string => ':' . $key, array_keys($data));

        $protectedTable = SqlDialect::protectColumnName($table);
        $columnsSql = implode(',', $columns);
        $placeholdersSql = implode(',', $placeholders);
        $query = <<<SQL
            INSERT {$ignore} INTO {$protectedTable} ({$columnsSql}) VALUES ({$placeholdersSql})
            SQL;

        $params = [];
        foreach ($data as $key => $value) {
            // A raw PHP bool binds inconsistently through mysqli/DBAL
            // (confirmed live: false arrived as '' rather than 0, tripping
            // a strict-mode "Incorrect integer value" error on a tinyint
            // column) -- normalize to int first, same convention already
            // used at other real call sites (e.g. SqlDialect::booleanToInt()
            // callers building their own $insert arrays).
            $value = SqlDialect::booleanToInt($value);
            $params[$key] = ($value === '' || $value === null || ! is_scalar($value)) ? null : $value;
        }

        $this->conn->executeStatement($query, $params);
    }

    /**
     * @param string[] $dbfields fields from $datas to insert, in column order
     * @param array<int, array<string, mixed>> $datas
     * @param array{ignore?: bool} $options
     */
    public function massInsert(string $table, array $dbfields, array $datas, array $options = []): void
    {
        if ($datas === []) {
            return;
        }

        // SQL-modernization audit: same verified-safe, stays-raw shape as
        // singleInsert() above -- see its own comment.
        $ignore = ($options['ignore'] ?? false) ? 'IGNORE' : '';
        $columns = array_map(SqlDialect::protectColumnName(...), $dbfields);
        $protectedTable = SqlDialect::protectColumnName($table);
        $columnsSql = implode(',', $columns);

        $this->conn->beginTransaction();
        try {
            foreach ($datas as $insert) {
                $placeholders = [];
                $params = [];
                foreach ($dbfields as $i => $field) {
                    $placeholder = 'p' . $i;
                    $placeholders[] = ':' . $placeholder;
                    $value = SqlDialect::booleanToInt($insert[$field] ?? null);
                    $params[$placeholder] = ($value === '' || $value === null || ! is_scalar($value)) ? null : $value;
                }

                $placeholdersSql = implode(',', $placeholders);
                $query = <<<SQL
                    INSERT {$ignore} INTO {$protectedTable} ({$columnsSql}) VALUES ({$placeholdersSql})
                    SQL;

                $this->conn->executeStatement($query, $params);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function singleUpdate(string $table, array $data, array $where, int $flags = 0): void
    {
        $this->updateRow($table, $data, $where, $flags);
    }

    /**
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<int, array<string, mixed>> $datas
     */
    public function massUpdate(string $table, array $dbfields, array $datas, int $flags = 0): void
    {
        if ($datas === []) {
            return;
        }

        $this->conn->beginTransaction();
        try {
            foreach ($datas as $data) {
                $updateData = [];
                foreach ($dbfields['update'] as $key) {
                    $updateData[$key] = $data[$key] ?? null;
                }

                $where = [];
                foreach ($dbfields['primary'] as $key) {
                    $where[$key] = $data[$key] ?? null;
                }

                $this->updateRow($table, $updateData, $where, $flags);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    private function updateRow(string $table, array $data, array $where, int $flags): void
    {
        if ($data === []) {
            return;
        }

        $qb = $this->conn->createQueryBuilder()
            ->update(SqlDialect::protectColumnName($table));

        $hasSetPart = false;
        $i = 0;
        foreach ($data as $key => $value) {
            $value = SqlDialect::booleanToInt($value);
            $isEmpty = ! isset($value) || $value === '' || ! is_scalar($value);
            if ($isEmpty) {
                if ((bool) ($flags & self::SKIP_EMPTY)) {
                    continue;
                }
                $qb->set(SqlDialect::protectColumnName($key), 'NULL');
                $hasSetPart = true;
                continue;
            }

            $placeholder = 'set' . $i++;
            $qb->set(SqlDialect::protectColumnName($key), ':' . $placeholder);
            $qb->setParameter($placeholder, $value);
            $hasSetPart = true;
        }

        if (! $hasSetPart) {
            return;
        }

        $j = 0;
        foreach ($where as $key => $value) {
            $value = SqlDialect::booleanToInt($value);
            if (isset($value) && is_scalar($value)) {
                $placeholder = 'where' . $j++;
                $qb->andWhere(SqlDialect::protectColumnName($key) . ' = :' . $placeholder);
                $qb->setParameter($placeholder, $value);
            } else {
                $qb->andWhere(SqlDialect::protectColumnName($key) . ' IS NULL');
            }
        }

        $qb->executeStatement();
    }
}
