<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Category domain slice of the 43 column-type changes deferred to P17-23:
 * 2 of the 11 "enum->tinyint" boolean columns (`categories.commentable`,
 * `categories.visible`) and 1 of the 6 "TIMESTAMP NOT NULL additions"
 * (`categories.lastmodified`).
 *
 * `commentable`/`visible` are `enum('true','false')` today -- a
 * MySQL-string boolean, not a real boolean type. Every write site across
 * the codebase (CategoryService::createVirtualCategory(),
 * CategoryRepository::updateCategoryVisibility(), the new
 * updateCategoryCommentable(), Controller\Admin\SiteUpdateSubController's
 * filesystem-sync category creation) already produces/expects a real PHP
 * `bool` one layer up and only converts to the 'true'/'false' string
 * immediately before writing -- confirmed by reading every real
 * consumer, not assumed. Every raw-SQL `= 'true'`/`= 'false'` literal
 * comparison across the codebase (Admin\CatOptionsPageRenderer's 4
 * bulk-select queries, Ws\PwgImages::addComment()'s commentable check,
 * Ws\PwgCategories::setInfo()'s no-op-change guard) is updated to compare
 * against `1`/`0` in the same commit -- left as string literals, MySQL's
 * non-numeric-string-to-int coercion silently maps BOTH 'true' and
 * 'false' to `0`, which would invert or break every one of these checks
 * without a single query erroring.
 *
 * `lastmodified` gains NOT NULL for the same reason as the Image domain's
 * identical change (its own `DEFAULT CURRENT_TIMESTAMP` is untouched,
 * every write site already supplies it explicitly via `Env::now()`, and
 * the live test DB has zero existing NULL rows).
 *
 * MySQL-specific danger, found live by testing the naive single-statement
 * `MODIFY commentable tinyint(1)` against a real MySQL 8 table first:
 * MySQL converts an `enum` column to a numeric type by its internal
 * ORDINAL INDEX (1-based), not by the string value -- `enum('true',
 * 'false')`'s ordinal for 'false' is **2**, not 0. A naive MODIFY would
 * silently turn every locked/non-commentable category's `false` into the
 * truthy value `2` instead of `0`, unlocking every privacy-locked album.
 * Confirmed empirically (`SELECT` before/after against a real MySQL
 * instance) before writing the safe fix below: add a real tinyint column,
 * populate it via a STRING comparison against the still-enum original
 * column (`commentable = 'true'`, which compares by value, not ordinal),
 * then drop the original and rename the new column into its place.
 */
final class Version20260723222502 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Category domain: commentable/visible enum->tinyint, lastmodified -> NOT NULL';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN commentable DROP DEFAULT');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN commentable TYPE smallint USING (commentable = \'true\')::int');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN commentable SET DEFAULT 1');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN visible DROP DEFAULT');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN visible TYPE smallint USING (visible = \'true\')::int');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN visible SET DEFAULT 1');
            $this->addSql('ALTER TABLE "' . $prefix . 'categories" ALTER COLUMN lastmodified SET NOT NULL');

            return;
        }

        $table = '`' . $prefix . 'categories`';

        $this->addSql('ALTER TABLE ' . $table . ' ADD COLUMN `commentable_tmp` tinyint(1) NOT NULL DEFAULT 1 AFTER `uppercats`');
        $this->addSql('UPDATE ' . $table . ' SET `commentable_tmp` = (`commentable` = \'true\')');
        $this->addSql('ALTER TABLE ' . $table . ' DROP COLUMN `commentable`');
        $this->addSql('ALTER TABLE ' . $table . ' CHANGE COLUMN `commentable_tmp` `commentable` tinyint(1) NOT NULL DEFAULT 1 AFTER `uppercats`');

        $this->addSql('ALTER TABLE ' . $table . ' ADD COLUMN `visible_tmp` tinyint(1) NOT NULL DEFAULT 1 AFTER `site_id`');
        $this->addSql('UPDATE ' . $table . ' SET `visible_tmp` = (`visible` = \'true\')');
        $this->addSql('ALTER TABLE ' . $table . ' DROP COLUMN `visible`');
        $this->addSql('ALTER TABLE ' . $table . ' CHANGE COLUMN `visible_tmp` `visible` tinyint(1) NOT NULL DEFAULT 1 AFTER `site_id`');

        $this->addSql('ALTER TABLE ' . $table . ' MODIFY `lastmodified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reverting to the enum(\'true\',\'false\') representation is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
