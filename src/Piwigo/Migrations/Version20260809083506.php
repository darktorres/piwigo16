<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * One-time data backfill: nulls out the legacy MySQL zero-date sentinel
 * ('0000-00-00 00:00:00') wherever it survives in `images.date_creation`/
 * `images.date_available` from a pre-existing production database (old
 * Piwigo/MySQL installs' EXIF sync could persist a camera's zero-clock
 * timestamp verbatim before Metadata\MetadataService::getSyncExifData()
 * started normalizing it to null on write -- see that method and
 * Image\ImageEntity's own docblock). No-op on Postgres: that platform
 * rejects the sentinel outright at the SQL level (`'0000-00-00
 * 00:00:00'::timestamp` is a real "date/time field value out of range"
 * error), so no row there can ever carry it. Closes the
 * data-layer gap the former Db\Type\GracefulSqlDateTimeType only papered
 * over for a single narrow ORM read path -- this fixes every consumer,
 * including the raw-DBAL reads (Controller\PictureController and most of
 * Image\ImageRepository) that Type never actually protected.
 */
final class Version20260809083506 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Backfill legacy MySQL zero-date sentinel to NULL in images.date_creation/date_available';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        // CAST(... AS CHAR) on the column side, not a bare `col = '0000-...'`
        // literal comparison -- MySQL's default strict sql_mode (NO_ZERO_DATE,
        // active on this server) rejects the zero-date *string literal itself*
        // while parsing it into a DATETIME for the comparison, before any row
        // is ever examined, so the literal-comparison form fails outright even
        // when the table is empty. Casting the column to CHAR compares as text
        // instead, never triggering that DATETIME-literal validation.
        $this->addSql(
            'UPDATE `images` SET `date_creation` = NULL WHERE CAST(`date_creation` AS CHAR) = \'0000-00-00 00:00:00\''
        );
        $this->addSql(
            'UPDATE `images` SET `date_available` = NULL WHERE CAST(`date_available` AS CHAR) = \'0000-00-00 00:00:00\''
        );
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Cannot recover which rows originally held the zero-date sentinel.'
        );
    }
}
