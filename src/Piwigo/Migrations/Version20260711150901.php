<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * 41 of the 42 real, working FK constraints -- pulled from the
 * reference's actual `install/piwigo_structure-mysql.sql`, not the plan
 * doc's partial ~19-constraint "representative" example block (which
 * omits `favorites`, `user_group`, `group_access`, `user_cache*`,
 * `user_feed`, `user_mail_notification`, `user_auth_keys`, `caddie`,
 * `lounge`, `rate`, `search`, `activity`, and both
 * `images.storage_category_id`/`added_by`). Cascade rules also follow
 * the reference exactly -- notably `images.storage_category_id` is
 * `ON DELETE SET NULL` there, not the doc's stated `RESTRICT` ("can't
 * delete album with physical photos"): the real system doesn't enforce
 * that as a hard DB constraint, likely because doing so would block
 * category deletion without corresponding service-layer support that
 * doesn't exist yet.
 *
 * The 42nd (`fk_user_failed_logins_user_id`) is added by the next
 * migration instead -- `user_failed_logins` doesn't exist until then.
 *
 * Uses DBAL's portable Schema API (not raw SQL) -- FK constraints are
 * exactly what it's designed to express portably; the diff against
 * `$schema` (introspected from the live DB) emits correct
 * platform-specific `ALTER TABLE ... ADD CONSTRAINT` DDL on MySQL,
 * MariaDB, and PostgreSQL from the same code.
 */
final class Version20260711150901 extends AbstractMigration
{
    /**
     * [child table, child column(s), parent table, parent column(s), onDelete, constraint name].
     *
     * @var list<array{0: string, 1: list<string>, 2: string, 3: list<string>, 4: string, 5: string}>
     */
    private const array FOREIGN_KEYS = [
        ['image_category', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_image_category_image_id'],
        ['image_category', ['category_id'], 'categories', ['id'], 'CASCADE', 'fk_image_category_category_id'],
        ['image_tag', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_image_tag_image_id'],
        ['image_tag', ['tag_id'], 'tags', ['id'], 'CASCADE', 'fk_image_tag_tag_id'],
        ['image_format', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_image_format_image_id'],
        ['comments', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_comments_image_id'],
        ['comments', ['author_id'], 'users', ['id'], 'SET NULL', 'fk_comments_author_id'],
        ['favorites', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_favorites_image_id'],
        ['favorites', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_favorites_user_id'],
        ['user_access', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_access_user_id'],
        ['user_access', ['cat_id'], 'categories', ['id'], 'CASCADE', 'fk_user_access_cat_id'],
        ['user_group', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_group_user_id'],
        ['user_group', ['group_id'], 'groups', ['id'], 'CASCADE', 'fk_user_group_group_id'],
        ['group_access', ['group_id'], 'groups', ['id'], 'CASCADE', 'fk_group_access_group_id'],
        ['group_access', ['cat_id'], 'categories', ['id'], 'CASCADE', 'fk_group_access_cat_id'],
        ['user_cache', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_cache_user_id'],
        ['user_cache_categories', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_cache_categories_user_id'],
        ['user_cache_categories', ['cat_id'], 'categories', ['id'], 'CASCADE', 'fk_user_cache_categories_cat_id'],
        ['user_infos', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_infos_user_id'],
        ['user_feed', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_feed_user_id'],
        ['user_mail_notification', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_mail_notification_user_id'],
        ['user_auth_keys', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_user_auth_keys_user_id'],
        // fk_user_failed_logins_user_id is NOT here -- user_failed_logins
        // doesn't exist until the next migration (Version20260711150903)
        // creates it; that migration adds its own FK constraint directly
        // right after creating the table, avoiding a create-then-alter
        // ordering dependency between the two migrations.
        ['caddie', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_caddie_user_id'],
        ['caddie', ['element_id'], 'images', ['id'], 'CASCADE', 'fk_caddie_element_id'],
        ['lounge', ['image_id'], 'images', ['id'], 'CASCADE', 'fk_lounge_image_id'],
        ['lounge', ['category_id'], 'categories', ['id'], 'CASCADE', 'fk_lounge_category_id'],
        ['rate', ['element_id'], 'images', ['id'], 'CASCADE', 'fk_rate_element_id'],
        ['rate', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_rate_user_id'],
        ['categories', ['id_uppercat'], 'categories', ['id'], 'SET NULL', 'fk_categories_id_uppercat'],
        ['categories', ['representative_picture_id'], 'images', ['id'], 'SET NULL', 'fk_categories_representative_picture_id'],
        ['images', ['storage_category_id'], 'categories', ['id'], 'SET NULL', 'fk_images_storage_category_id'],
        ['images', ['added_by'], 'users', ['id'], 'SET NULL', 'fk_images_added_by'],
        ['history', ['image_id'], 'images', ['id'], 'SET NULL', 'fk_history_image_id'],
        ['history', ['category_id'], 'categories', ['id'], 'SET NULL', 'fk_history_category_id'],
        ['history', ['search_id'], 'search', ['id'], 'SET NULL', 'fk_history_search_id'],
        ['history', ['format_id'], 'image_format', ['format_id'], 'SET NULL', 'fk_history_format_id'],
        ['history', ['auth_key_id'], 'user_auth_keys', ['auth_key_id'], 'SET NULL', 'fk_history_auth_key_id'],
        ['history', ['user_id'], 'users', ['id'], 'CASCADE', 'fk_history_user_id'],
        ['search', ['created_by'], 'users', ['id'], 'SET NULL', 'fk_search_created_by'],
        ['search', ['forked_from'], 'search', ['id'], 'SET NULL', 'fk_search_forked_from'],
        ['activity', ['performed_by'], 'users', ['id'], 'SET NULL', 'fk_activity_performed_by'],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Add 41 of the 42 real FK constraints (reference-verified list, not the plan doc\'s partial example block)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        foreach (self::FOREIGN_KEYS as [$childTable, $localCols, $parentTable, $foreignCols, $onDelete, $name]) {
            $table = $schema->getTable($prefix . $childTable);
            if ($table->hasForeignKey($name)) {
                continue;
            }
            $table->addForeignKeyConstraint(
                $prefix . $parentTable,
                $localCols,
                $foreignCols,
                [
                    'onDelete' => $onDelete,
                ],
                $name,
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping these FK constraints is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
