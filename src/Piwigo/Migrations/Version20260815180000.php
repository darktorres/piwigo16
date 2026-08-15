<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Drops `unsigned` from every MySQL/MariaDB integer column and narrows the
 * eleven PostgreSQL `bigint` columns to `integer`, so the two providers
 * finally describe the same types.
 *
 * Three problems collapse into one change.
 *
 * **1. `unsigned` has no PostgreSQL equivalent**, and that single fact
 * generates the whole translation table in `Version20260804122300`:
 * `tinyint unsigned`->`smallint`, `smallint unsigned`->`integer`,
 * `mediumint unsigned`->`integer`, `int unsigned`->`bigint`. Removing
 * `unsigned` lets MySQL adopt the type PostgreSQL already uses, and the
 * translation rules stop being needed for future migrations.
 *
 * **2. Capacity ceilings differed by provider.** These are `AUTO_INCREMENT`
 * keys, so the ceiling applies to ids *ever issued*, not live rows:
 *
 *   categories.id  smallint unsigned  65,535 albums  ->  int  2.1B
 *   tags.id        smallint unsigned  65,535 tags    ->  int  2.1B
 *   groups.id      smallint unsigned  65,535         ->  int  2.1B
 *   sites.id       tinyint unsigned      255 sites   ->  int  2.1B
 *
 * PostgreSQL never had those ceilings, so the same gallery could outgrow
 * MySQL while running fine on PostgreSQL.
 *
 * **3. PostgreSQL foreign keys did not match their primary keys.** Comparing
 * both live schemas column by column showed `int unsigned` mapped two ways:
 * `integer` for nine columns -- all primary keys -- and `bigint` for eleven,
 * mostly the foreign keys pointing at those very primary keys
 * (`history.search_id` -> `search.id`, `search.forked_from` -> `search.id`,
 * `history.auth_key_id` -> `user_auth_keys.auth_key_id`, ...). An `int8`
 * column referencing an `int4` key forces a cast on every join. Narrowing
 * those eleven to `integer` makes each foreign key the same type as its
 * referent. They are ids and hit counters; none approaches 2.1B.
 *
 * **Why the MySQL branch drops and recreates every foreign key.** MySQL
 * refuses to retype a column involved in one:
 *
 *   ERROR 3780: Referencing column 'id_uppercat' and referenced column 'id'
 *   in foreign key constraint 'fk_categories_id_uppercat' are incompatible.
 *
 * `SET FOREIGN_KEY_CHECKS=0` does not bypass that -- verified; the check is
 * on definition compatibility, not on row validity. So the constraints come
 * off, the columns change, and the constraints go back on.
 *
 * **Why this introspects rather than listing columns literally.** 77 columns
 * and 40 constraints is well past the size where a hand-copied list is
 * trustworthy, and reconstructing each column's full definition (nullability,
 * default, AUTO_INCREMENT, comment) by hand is exactly where a silent
 * mistake would hide. The mapping below is the reviewable part; which columns
 * it applies to comes from the database.
 */
final class Version20260815180000 extends AbstractMigration
{
    /**
     * MySQL unsigned type -> the signed type covering at least the same
     * range, chosen so it matches what PostgreSQL already stores.
     *
     * `int unsigned` (max ~4.29B) is the one narrowing: it becomes `int`
     * (~2.15B), matching the `integer` PostgreSQL already uses for every
     * primary key in this group.
     *
     * @var array<string, string>
     */
    private const array TYPE_MAP = [
        'tinyint' => 'smallint',
        'smallint' => 'int',
        'mediumint' => 'int',
        'int' => 'int',
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Drop unsigned on MySQL/MariaDB and narrow PostgreSQL bigint columns, so both providers agree';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->upPostgres();

            return;
        }

        $this->upMysql();
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring `unsigned` would reintroduce the provider divergence this removes, '
            . 'and the original per-column widths are recoverable only from the baseline migrations.'
        );
    }

    /**
     * PostgreSQL keeps every other column as-is; only the eleven `bigint`
     * columns narrow. `USING` is unnecessary -- int8 to int4 is an
     * assignment cast PostgreSQL performs implicitly, and it will error
     * rather than truncate if a value does not fit.
     */
    private function upPostgres(): void
    {
        // fetchAllNumeric(), not fetchAllAssociative(): `information_schema`
        // spells its own column names differently per engine -- lowercase on
        // PostgreSQL, uppercase on MySQL -- and static analysis resolves this
        // query against whichever engine it is configured with, so keying by
        // name here reports a missing offset for the branch that is correct.
        // Positional access is true on both.
        $rows = $this->connection->fetchAllNumeric(
            "SELECT table_name, column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND data_type = 'bigint'
             ORDER BY table_name, column_name"
        );

        foreach ($rows as [$table, $column]) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE integer',
                $table,
                (string) $column,
            ));
        }
    }

    private function upMysql(): void
    {
        $database = $this->connection->fetchOne('SELECT DATABASE()');

        $constraints = $this->connection->fetchAllAssociative(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    r.DELETE_RULE, r.UPDATE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME',
            [$database]
        );

        foreach ($constraints as $fk) {
            $this->addSql(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                $fk['TABLE_NAME'],
                (string) $fk['CONSTRAINT_NAME'],
            ));
        }

        foreach ($this->unsignedColumns($database) as $sql) {
            $this->addSql($sql);
        }

        foreach ($constraints as $fk) {
            $this->addSql(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                $fk['TABLE_NAME'],
                (string) $fk['CONSTRAINT_NAME'],
                (string) $fk['COLUMN_NAME'],
                (string) $fk['REFERENCED_TABLE_NAME'],
                (string) $fk['REFERENCED_COLUMN_NAME'],
                $fk['DELETE_RULE'],
                $fk['UPDATE_RULE'],
            ));
        }
    }

    /**
     * `information_schema.COLUMNS.COLUMN_DEFAULT` does not mean the same
     * thing on both engines, and getting this wrong is silent until the
     * statement runs:
     *
     *   column state        MySQL 8.4        MariaDB 12
     *   no default          PHP null         PHP null
     *   nullable, no default PHP null        the string "NULL" (unquoted)
     *   string default ''   "" (unquoted)    "''" (already quoted)
     *   numeric default 0   "0"              "0"
     *
     * Taking MariaDB's literal "NULL" at face value produced
     * `DEFAULT 'NULL'` on an integer column and failed the whole migration
     * with "Invalid default value for 'performed_by'"; re-quoting MariaDB's
     * already-quoted string defaults would corrupt them the same way.
     *
     * Returns null when no `DEFAULT` clause should be emitted at all.
     */
    private static function formatDefault(mixed $default): ?string
    {
        // COLUMN_DEFAULT is a string or null on both engines; anything else
        // means the introspection returned a shape this does not model, and
        // emitting a stringified array/object into DDL would be worse than
        // emitting no DEFAULT clause at all.
        if (! is_scalar($default)) {
            return null;
        }

        $value = (string) $default;
        if (strtoupper($value) === 'NULL') {
            // MariaDB's way of saying "nullable, no explicit default", which
            // is what omitting the clause already means.
            return null;
        }

        if (is_numeric($value) || str_starts_with($value, "'")) {
            return $value;
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * One `MODIFY` per unsigned column, rebuilding the column's full
     * definition from information_schema so nullability, default,
     * AUTO_INCREMENT and comment survive -- `MODIFY` replaces the entire
     * definition, so anything omitted here would be silently dropped.
     *
     * @return list<string>
     */
    private function unsignedColumns(mixed $database): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                    COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_TYPE LIKE '%unsigned%'
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
            [$database]
        );

        $statements = [];
        foreach ($rows as $row) {
            $columnType = $row['COLUMN_TYPE'];
            $base = strtok($columnType, '( ');
            if (! is_string($base) || ! isset(self::TYPE_MAP[$base])) {
                // float(5,2) unsigned -- keeps its precision, just loses the
                // unsigned qualifier (PostgreSQL already stores it as `real`).
                $newType = trim(str_replace('unsigned', '', $columnType));
            } else {
                $newType = self::TYPE_MAP[$base];
            }

            $definition = $newType;
            $definition .= $row['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL';

            $default = self::formatDefault($row['COLUMN_DEFAULT']);
            if ($default !== null) {
                $definition .= ' DEFAULT ' . $default;
            }

            $extra = strtoupper((string) $row['EXTRA']);
            if (str_contains($extra, 'AUTO_INCREMENT')) {
                $definition .= ' AUTO_INCREMENT';
            }

            $comment = $row['COLUMN_COMMENT'];
            if ($comment !== '') {
                $definition .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
            }

            $statements[] = sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s',
                $row['TABLE_NAME'],
                $row['COLUMN_NAME'],
                $definition,
            );
        }

        return $statements;
    }
}
