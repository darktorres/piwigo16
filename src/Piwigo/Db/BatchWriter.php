<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Statement;
use LogicException;

/**
 * Batched parameterized INSERT/UPDATE helpers, shared rather than
 * duplicated per-repository -- these 4 methods back ~25 call sites across
 * Category/Image/Admin/Controller/Mail/Config, not a single domain's
 * concern.
 *
 * massInsert()/massUpdate() issue real multi-row, chunked SQL (a single
 * multi-row `VALUES (...), (...), ...` per `INSERT` chunk; a single
 * `UPDATE ... JOIN (VALUES ...)` per `UPDATE` chunk) rather than one
 * statement per row -- profiling a real admin-sync pass at 20,000 images
 * found `massUpdate()`'s former row-by-row loop alone responsible for
 * ~27% of total instrumented time (99s of 367s, 49,600 individual
 * `UPDATE` statements), almost entirely spent in per-statement
 * network/parse/bind/execute overhead rather than genuine work.
 *
 * `updateChunk()`'s own docblock covers why it's a `VALUES`-derived-table
 * `JOIN`/`FROM` now, not a searched `CASE` (a real, measured O(chunk²)
 * problem that approach had -- a session-scoped `CREATE TEMPORARY TABLE`
 * would also have fixed it, but was rejected in favor of a real,
 * lifecycle-free derived table).
 *
 * {@see CHUNK_SIZE} is shared with `massInsert()`, which never had the
 * old CASE-based `massUpdate()`'s own O(chunk²) blowup (the reason 500
 * was chosen over a bigger chunk in the first place -- see git history
 * for the exact per-chunk-size timings that motivated it) -- the
 * JOIN-based `updateChunk()` doesn't have that problem either, so 500 is
 * no longer a correctness-adjacent ceiling for it, just an unexamined
 * inherited default. Worth its own re-tuning pass if fewer, larger
 * chunks turn out to help further; not attempted here, since that's a
 * separate question from replacing the CASE approach itself.
 *
 * The whole call (every chunk) still runs inside one
 * `Connection::transactional()` closure, so a failure in a later chunk
 * still rolls back every earlier chunk from the same call -- the
 * all-or-nothing guarantee is unchanged, just no longer paid for with a
 * statement per row.
 *
 * singleInsert()/singleUpdate() (and the private per-row updateRow() they
 * share) are deliberately NOT batched -- a lone row has no batching
 * upside, and tests/Unit/Db/BatchWriterTest.php's mutation-testing-derived
 * coverage is pinned to updateRow()'s exact per-row internals.
 * `updateChunk()` also falls back to `updateRow()` for the rare row whose
 * own primary-key value is null/non-scalar (see its own docblock) --
 * reusing this already-tested per-row path there rather than
 * reimplementing `updateRow()`'s own `IS NULL` handling a second time
 * inside the batched JOIN's own join condition, where it would need a
 * NULL-safe equality operator with no single portable spelling across
 * MySQL/MariaDB/PostgreSQL/SQLite.
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
     * class's own docblock for why bigger is NOT better past this point
     * (a real, measured regression, not just an untested assumption).
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
     * @return int the number of rows the database actually inserted --
     *   with `ignore: true`, a row skipped as a duplicate/FK-violation
     *   under `INSERT IGNORE`/`ON CONFLICT DO NOTHING`/`INSERT OR IGNORE`
     *   is excluded from this count on every supported platform (each
     *   already reports only genuinely-inserted rows via its own affected-
     *   rows count), so this doubles as "how many were newly added"
     *   without a separate existence check.
     */
    public function massInsert(string $table, array $dbfields, array $datas, array $options = []): int
    {
        if ($datas === []) {
            return 0;
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
        return $this->conn->transactional(function (Connection $conn) use ($datas, $dbfields, $ignore, $protectedTable, $columnsSql): int {
            $affected = 0;
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

                $affected += (int) $conn->executeStatement($query, $params);
            }

            return $affected;
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
        //
        // $statementCache: reused across every chunk that ends up with an
        // identical SQL shape (same table/primary/actually-used update
        // columns/row count) within this one call -- a large sync chunks
        // into many same-sized batches whose SQL text is byte-identical
        // except for the bound values, so preparing it once and re-binding
        // avoids Doctrine re-parsing (`Doctrine\DBAL\SQL\Parser::parse`)
        // and re-preparing the same statement once per chunk. Scoped to
        // this one massUpdate() call (a local, not an instance property --
        // this class is `readonly`) rather than across calls, since a
        // different call's own $dbfields/$table already produces a
        // different cache key anyway.
        $statementCache = [];

        $this->conn->transactional(function () use ($table, $dbfields, $datas, $flags, &$statementCache): void {
            foreach (self::chunk($datas) as $chunk) {
                $this->updateChunk($table, $dbfields, $chunk, $flags, $statementCache);
            }
        });
    }

    /**
     * Splits a chunk into the fast path (every row's primary-key value is
     * a real scalar -- the overwhelming common case) and a rare fallback
     * (a null/non-scalar primary value, matching via `col IS NULL` rather
     * than a real key equality -- `$dbfields['primary']` is whichever
     * WHERE-matching column set the caller chose, not necessarily a real
     * `NOT NULL` primary key). The fast path batches into one
     * {@see updateChunkViaJoin()} call per chunk; a null-key row is handed
     * to the already-tested per-row {@see updateRow()} entirely untouched
     * (its own `$data`/`$flags`), rather than reimplementing
     * `updateRow()`'s `IS NULL` handling a second time inside the batched
     * JOIN's own join condition, where it would need a NULL-safe equality
     * operator with no single spelling portable across
     * MySQL/MariaDB/PostgreSQL/SQLite.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param list<array<string, mixed>> $chunk
     * @param array<string, Statement> $statementCache see {@see massUpdate()}'s own docblock
     */
    private function updateChunk(string $table, array $dbfields, array $chunk, int $flags, array &$statementCache): void
    {
        /** @var list<array{primary: array<string, int|float|string>, setValues: array<string, mixed>}> */
        $joinRows = [];

        foreach ($chunk as $data) {
            $primaryValues = [];
            $hasNullPrimary = false;
            foreach ($dbfields['primary'] as $key) {
                $value = SqlDialect::booleanToInt($data[$key] ?? null);
                // booleanToInt() already converts a real bool to int, so a
                // bool can never actually reach here -- ! is_bool() is
                // genuine defensive narrowing (matches this method's own
                // updateChunkViaJoin() parameter type), not a no-op check.
                if (isset($value) && is_scalar($value) && ! is_bool($value)) {
                    $primaryValues[$key] = $value;
                } else {
                    $hasNullPrimary = true;
                    break;
                }
            }

            if ($hasNullPrimary) {
                $where = [];
                foreach ($dbfields['primary'] as $key) {
                    $where[$key] = $data[$key] ?? null;
                }
                $updateData = [];
                foreach ($dbfields['update'] as $key) {
                    $updateData[$key] = $data[$key] ?? null;
                }
                $this->updateRow($table, $updateData, $where, $flags);
                continue;
            }

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

            $joinRows[] = [
                'primary' => $primaryValues,
                'setValues' => $setValues,
            ];
        }

        if ($joinRows !== []) {
            $this->updateChunkViaJoin($table, $dbfields, $joinRows, $statementCache);
        }
    }

    /**
     * One `UPDATE ... JOIN (VALUES ...)` (MySQL/MariaDB) or
     * `UPDATE ... SET ... FROM (VALUES ...) WHERE ...` (PostgreSQL/SQLite)
     * per chunk -- replaces a former searched-`CASE` `UPDATE`, which cost
     * the database O(chunk_size) comparisons *per row* to find its own
     * matching `WHEN` branch (branches evaluated in order), i.e.
     * O(chunk_size²) total per chunk. Measured directly against a real
     * MySQL sync at 10,000 images (`tests/Bench/SiteSyncBench.php`): 100
     * rows/chunk ~52s, 500 ~54s, 1,000 ~56s (flat, within normal
     * run-to-run noise), 2,000 ~65s, 5,000 ~98s -- a clear, reproducible
     * regression once chunk size grew.
     *
     * A `VALUES`-derived table joined by primary key gives the database a
     * real (indexed, on the real table's side) join instead -- back to
     * O(n) per chunk. Measured ~2-3x faster than the CASE approach at
     * 1,200/10,000-row scale on real MySQL 8.4, the speedup growing with
     * chunk size exactly as the O(n²) vs O(n) difference predicts. A
     * session-scoped `CREATE TEMPORARY TABLE` would get the same join
     * shape, but was rejected in favor of this lifecycle-free
     * alternative: no `CREATE`/`DROP` pair, no session-scoping or
     * naming-collision risk under concurrent connections sharing one
     * schema.
     *
     * Every row here already has a fully scalar primary-key value and a
     * non-empty `setValues` -- both already validated by
     * {@see updateChunk()}, not re-checked here.
     *
     * Per-column "was this actually provided" flags (`{col}_set` in the
     * derived table) preserve `SKIP_EMPTY`'s real per-row/per-column
     * semantics: different rows in the same chunk can legitimately supply
     * different subsets of `$dbfields['update']` (e.g. different photos'
     * own available EXIF/IPTC fields), and a row that doesn't touch a
     * given column must fall through to that column's own current value,
     * not have some other row's value (or NULL) clobber it. A flat
     * `SET col = nv.col` for every row would silently break that -- the
     * `CASE WHEN {col}_set THEN nv.{col} ELSE t.{col} END` per column
     * reproduces the old CASE-based design's own `WHEN ... ELSE <column>
     * END` per-column fallthrough exactly, just keyed off a boolean flag
     * per row instead of a per-row predicate re-match against every other
     * row's own predicate.
     *
     * Tuple/column syntax differs per platform in 2 independent, verified
     * (not assumed) ways:
     *
     * 1. Real MySQL specifically -- not MariaDB, despite both extending
     *    `AbstractMySQLPlatform` -- requires each `VALUES` tuple wrapped in
     *    `ROW(...)`. Confirmed live: MariaDB 12.3 rejects `ROW(...)`
     *    outright ("You have an error in your SQL syntax"), MySQL 8.4
     *    requires it; MariaDB/PostgreSQL/SQLite all accept a bare
     *    `(...), (...)` tuple list instead.
     * 2. SQLite's own `VALUES (...)` table-value-constructor has no
     *    `AS alias(col1, col2, ...)` named-column form at all -- confirmed
     *    live, a real syntax error -- only MySQL/MariaDB/PostgreSQL support
     *    naming columns that way. SQLite's own branch instead references
     *    columns by their auto-generated `column1`, `column2`, ...
     *    (1-based) positional names.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param list<array{primary: array<string, int|float|string>, setValues: array<string, mixed>}> $rows
     * @param array<string, Statement> $statementCache see {@see massUpdate()}'s own docblock
     */
    private function updateChunkViaJoin(string $table, array $dbfields, array $rows, array &$statementCache): void
    {
        // Only the columns at least one row in this chunk actually sets --
        // matches the old CASE-based design's own optimization of
        // omitting a SET clause (and every WHEN branch with it) entirely
        // when no row in the chunk touches that column. A column every row
        // in the chunk provides needs no `{col}_set` flag or `CASE` at all
        // -- `SET col = nv.col` directly -- saving both a bound parameter
        // and a branch per row; a column only SOME rows provide still
        // needs the full flag/CASE machinery to preserve SKIP_EMPTY's
        // real per-row fallthrough (see this method's own docblock).
        $usedKeys = [];
        $providedCount = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['setValues']) as $key) {
                $usedKeys[$key] = true;
                $providedCount[$key] = ($providedCount[$key] ?? 0) + 1;
            }
        }
        $updateKeys = array_values(array_filter(
            $dbfields['update'],
            static fn (string $key): bool => isset($usedKeys[$key]),
        ));

        if ($updateKeys === []) {
            return;
        }

        $rowCount = count($rows);
        $alwaysProvided = [];
        foreach ($updateKeys as $key) {
            $alwaysProvided[$key] = ($providedCount[$key] ?? 0) === $rowCount;
        }

        $platform = $this->conn->getDatabasePlatform();
        $isSqlite = $platform instanceof SQLitePlatform;
        // MySQLPlatform and MariaDBPlatform are sibling classes (both
        // extend AbstractMySQLPlatform, neither extends the other), so
        // `instanceof MySQLPlatform` alone already excludes MariaDB --
        // real MySQL's ROW() requirement (below) does not apply to it.
        $requiresRowWrapper = $platform instanceof MySQLPlatform;
        $usesJoinClause = $platform instanceof AbstractMySQLPlatform;

        $protectedTable = $this->protectColumnName($table);
        $protectedPrimary = array_map($this->protectColumnName(...), $dbfields['primary']);
        $protectedUpdate = array_combine($updateKeys, array_map($this->protectColumnName(...), $updateKeys));

        // Column layout within each VALUES tuple: primary key(s) first,
        // then per update column either just its value (always-provided)
        // or a (value, was-provided-flag) pair (sometimes-provided), in
        // $updateKeys order -- $columnPositions records each column's own
        // slot index/indices so the tuple-building loop and the
        // alias/column-reference logic below agree on this exactly without
        // hardcoding a uniform per-column width.
        $aliasColumns = [...$dbfields['primary']];
        /** @var array<string, array{value: int, flag: int|null}> */
        $columnPositions = [];
        foreach ($updateKeys as $key) {
            $valueIndex = count($aliasColumns);
            $aliasColumns[] = $key;
            if ($alwaysProvided[$key]) {
                $columnPositions[$key] = [
                    'value' => $valueIndex,
                    'flag' => null,
                ];
                continue;
            }
            $flagIndex = count($aliasColumns);
            $aliasColumns[] = $key . '_set';
            $columnPositions[$key] = [
                'value' => $valueIndex,
                'flag' => $flagIndex,
            ];
        }

        // PostgreSQL specifically: a bound parameter inside a bare `VALUES
        // (...)` list has no type of its own to infer from context, unlike
        // MySQL/MariaDB/SQLite's own looser, coercive comparison semantics.
        // Confirmed live, 3 distinct failures without this: (1) `t.id =
        // nv.id` -- "operator does not exist: integer = text" -- fixed by
        // casting every non-null value parameter to match its own PHP
        // runtime type (this class's own real callers only ever use plain
        // int/float/string columns, per its class docblock's "genuinely
        // arbitrary by design" column-value philosophy); (2)
        // `CASE WHEN nv.col_set = 1` -- the same operator-resolution
        // problem for the always-int 0/1 flag column, fixed with a fixed
        // `::integer` cast; (3) `t.rnk = CASE ... THEN nv.rnk ELSE t.rnk
        // END` for an integer `rnk` column -- "CASE types integer and text
        // cannot be matched", proving the update-*value* column needs the
        // exact same per-value cast as the primary key, not just backward
        // inference from the CASE's own ELSE branch as originally assumed.
        //
        // A genuinely null value (either "not provided" or an explicit
        // SKIP_EMPTY-surviving NULL) is spliced as the bare literal `NULL`
        // instead of a cast bound parameter -- an explicit `::text`/etc.
        // cast on a null parameter would itself become a concretely-typed
        // NULL that can *still* fail to unify against a differently-typed
        // CASE branch (the exact problem above, just moved), whereas an
        // untyped `NULL` literal is PostgreSQL's flexible "unknown"
        // pseudo-type, which unifies against anything.
        $postgresCast = static function (mixed $value) use ($platform): string {
            if (! $platform instanceof PostgreSQLPlatform || $value === null) {
                return '?';
            }

            return match (true) {
                is_int($value) => '?::bigint',
                is_float($value) => '?::double precision',
                default => '?::text',
            };
        };

        $rowTuples = [];
        $params = [];
        // Explicit type per parameter, keyed by the same index as $params --
        // only the synthetic `_set` flag parameters (always a literal 0/1
        // PHP int, never a real table column) are bound as
        // ParameterType::INTEGER (see the bind loop's own docblock for why).
        // Primary key and update-value parameters stay ParameterType::STRING
        // regardless of their real PHP type: an update-value column is
        // genuinely arbitrary (this class's own docblock) and can be a real
        // MySQL JSON column (e.g. `config`.`value`) -- confirmed live that
        // mysqli binding a native integer (ParameterType::INTEGER) into a
        // JSON column fails with "Invalid JSON text: not a JSON text, may
        // need CAST", while the exact same value bound as STRING succeeds
        // (MySQL parses the string bytes as JSON text). Primary keys never
        // needed INTEGER either -- the earlier SQLite investigation found
        // the primary-key JOIN condition already worked correctly under the
        // STRING default because it compares against a real declared-
        // affinity column, unlike the flag column's bare-literal comparison.
        $paramTypes = [];
        foreach ($rows as $row) {
            $tupleParts = [];
            foreach ($dbfields['primary'] as $key) {
                $tupleParts[] = $postgresCast($row['primary'][$key]);
                $params[] = $row['primary'][$key];
                $paramTypes[] = ParameterType::STRING;
            }
            foreach ($updateKeys as $key) {
                $provided = array_key_exists($key, $row['setValues']);
                $value = $provided ? $row['setValues'][$key] : null;
                if ($value === null) {
                    $tupleParts[] = 'NULL';
                } else {
                    $tupleParts[] = $postgresCast($value);
                    $params[] = $value;
                    $paramTypes[] = ParameterType::STRING;
                }
                if ($alwaysProvided[$key]) {
                    // Every row in this chunk provides $key -- no flag slot
                    // at all (see $columnPositions's own construction).
                    continue;
                }
                // The flag column IS compared with a plain `= 1` equality
                // (`CASE WHEN nv.{col}_set = 1 THEN ...`), the same
                // operator-resolution problem as the primary key above.
                // Always a literal 0/1 PHP int, never null, so a fixed
                // `::integer` cast (no per-value dispatch needed).
                $tupleParts[] = $platform instanceof PostgreSQLPlatform ? '?::integer' : '?';
                $params[] = $provided ? 1 : 0;
                $paramTypes[] = ParameterType::INTEGER;
            }
            $rowTuples[] = $requiresRowWrapper
                ? 'ROW(' . implode(',', $tupleParts) . ')'
                : '(' . implode(',', $tupleParts) . ')';
        }
        $valuesSql = 'VALUES ' . implode(',', $rowTuples);

        $nvColumnName = function (int $index) use ($aliasColumns, $isSqlite): string {
            // SQLite has no named-column VALUES-alias form (see this
            // method's own docblock) -- column1/column2/... (1-based) are
            // its own auto-generated names for a bare VALUES row source.
            $name = $isSqlite ? 'column' . ($index + 1) : $aliasColumns[$index];

            return $this->protectColumnName($name);
        };

        $joinParts = [];
        foreach ($dbfields['primary'] as $i => $key) {
            $joinParts[] = 't.' . $protectedPrimary[$i] . ' = nv.' . $nvColumnName($i);
        }
        $joinSql = implode(' AND ', $joinParts);

        $setParts = [];
        foreach ($updateKeys as $key) {
            $protectedColumn = $protectedUpdate[$key];
            // The SET target itself is table-qualified (`t.col = ...`)
            // only for the JOIN-clause platforms (MySQL/MariaDB) --
            // PostgreSQL rejects a qualified SET target outright
            // ("SET target columns cannot be qualified with the relation
            // name", confirmed live), and standard `UPDATE ... FROM`
            // syntax (which SQLite's own variant follows) never qualifies
            // it either. The CASE expression's own internal references
            // (`nv.col`, and `t.col` in the ELSE fallthrough) are
            // unaffected by this restriction on every platform.
            $setTarget = $usesJoinClause ? 't.' . $protectedColumn : $protectedColumn;
            $valueColumn = $nvColumnName($columnPositions[$key]['value']);
            $flagIndex = $columnPositions[$key]['flag'];
            $setParts[] = $flagIndex === null
                ? $setTarget . ' = nv.' . $valueColumn
                : $setTarget . ' = CASE WHEN nv.' . $nvColumnName($flagIndex) . ' = 1 THEN nv.' . $valueColumn . ' ELSE t.' . $protectedColumn . ' END';
        }
        $setSql = implode(',', $setParts);

        $nvAlias = $isSqlite
            ? 'nv'
            : 'nv(' . implode(',', array_map($this->protectColumnName(...), $aliasColumns)) . ')';

        // Reused (see massUpdate()'s own docblock) whenever a later chunk
        // produces the byte-identical SQL shape: same table/primary
        // columns/actually-used update columns/always-vs-sometimes-provided
        // pattern per column/row count. Any difference in any of those
        // changes the generated SQL text itself, so the key must capture
        // all of them, not just $updateKeys -- two chunks with the same
        // used columns but a different always-provided pattern still
        // produce different tuple widths and SET clauses.
        $cacheKey = $table . '|' . implode(',', $dbfields['primary']) . '|' . implode(',', array_map(
            static fn (string $key): string => $key . ':' . ($alwaysProvided[$key] ? '1' : '0'),
            $updateKeys,
        )) . '|' . $rowCount;

        if (isset($statementCache[$cacheKey])) {
            $stmt = $statementCache[$cacheKey];
        } else {
            $sql = $usesJoinClause
                ? "UPDATE {$protectedTable} AS t JOIN ({$valuesSql}) AS {$nvAlias} ON {$joinSql} SET {$setSql}"
                : "UPDATE {$protectedTable} AS t SET {$setSql} FROM ({$valuesSql}) AS {$nvAlias} WHERE {$joinSql}";
            $stmt = $this->conn->prepare($sql);
            $statementCache[$cacheKey] = $stmt;
        }

        // Explicit ParameterType per value (from $paramTypes, built above),
        // not DBAL's own bindValue() default (ParameterType::STRING
        // regardless of the real PHP type, confirmed by reading
        // Doctrine\DBAL\Statement::bindValue()'s own signature) -- confirmed
        // live this isn't cosmetic: DBAL's native `sqlite3` driver (this
        // project's own real SQLite driver, not pdo_sqlite) forwards the
        // type as-is to `SQLite3Stmt::bindValue()`, so an int flag column
        // bound as the default STRING becomes SQLite TEXT '1'/'0' --
        // compared against the bare numeric literal `1` in `CASE WHEN
        // nv.{col}_set = 1`, a TEXT-vs-untyped-VALUES-column comparison
        // silently evaluates false every time (no affinity to coerce
        // through, unlike comparing against a real declared-affinity
        // column), so the CASE always fell through to its ELSE branch --
        // explaining a real, reproduced bug: every "sometimes provided"
        // column silently never updated on SQLite. PostgreSQL's own driver
        // does NOT forward ParameterType to a real wire-level type either
        // way (confirmed live, still fails with it alone) -- the SQL-level
        // `::cast` above remains the real fix there; this is a second,
        // independent gap on a second platform, not a replacement for it.
        // Only the synthetic flag parameters use ParameterType::INTEGER --
        // NOT a blind is_int($value) check, confirmed live that binding a
        // genuine primary-key/update-value int as ParameterType::INTEGER
        // breaks writing to a real MySQL JSON column (e.g. `config`.`value`)
        // with "Invalid JSON text: not a JSON text, may need CAST" (see
        // $paramTypes's own construction above for the full explanation).
        foreach ($params as $i => $value) {
            // Never null: a null value is always spliced as the literal
            // `NULL` above instead of appended here (see the tuple-building
            // loop), so $params only ever holds a genuine primary key,
            // update value, or 0/1 flag.
            $stmt->bindValue($i + 1, $value, $paramTypes[$i]);
        }
        $stmt->executeStatement();
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
