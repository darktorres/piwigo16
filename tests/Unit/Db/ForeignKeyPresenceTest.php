<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Piwigo\Db\DbConnection;

/**
 * introspectSchema() (both tests below) can't be used directly against a
 * real, FTS5-bearing sqlite3 connection -- confirmed live: FTS5 virtual
 * tables, and each of their 4 shadow tables, report an empty `type` in
 * `PRAGMA table_info` (no real SQL type -- not a Piwigo schema choice,
 * FTS5's own storage model), which DBAL's SQLiteSchemaManager column
 * introspection unconditionally assumes is a real type string for and
 * throws a TypeError on. Every real Piwigo SQLite database has these
 * tables (Wave 2 of the SQLite campaign), so this isn't a hypothetical.
 * Same shadow-table exclusion SchemaDumpService::runSqliteDump() already
 * established for the identical reason -- these tables are FTS5's own
 * synthetic storage, not part of the relational schema this audit's own
 * FK/index-naming assumptions apply to.
 *
 * @return list<Table>
 */
function foreignKeyPresenceTestTables(Connection $conn): array
{
    $schemaManager = $conn->createSchemaManager();

    if (! $conn->getDatabasePlatform() instanceof SQLitePlatform) {
        return $schemaManager->introspectSchema()
            ->getTables();
    }

    $excluded = [];
    // sqlite_master is a SQLite-only catalog table, validated against
    // whichever one connection phpstan-dba has -- matches
    // SchemaDumpService's own identical sqlite_master query.
    // @phpstan-ignore dba.syntaxError
    foreach ($conn->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND sql LIKE 'CREATE VIRTUAL TABLE%'") as $ftsTable) {
        if (! is_string($ftsTable)) {
            continue;
        }
        $excluded[$ftsTable] = true;
        foreach (['_data', '_idx', '_docsize', '_config'] as $suffix) {
            $excluded[$ftsTable . $suffix] = true;
        }
    }

    $tables = [];
    foreach ($schemaManager->introspectTableNames() as $tableName) {
        // getValue(), not toString() -- toString() returns the quoted
        // spelling DBAL's own SQLite introspection gives these names
        // (confirmed live: passing that straight into
        // introspectTableByUnquotedName() double-quotes it, a real
        // "There is no table with name \"\"migration_versions\"\""
        // failure), so the identifier needs re-dispatching through
        // whichever of the 2 introspectTableBy*Name() methods actually
        // matches how DBAL itself quoted it.
        $identifier = $tableName->getUnqualifiedName();
        $name = $identifier->getValue();
        if (isset($excluded[$name])) {
            continue;
        }
        $tables[] = $identifier->isQuoted()
            ? $schemaManager->introspectTableByQuotedName($name)
            : $schemaManager->introspectTableByUnquotedName($name);
    }

    return $tables;
}

/**
 * Asserts that every reference-shaped column (`*_id`, plus
 * `*_id_from`/`*_id_to`, the shape a bare `%_id` pattern misses -- see
 * `history_summary`'s watermarks below) either carries a real foreign
 * key or is named on this file's own $EXCEPTIONS list. `db-multi-provider`
 * runs `tests/Unit/Db` unconditionally on all four providers (mysql/
 * mariadb/pgsql/sqlite), which is what makes this the right home:
 * `tests/Arch` has no live DB connection to introspect, and
 * `tests/Integration` only runs on that job's pgsql leg.
 *
 * Closes a real gap, not a hypothetical one: the CI drift guard
 * (`schema:dump` + `git diff --exit-code`) proves the migrations are
 * internally consistent, but a relationship that was simply never
 * declared produces no drift at all -- the migration and the dump agree
 * perfectly either way. Every one of the four foreign keys added
 * elsewhere in this campaign (`old_permalinks.cat_id`,
 * `categories.site_id`, `plugin_migrations.plugin_id`, `activity`'s
 * polymorphic pair) lived through every prior CI run this way, unnoticed
 * until a direct schema audit found them by hand. This test makes that
 * audit executable instead of re-derived from scratch next time.
 *
 * $EXCEPTIONS is the audit's own conclusion about every currently
 * unconstrained reference-shaped column in the schema, not a suppression
 * list grown to make the test pass -- each entry names which specific
 * paragraph of the schema audit justifies it:
 *
 * - Own primary keys, never a reference to another table:
 *   `activity.activity_id`, `history_summary.summary_id`,
 *   `image_format.format_id`, `user_auth_keys.auth_key_id`,
 *   `extension_ignored_updates.extension_id`,
 *   `integrity_ignored_anomalies.anomaly_id`.
 * - `history_summary.history_id_from`/`history_id_to` -- watermarks
 *   recording a purged id range, not references; `SET NULL` would null
 *   exactly the summaries the purge just consumed, `CASCADE` would
 *   delete the aggregates the feature exists to preserve.
 * - `comments.anonymous_id`, `rate.anonymous_id` -- anonymous-visitor
 *   markers (a truncated IP), not row references.
 * - `activity.object_id`, `audit_log.entity_id` -- the historical-fact
 *   half of a deliberate two-column design (the typed sibling columns
 *   this campaign added, e.g. `activity.category_id`, carry the live,
 *   `ON DELETE SET NULL` reference; these two stay a permanent,
 *   unconstrained record of what the deleted row *was*, since a foreign
 *   key would force them to null or cascade away along with it).
 *
 * A future column matching this test's own naming heuristic that isn't
 * on the list, and isn't given a real foreign key, is exactly the case
 * this test exists to catch.
 */
test('every reference-shaped column is either foreign-keyed or on the exception list', function (): void {
    $EXCEPTIONS = [
        'activity.activity_id',
        'activity.object_id',
        'audit_log.entity_id',
        'comments.anonymous_id',
        'extension_ignored_updates.extension_id',
        'history_summary.summary_id',
        'history_summary.history_id_from',
        'history_summary.history_id_to',
        'image_format.format_id',
        'integrity_ignored_anomalies.anomaly_id',
        'rate.anonymous_id',
        'user_auth_keys.auth_key_id',
    ];

    $unconstrained = [];
    foreach (foreignKeyPresenceTestTables(DbConnection::build()) as $table) {
        $tableName = $table->getObjectName()
            ->toString();

        $foreignKeyedColumns = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            foreach ($foreignKey->getReferencingColumnNames() as $columnName) {
                $foreignKeyedColumns[] = $columnName->toString();
            }
        }

        foreach ($table->getColumns() as $column) {
            $columnName = $column->getObjectName()
                ->toString();
            $looksLikeAReference = str_ends_with($columnName, '_id')
                || str_ends_with($columnName, '_id_from')
                || str_ends_with($columnName, '_id_to');
            if (! $looksLikeAReference || in_array($columnName, $foreignKeyedColumns, true)) {
                continue;
            }

            $key = $tableName . '.' . $columnName;
            if (! in_array($key, $EXCEPTIONS, true)) {
                $unconstrained[] = $key;
            }
        }
    }

    expect($unconstrained)
        ->toBe([]);
});

/**
 * Closes the other half of the same gap: a foreign key can exist and
 * still leave its referencing column unindexed. InnoDB indexes every
 * foreign key automatically; PostgreSQL and SQLite do not (Wave 1 of the
 * SQLite campaign added the same explicit `sqliteExtraIndexes()` data
 * Postgres's own `upPostgres()` already needed for the identical gap),
 * so this only ever catches something real on those 2 providers --
 * `db-multi-provider` runs this file on all four, which is what makes it
 * worth asserting unconditionally rather than gating it on the connected
 * platform.
 *
 * An FK is covered when some index's own column list, truncated to the
 * FK's column count, equals the FK's columns in order -- this handles a
 * composite primary key covering a single-column FK by construction
 * (`image_category`'s PK `(image_id, category_id)` covers `image_id` as
 * a leading-column match, no separate index needed; `favorites`' PK
 * `(user_id, image_id)` does not cover `image_id`, which correctly still
 * needs -- and has -- its own index, `fk_favorites_image_id`). No
 * composite FKs exist anywhere in this schema (every `FOREIGN KEY (...)`
 * names exactly one column), so every real case here reduces to a
 * single-column comparison, but the general form costs nothing extra.
 */
test('every foreign key\'s referencing column is indexed', function (): void {
    $uncovered = [];
    foreach (foreignKeyPresenceTestTables(DbConnection::build()) as $table) {
        $tableName = $table->getObjectName()
            ->toString();

        $indexColumnLists = [];
        foreach ($table->getIndexes() as $index) {
            $indexColumnLists[] = array_map(
                static fn ($indexedColumn): string => $indexedColumn->getColumnName()
                    ->toString(),
                $index->getIndexedColumns(),
            );
        }

        foreach ($table->getForeignKeys() as $foreignKey) {
            $fkColumns = array_map(
                static fn ($columnName): string => $columnName->toString(),
                $foreignKey->getReferencingColumnNames(),
            );
            $covered = array_any($indexColumnLists, fn ($indexColumns): bool => array_slice($indexColumns, 0, count($fkColumns)) === $fkColumns);

            if (! $covered) {
                $uncovered[] = $tableName . '.' . implode(',', $fkColumns);
            }
        }
    }

    expect($uncovered)
        ->toBe([]);
});
