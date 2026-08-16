<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Drops `search_filter_view`. Its own committed schema comment already
 * says why: "named, reusable saved search-filter presets, unused: not read
 * or written by any repository or service in this codebase" -- confirmed
 * again here, zero references anywhere in `src/` outside migrations, no
 * Doctrine entity ever mapped it, and no foreign key references it.
 *
 * Same `DROP TABLE` on both platforms -- nothing in this table's shape
 * (`name`/`config_json`/`created_at`, PK on `name`) is platform-specific.
 */
final class Version20260816000000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Drop the unused search_filter_view table';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE search_filter_view');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Recreating an empty table loses no data (the table was never read or written), but restoring it would '
            . 'undo the point of this migration -- confirming it back into existence as something that looks live.'
        );
    }
}
