<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Image domain slice of the 43 column-type changes deferred to P17-23 (see
 * `docs/PLAN-REPLAY.md` lines 2550-2569): 1 of the 8 "default changes"
 * (`images.date_available`) and 1 of the 6 "TIMESTAMP NOT NULL additions"
 * (`images.lastmodified`).
 *
 * `date_available` drops its `NOT NULL DEFAULT '1970-01-01 00:00:00'`
 * epoch-sentinel default -- a pre-utf8mb4-era workaround for MySQL's old
 * ban on multiple TIMESTAMP-like columns without explicit defaults, not a
 * real "unset" value. Verified safe: `UploadService::addImage()`
 * (`Admin/Upload/UploadService.php`) is the ONLY INSERT site for this
 * table (confirmed via a full-repo grep for `Tables::images()` INSERT/
 * singleInsert call sites) and always supplies `date_available` itself --
 * the DB default has never actually been relied on for a real photo row.
 * No other code anywhere compares against the literal `1970-01-01` value
 * as a sentinel (also grep-confirmed) -- this is a `Admin/Install/DbPatch/
 * Patch158.php`-era historical artifact only.
 *
 * `lastmodified` gains NOT NULL (its existing `DEFAULT CURRENT_TIMESTAMP
 * ON UPDATE CURRENT_TIMESTAMP` is untouched, so this only forecloses an
 * explicit NULL assignment nothing in this codebase ever makes) --
 * verified zero existing NULL rows in the live test DB before writing
 * this, and the same INSERT site above always supplies it explicitly too
 * (reusing the same `$dbnow` as `date_available`, per that call site's own
 * comment, so PIWIGO_TEST_NOW-frozen tests never see the two columns
 * disagree).
 */
final class Version20260723222501 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Image domain: date_available epoch-sentinel default -> NULL, lastmodified -> NOT NULL';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "' . $prefix . 'images" ALTER COLUMN date_available DROP NOT NULL');
            $this->addSql('ALTER TABLE "' . $prefix . 'images" ALTER COLUMN date_available DROP DEFAULT');
            $this->addSql('ALTER TABLE "' . $prefix . 'images" ALTER COLUMN lastmodified SET NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE `' . $prefix . 'images` MODIFY `date_available` datetime DEFAULT NULL');
        $this->addSql('ALTER TABLE `' . $prefix . 'images` MODIFY `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reverting to the epoch-sentinel default is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
