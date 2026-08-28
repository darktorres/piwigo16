<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use LogicException;
use Override;

/**
 * [P58-A] Repairs config rows that hold the JSON *string* `"true"`/`"false"`
 * where a bare JSON boolean belongs.
 *
 * `Controller\Admin\ConfigurationSubController` normalized every checkbox on
 * its main/comments/display tabs to the PHP strings `'true'`/`'false'`, and
 * `Controller\Admin\NotificationByMailSubController` passed its own radio
 * pairs' `'true'`/`'false'` values through verbatim. Both saved through
 * `ConfigService::confUpdateParam()`, which `json_encode()`s --
 * so the column got `"true"`, quoted. `ConfigService::hydrate()` resolves a
 * `bool`-typed `CurrentConfig` property through
 * `is_bool($decoded) ? $decoded : false`, and a string is not a bool: every
 * one of these settings read back as **false** after being saved, whichever
 * way the admin had set it. Saving a tab silently turned its whole checkbox
 * list off.
 *
 * (The sibling write path was always correct and is untouched:
 * `ConfigRepository::massUpdateValues()` writes the column raw, so
 * `UploadService`'s own `'true'`/`'false'` strings land unquoted and decode
 * as booleans.)
 *
 * `index_search_in_set_action` is in the list for a different reason: it was
 * *seeded* as `"true"` and its property was declared `string`, so it read
 * back as the truthy string `'true'` and its feature could never be switched
 * off. Its property is a real `bool` as of this same change.
 *
 * The param list is written out rather than derived. A migration is a
 * snapshot of what was wrong when it ran; deriving the list from
 * `ConfigurationSubController` would make this file's behaviour change
 * whenever that class does.
 */
final class Version20260828120000 extends AbstractMigration
{
    /**
     * The three tabs' checkbox lists as they stood at
     * `ConfigurationSubController`'s own `$main_checkboxes`,
     * `$comments_checkboxes` and `$display_checkboxes`, plus the three
     * `bool`-typed radio params `NotificationByMailSubController` saved the
     * same way.
     */
    private const array BOOLEAN_PARAMS = [
        'activate_comments',
        'allow_user_customization',
        'allow_user_registration',
        'comments_author_mandatory',
        'comments_email_mandatory',
        'comments_enable_website',
        'comments_forall',
        'comments_validation',
        'display_fromto',
        'email_admin_on_comment',
        'email_admin_on_comment_deletion',
        'email_admin_on_comment_edition',
        'email_admin_on_comment_validation',
        'history_admin',
        'history_guest',
        'index_caddie_icon',
        'index_created_date_icon',
        'index_edit_icon',
        'index_flat_icon',
        'index_new_icon',
        'index_posted_date_icon',
        'index_search_in_set_action',
        'index_search_in_set_button',
        'index_sizes_icon',
        'index_slideshow_icon',
        'index_sort_order_input',
        'log',
        'menubar_filter_icon',
        'nbm_send_detailed_content',
        'nbm_send_html_mail',
        'nbm_send_recent_post_dates',
        'obligatory_user_mail_address',
        'picture_caddie_icon',
        'picture_download_icon',
        'picture_edit_icon',
        'picture_favorite_icon',
        'picture_menu',
        'picture_metadata_icon',
        'picture_navigation_icons',
        'picture_navigation_thumb',
        'picture_representative_icon',
        'picture_sizes_icon',
        'picture_slideshow_icon',
        'rate',
        'rate_anonymous',
        'show_mobile_app_banner_in_admin',
        'show_mobile_app_banner_in_gallery',
        'upload_detect_duplicate',
        'user_can_delete_comment',
        'user_can_edit_comment',
    ];

    #[Override]
    public function getDescription(): string
    {
        return '[P58-A] Repair config rows storing "true"/"false" as JSON strings instead of booleans';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->addSql($this->postgresSql('true'));
            $this->addSql($this->postgresSql('false'));

            return;
        }

        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql($this->mysqlSql('true'));
            $this->addSql($this->mysqlSql('false'));

            return;
        }

        if ($this->platform instanceof SQLitePlatform) {
            $this->addSql($this->sqliteSql('true'));
            $this->addSql($this->sqliteSql('false'));

            return;
        }

        throw new LogicException(self::class . ' has no migration path for platform ' . $this->platform::class);
    }

    /**
     * Deliberately empty. The string form was never correct for these
     * params -- it is what made the settings unreadable -- so there is no
     * prior state worth restoring, and re-quoting them would reintroduce
     * the bug.
     */
    #[Override]
    public function down(Schema $schema): void {}

    /**
     * `config.value` is a real `json` column on MySQL and `jsonb` on
     * Postgres, so `value = '"true"'` does NOT match a stored JSON string:
     * the literal is parsed as JSON on one side and the comparison is
     * between JSON values, not text. Each platform below asks the question
     * in its own type system -- "is this a JSON string, and does it read
     * 'true'" -- rather than comparing raw text. Found the hard way: the
     * text-comparison version ran clean and changed nothing.
     */
    private function mysqlSql(string $word): string
    {
        return 'UPDATE config SET value = CAST(\'' . $word . '\' AS JSON)'
            . ' WHERE param IN (' . self::paramList() . ')'
            . " AND JSON_TYPE(value) = 'STRING'"
            . " AND JSON_UNQUOTE(value) = '" . $word . "'";
    }

    private function postgresSql(string $word): string
    {
        return "UPDATE config SET value = '" . $word . "'::jsonb"
            . ' WHERE param IN (' . self::paramList() . ')'
            . " AND jsonb_typeof(value) = 'string'"
            . " AND value #>> '{}' = '" . $word . "'";
    }

    /**
     * SQLite has no JSON column type -- the value is stored as the literal
     * text `"true"`, quotes included, so a plain text comparison is the
     * right question there.
     */
    private function sqliteSql(string $word): string
    {
        return "UPDATE config SET value = '" . $word . "'"
            . ' WHERE param IN (' . self::paramList() . ')'
            . " AND value = '\"" . $word . "\"'";
    }

    private static function paramList(): string
    {
        return implode(', ', array_map(static fn (string $p): string => "'" . $p . "'", self::BOOLEAN_PARAMS));
    }
}
