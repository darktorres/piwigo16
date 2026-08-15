<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Indexes every foreign-key constraint whose referencing column had no
 * index on PostgreSQL -- 22 of them.
 *
 * MySQL/InnoDB creates an index for every foreign key automatically;
 * PostgreSQL does not. Enforcing either referential action means finding the
 * child rows, so without an index that is a sequential scan of the child
 * table on **every** parent delete or update.
 *
 * The worst concentration is `history`, the highest-write table in the
 * schema, which had five unindexed referencing columns. Before this,
 * deleting a single user forced a sequential scan of `activity`, `comments`,
 * `images`, `history`, `rate`, `user_auth_keys`, `user_feed` and
 * `user_group` -- eight scans for one row.
 *
 * The list comes from PostgreSQL's own catalog, not from parsing the
 * committed schema file:
 *
 *   SELECT c.conrelid::regclass, a.attname
 *   FROM pg_constraint c
 *   JOIN unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
 *   JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
 *   WHERE c.contype = 'f'
 *     AND NOT EXISTS (SELECT 1 FROM pg_index i
 *                     WHERE i.indrelid = c.conrelid AND i.indkey[0] = a.attnum);
 *
 * That distinction mattered: a first pass that regex-parsed
 * `install/piwigo_structure-pgsql.sql` found only 9 of these 22. The catalog
 * also encodes the rule correctly -- an index covers a foreign key only if
 * the column is its **leading** column, which is why `cat_id` still counts as
 * uncovered despite sitting inside two composite primary keys.
 *
 * MySQL and MariaDB are deliberately untouched. InnoDB already created an
 * index for each of these constraints, named after the constraint itself, so
 * adding another on the same column would be a genuine duplicate rather than
 * a no-op -- and their committed schema dumps stay byte-identical, which
 * keeps the drift guard meaningful on those legs.
 *
 * This went unnoticed because `.env.test` is mysqli, so no local run
 * exercises PostgreSQL, and MySQL hides the problem by indexing foreign keys
 * for you.
 */
final class Version20260815160000 extends AbstractMigration
{
    /**
     * table => column, in the schema's own `<table>_<column>_idx` naming
     * convention (matching categories_lastmodified_idx/groups_lastmodified_idx).
     *
     * @var list<array{string, string}>
     */
    private const array FK_INDEXES = [
        ['activity', 'performed_by'],
        ['caddie', 'element_id'],
        ['categories', 'representative_picture_id'],
        ['comments', 'author_id'],
        ['favorites', 'image_id'],
        ['group_access', 'cat_id'],
        ['history', 'auth_key_id'],
        ['history', 'category_id'],
        ['history', 'format_id'],
        ['history', 'image_id'],
        ['history', 'search_id'],
        ['history', 'user_id'],
        ['image_format', 'image_id'],
        ['images', 'added_by'],
        ['lounge', 'category_id'],
        ['rate', 'user_id'],
        ['search', 'created_by'],
        ['search', 'forked_from'],
        ['user_access', 'cat_id'],
        ['user_auth_keys', 'user_id'],
        ['user_feed', 'user_id'],
        ['user_group', 'user_id'],
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Index the 22 unindexed foreign-key columns on PostgreSQL';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if (! $this->platform instanceof PostgreSQLPlatform) {
            // MySQL/MariaDB: InnoDB already indexes every foreign key.
            return;
        }

        foreach (self::FK_INDEXES as [$table, $column]) {
            $this->addSql(sprintf(
                'CREATE INDEX %s_%s_idx ON %s (%s)',
                $table,
                $column,
                $table,
                $column,
            ));
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        if (! $this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        foreach (self::FK_INDEXES as [$table, $column]) {
            $this->addSql(sprintf('DROP INDEX %s_%s_idx', $table, $column));
        }
    }
}
