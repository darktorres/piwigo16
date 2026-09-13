<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

/**
 * Batched parameterized INSERT/UPDATE helpers, shared rather than
 * duplicated per-repository -- these 4 methods back ~25 call sites across
 * Category/Image/Admin/Controller/Mail/Config, not a single domain's
 * concern.
 *
 * massInsert()/massUpdate() issue real multi-row, chunked SQL (a single
 * multi-row `VALUES (...), (...), ...` per `INSERT` chunk; a single
 * searched-`CASE` `UPDATE` per chunk) rather than one statement per row --
 * profiling a real admin-sync pass at 20,000 images found `massUpdate()`'s
 * former row-by-row loop alone responsible for ~27% of total instrumented
 * time (99s of 367s, 49,600 individual `UPDATE` statements), almost
 * entirely spent in per-statement network/parse/bind/execute overhead
 * rather than genuine work. Each chunk stays under {@see CHUNK_SIZE} rows,
 * safely below PostgreSQL's 65,535-parameter protocol limit and SQLite's
 * 32,766-variable default even for the widest real row shape (~30
 * admin-configurable EXIF/IPTC columns, `Metadata\MetadataService::
 * getSyncMetadataAttributes()`). The whole call (every chunk) still runs
 * inside one `Connection::transactional()` closure, so a failure in a
 * later chunk still rolls back every earlier chunk from the same call --
 * the all-or-nothing guarantee is unchanged, just no longer paid for with
 * a statement per row.
 *
 * singleInsert()/singleUpdate() (and the private per-row updateRow() they
 * share) are deliberately NOT batched -- a lone row has no batching
 * upside, and tests/Unit/Db/BatchWriterTest.php's mutation-testing-derived
 * coverage is pinned to updateRow()'s exact per-row internals.
 */
final readonly class BatchWriter
{
    // Every $data/$where/$datas value below is genuinely arbitrary by
    // design -- a generic column-name => value bag spanning ~25 call sites
    // across Category/Image/Admin/Controller/Mail/Config, one column can be an
    // int, string, float, bool, or null depending on the target table.
    // Matches Doctrine\DBAL\Connection::executeStatement()'s own `array
    // $params` parameter (not typed any narrower by DBAL itself); every
    // value is is_scalar()-checked at its own use site below.

    public const int SKIP_EMPTY = 1;

    /**
     * Max rows per `massInsert()`/`massUpdate()` statement -- see this
     * class's own docblock for why 500 is safe across every supported
     * platform even at the widest realistic row shape.
     */
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $conn,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $datas
     * @return list<list<array<string, mixed>>>
     */
    private static function chunk(array $datas): array
    {
        return array_chunk($datas, self::CHUNK_SIZE);
    }

    /**
     * Routes through the DBAL platform's own identifier-quoting --
     * `AbstractMySQLPlatform::quoteSingleIdentifier()` escapes an embedded
     * backtick character as `` '`' . str_replace('`', '``', $str) . '`' ``.
     * Not a live vulnerability either way: every real caller here passes a
     * fixed, code-controlled column/table name.
     */
    private function protectColumnName(string $name): string
    {
        return $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier($name);
    }

    /**
     * `INSERT IGNORE` has no Postgres or SQLite equivalent -- Postgres's
     * `ON CONFLICT DO NOTHING` is appended after `VALUES (...), (...)`
     * rather than as a keyword before `INTO` like MySQL's `IGNORE`. No
     * conflict target needed: a bare `ON CONFLICT DO NOTHING` downgrades
     * any collision to a no-op, matching `INSERT IGNORE`'s own "duplicate
     * key becomes a silent skip" semantic exactly for every real caller
     * here (all about duplicate-key avoidance on a genuine unique/primary
     * key, never a broader error-suppression need). SQLite's own real
     * equivalent is a genuinely different keyword placement again --
     * `INSERT OR IGNORE INTO ...`, its own "conflict clause" extension
     * (`OR ROLLBACK`/`ABORT`/`FAIL`/`REPLACE`/`IGNORE`), verified live: a
     * bare `INSERT IGNORE INTO ...` (MySQL's own syntax) is a real
     * SQLite syntax error, not a silent no-op.
     *
     * $valuesSql is the *complete*, already-parenthesized `VALUES` body --
     * `(:p0,:p1)` for one row, `(:p0_0,:p0_1),(:p1_0,:p1_1)` for a
     * multi-row batch -- this method only ever splices it in after
     * `VALUES `, it never adds its own wrapping parens (singleInsert()'s
     * one-row case and massInsert()'s multi-row case build that string
     * themselves, since only they know how many rows/tuples it holds).
     */
    private function buildInsertSql(string $protectedTable, string $columnsSql, string $valuesSql, bool $ignore): string
    {
        if (! $ignore) {
            return <<<SQL
                INSERT INTO {$protectedTable} ({$columnsSql}) VALUES {$valuesSql}
                SQL;
        }

        $platform = $this->conn->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
                INSERT INTO {$protectedTable} ({$columnsSql}) VALUES {$valuesSql} ON CONFLICT DO NOTHING
                SQL;
        }

        if ($platform instanceof SQLitePlatform) {
            return <<<SQL
                INSERT OR IGNORE INTO {$protectedTable} ({$columnsSql}) VALUES {$valuesSql}
                SQL;
        }

        if (! $platform instanceof AbstractMySQLPlatform) {
            throw new LogicException(self::class . '::buildInsertSql() has no implementation for platform ' . $platform::class);
        }

        return <<<SQL
            INSERT IGNORE INTO {$protectedTable} ({$columnsSql}) VALUES {$valuesSql}
            SQL;
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

        // buildInsertSql()'s own $protectedTable/$columnsSql/$placeholdersSql
        // inputs below are all structural (protected identifiers +
        // bound-parameter placeholder syntax); every real value flows
        // through $params/executeStatement()'s own binding, never raw
        // string interpolation. Stays on raw
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
        $valuesSql = '(' . implode(',', $placeholders) . ')';
        $query = $this->buildInsertSql($protectedTable, $columnsSql, $valuesSql, $options['ignore'] ?? false);

        $params = [];
        foreach ($data as $key => $value) {
            // A raw PHP bool binds inconsistently through mysqli/DBAL
            // (false arrives as '' rather than 0, tripping a strict-mode
            // "Incorrect integer value" error on a tinyint column) --
            // normalize to int first, same convention already
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

        // Same stays-raw shape as singleInsert() above -- see its own
        // comment.
        $ignore = $options['ignore'] ?? false;
        $columns = array_map($this->protectColumnName(...), $dbfields);
        $protectedTable = $this->protectColumnName($table);
        $columnsSql = implode(',', $columns);

        // Connection::transactional() wraps the WHOLE call (every chunk)
        // in a single all-or-nothing transaction -- a violation in a
        // later chunk still rolls back every earlier chunk already
        // executed in this same call, removing any chance of forgetting a
        // rollBack()-and-rethrow.
        $this->conn->transactional(function (Connection $conn) use ($datas, $dbfields, $ignore, $protectedTable, $columnsSql): void {
            foreach (self::chunk($datas) as $chunk) {
                $rowTuples = [];
                $params = [];
                foreach ($chunk as $rowIndex => $insert) {
                    $placeholders = [];
                    foreach ($dbfields as $i => $field) {
                        $placeholder = 'p' . $rowIndex . '_' . $i;
                        $placeholders[] = ':' . $placeholder;
                        $value = SqlDialect::booleanToInt($insert[$field] ?? null);
                        $params[$placeholder] = ($value === '' || $value === null || ! is_scalar($value)) ? null : $value;
                    }

                    $rowTuples[] = '(' . implode(',', $placeholders) . ')';
                }

                $valuesSql = implode(',', $rowTuples);
                $query = $this->buildInsertSql($protectedTable, $columnsSql, $valuesSql, $ignore);

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

        // See massInsert()'s own comment -- same Connection::transactional()
        // whole-call (every chunk) single-transaction wrapping.
        $this->conn->transactional(function () use ($table, $dbfields, $datas, $flags): void {
            foreach (self::chunk($datas) as $chunk) {
                $this->updateChunk($table, $dbfields, $chunk, $flags);
            }
        });
    }

    /**
     * One real, searched-`CASE` batched `UPDATE` for an entire chunk,
     * replacing what used to be one `updateRow()` call per row. Portable
     * as-is (searched `CASE`/`AND`/`OR` only) -- no per-platform branch
     * needed here, unlike buildInsertSql()'s own `ignore` handling.
     *
     * Per row, first computes its own primary-key predicate (`col = :k` per
     * primary column, ANDed together -- `col IS NULL` for a null/non-scalar
     * key value, exactly like updateRow() -- and dropping the row entirely
     * if every update column is empty-and-skipped, exactly like
     * updateRow()'s own all-fields-skipped early return). Then, per SET
     * column, only rows that actually touch *that* column get a `WHEN`
     * branch -- a row skipping a column under SKIP_EMPTY simply has none,
     * so it falls through to `ELSE <column>` (its own current value,
     * unchanged) for that column specifically, while still being matched
     * by the shared `WHERE` (built from the exact same per-row predicates)
     * for whichever *other* column(s) it does set. One predicate per row,
     * reused for both its own `WHEN` branches and its own `WHERE` branch,
     * rather than two independently-built copies that could drift apart.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param list<array<string, mixed>> $chunk
     */
    private function updateChunk(string $table, array $dbfields, array $chunk, int $flags): void
    {
        $protectedPrimary = array_map($this->protectColumnName(...), $dbfields['primary']);
        $protectedUpdate = [];
        foreach ($dbfields['update'] as $key) {
            $protectedUpdate[$key] = $this->protectColumnName($key);
        }

        /** @var list<array{predicateSql: string, predicateParams: array<string, int|float|string>, setValues: array<string, mixed>}> */
        $rows = [];
        $paramIndex = 0;
        foreach ($chunk as $data) {
            $setValues = [];
            foreach ($dbfields['update'] as $key) {
                $value = SqlDialect::booleanToInt($data[$key] ?? null);
                $isEmpty = ! isset($value) || $value === '' || ! is_scalar($value);
                if ($isEmpty) {
                    if ((bool) ($flags & self::SKIP_EMPTY)) {
                        continue;
                    }
                    $setValues[$key] = null;
                    continue;
                }
                $setValues[$key] = $value;
            }

            if ($setValues === []) {
                continue;
            }

            $predicateParts = [];
            $predicateParams = [];
            foreach ($dbfields['primary'] as $primaryIndex => $key) {
                $value = SqlDialect::booleanToInt($data[$key] ?? null);
                if (isset($value) && is_scalar($value)) {
                    $placeholder = 'k' . $paramIndex++;
                    $predicateParts[] = $protectedPrimary[$primaryIndex] . ' = :' . $placeholder;
                    $predicateParams[$placeholder] = $value;
                } else {
                    $predicateParts[] = $protectedPrimary[$primaryIndex] . ' IS NULL';
                }
            }

            $rows[] = [
                'predicateSql' => implode(' AND ', $predicateParts),
                'predicateParams' => $predicateParams,
                'setValues' => $setValues,
            ];
        }

        if ($rows === []) {
            return;
        }

        $qb = $this->conn->createQueryBuilder()
            ->update($this->protectColumnName($table));

        foreach ($dbfields['update'] as $key) {
            $whenParts = [];
            $columnParams = [];
            foreach ($rows as $row) {
                if (! array_key_exists($key, $row['setValues'])) {
                    continue;
                }

                $value = $row['setValues'][$key];
                if ($value === null) {
                    $whenParts[] = 'WHEN ' . $row['predicateSql'] . ' THEN NULL';
                } else {
                    $placeholder = 'v' . $paramIndex++;
                    $whenParts[] = 'WHEN ' . $row['predicateSql'] . ' THEN :' . $placeholder;
                    $columnParams[$placeholder] = $value;
                }

                foreach ($row['predicateParams'] as $pName => $pValue) {
                    $columnParams[$pName] = $pValue;
                }
            }

            if ($whenParts === []) {
                continue;
            }

            $protectedColumn = $protectedUpdate[$key];
            $qb->set($protectedColumn, 'CASE ' . implode(' ', $whenParts) . ' ELSE ' . $protectedColumn . ' END');
            foreach ($columnParams as $pName => $pValue) {
                $qb->setParameter($pName, $pValue);
            }
        }

        $whereParts = [];
        foreach ($rows as $row) {
            $whereParts[] = '(' . $row['predicateSql'] . ')';
            foreach ($row['predicateParams'] as $pName => $pValue) {
                $qb->setParameter($pName, $pValue);
            }
        }
        $qb->where(implode(' OR ', $whereParts));

        $qb->executeStatement();
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
