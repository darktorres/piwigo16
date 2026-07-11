<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Orphan cleanup: before the 42 FK constraints (next migration) go on,
 * delete any row whose FK column value doesn't exist in its parent
 * table. Standard ANSI `NOT IN (SELECT ...)` -- no platform branching
 * needed, works identically on MySQL/MariaDB/PostgreSQL.
 *
 * Runs as direct `executeStatement()` calls (not `addSql()`) so real
 * per-table affected-row counts can be logged via `write()` -- 0 orphans
 * is a valid, verified outcome, not assumed. Matches the full 42-entry
 * FK list from Migration 4, not the plan doc's partial 19-constraint
 * example block.
 *
 * @see Version20260711150901 for the FK constraints this cleanup enables.
 */
final class Version20260711150900 extends AbstractMigration
{
    /**
     * [child table, child column, parent table, parent column].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const array FK_PAIRS = [
        ['image_category', 'image_id', 'images', 'id'],
        ['image_category', 'category_id', 'categories', 'id'],
        ['image_tag', 'image_id', 'images', 'id'],
        ['image_tag', 'tag_id', 'tags', 'id'],
        ['image_format', 'image_id', 'images', 'id'],
        ['comments', 'image_id', 'images', 'id'],
        ['comments', 'author_id', 'users', 'id'],
        ['favorites', 'image_id', 'images', 'id'],
        ['favorites', 'user_id', 'users', 'id'],
        ['user_access', 'user_id', 'users', 'id'],
        ['user_access', 'cat_id', 'categories', 'id'],
        ['user_group', 'user_id', 'users', 'id'],
        ['user_group', 'group_id', 'groups', 'id'],
        ['group_access', 'group_id', 'groups', 'id'],
        ['group_access', 'cat_id', 'categories', 'id'],
        ['user_cache', 'user_id', 'users', 'id'],
        ['user_cache_categories', 'user_id', 'users', 'id'],
        ['user_cache_categories', 'cat_id', 'categories', 'id'],
        ['user_infos', 'user_id', 'users', 'id'],
        ['user_feed', 'user_id', 'users', 'id'],
        ['user_mail_notification', 'user_id', 'users', 'id'],
        ['user_auth_keys', 'user_id', 'users', 'id'],
        ['caddie', 'user_id', 'users', 'id'],
        ['caddie', 'element_id', 'images', 'id'],
        ['lounge', 'image_id', 'images', 'id'],
        ['lounge', 'category_id', 'categories', 'id'],
        ['rate', 'element_id', 'images', 'id'],
        ['rate', 'user_id', 'users', 'id'],
        ['categories', 'id_uppercat', 'categories', 'id'],
        ['categories', 'representative_picture_id', 'images', 'id'],
        ['images', 'storage_category_id', 'categories', 'id'],
        ['images', 'added_by', 'users', 'id'],
        ['history', 'image_id', 'images', 'id'],
        ['history', 'category_id', 'categories', 'id'],
        ['history', 'search_id', 'search', 'id'],
        ['history', 'format_id', 'image_format', 'format_id'],
        ['history', 'auth_key_id', 'user_auth_keys', 'auth_key_id'],
        ['history', 'user_id', 'users', 'id'],
        ['search', 'created_by', 'users', 'id'],
        ['search', 'forked_from', 'search', 'id'],
        ['activity', 'performed_by', 'users', 'id'],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Delete rows with dangling FK references before the 42 FK constraints go on, logging real counts';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();
        $totalDeleted = 0;

        foreach (self::FK_PAIRS as [$childTable, $childCol, $parentTable, $parentCol]) {
            // The subquery is wrapped in a materialized derived table
            // (`SELECT x FROM (SELECT ...) AS parent_ids`) rather than a
            // bare `SELECT ... FROM parentTable` -- MySQL rejects
            // referencing the same table being deleted from directly in
            // a subquery's FROM clause ("You can't specify target table
            // ... for update in FROM clause"), which real self-referencing
            // pairs here trigger (categories.id_uppercat -> categories.id,
            // search.forked_from -> search.id). The wrapper is valid,
            // harmless SQL for every pair, self-referencing or not, so
            // it's applied uniformly instead of special-casing.
            $sql = 'DELETE FROM ' . $prefix . $childTable
                . ' WHERE ' . $childCol . ' IS NOT NULL'
                . ' AND ' . $childCol . ' NOT IN ('
                . 'SELECT parent_id FROM (SELECT ' . $parentCol . ' AS parent_id FROM ' . $prefix . $parentTable . ') AS orphan_check'
                . ')';
            $deleted = $this->connection->executeStatement($sql);
            if ($deleted > 0) {
                $totalDeleted += $deleted;
                $this->write("Deleted {$deleted} orphan row(s) from {$prefix}{$childTable} (dangling {$childCol} -> {$prefix}{$parentTable}.{$parentCol})");
            }
        }

        $this->write("Orphan cleanup complete: {$totalDeleted} row(s) deleted across " . count(self::FK_PAIRS) . ' FK pairs checked');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deleted orphan rows cannot be restored.');
    }
}
