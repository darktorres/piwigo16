<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use LogicException;
use Override;

/**
 * Baseline bootstrap, final step: every FK constraint, added once all 38
 * tables from the 3 preceding migrations exist (matching `install/
 * piwigo_structure-mysql.sql`'s own "Foreign key constraints (added last,
 * once every table above exists)" section). `ALTER TABLE ... ADD
 * CONSTRAINT ... FOREIGN KEY ... REFERENCES ... ON DELETE ...` is
 * identical syntax on both platforms -- no real per-platform type
 * translation needed here, unlike every preceding migration in this set,
 * so the 49 FK definitions are declared once as shared data and rendered
 * per-platform (backtick-quoted for MySQL/MariaDB, unquoted for
 * Postgres), rather than duplicated as two independent statement lists.
 *
 * Each definition becomes its own `addSql()` call, not one batched
 * multi-statement string -- per `AbstractMigration::addSql()`'s own
 * source: it wraps the whole string as a single `Query` object, executed
 * as one statement later, so a `;`-separated batch of 49 `ALTER TABLE`s
 * in one call would not reliably execute past the first one (mysqli's
 * default single-query execution path has no multi-statement support to
 * rely on here).
 *
 * The 7th element per row is Postgres-only: whether the referencing
 * column needs its own `CREATE INDEX` before the constraint. InnoDB
 * indexes every foreign key automatically; PostgreSQL does not, and most
 * of these columns have no other index (composite-PK leading columns and
 * a few app-level `KEY`s already cover the rest -- see the `false` rows).
 * Without the index, enforcing the referential action is a sequential
 * scan of the child table on every parent delete or update -- `history`,
 * the highest-write table in the schema, has six referencing columns that
 * would otherwise all be unindexed. `plugin_migrations.plugin_id` is
 * `false` for a different reason: it is the leading column of that
 * table's own composite primary key, already covered on both engines.
 */
final class Version20260804122303 extends AbstractMigration
{
    /**
     * The single source of truth for every FK this baseline schema declares
     * -- also called by Version20260804122300-2's own upSqlite() methods
     * (public for exactly that reason), since SQLite cannot `ALTER TABLE
     * ADD CONSTRAINT ... FOREIGN KEY` at all: unlike MySQL/Postgres, its FKs
     * must be declared inline in each owning table's own `CREATE TABLE`, via
     * {@see self::sqliteReferences()}/{@see self::sqliteExtraIndexes()}
     * below, rather than added here afterward.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: bool}>
     */
    public static function foreignKeys(): array
    {
        return [
            ['image_category', 'fk_image_category_image_id', 'image_id', 'images', 'id', 'CASCADE', false],
            ['image_category', 'fk_image_category_category_id', 'category_id', 'categories', 'id', 'CASCADE', false],
            ['image_tag', 'fk_image_tag_image_id', 'image_id', 'images', 'id', 'CASCADE', false],
            ['image_tag', 'fk_image_tag_tag_id', 'tag_id', 'tags', 'id', 'CASCADE', false],
            ['image_format', 'fk_image_format_image_id', 'image_id', 'images', 'id', 'CASCADE', true],
            ['comments', 'fk_comments_image_id', 'image_id', 'images', 'id', 'CASCADE', false],
            ['comments', 'fk_comments_author_id', 'author_id', 'users', 'id', 'SET NULL', true],
            ['favorites', 'fk_favorites_image_id', 'image_id', 'images', 'id', 'CASCADE', true],
            ['favorites', 'fk_favorites_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['user_access', 'fk_user_access_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['user_access', 'fk_user_access_cat_id', 'cat_id', 'categories', 'id', 'CASCADE', true],
            ['user_group', 'fk_user_group_user_id', 'user_id', 'users', 'id', 'CASCADE', true],
            ['user_group', 'fk_user_group_group_id', 'group_id', 'groups', 'id', 'CASCADE', false],
            ['group_access', 'fk_group_access_group_id', 'group_id', 'groups', 'id', 'CASCADE', false],
            ['group_access', 'fk_group_access_cat_id', 'cat_id', 'categories', 'id', 'CASCADE', true],
            ['user_infos', 'fk_user_infos_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['user_feed', 'fk_user_feed_user_id', 'user_id', 'users', 'id', 'CASCADE', true],
            ['user_mail_notification', 'fk_user_mail_notification_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['user_auth_keys', 'fk_user_auth_keys_user_id', 'user_id', 'users', 'id', 'CASCADE', true],
            ['user_failed_logins', 'fk_user_failed_logins_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['caddie', 'fk_caddie_user_id', 'user_id', 'users', 'id', 'CASCADE', false],
            ['caddie', 'fk_caddie_element_id', 'element_id', 'images', 'id', 'CASCADE', true],
            ['lounge', 'fk_lounge_image_id', 'image_id', 'images', 'id', 'CASCADE', false],
            ['lounge', 'fk_lounge_category_id', 'category_id', 'categories', 'id', 'CASCADE', true],
            ['rate', 'fk_rate_element_id', 'element_id', 'images', 'id', 'CASCADE', false],
            ['rate', 'fk_rate_user_id', 'user_id', 'users', 'id', 'CASCADE', true],
            ['categories', 'fk_categories_id_uppercat', 'id_uppercat', 'categories', 'id', 'SET NULL', false],
            ['categories', 'fk_categories_representative_picture_id', 'representative_picture_id', 'images', 'id', 'SET NULL', true],
            ['images', 'fk_images_storage_category_id', 'storage_category_id', 'categories', 'id', 'SET NULL', false],
            ['images', 'fk_images_added_by', 'added_by', 'users', 'id', 'SET NULL', true],
            ['history', 'fk_history_image_id', 'image_id', 'images', 'id', 'SET NULL', true],
            ['history', 'fk_history_category_id', 'category_id', 'categories', 'id', 'SET NULL', true],
            ['history', 'fk_history_search_id', 'search_id', 'search', 'id', 'SET NULL', true],
            ['history', 'fk_history_format_id', 'format_id', 'image_format', 'format_id', 'SET NULL', true],
            ['history', 'fk_history_auth_key_id', 'auth_key_id', 'user_auth_keys', 'auth_key_id', 'SET NULL', true],
            ['history', 'fk_history_user_id', 'user_id', 'users', 'id', 'CASCADE', true],
            ['search', 'fk_search_created_by', 'created_by', 'users', 'id', 'SET NULL', true],
            ['search', 'fk_search_forked_from', 'forked_from', 'search', 'id', 'SET NULL', true],
            ['activity', 'fk_activity_performed_by', 'performed_by', 'users', 'id', 'SET NULL', true],
            ['audit_log', 'fk_audit_log_actor_id', 'actor_id', 'users', 'id', 'SET NULL', false],
            ['old_permalinks', 'fk_old_permalinks_cat_id', 'cat_id', 'categories', 'id', 'CASCADE', true],
            ['categories', 'fk_categories_site_id', 'site_id', 'sites', 'id', 'CASCADE', true],
            ['plugin_migrations', 'fk_plugin_migrations_plugin_id', 'plugin_id', 'plugins', 'id', 'RESTRICT', false],
            ['activity', 'fk_activity_user_id', 'user_id', 'users', 'id', 'SET NULL', true],
            ['activity', 'fk_activity_category_id', 'category_id', 'categories', 'id', 'SET NULL', true],
            ['activity', 'fk_activity_image_id', 'image_id', 'images', 'id', 'SET NULL', true],
            ['activity', 'fk_activity_tag_id', 'tag_id', 'tags', 'id', 'SET NULL', true],
            ['activity', 'fk_activity_group_id', 'group_id', 'groups', 'id', 'SET NULL', true],
            ['audit_log', 'fk_audit_log_group_id', 'group_id', 'groups', 'id', 'SET NULL', true],
        ];
    }

    /**
     * The `REFERENCES ...` fragment to splice into a SQLite `CREATE TABLE`
     * column definition, or '' if that column carries no FK -- looked up
     * from the same {@see self::foreignKeys()} rows every other platform's
     * FK set is drawn from, so the two can never drift apart.
     */
    public static function sqliteReferences(string $table, string $column): string
    {
        foreach (self::foreignKeys() as [$fkTable, , $fkColumn, $refTable, $refColumn, $onDelete]) {
            if ($fkTable === $table && $fkColumn === $column) {
                return sprintf(' REFERENCES %s(%s) ON DELETE %s', $refTable, $refColumn, $onDelete);
            }
        }

        return '';
    }

    /**
     * `CREATE INDEX` statements for the columns of $table that need one on
     * a platform with no automatic FK-column indexing -- the same real
     * columns Postgres's own upPostgres() already indexes explicitly (the
     * 7th `foreignKeys()` element), reused here since SQLite has the exact
     * same "no auto-index on FK columns" gap InnoDB doesn't have.
     *
     * @return list<string>
     */
    public static function sqliteExtraIndexes(string $table): array
    {
        $statements = [];

        foreach (self::foreignKeys() as [$fkTable, , $fkColumn, , , , $needsIndex]) {
            if ($fkTable === $table && $needsIndex) {
                $statements[] = sprintf('CREATE INDEX %s_%s_idx ON %s (%s)', $table, $fkColumn, $table, $fkColumn);
            }
        }

        return $statements;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: every FK constraint across all 38 tables';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof SQLitePlatform) {
            // Every FK this method would otherwise add here is already
            // declared inline in its owning table's own CREATE TABLE, in
            // Version20260804122300-2's own upSqlite() methods (via
            // self::sqliteReferences() above) -- SQLite has no ALTER-based
            // constraint addition at all, so there is nothing left to run
            // on this platform.
            return;
        }

        $isPostgres = $this->platform instanceof PostgreSQLPlatform;

        // Explicit, not a silent "anything non-Postgres gets MySQL syntax"
        // fallthrough -- see Version20260804122300's own up() for why.
        if (! $isPostgres && ! $this->platform instanceof AbstractMySQLPlatform) {
            throw new LogicException(self::class . ' has no migration path for platform ' . $this->platform::class);
        }

        foreach (self::foreignKeys() as [$table, $constraintName, $column, $refTable, $refColumn, $onDelete, $needsPostgresIndex]) {
            if ($isPostgres) {
                if ($needsPostgresIndex) {
                    $this->addSql(sprintf('CREATE INDEX %s_%s_idx ON %s (%s)', $table, $column, $table, $column));
                }

                $this->addSql(
                    'ALTER TABLE ' . $table .
                    ' ADD CONSTRAINT ' . $constraintName .
                    ' FOREIGN KEY (' . $column . ') REFERENCES ' . $refTable . ' (' . $refColumn . ')' .
                    ' ON DELETE ' . $onDelete
                );

                continue;
            }

            $this->addSql(
                'ALTER TABLE `' . $table . '`' .
                ' ADD CONSTRAINT `' . $constraintName . '`' .
                ' FOREIGN KEY (`' . $column . '`) REFERENCES `' . $refTable . '` (`' . $refColumn . '`)' .
                ' ON DELETE ' . $onDelete
            );
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Baseline bootstrap has nothing to revert to but a blank database.'
        );
    }
}
