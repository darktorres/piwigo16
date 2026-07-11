<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Descending indexes for the two most common sort orders (doc explicitly
 * places these in the P15 engine+charset batch as "pure index
 * additions"): `images(date_available DESC, id DESC)` for the gallery's
 * default photo ordering, `history(date DESC, id DESC)` for history
 * pages.
 *
 * DBAL's Schema/Index API has no portable column-direction option --
 * genuinely one of the cases the Schema API can't express, per the P15
 * plan's own stated exception. `CREATE INDEX ... (col DESC, col2 DESC)`
 * is identical syntax on MySQL 8+/MariaDB and PostgreSQL, so no platform
 * branching is needed here despite being raw SQL.
 *
 * Also the 4 FULLTEXT indexes `include/functions_search.inc.php`'s
 * qsearch already needs TODAY, not a later phase -- found empirically,
 * not planned: the P15 plan originally deferred FULLTEXT to whichever
 * phase builds SearchService, reasoning from the reference's real
 * history (FULLTEXT landed in a separate, later commit, `220ac16a0`,
 * tagged F11-b). That reasoning was wrong -- `220ac16a0`'s own message
 * says it's a *bug fix*: qsearch's `MATCH(<cols>) AGAINST(...)` calls
 * already exist in the current legacy code, and MySQL's InnoDB rejects
 * them outright ("Can't find FULLTEXT index matching the column list")
 * once a table has no FULLTEXT index -- confirmed by reproducing
 * `tests/Browser/SearchTest.php`'s failure directly against a real
 * server, then proving the identical query succeeds against the
 * *original, un-migrated* MyISAM schema. The engine conversion migration
 * earlier in this same batch is what turns a previously-dormant gap into
 * an active regression, so the fix has to land in the same phase, not a
 * later one. Exact 4 indexes (columns and names) match the reference's
 * own real, tested fix. MySQL/MariaDB only -- qsearch is legacy
 * procedural code running exclusively through the mysqli dblayer, never
 * against a PostgreSQL connection, so this is skipped there, not
 * platform-branched.
 */
final class Version20260711150902 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Descending indexes (images/history) + the 4 FULLTEXT indexes qsearch needs (images, tags, categories)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        $this->addSql('CREATE INDEX idx_images_date_desc ON ' . $prefix . 'images (date_available DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_history_date_desc ON ' . $prefix . 'history (date DESC, id DESC)');

        if ($this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE ' . $prefix . 'images ADD FULLTEXT INDEX images_ft_name_comment (name, comment)');
        $this->addSql('ALTER TABLE ' . $prefix . 'images ADD FULLTEXT INDEX images_ft_author (author)');
        $this->addSql('ALTER TABLE ' . $prefix . 'tags ADD FULLTEXT INDEX tags_ft_name (name)');
        $this->addSql('ALTER TABLE ' . $prefix . 'categories ADD FULLTEXT INDEX categories_ft_name_comment (name, comment)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping these indexes is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
