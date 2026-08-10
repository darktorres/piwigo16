<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Baseline bootstrap, final step: every FK constraint, added once all 39
 * tables from the 3 preceding migrations exist (matching `install/
 * piwigo_structure-mysql.sql`'s own "Foreign key constraints (added last,
 * once every table above exists)" section). `ALTER TABLE ... ADD
 * CONSTRAINT ... FOREIGN KEY ... REFERENCES ... ON DELETE ...` is
 * identical syntax on both platforms -- no real per-platform type
 * translation needed here, unlike every preceding migration in this set,
 * so the 40 FK definitions are declared once as shared data and rendered
 * per-platform (backtick-quoted for MySQL/MariaDB, unquoted for
 * Postgres), rather than duplicated as two independent statement lists.
 *
 * Each definition becomes its own `addSql()` call, not one batched
 * multi-statement string -- verified against `AbstractMigration::
 * addSql()`'s own source: it wraps the whole string as a single `Query`
 * object, executed as one statement later, so a `;`-separated batch of 40
 * `ALTER TABLE`s in one call would not reliably execute past the first
 * one (mysqli's default single-query execution path has no multi-
 * statement support to rely on here).
 */
final class Version20260804122303 extends AbstractMigration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private static function foreignKeys(): array
    {
        return [
            ['image_category', 'fk_image_category_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['image_category', 'fk_image_category_category_id', 'category_id', 'categories', 'id', 'CASCADE'],
            ['image_tag', 'fk_image_tag_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['image_tag', 'fk_image_tag_tag_id', 'tag_id', 'tags', 'id', 'CASCADE'],
            ['image_format', 'fk_image_format_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['comments', 'fk_comments_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['comments', 'fk_comments_author_id', 'author_id', 'users', 'id', 'SET NULL'],
            ['favorites', 'fk_favorites_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['favorites', 'fk_favorites_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_access', 'fk_user_access_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_access', 'fk_user_access_cat_id', 'cat_id', 'categories', 'id', 'CASCADE'],
            ['user_group', 'fk_user_group_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_group', 'fk_user_group_group_id', 'group_id', 'groups', 'id', 'CASCADE'],
            ['group_access', 'fk_group_access_group_id', 'group_id', 'groups', 'id', 'CASCADE'],
            ['group_access', 'fk_group_access_cat_id', 'cat_id', 'categories', 'id', 'CASCADE'],
            ['user_infos', 'fk_user_infos_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_feed', 'fk_user_feed_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_mail_notification', 'fk_user_mail_notification_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_auth_keys', 'fk_user_auth_keys_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['user_failed_logins', 'fk_user_failed_logins_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['caddie', 'fk_caddie_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['caddie', 'fk_caddie_element_id', 'element_id', 'images', 'id', 'CASCADE'],
            ['lounge', 'fk_lounge_image_id', 'image_id', 'images', 'id', 'CASCADE'],
            ['lounge', 'fk_lounge_category_id', 'category_id', 'categories', 'id', 'CASCADE'],
            ['rate', 'fk_rate_element_id', 'element_id', 'images', 'id', 'CASCADE'],
            ['rate', 'fk_rate_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['categories', 'fk_categories_id_uppercat', 'id_uppercat', 'categories', 'id', 'SET NULL'],
            ['categories', 'fk_categories_representative_picture_id', 'representative_picture_id', 'images', 'id', 'SET NULL'],
            ['images', 'fk_images_storage_category_id', 'storage_category_id', 'categories', 'id', 'SET NULL'],
            ['images', 'fk_images_added_by', 'added_by', 'users', 'id', 'SET NULL'],
            ['history', 'fk_history_image_id', 'image_id', 'images', 'id', 'SET NULL'],
            ['history', 'fk_history_category_id', 'category_id', 'categories', 'id', 'SET NULL'],
            ['history', 'fk_history_search_id', 'search_id', 'search', 'id', 'SET NULL'],
            ['history', 'fk_history_format_id', 'format_id', 'image_format', 'format_id', 'SET NULL'],
            ['history', 'fk_history_auth_key_id', 'auth_key_id', 'user_auth_keys', 'auth_key_id', 'SET NULL'],
            ['history', 'fk_history_user_id', 'user_id', 'users', 'id', 'CASCADE'],
            ['search', 'fk_search_created_by', 'created_by', 'users', 'id', 'SET NULL'],
            ['search', 'fk_search_forked_from', 'forked_from', 'search', 'id', 'SET NULL'],
            ['activity', 'fk_activity_performed_by', 'performed_by', 'users', 'id', 'SET NULL'],
            ['audit_log', 'fk_audit_log_actor_id', 'actor_id', 'users', 'id', 'SET NULL'],
        ];
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: every FK constraint across all 39 tables';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $isPostgres = $this->platform instanceof PostgreSQLPlatform;

        foreach (self::foreignKeys() as [$table, $constraintName, $column, $refTable, $refColumn, $onDelete]) {
            if ($isPostgres) {
                $this->addSql(
                    'ALTER TABLE ' . $table .
                    ' ADD CONSTRAINT ' . $constraintName .
                    ' FOREIGN KEY (' . $column . ') REFERENCES ' . $refTable . ' (' . $refColumn . ')' .
                    ' ON DELETE ' . $onDelete
                );

                continue;
            }

            $this->addSql(
                'ALTER TABLE `' . $table . '`' .
                ' ADD CONSTRAINT `' . $constraintName . '`' .
                ' FOREIGN KEY (`' . $column . '`) REFERENCES `' . $refTable . '` (`' . $refColumn . '`)' .
                ' ON DELETE ' . $onDelete
            );
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Baseline bootstrap has nothing to revert to but a blank database.'
        );
    }
}
