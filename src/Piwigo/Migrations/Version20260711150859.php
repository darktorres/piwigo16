<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Type alignment for FK compatibility -- fixes the 5 real signed/unsigned
 * and nullability mismatches found by systematically checking all 42 real
 * FK pairs (see the reference's `50c3dbcb8`/`2865c3cc2` commits for 3 of
 * these; `caddie.element_id` and re-verification of the other 2 against
 * the *current* origin schema, not assumed identical to the reference,
 * found the same set). InnoDB rejects FK declarations across
 * signed/unsigned mismatches and NOT NULL -> nullable parent mismatches:
 *
 * - history.category_id, history.image_id: gain `unsigned` (parent
 *   categories.id/images.id are both unsigned).
 * - caddie.element_id: gains `unsigned` (parent images.id is unsigned).
 * - images.added_by, activity.performed_by: become nullable (both FKs
 *   are `ON DELETE SET NULL`, which requires a nullable child column).
 *
 * PostgreSQL has no unsigned integer type -- DBAL silently drops the
 * property -- so the 3 columns gaining `unsigned` get an explicit
 * `CHECK (col >= 0)` there instead, per the doc's own stated type
 * mapping. The 2 nullability changes apply identically on every
 * platform.
 */
final class Version20260711150859 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Type-align 5 FK-compat columns: history.category_id/image_id + caddie.element_id (unsigned), images.added_by + activity.performed_by (nullable)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "' . $prefix . 'history" ADD CONSTRAINT chk_history_category_id_unsigned CHECK (category_id IS NULL OR category_id >= 0)');
            $this->addSql('ALTER TABLE "' . $prefix . 'history" ADD CONSTRAINT chk_history_image_id_unsigned CHECK (image_id IS NULL OR image_id >= 0)');
            $this->addSql('ALTER TABLE "' . $prefix . 'caddie" ADD CONSTRAINT chk_caddie_element_id_unsigned CHECK (element_id >= 0)');
            $this->addSql('ALTER TABLE "' . $prefix . 'images" ALTER COLUMN added_by DROP NOT NULL');
            $this->addSql('ALTER TABLE "' . $prefix . 'activity" ALTER COLUMN performed_by DROP NOT NULL');

            return;
        }

        $this->addSql('ALTER TABLE `' . $prefix . 'history` MODIFY `category_id` smallint(5) unsigned DEFAULT NULL');
        $this->addSql('ALTER TABLE `' . $prefix . 'history` MODIFY `image_id` mediumint(8) unsigned DEFAULT NULL');
        $this->addSql('ALTER TABLE `' . $prefix . "caddie` MODIFY `element_id` mediumint(8) unsigned NOT NULL DEFAULT '0'");
        $this->addSql('ALTER TABLE `' . $prefix . 'images` MODIFY `added_by` mediumint(8) unsigned DEFAULT NULL');
        $this->addSql('ALTER TABLE `' . $prefix . 'activity` MODIFY `performed_by` mediumint(8) unsigned DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reverting these FK-prerequisite type fixes is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
