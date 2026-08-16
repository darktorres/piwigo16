<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;
use RuntimeException;

/**
 * Declares two relationships the schema already had but never constrained.
 *
 * §7 audited which foreign keys lacked an index on PostgreSQL. It never asked
 * the other question -- which relationships have no constraint at all -- and
 * a sweep for tables with zero outgoing foreign keys found these two.
 *
 * **`old_permalinks.cat_id` -> `categories.id`.** The gap was already
 * documented in code: `CategoryService::deleteCategories()` explains that
 * `old_permalinks` "has no such constraint, so it still needs its own delete,
 * and that is exactly why these two run together: without a transaction, a
 * failure between them leaves permalinks pointing at categories that no
 * longer exist, with no FK to clean them up." A cascade replaces that manual
 * delete outright -- it is a plain row removal with no side effects -- the
 * same way the three child deletes that cascades already performed were
 * removed earlier.
 *
 * **`categories.site_id` -> `sites.id`.** Correctness rested on ordering
 * across an event boundary: `CategoryService::deleteSite()` deletes the
 * site's categories, then dispatches `DeleteSite`, whose listener removes the
 * `sites` row. Anything reaching `SiteRepository::delete()` directly orphaned
 * every category pointing at that site. This constraint is a backstop, not a
 * replacement: `deleteSite()` still has to run, because deleting a category
 * row is not the same as deleting its photos, files and derivatives.
 *
 * **PostgreSQL needs the indexes spelled out.** InnoDB creates one per
 * foreign key automatically; PostgreSQL does not, and neither referencing
 * column is indexed today (`old_permalinks`'s primary key is `permalink`
 * alone, so `cat_id` sits in no index at all). Adding the constraints without
 * the indexes would reintroduce exactly the sequential-scan-per-parent-delete
 * problem §7 existed to fix.
 */
final class Version20260815200000 extends AbstractMigration
{
    /**
     * Referencing table, referencing column, referenced table, referenced
     * column -- both `ON DELETE CASCADE`.
     *
     * @var list<array{string, string, string, string}>
     */
    private const array RELATIONSHIPS = [
        ['old_permalinks', 'cat_id', 'categories', 'id'],
        ['categories', 'site_id', 'sites', 'id'],
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Constrain old_permalinks.cat_id and categories.site_id, the two relationships the schema modelled only by convention';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->guardAgainstOrphans();

        $isPostgres = $this->platform instanceof PostgreSQLPlatform;

        foreach (self::RELATIONSHIPS as [$table, $column, $refTable, $refColumn]) {
            if ($isPostgres) {
                // Before the constraint, not after: PostgreSQL validates the
                // existing rows while adding it, and that check is itself a
                // sequential scan without the index.
                $this->addSql(sprintf(
                    'CREATE INDEX %s_%s_idx ON %s (%s)',
                    $table,
                    $column,
                    $table,
                    $column,
                ));
            }

            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT fk_%s_%s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE CASCADE',
                $table,
                $table,
                $column,
                $column,
                $refTable,
                $refColumn,
            ));
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping these constraints would restore a schema that models both relationships by convention only.'
        );
    }

    /**
     * Fails with a readable message rather than letting the ALTER statement
     * do it.
     *
     * Adding a foreign key over orphaned rows already errors on both engines,
     * but MySQL reports only "Cannot add or update a child row", naming
     * neither the offending rows nor how many there are. An installation that
     * has been running long enough to accumulate orphans deserves to be told
     * what to clean up -- and silently deleting those rows here would destroy
     * data this migration has no mandate over.
     */
    private function guardAgainstOrphans(): void
    {
        foreach (self::RELATIONSHIPS as [$table, $column, $refTable, $refColumn]) {
            // Built through the query builder rather than sprintf: static
            // analysis resolves a format string against every combination of
            // the constant matrix above, including the mismatched ones
            // (`categories` paired with `cat_id`), and reports each as an
            // unknown column.
            $orphans = $this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($table, 'child')
                ->leftJoin('child', $refTable, 'parent', 'parent.' . $refColumn . ' = child.' . $column)
                ->where('child.' . $column . ' IS NOT NULL')
                ->andWhere('parent.' . $refColumn . ' IS NULL')
                ->executeQuery()
                ->fetchOne();

            if (is_numeric($orphans) && (int) $orphans > 0) {
                throw new RuntimeException(sprintf(
                    '%s.%s has %d row(s) referencing a %s.%s that no longer exists. '
                    . 'Delete or repoint them, then re-run this migration.',
                    $table,
                    $column,
                    (int) $orphans,
                    $refTable,
                    $refColumn,
                ));
            }
        }
    }
}
