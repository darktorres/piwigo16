<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Engine + charset conversion for all 34 origin tables:
 * `ENGINE=MyISAM` (no charset declared) -> `ENGINE=InnoDB DEFAULT
 * CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. No-op on PostgreSQL, which
 * has no storage-engine concept and is UTF8-native at the database level.
 *
 * The 9 `varchar(N) binary` columns (categories.permalink, images.file,
 * old_permalinks.permalink, plugins.id, sessions.id, tags.url_name,
 * user_feed.id, user_mail_notification.check_key, users.username) are
 * fixed to an explicit `COLLATE utf8mb4_bin` in a SEPARATE `ALTER TABLE`
 * statement immediately after their table's charset conversion -- this is
 * NOT part of the 43 column-type changes deferred to P17-23. The legacy
 * `binary` column attribute is a charset-relative shorthand (pre-utf8mb4
 * MySQL 4.x syntax): the moment a table's charset changes, those columns
 * MUST be given an explicit collation or they silently become
 * case-insensitive utf8mb4_unicode_ci comparisons -- a real regression
 * (e.g. a case-insensitive users.username breaks the uniqueness guarantee
 * between "Alice" and "alice"; a case-insensitive sessions.id is a
 * genuine session-token comparison weakness).
 *
 * **Docs/PLAN-REPLAY-AUDIT.md gap-closure finding, 2026-07-23:** an
 * earlier version of this migration combined both clauses into ONE
 * `ALTER TABLE ... ENGINE=InnoDB, CONVERT TO CHARACTER SET ..., MODIFY
 * ... COLLATE utf8mb4_bin ...` statement (matching what this class's own
 * comment described as "mechanically inseparable," and what looked like
 * the reference's own equivalent) -- confirmed live, against a real
 * MySQL 8 instance, that this does NOT work: `CONVERT TO CHARACTER SET`
 * silently wins over the same statement's own column-specific `MODIFY
 * ... COLLATE`, leaving every one of the 9 columns at
 * `utf8mb4_unicode_ci` regardless. Every one of the 9 columns was
 * affected, undetected until now because Doctrine's abstract `Types::`
 * system has no concept of collation at all -- a broken and a correct
 * column both report as `Types::STRING`, so no type-level check could
 * have caught this. Splitting the two `ALTER TABLE` statements (this
 * class's own two-`addSql()`-calls-per-table shape below) is the
 * confirmed-working fix.
 */
final class Version20260711150858 extends AbstractMigration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'activity', 'caddie', 'categories', 'comments', 'config', 'favorites',
        'group_access', 'groups', 'history', 'history_summary', 'image_category',
        'image_format', 'image_tag', 'images', 'languages', 'lounge',
        'old_permalinks', 'plugins', 'rate', 'search', 'sessions', 'sites',
        'tags', 'themes', 'upgrade', 'user_access', 'user_auth_keys',
        'user_cache', 'user_cache_categories', 'user_feed', 'user_group',
        'user_infos', 'user_mail_notification', 'users',
    ];

    /**
     * table => full MODIFY COLUMN clause (charset-relative `binary`
     * shorthand -> explicit utf8mb4_bin), applied in the same ALTER as
     * the table's own charset conversion.
     *
     * @var array<string, string>
     */
    private const array BINARY_COLUMN_FIXES = [
        'categories' => 'MODIFY `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL',
        'images' => "MODIFY `file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'old_permalinks' => "MODIFY `permalink` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'plugins' => "MODIFY `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'sessions' => "MODIFY `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'tags' => "MODIFY `url_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'user_feed' => "MODIFY `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'user_mail_notification' => "MODIFY `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        'users' => "MODIFY `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Engine+charset: all 34 origin tables -> InnoDB + utf8mb4/utf8mb4_unicode_ci (no-op on PostgreSQL)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        $prefix = Config::dbPrefix();
        foreach (self::TABLES as $table) {
            $tableName = $prefix . $table;
            $this->addSql('ALTER TABLE `' . $tableName . '` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            if (isset(self::BINARY_COLUMN_FIXES[$table])) {
                $this->addSql('ALTER TABLE `' . $tableName . '` ' . self::BINARY_COLUMN_FIXES[$table]);
            }
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reverting to MyISAM is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
