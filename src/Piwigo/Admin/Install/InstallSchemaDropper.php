<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Piwigo\Db\EntityManagerFactory;

/**
 * Detects and removes a prior Piwigo install's schema from a database
 * install.php is about to (re)install into -- InstallWizard's own
 * confirm-overwrite flow (see its own docblock) is the only caller.
 */
final class InstallSchemaDropper
{
    /**
     * `migration_versions` (Doctrine Migrations' own tracking table,
     * {@see \Piwigo\Db\MigrationDependencyFactory::build()}'s hardcoded
     * `table_storage`) is the presence signal: the one table that can only
     * exist if this exact schema-management pipeline touched this DB
     * before -- more precise than checking for a generically-named table
     * (`users`/`config`) a coincidentally-shared DB could plausibly also
     * have.
     *
     * `?bool`, not `bool`: a successful DB *connection* doesn't guarantee
     * schema-introspection privileges, a genuinely distinct MySQL/Postgres
     * permission -- `introspectTableNames()` runs a real SQL query, and a
     * privilege error surfaces as a catchable {@see DbalException}. `null`
     * means "connected fine but couldn't determine", kept distinct from a
     * definite `false` so a caller never mistakes "couldn't check" for
     * "definitely no prior install".
     */
    public function hasExistingInstall(Connection $conn): ?bool
    {
        try {
            return in_array('migration_versions', $this->tableNames($conn), true);
        } catch (DbalException) {
            return null;
        }
    }

    /**
     * Drops every real Piwigo table from $conn, scoped to exactly the
     * known Piwigo table set (every `#[ORM\Entity]`-mapped table, plus
     * `migration_versions`) -- never a blanket drop of the whole database
     * (would destroy an unrelated app's tables if $conn's database is
     * shared).
     *
     * Deliberately NOT {@see \Doctrine\ORM\Tools\SchemaTool::dropSchema()}
     * (tried first, reverted): that method builds its DROP statements
     * from the ORM metadata's own *guessed* foreign-key constraint names
     * (Doctrine's own hash-based naming convention, e.g.
     * `FK_3AF34668F6BD1646`) -- but every real FK in this schema was
     * created by hand-written SQL in `src/Piwigo/Migrations/` with an
     * explicit, human-readable name (`fk_image_category_category_id`,
     * matching this codebase's own `fk_<table>_<column>` convention
     * throughout), so `dropSchema()`'s `ALTER TABLE ... DROP FOREIGN KEY
     * <guessed-name>` statements fail outright ("check that column/key
     * exists"). `dropSchema()` also catches and silently discards every
     * per-statement failure, so that mismatch never surfaces as an
     * error -- it just leaves every table still referenced by a live FK
     * behind (confirmed live: 6 of 39 tables survived, then the very
     * next `InstallSchemaMigrator::migrate()` failed with "table already
     * exists" -- the exact failure this whole feature exists to
     * prevent).
     *
     * Instead: introspect the REAL live schema (real, current FK
     * constraint names, not guessed ones), drop every table from that
     * introspected `Schema` object that isn't in the known Piwigo set,
     * then generate DROP SQL from what's left -- `Schema::toDropSql()`
     * orders foreign keys before tables correctly because it's working
     * from the schema's own real, introspected dependency graph, not a
     * separately-computed one. Confirmed live: leaves zero Piwigo tables
     * behind and leaves an unrelated table in the same database
     * untouched.
     */
    public function drop(Connection $conn): void
    {
        $piwigoTableNames = array_map(
            static fn ($classMetadata): string => $classMetadata->getTableName(),
            EntityManagerFactory::build($conn)
                ->getMetadataFactory()
                ->getAllMetadata(),
        );
        $piwigoTableNames[] = 'migration_versions';

        $liveSchema = $conn->createSchemaManager()
            ->introspectSchema();

        foreach ($liveSchema->getTables() as $table) {
            $tableName = $table->getObjectName()
                ->getUnqualifiedName()
                ->getValue();
            if (! in_array($tableName, $piwigoTableNames, true)) {
                $liveSchema->dropTable($tableName);
            }
        }

        foreach ($liveSchema->toDropSql($conn->getDatabasePlatform()) as $sql) {
            $conn->executeStatement($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function tableNames(Connection $conn): array
    {
        return array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()
                ->getValue(),
            $conn->createSchemaManager()
                ->introspectTableNames(),
        );
    }
}
