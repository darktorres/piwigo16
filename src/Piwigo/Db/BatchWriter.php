<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

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
     * Phase 4 Item 16: routes through the real DBAL platform's own
     * identifier-quoting -- `AbstractMySQLPlatform::quoteSingleIdentifier()`
     * escapes an embedded backtick character
     * (`` '`' . str_replace('`', '``', $str) . '`' ``), which the
     * hand-rolled `SqlDialect::protectColumnName()` this replaced never
     * did. Not a live vulnerability either way (every real caller here
     * passes a fixed, code-controlled column/table name), but a real
     * correctness gap closed for free by using the framework's own,
     * already-correct, per-platform implementation instead.
     */
    private function protectColumnName(string $name): string
    {
        return $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier($name);
    }

    /**
     * `INSERT IGNORE` has no Postgres equivalent -- `ON CONFLICT DO
     * NOTHING` is the real per-platform match, appended after `VALUES
     * (...)` rather than as a keyword before `INTO` like MySQL's `IGNORE`.
     * No conflict target needed: a bare `ON CONFLICT DO NOTHING`
     * downgrades any collision to a no-op, matching `INSERT IGNORE`'s own
     * "duplicate key becomes a silent skip" semantic exactly for every
     * real caller here (all about duplicate-key avoidance on a genuine
     * unique/primary key, never a broader error-suppression need).
     */
    private function buildInsertSql(string $protectedTable, string $columnsSql, string $placeholdersSql, bool $ignore): string
    {
        if (! $ignore) {
            return "INSERT INTO {$protectedTable} ({$columnsSql}) VALUES ({$placeholdersSql})";
        }

        if ($this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return "INSERT INTO {$protectedTable} ({$columnsSql}) VALUES ({$placeholdersSql}) ON CONFLICT DO NOTHING";
        }

        return "INSERT IGNORE INTO {$protectedTable} ({$columnsSql}) VALUES ({$placeholdersSql})";
    }

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
        // not a raw-string-splice defect -- buildInsertSql()'s own
        // $protectedTable/$columnsSql/$placeholdersSql inputs below are
        // all structural (protected identifiers + bound-parameter
        // placeholder syntax), every real value flows through
        // $params/executeStatement()'s own binding. Stays on raw
        // Connection rather than QueryBuilder::insert() deliberately:
        // DBAL's QueryBuilder has no INSERT IGNORE/ON CONFLICT support
        // (confirmed against its own source), and that behavior is real,
        // load-bearing for callers of this method (see
        // Permission\PermissionRepository::massInsertUserAccess()'s own
        // docblock on the same constraint).
        $columns = array_map($this->protectColumnName(...), array_keys($data));
        $placeholders = array_map(static fn (string $key): string => ':' . $key, array_keys($data));

        $protectedTable = $this->protectColumnName($table);
        $columnsSql = implode(',', $columns);
        $placeholdersSql = implode(',', $placeholders);
        $query = $this->buildInsertSql($protectedTable, $columnsSql, $placeholdersSql, $options['ignore'] ?? false);

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
        $ignore = $options['ignore'] ?? false;
        $columns = array_map($this->protectColumnName(...), $dbfields);
        $protectedTable = $this->protectColumnName($table);
        $columnsSql = implode(',', $columns);

        // Phase 5 Item 23: Connection::transactional() replaces the manual
        // beginTransaction()/commit()/catch/rollBack()-and-rethrow
        // boilerplate -- same all-or-nothing behavior, less code, and
        // removes any chance of a future edit forgetting the
        // rollBack()-and-rethrow half of the pattern.
        $this->conn->transactional(function (Connection $conn) use ($datas, $dbfields, $ignore, $protectedTable, $columnsSql): void {
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
                $query = $this->buildInsertSql($protectedTable, $columnsSql, $placeholdersSql, $ignore);

                $conn->executeStatement($query, $params);
            }
        });
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

        // Phase 5 Item 23: see massInsert()'s own comment -- same
        // Connection::transactional() replacement.
        $this->conn->transactional(function () use ($table, $dbfields, $datas, $flags): void {
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
        });
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
            ->update($this->protectColumnName($table));

        $hasSetPart = false;
        $i = 0;
        foreach ($data as $key => $value) {
            $value = SqlDialect::booleanToInt($value);
            $isEmpty = ! isset($value) || $value === '' || ! is_scalar($value);
            if ($isEmpty) {
                if ((bool) ($flags & self::SKIP_EMPTY)) {
                    continue;
                }
                $qb->set($this->protectColumnName($key), 'NULL');
                $hasSetPart = true;
                continue;
            }

            $placeholder = 'set' . $i++;
            $qb->set($this->protectColumnName($key), ':' . $placeholder);
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
                $qb->andWhere($this->protectColumnName($key) . ' = :' . $placeholder);
                $qb->setParameter($placeholder, $value);
            } else {
                $qb->andWhere($this->protectColumnName($key) . ' IS NULL');
            }
        }

        $qb->executeStatement();
    }
}
