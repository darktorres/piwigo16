<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Converts `history.section` from a MySQL `ENUM` to `VARCHAR(20)`, matching
 * what PostgreSQL has stored all along (`character varying(20)`).
 *
 * The ENUM was widened at runtime: `HistoryService::logVisit()` -- a page
 * view -- issued `ALTER TABLE history CHANGE section section enum(...)`
 * whenever a plugin or theme introduced a section name the column did not
 * yet list. That required `ALTER` privilege in production for an ordinary
 * request, took a metadata lock on a hot, high-write table, and implicitly
 * committed. It was also platform-divergent: the whole mechanism was a
 * no-op on PostgreSQL, whose column already accepted any value.
 *
 * A VARCHAR needs no widening, so the DDL path, the ENUM introspection that
 * fed it (`DESC history`, parsed out of the live column definition) and the
 * MySQL/PostgreSQL split in `getSectionEnumOptions()` all go with it. The
 * known-section list is now derived from the data on both platforms, which
 * is what PostgreSQL already did.
 *
 * 20 characters is PostgreSQL's existing width, not a new choice; the
 * longest built-in section is `most_visited` at 12. `HistoryService`
 * enforces the same bound before insert, so a longer plugin section name is
 * dropped rather than relying on either engine's own overflow behaviour --
 * PostgreSQL errors, and MySQL under a non-strict `sql_mode` would
 * silently truncate.
 *
 * No data migration is needed: every existing value is one of the ENUM
 * members, all of which are valid VARCHAR content well inside 20
 * characters.
 */
final class Version20260815120000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Convert history.section from a runtime-widened ENUM to VARCHAR(20)';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            // Already `character varying(20)` -- the ENUM only ever existed
            // on MySQL.
            return;
        }

        $this->addSql(
            'ALTER TABLE `history` MODIFY `section` VARCHAR(20) DEFAULT NULL '
            . "COMMENT 'gallery navigation view the visit occurred in, plugin-defined sections stored as-is'"
        );
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Restoring the ENUM would require reconstructing its member list, which was '
            . 'schema state mutated at runtime rather than anything recorded in a migration.'
        );
    }
}
