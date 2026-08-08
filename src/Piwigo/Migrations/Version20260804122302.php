<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;
use Piwigo\Db\DbCredentials;

/**
 * Baseline bootstrap, admin/system domain: config, activity, history,
 * history_summary, plugins, themes, languages, plugin_migrations,
 * extension_ignored_updates, integrity_ignored_anomalies, audit_log,
 * sites, search, search_filter_view, derivative_settings,
 * derivative_size -- the remaining 16 of the 39 tables. See
 * Version20260804122300's own docblock for the full translation-rule
 * rationale shared by every migration in this set.
 *
 * `derivative_size.enabled` stays a Postgres `smallint`, not `boolean`,
 * despite looking boolean-ish (`DEFAULT 1`) -- the source schema itself
 * chose `smallint` over the `tinyint(1)` convention it otherwise uses
 * consistently for real boolean flags elsewhere in this file, so this
 * migration preserves that same distinction rather than reinterpreting it.
 */
final class Version20260804122302 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: admin/system domain (config, activity, history, and 13 more)';
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
            'Baseline bootstrap has nothing to revert to but a blank database.'
        );
    }

    private function upMysql(): void
    {
        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_activity` (
              `activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `object` varchar(255) NOT NULL COMMENT 'entity type the action applies to, e.g. user, photo, album, tag, plugin',
              `object_id` int(11) unsigned NOT NULL COMMENT 'id of the affected object, or the target user id on a logout action',
              `action` varchar(255) NOT NULL COMMENT 'action verb, e.g. add, delete, login, logout, autoupdate',
              `performed_by` mediumint(8) unsigned DEFAULT NULL COMMENT 'acting user id, null for an unresolved or system actor',
              `session_idx` varchar(255) NOT NULL COMMENT 'PHP session id active during the request, or none if there was no session',
              `ip_address` varchar(50) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that triggered the action',
              `occured_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when the action was recorded',
              `details` JSON DEFAULT NULL COMMENT 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata',
              `user_agent` varchar(255) DEFAULT NULL COMMENT 'browser user agent string, only captured on login actions',
              PRIMARY KEY (`activity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='general activity log of user and system actions, distinct from the tamper-evident audit_log'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_config` (
              `param` varchar(40) NOT NULL default '' COMMENT 'configuration key',
              `value` JSON DEFAULT NULL COMMENT 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()',
              `comment` varchar(255) default NULL COMMENT 'human-readable description of the param, seeded for built-in settings by install/config.sql',
              PRIMARY KEY  (`param`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_history` (
              `id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `date` date default NULL COMMENT 'calendar date of the visit',
              `time` time NOT NULL default '00:00:00' COMMENT 'time of day of the visit',
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'visiting user id, the guest user id for anonymous visitors',
              `IP` char(39) NOT NULL default '' COMMENT 'REMOTE_ADDR of the request, truncated to fit an IPv6 address',
              `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') default NULL COMMENT 'gallery navigation view the visit occurred in, plugin-defined sections are appended to this enum automatically',
              `category_id` smallint(5) unsigned default NULL COMMENT 'album being viewed, set when section is a category-based view',
              `search_id` int(10) unsigned default NULL COMMENT 'search being viewed, set when section is search',
              `tag_ids` varchar(50) default NULL COMMENT 'comma-separated tag ids being viewed, set when section is tags, truncated to fit',
              `image_id` mediumint(8) unsigned default NULL COMMENT 'viewed image id, null for a listing/section page-view',
              `image_type` enum('picture','high','other') default NULL COMMENT 'size the image was viewed at',
              `format_id` int(11) unsigned default NULL COMMENT 'image_format row downloaded or viewed, when applicable',
              `auth_key_id` int(11) unsigned DEFAULT NULL COMMENT 'API auth key the request was authenticated with, if any',
              PRIMARY KEY  (`id`),
              KEY `idx_history_date_desc` (`date` DESC, `id` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-visit page-view log, periodically rolled up into history_summary'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_history_summary` (
              `summary_id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `year` smallint(4) NOT NULL default '0' COMMENT 'rollup year',
              `month` tinyint(2) default NULL COMMENT 'rollup month, null for a year-level summary row',
              `day` tinyint(2) default NULL COMMENT 'rollup day, null for a year- or month-level summary row',
              `hour` tinyint(2) default NULL COMMENT 'rollup hour, null for a year-, month-, or day-level summary row',
              `nb_pages` int(11) default NULL COMMENT 'number of history page-views folded into this summary row',
              `history_id_from` int(10) unsigned default NULL COMMENT 'lowest history.id folded into this summary row',
              `history_id_to` int(10) unsigned default NULL COMMENT 'highest history.id folded into this summary row, the next run resumes past this id',
              PRIMARY KEY (`summary_id`),
              UNIQUE KEY history_summary_ymdh (`year`,`month`,`day`,`hour`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='year/month/day/hour rollup of piwigo_history, one row per granularity level, letting old detail rows be purged'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_languages` (
              `id` varchar(64) NOT NULL default '' COMMENT 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed language pack version string',
              `name` varchar(64) default NULL COMMENT 'human-readable language display name',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active language packs, row deleted outright on deactivation'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_plugins` (
              `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'plugin directory-name identifier, row existence alone means installed, active or not',
              `state` enum('inactive','active') NOT NULL default 'inactive' COMMENT 'whether the installed plugin is currently active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed plugin version string',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed plugins and their active/inactive state'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_search` (
              `id` int(10) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `search_uuid` CHAR(23) DEFAULT NULL COMMENT 'public, shareable identifier for this saved search, used in URLs instead of id',
              `created_on` DATETIME DEFAULT NULL COMMENT 'when the search was saved',
              `created_by` MEDIUMINT(8) UNSIGNED COMMENT 'user id who saved the search, null for an anonymous search',
              `forked_from` INT(10) UNSIGNED COMMENT 'search this one was refined/derived from, null for an original search',
              `rules` JSON COMMENT 'encoded search criteria (query terms, filters) evaluated by SearchService',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='saved/shareable search queries'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_sites` (
              `id` tinyint(4) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key, referenced by categories.site_id',
              `galleries_url` varchar(255) NOT NULL default '' COMMENT 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `sites_ui1` (`galleries_url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='multi-site photo sources synchronized into albums'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_themes` (
              `id` varchar(64) NOT NULL default '' COMMENT 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed theme version string',
              `name` varchar(64) default NULL COMMENT 'human-readable theme display name',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active themes, row deleted outright on deactivation'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_derivative_settings` (
              `id` smallint NOT NULL COMMENT 'settings row identifier',
              `default_quality` int NOT NULL DEFAULT 95 COMMENT 'default JPEG compression quality, 0 to 100, for generated derivative images',
              `watermark_json` JSON NOT NULL COMMENT 'encoded watermark configuration',
              `custom_json` JSON NOT NULL COMMENT 'encoded custom derivative-generation parameters',
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_derivative_size` (
              `name` varchar(32) NOT NULL COMMENT 'derivative size name, e.g. thumb, medium, xxlarge',
              `enabled` smallint NOT NULL DEFAULT 1 COMMENT 'whether this derivative size is generated',
              `max_width` int NOT NULL DEFAULT 0 COMMENT 'maximum output width in pixels, see SizingParams',
              `max_height` int NOT NULL DEFAULT 0 COMMENT 'maximum output height in pixels, see SizingParams',
              `max_crop` decimal(5,4) NOT NULL DEFAULT 0 COMMENT 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop',
              `min_width` int DEFAULT NULL COMMENT 'minimum output width required to allow cropping, see SizingParams::min_size',
              `min_height` int DEFAULT NULL COMMENT 'minimum output height required to allow cropping, see SizingParams::min_size',
              `sharpen` decimal(5,4) NOT NULL DEFAULT 0 COMMENT 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen',
              `last_mod_time` int NOT NULL DEFAULT 0 COMMENT 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time',
              PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_extension_ignored_updates` (
              `extension_type` varchar(16) NOT NULL COMMENT 'plugin, theme, or language, see ExtensionType',
              `extension_id` varchar(64) NOT NULL COMMENT 'directory-name identifier of the extension whose update is being ignored',
              `ignored_at` DATETIME NOT NULL COMMENT 'when the update was dismissed',
              PRIMARY KEY (`extension_type`,`extension_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_integrity_ignored_anomalies` (
              `anomaly_id` varchar(64) NOT NULL COMMENT 'add_anomaly()-generated md5 id, see CheckIntegrity',
              `piwigo_version` varchar(16) NOT NULL COMMENT 'Piwigo version the anomaly was ignored under',
              `ignored_at` DATETIME NOT NULL COMMENT 'when the anomaly was dismissed',
              PRIMARY KEY (`anomaly_id`,`piwigo_version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_plugin_migrations` (
              `plugin_id` varchar(64) NOT NULL COMMENT 'directory-name identifier of the plugin that ran this migration',
              `version` varchar(191) NOT NULL COMMENT 'plugin-internal migration version identifier',
              `executed_at` DATETIME NOT NULL COMMENT 'when this plugin migration ran',
              PRIMARY KEY (`plugin_id`,`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_search_filter_view` (
              `name` varchar(64) NOT NULL COMMENT 'saved filter view name',
              `config_json` JSON NOT NULL COMMENT 'encoded search filter configuration',
              `created_at` DATETIME NOT NULL COMMENT 'when the filter view was saved',
              PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='named, reusable saved search-filter presets, unused: not read or written by any repository or service in this codebase'
            SQL));

        $this->addSql($this->withMysqlPrefix(<<<'SQL'
            CREATE TABLE `piwigo_audit_log` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `actor_id` mediumint(8) unsigned DEFAULT NULL COMMENT 'acting user id, null for an unattributed or system action',
              `action` varchar(64) NOT NULL COMMENT 'action verb, e.g. delete, grant, revoke',
              `entity_type` varchar(64) NOT NULL COMMENT 'audited entity type, e.g. group, permission',
              `entity_id` int(11) unsigned DEFAULT NULL COMMENT 'id of the audited entity, null when not applicable',
              `before_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded',
              `after_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded',
              `ip_address` varchar(45) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that performed the action',
              `created_at` DATETIME NOT NULL COMMENT 'when the action was recorded',
              `prev_hash` varchar(64) DEFAULT NULL COMMENT 'row_hash of the previous row, null for the first row, forms the hash chain',
              `row_hash` varchar(64) NOT NULL COMMENT 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash',
              PRIMARY KEY (`id`),
              KEY `idx_audit_log_entity` (`entity_type`, `entity_id`),
              KEY `idx_audit_log_actor` (`actor_id`),
              KEY `idx_audit_log_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SEC-57 append-only, hash-chained audit trail of admin actions and permission changes'
            SQL));
    }

    private function upPostgres(): void
    {
        $prefix = DbCredentials::fromEnv()->prefix;

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'activity (' .
            'activity_id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'object varchar(255) NOT NULL, ' .
            'object_id bigint NOT NULL, ' .
            'action varchar(255) NOT NULL, ' .
            'performed_by integer DEFAULT NULL, ' .
            'session_idx varchar(255) NOT NULL, ' .
            'ip_address varchar(50) DEFAULT NULL, ' .
            'occured_on timestamp(0) NOT NULL DEFAULT now(), ' .
            'details jsonb DEFAULT NULL, ' .
            'user_agent varchar(255) DEFAULT NULL, ' .
            'PRIMARY KEY (activity_id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}activity IS 'general activity log of user and system actions, distinct from the tamper-evident audit_log'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.activity_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.object IS 'entity type the action applies to, e.g. user, photo, album, tag, plugin'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.object_id IS 'id of the affected object, or the target user id on a logout action'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.action IS 'action verb, e.g. add, delete, login, logout, autoupdate'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.performed_by IS 'acting user id, null for an unresolved or system actor'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.session_idx IS 'PHP session id active during the request, or none if there was no session'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.ip_address IS 'REMOTE_ADDR of the request that triggered the action'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.occured_on IS 'when the action was recorded'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.details IS 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata'");
        $this->addSql("COMMENT ON COLUMN {$prefix}activity.user_agent IS 'browser user agent string, only captured on login actions'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'config (' .
            "param varchar(40) NOT NULL DEFAULT '', " .
            'value jsonb DEFAULT NULL, ' .
            'comment varchar(255) DEFAULT NULL, ' .
            'PRIMARY KEY (param))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}config IS 'configuration table'");
        $this->addSql("COMMENT ON COLUMN {$prefix}config.param IS 'configuration key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}config.value IS 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()'");
        $this->addSql("COMMENT ON COLUMN {$prefix}config.comment IS 'human-readable description of the param, seeded for built-in settings by install/config.sql'");

        // `section` deliberately has NO CHECK constraint, unlike every
        // other enum-shaped column in this schema -- real, found live
        // while auditing this table: `HistoryRepository::
        // getSectionEnumOptions()`/`alterSectionEnum()` widen the MySQL
        // native ENUM at runtime (`ALTER TABLE ... CHANGE section section
        // enum(...)`) whenever a plugin introduces a new section value
        // (`HistoryService`'s own real caller). A closed CHECK constraint
        // would reject exactly the plugin-extensibility this column
        // exists to support -- `image_type` right below has a real,
        // closed, core-only value set (no equivalent widening mechanism
        // exists for it), so it keeps its CHECK constraint.
        $this->addSql(
            'CREATE TABLE ' . $prefix . 'history (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'date date DEFAULT NULL, ' .
            "\"time\" time NOT NULL DEFAULT '00:00:00', " .
            'user_id integer NOT NULL, ' .
            "ip char(39) NOT NULL DEFAULT '', " .
            'section varchar(20) DEFAULT NULL, ' .
            'category_id integer DEFAULT NULL, ' .
            'search_id bigint DEFAULT NULL, ' .
            'tag_ids varchar(50) DEFAULT NULL, ' .
            'image_id integer DEFAULT NULL, ' .
            "image_type varchar(10) DEFAULT NULL CHECK (image_type IS NULL OR image_type IN ('picture', 'high', 'other')), " .
            'format_id bigint DEFAULT NULL, ' .
            'auth_key_id bigint DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX idx_history_date_desc ON ' . $prefix . 'history (date DESC, id DESC)');
        $this->addSql("COMMENT ON TABLE {$prefix}history IS 'per-visit page-view log, periodically rolled up into history_summary'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.date IS 'calendar date of the visit'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.\"time\" IS 'time of day of the visit'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.user_id IS 'visiting user id, the guest user id for anonymous visitors'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.ip IS 'REMOTE_ADDR of the request, truncated to fit an IPv6 address'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.section IS 'gallery navigation view the visit occurred in, plugin-defined sections are appended to this enum automatically'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.category_id IS 'album being viewed, set when section is a category-based view'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.search_id IS 'search being viewed, set when section is search'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.tag_ids IS 'comma-separated tag ids being viewed, set when section is tags, truncated to fit'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.image_id IS 'viewed image id, null for a listing/section page-view'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.image_type IS 'size the image was viewed at'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.format_id IS 'image_format row downloaded or viewed, when applicable'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history.auth_key_id IS 'API auth key the request was authenticated with, if any'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'history_summary (' .
            'summary_id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'year smallint NOT NULL DEFAULT 0, ' .
            'month smallint DEFAULT NULL, ' .
            'day smallint DEFAULT NULL, ' .
            'hour smallint DEFAULT NULL, ' .
            'nb_pages integer DEFAULT NULL, ' .
            'history_id_from bigint DEFAULT NULL, ' .
            'history_id_to bigint DEFAULT NULL, ' .
            'PRIMARY KEY (summary_id))'
        );
        $this->addSql('ALTER TABLE ' . $prefix . 'history_summary ADD CONSTRAINT history_summary_ymdh UNIQUE (year, month, day, hour)');
        $this->addSql("COMMENT ON TABLE {$prefix}history_summary IS 'year/month/day/hour rollup of piwigo_history, one row per granularity level, letting old detail rows be purged'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.summary_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.year IS 'rollup year'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.month IS 'rollup month, null for a year-level summary row'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.day IS 'rollup day, null for a year- or month-level summary row'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.hour IS 'rollup hour, null for a year-, month-, or day-level summary row'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.nb_pages IS 'number of history page-views folded into this summary row'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.history_id_from IS 'lowest history.id folded into this summary row'");
        $this->addSql("COMMENT ON COLUMN {$prefix}history_summary.history_id_to IS 'highest history.id folded into this summary row, the next run resumes past this id'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'languages (' .
            "id varchar(64) NOT NULL DEFAULT '', " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'name varchar(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}languages IS 'installed/active language packs, row deleted outright on deactivation'");
        $this->addSql("COMMENT ON COLUMN {$prefix}languages.id IS 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active'");
        $this->addSql("COMMENT ON COLUMN {$prefix}languages.version IS 'installed language pack version string'");
        $this->addSql("COMMENT ON COLUMN {$prefix}languages.name IS 'human-readable language display name'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'plugins (' .
            "id varchar(64) COLLATE \"C\" NOT NULL DEFAULT '', " .
            "state varchar(10) NOT NULL DEFAULT 'inactive' CHECK (state IN ('inactive', 'active')), " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}plugins IS 'installed plugins and their active/inactive state'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugins.id IS 'plugin directory-name identifier, row existence alone means installed, active or not'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugins.state IS 'whether the installed plugin is currently active'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugins.version IS 'installed plugin version string'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'search (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'search_uuid char(23) DEFAULT NULL, ' .
            'created_on timestamp(0) DEFAULT NULL, ' .
            'created_by integer DEFAULT NULL, ' .
            'forked_from bigint DEFAULT NULL, ' .
            'rules jsonb DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}search IS 'saved/shareable search queries'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.search_uuid IS 'public, shareable identifier for this saved search, used in URLs instead of id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.created_on IS 'when the search was saved'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.created_by IS 'user id who saved the search, null for an anonymous search'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.forked_from IS 'search this one was refined/derived from, null for an original search'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search.rules IS 'encoded search criteria (query terms, filters) evaluated by SearchService'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'sites (' .
            'id smallint GENERATED BY DEFAULT AS IDENTITY, ' .
            "galleries_url varchar(255) NOT NULL DEFAULT '', " .
            'PRIMARY KEY (id))'
        );
        $this->addSql('ALTER TABLE ' . $prefix . 'sites ADD CONSTRAINT sites_ui1_unique UNIQUE (galleries_url)');
        $this->addSql("COMMENT ON TABLE {$prefix}sites IS 'multi-site photo sources synchronized into albums'");
        $this->addSql("COMMENT ON COLUMN {$prefix}sites.id IS 'surrogate primary key, referenced by categories.site_id'");
        $this->addSql("COMMENT ON COLUMN {$prefix}sites.galleries_url IS 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'themes (' .
            "id varchar(64) NOT NULL DEFAULT '', " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'name varchar(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}themes IS 'installed/active themes, row deleted outright on deactivation'");
        $this->addSql("COMMENT ON COLUMN {$prefix}themes.id IS 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active'");
        $this->addSql("COMMENT ON COLUMN {$prefix}themes.version IS 'installed theme version string'");
        $this->addSql("COMMENT ON COLUMN {$prefix}themes.name IS 'human-readable theme display name'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'derivative_settings (' .
            'id smallint NOT NULL, ' .
            'default_quality integer NOT NULL DEFAULT 95, ' .
            'watermark_json jsonb NOT NULL, ' .
            'custom_json jsonb NOT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}derivative_settings IS 'global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_settings.id IS 'settings row identifier'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_settings.default_quality IS 'default JPEG compression quality, 0 to 100, for generated derivative images'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_settings.watermark_json IS 'encoded watermark configuration'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_settings.custom_json IS 'encoded custom derivative-generation parameters'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'derivative_size (' .
            'name varchar(32) NOT NULL, ' .
            'enabled smallint NOT NULL DEFAULT 1, ' .
            'max_width integer NOT NULL DEFAULT 0, ' .
            'max_height integer NOT NULL DEFAULT 0, ' .
            'max_crop numeric(5,4) NOT NULL DEFAULT 0, ' .
            'min_width integer DEFAULT NULL, ' .
            'min_height integer DEFAULT NULL, ' .
            'sharpen numeric(5,4) NOT NULL DEFAULT 0, ' .
            'last_mod_time integer NOT NULL DEFAULT 0, ' .
            'PRIMARY KEY (name))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}derivative_size IS 'per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.name IS 'derivative size name, e.g. thumb, medium, xxlarge'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.enabled IS 'whether this derivative size is generated'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.max_width IS 'maximum output width in pixels, see SizingParams'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.max_height IS 'maximum output height in pixels, see SizingParams'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.max_crop IS 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.min_width IS 'minimum output width required to allow cropping, see SizingParams::min_size'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.min_height IS 'minimum output height required to allow cropping, see SizingParams::min_size'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.sharpen IS 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen'");
        $this->addSql("COMMENT ON COLUMN {$prefix}derivative_size.last_mod_time IS 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'extension_ignored_updates (' .
            'extension_type varchar(16) NOT NULL, ' .
            'extension_id varchar(64) NOT NULL, ' .
            'ignored_at timestamp NOT NULL, ' .
            'PRIMARY KEY (extension_type, extension_id))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}extension_ignored_updates IS 'extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository'");
        $this->addSql("COMMENT ON COLUMN {$prefix}extension_ignored_updates.extension_type IS 'plugin, theme, or language, see ExtensionType'");
        $this->addSql("COMMENT ON COLUMN {$prefix}extension_ignored_updates.extension_id IS 'directory-name identifier of the extension whose update is being ignored'");
        $this->addSql("COMMENT ON COLUMN {$prefix}extension_ignored_updates.ignored_at IS 'when the update was dismissed'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'integrity_ignored_anomalies (' .
            'anomaly_id varchar(64) NOT NULL, ' .
            'piwigo_version varchar(16) NOT NULL, ' .
            'ignored_at timestamp NOT NULL, ' .
            'PRIMARY KEY (anomaly_id, piwigo_version))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}integrity_ignored_anomalies IS 'integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository'");
        $this->addSql("COMMENT ON COLUMN {$prefix}integrity_ignored_anomalies.anomaly_id IS 'add_anomaly()-generated md5 id, see CheckIntegrity'");
        $this->addSql("COMMENT ON COLUMN {$prefix}integrity_ignored_anomalies.piwigo_version IS 'Piwigo version the anomaly was ignored under'");
        $this->addSql("COMMENT ON COLUMN {$prefix}integrity_ignored_anomalies.ignored_at IS 'when the anomaly was dismissed'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'plugin_migrations (' .
            'plugin_id varchar(64) NOT NULL, ' .
            'version varchar(191) NOT NULL, ' .
            'executed_at timestamp NOT NULL, ' .
            'PRIMARY KEY (plugin_id, version))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}plugin_migrations IS 'per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugin_migrations.plugin_id IS 'directory-name identifier of the plugin that ran this migration'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugin_migrations.version IS 'plugin-internal migration version identifier'");
        $this->addSql("COMMENT ON COLUMN {$prefix}plugin_migrations.executed_at IS 'when this plugin migration ran'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'search_filter_view (' .
            'name varchar(64) NOT NULL, ' .
            'config_json jsonb NOT NULL, ' .
            'created_at timestamp NOT NULL, ' .
            'PRIMARY KEY (name))'
        );
        $this->addSql("COMMENT ON TABLE {$prefix}search_filter_view IS 'named, reusable saved search-filter presets, unused: not read or written by any repository or service in this codebase'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search_filter_view.name IS 'saved filter view name'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search_filter_view.config_json IS 'encoded search filter configuration'");
        $this->addSql("COMMENT ON COLUMN {$prefix}search_filter_view.created_at IS 'when the filter view was saved'");

        $this->addSql(
            'CREATE TABLE ' . $prefix . 'audit_log (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'actor_id integer DEFAULT NULL, ' .
            'action varchar(64) NOT NULL, ' .
            'entity_type varchar(64) NOT NULL, ' .
            'entity_id bigint DEFAULT NULL, ' .
            'before_json jsonb DEFAULT NULL, ' .
            'after_json jsonb DEFAULT NULL, ' .
            'ip_address varchar(45) DEFAULT NULL, ' .
            'created_at timestamp(0) NOT NULL, ' .
            'prev_hash varchar(64) DEFAULT NULL, ' .
            'row_hash varchar(64) NOT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX idx_audit_log_entity ON ' . $prefix . 'audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_log_actor ON ' . $prefix . 'audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON ' . $prefix . 'audit_log (created_at)');
        $this->addSql("COMMENT ON TABLE {$prefix}audit_log IS 'SEC-57 append-only, hash-chained audit trail of admin actions and permission changes'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.actor_id IS 'acting user id, null for an unattributed or system action'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.action IS 'action verb, e.g. delete, grant, revoke'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.entity_type IS 'audited entity type, e.g. group, permission'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.entity_id IS 'id of the audited entity, null when not applicable'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.before_json IS 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.after_json IS 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.ip_address IS 'REMOTE_ADDR of the request that performed the action'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.created_at IS 'when the action was recorded'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.prev_hash IS 'row_hash of the previous row, null for the first row, forms the hash chain'");
        $this->addSql("COMMENT ON COLUMN {$prefix}audit_log.row_hash IS 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash'");
    }

    /**
     * Same rationale as Version20260804122300::withMysqlPrefix() -- see
     * that method's own docblock. Real, pre-existing gap found while
     * writing this migration (not introduced by it): `integrity_ignored_
     * anomalies.piwigo_version` is the one column in the entire 39-table
     * schema whose own name happens to start with the literal string
     * `piwigo_` -- the blanket `str_replace()` this method (and the
     * legacy `InstallService::executeSqlfile()` mechanism it mirrors) both
     * use would silently rename that column too for any non-default
     * `PIWIGO_DB_PREFIX`, breaking `IntegrityIgnoredAnomalyRepository`'s
     * own hardcoded `#[ORM\Column(name: 'piwigo_version')]` mapping (a
     * column name is never itself prefix-relative, only table names are).
     * Protected here rather than left latent now that it's been noticed.
     */
    private function withMysqlPrefix(string $sql): string
    {
        $protected = str_replace('`piwigo_version`', '`__PIWIGO_VERSION_COLUMN__`', $sql);
        $prefixed = str_replace('piwigo_', DbCredentials::fromEnv()->prefix, $protected);

        return str_replace('`__PIWIGO_VERSION_COLUMN__`', '`piwigo_version`', $prefixed);
    }
}
