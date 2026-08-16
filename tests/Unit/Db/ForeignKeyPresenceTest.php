<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;

/**
 * Asserts that every reference-shaped column (`*_id`, plus
 * `*_id_from`/`*_id_to`, the shape a bare `%_id` pattern misses -- see
 * `history_summary`'s watermarks below) either carries a real foreign
 * key or is named on this file's own $EXCEPTIONS list. `db-multi-provider`
 * runs `tests/Unit/Db` unconditionally on all three providers, which is
 * what makes this the right home: `tests/Arch` has no live DB connection
 * to introspect, and `tests/Integration` only runs on that job's pgsql
 * leg.
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

    $schema = DbConnection::build()
        ->createSchemaManager()
        ->introspectSchema();

    $unconstrained = [];
    foreach ($schema->getTables() as $table) {
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
