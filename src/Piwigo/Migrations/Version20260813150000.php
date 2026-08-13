<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Drops the `ON UPDATE CURRENT_TIMESTAMP`-equivalent auto-bump on
 * `lastmodified` for all 5 tables that have it (`categories`, `groups`,
 * `images`, `tags`, `user_infos` -- created in `Version20260804122300.php`/
 * `Version20260804122301.php`). That auto-bump read the real DB-server
 * clock on every row UPDATE, invisible to `Env::now()`'s `PIWIGO_TEST_NOW`
 * freeze and the source of a real golden-HTML determinism bug (a drifting
 * `AdminUiHelper::getAdminClientCacheKeys()` fingerprint). `Piwigo\Db\
 * LastModifiedListener` (entity-flush writes) plus explicit `Env::now()`
 * sets added at every DQL/`BatchWriter` write site now own this column
 * exclusively -- see that class and its own call sites for the full
 * write-path audit this migration's removal depends on.
 *
 * `DEFAULT CURRENT_TIMESTAMP`/`DEFAULT now()` (the INSERT-time default) is
 * deliberately left untouched on both platforms -- no bug was found in any
 * insert path, and auditing every raw/`BatchWriter` bulk insert against
 * these 5 tables for its own sake is out of scope here.
 *
 * The `groups`/`tags` column comments ("...set on insert only") are also
 * corrected to match every table's now-identical
 * `Env::now()`-on-every-write behavior.
 */
final class Version20260813150000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Drop ON UPDATE CURRENT_TIMESTAMP on lastmodified for categories/groups/images/tags/user_infos';
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
            'Cannot recover which rows would have been auto-bumped by ON UPDATE CURRENT_TIMESTAMP.'
        );
    }

    private function upMysql(): void
    {
        $this->addSql("ALTER TABLE `categories` MODIFY `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'row last-update timestamp'");
        $this->addSql("ALTER TABLE `groups` MODIFY `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'row last-update timestamp'");
        $this->addSql("ALTER TABLE `images` MODIFY `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'row last-update timestamp'");
        $this->addSql("ALTER TABLE `tags` MODIFY `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'row last-update timestamp'");
        $this->addSql("ALTER TABLE `user_infos` MODIFY `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'row last-update timestamp'");
    }

    private function upPostgres(): void
    {
        $this->addSql('DROP TRIGGER trg_categories_lastmodified ON categories');
        $this->addSql('DROP TRIGGER trg_groups_lastmodified ON groups');
        $this->addSql('DROP TRIGGER trg_images_lastmodified ON images');
        $this->addSql('DROP TRIGGER trg_tags_lastmodified ON tags');
        $this->addSql('DROP TRIGGER trg_user_infos_lastmodified ON user_infos');
        // Nothing else uses it once all 5 triggers above are gone.
        $this->addSql('DROP FUNCTION set_lastmodified()');

        $this->addSql("COMMENT ON COLUMN groups.lastmodified IS 'row last-update timestamp'");
        $this->addSql("COMMENT ON COLUMN tags.lastmodified IS 'row last-update timestamp'");
    }
}
