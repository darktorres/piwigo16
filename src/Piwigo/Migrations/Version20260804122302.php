<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use LogicException;
use Override;

/**
 * Baseline bootstrap, admin/system domain: config, activity, history,
 * history_summary, plugins, themes, languages, plugin_migrations,
 * extension_ignored_updates, integrity_ignored_anomalies, audit_log,
 * sites, search, derivative_settings, derivative_size -- the remaining 15
 * of the 38 tables. See Version20260804122300's own docblock for the full
 * translation-rule rationale shared by every migration in this set.
 *
 * `derivative_size.enabled` stays a Postgres `smallint`, not `boolean`,
 * despite looking boolean-ish (`DEFAULT 1`) -- the source schema itself
 * chose `smallint` over the `tinyint(1)` convention it otherwise uses
 * consistently for real boolean flags elsewhere in this file, so this
 * migration preserves that same distinction rather than reinterpreting it.
 *
 * `activity` carries both a durable, generic `object`/`object_id` pair
 * (a historical fact that must survive the referenced row's deletion) and
 * a typed, `ON DELETE SET NULL` reference column per entity kind
 * (`user_id`/`category_id`/`image_id`/`tag_id`/`group_id`, plus a
 * `system_scope` discriminator for `object = 'system'` rows, which store
 * an `ActivitySystem` constant rather than a row id). `audit_log` gets the
 * same typed-reference treatment for its one live entity type so far
 * (`group_id`) while `entity_type`/`entity_id` remain the historical
 * record the hash chain covers. `history_summary.history_id_from`/`_to`
 * are watermarks, not references: autopurge's cursor deliberately outlives
 * the `history` rows it summarises, so neither can carry a foreign key.
 */
final class Version20260804122302 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: admin/system domain (config, activity, history, and 12 more)';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->upPostgres();

            return;
        }

        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->upMysql();

            return;
        }

        if ($this->platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        // Explicit, not a silent `else { upMysql() }` fallthrough -- see
        // Version20260804122300's own up() for why.
        throw new LogicException(self::class . ' has no migration path for platform ' . $this->platform::class);
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
        $this->addSql(<<<'SQL'
            CREATE TABLE `activity` (
              `activity_id` int NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `object` varchar(255) NOT NULL COMMENT 'entity type the action applies to, e.g. user, photo, album, tag, plugin',
              `object_id` int NOT NULL COMMENT 'id of the affected object, or the target user id on a logout action -- the durable record of which row this was about, kept when the typed reference column beside it is nulled by a deletion',
              `action` varchar(255) NOT NULL COMMENT 'action verb, e.g. add, delete, login, logout, autoupdate',
              `performed_by` int DEFAULT NULL COMMENT 'acting user id, null for an unresolved or system actor',
              `session_idx` varchar(255) NOT NULL COMMENT 'PHP session id active during the request, or none if there was no session',
              `ip_address` varchar(50) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that triggered the action',
              `occured_on` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when the action was recorded',
              `details` JSON DEFAULT NULL COMMENT 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata',
              `user_agent` varchar(255) DEFAULT NULL COMMENT 'browser user agent string, only captured on login actions',
              `user_id` int DEFAULT NULL COMMENT 'typed reference for object = user, ON DELETE SET NULL',
              `category_id` int DEFAULT NULL COMMENT 'typed reference for object = album, ON DELETE SET NULL',
              `image_id` int DEFAULT NULL COMMENT 'typed reference for object = photo, ON DELETE SET NULL',
              `tag_id` int DEFAULT NULL COMMENT 'typed reference for object = tag, ON DELETE SET NULL',
              `group_id` int DEFAULT NULL COMMENT 'typed reference for object = group, ON DELETE SET NULL',
              `system_scope` SMALLINT DEFAULT NULL COMMENT 'ActivitySystem constant for object = system rows, which store no row id in object_id',
              PRIMARY KEY (`activity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='general activity log of user and system actions, distinct from the tamper-evident audit_log'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `config` (
              `param` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'configuration key',
              `value` JSON DEFAULT NULL COMMENT 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()',
              `comment` varchar(255) default NULL COMMENT 'human-readable description of the param, seeded for built-in settings by install/config.sql',
              PRIMARY KEY  (`param`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='configuration table'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `history` (
              `id` int NOT NULL auto_increment COMMENT 'surrogate primary key',
              `date` date default NULL COMMENT 'calendar date of the visit',
              `time` time NOT NULL default '00:00:00' COMMENT 'time of day of the visit',
              `user_id` int NOT NULL default '0' COMMENT 'visiting user id, the guest user id for anonymous visitors',
              `IP` char(39) NOT NULL default '' COMMENT 'REMOTE_ADDR of the request, truncated to fit an IPv6 address',
              `section` varchar(20) default NULL COMMENT 'gallery navigation view the visit occurred in, plugin-defined sections stored as-is',
              `category_id` int default NULL COMMENT 'album being viewed, set when section is a category-based view',
              `search_id` int default NULL COMMENT 'search being viewed, set when section is search',
              `tag_ids` varchar(50) default NULL COMMENT 'comma-separated tag ids being viewed, set when section is tags, truncated to fit',
              `image_id` int default NULL COMMENT 'viewed image id, null for a listing/section page-view',
              `image_type` enum('picture','high','other') default NULL COMMENT 'size the image was viewed at',
              `format_id` int default NULL COMMENT 'image_format row downloaded or viewed, when applicable',
              `auth_key_id` int DEFAULT NULL COMMENT 'API auth key the request was authenticated with, if any',
              PRIMARY KEY  (`id`),
              KEY `idx_history_date_desc` (`date` DESC, `id` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-visit page-view log, periodically rolled up into history_summary'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `history_summary` (
              `summary_id` int NOT NULL auto_increment COMMENT 'surrogate primary key',
              `year` smallint(4) NOT NULL default '0' COMMENT 'rollup year',
              `month` tinyint(2) default NULL COMMENT 'rollup month, null for a year-level summary row',
              `day` tinyint(2) default NULL COMMENT 'rollup day, null for a year- or month-level summary row',
              `hour` tinyint(2) default NULL COMMENT 'rollup hour, null for a year-, month-, or day-level summary row',
              `nb_pages` int(11) default NULL COMMENT 'number of history page-views folded into this summary row',
              `history_id_from` int default NULL COMMENT 'lowest history.id folded into this summary row -- a watermark, not a reference: autopurge deliberately deletes the history rows this range covers, so no foreign key can exist here',
              `history_id_to` int default NULL COMMENT 'highest history.id folded into this summary row, the next run resumes past this id -- a watermark, not a reference, and autopurge cursors on it, so it must survive the purge it drives',
              PRIMARY KEY (`summary_id`),
              UNIQUE KEY history_summary_ymdh (`year`,`month`,`day`,`hour`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='year/month/day/hour rollup of history, one row per granularity level, letting old detail rows be purged'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `languages` (
              `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed language pack version string',
              `name` varchar(64) default NULL COMMENT 'human-readable language display name',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active language packs, row deleted outright on deactivation'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `plugins` (
              `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'plugin directory-name identifier, row existence alone means installed, active or not',
              `state` enum('inactive','active') NOT NULL default 'inactive' COMMENT 'whether the installed plugin is currently active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed plugin version string',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed plugins and their active/inactive state'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `search` (
              `id` int NOT NULL auto_increment COMMENT 'surrogate primary key',
              `search_uuid` CHAR(23) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'public, shareable identifier for this saved search, used in URLs instead of id',
              `created_on` DATETIME DEFAULT NULL COMMENT 'when the search was saved',
              `created_by` INT DEFAULT NULL COMMENT 'user id who saved the search, null for an anonymous search',
              `forked_from` INT DEFAULT NULL COMMENT 'search this one was refined/derived from, null for an original search',
              `rules` JSON DEFAULT NULL COMMENT 'encoded search criteria (query terms, filters) evaluated by SearchService',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='saved/shareable search queries'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `sites` (
              `id` smallint NOT NULL auto_increment COMMENT 'surrogate primary key, referenced by categories.site_id',
              `galleries_url` varchar(255) NOT NULL default '' COMMENT 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `sites_ui1` (`galleries_url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='multi-site photo sources synchronized into albums'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `themes` (
              `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active',
              `version` varchar(64) NOT NULL default '0' COMMENT 'installed theme version string',
              `name` varchar(64) default NULL COMMENT 'human-readable theme display name',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='installed/active themes, row deleted outright on deactivation'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `derivative_settings` (
              `id` smallint NOT NULL COMMENT 'settings row identifier',
              `default_quality` int NOT NULL DEFAULT 95 COMMENT 'default JPEG compression quality, 0 to 100, for generated derivative images',
              `watermark_json` JSON NOT NULL COMMENT 'encoded watermark configuration',
              `custom_json` JSON NOT NULL COMMENT 'encoded custom derivative-generation parameters',
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `derivative_size` (
              `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'derivative size name, e.g. thumb, medium, xxlarge',
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
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `extension_ignored_updates` (
              `extension_type` varchar(16) NOT NULL COMMENT 'plugin, theme, or language, see ExtensionType',
              `extension_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'directory-name identifier of the extension whose update is being ignored',
              `ignored_at` DATETIME NOT NULL COMMENT 'when the update was dismissed',
              PRIMARY KEY (`extension_type`,`extension_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `integrity_ignored_anomalies` (
              `anomaly_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'add_anomaly()-generated md5 id, see CheckIntegrity',
              `piwigo_version` varchar(16) NOT NULL COMMENT 'Piwigo version the anomaly was ignored under',
              `ignored_at` DATETIME NOT NULL COMMENT 'when the anomaly was dismissed',
              PRIMARY KEY (`anomaly_id`,`piwigo_version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `plugin_migrations` (
              `plugin_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'directory-name identifier of the plugin that ran this migration',
              `version` varchar(191) NOT NULL COMMENT 'plugin-internal migration version identifier',
              `executed_at` DATETIME NOT NULL COMMENT 'when this plugin migration ran',
              PRIMARY KEY (`plugin_id`,`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `audit_log` (
              `id` int NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `actor_id` int DEFAULT NULL COMMENT 'acting user id, null for an unattributed or system action',
              `action` varchar(64) NOT NULL COMMENT 'action verb, e.g. delete, grant, revoke',
              `entity_type` varchar(64) NOT NULL COMMENT 'audited entity type, e.g. group, permission',
              `entity_id` int DEFAULT NULL COMMENT 'id of the audited entity, null when not applicable',
              `before_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded',
              `after_json` JSON DEFAULT NULL COMMENT 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded',
              `ip_address` varchar(45) DEFAULT NULL COMMENT 'REMOTE_ADDR of the request that performed the action',
              `created_at` DATETIME NOT NULL COMMENT 'when the action was recorded',
              `prev_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'row_hash of the previous row, null for the first row, forms the hash chain',
              `row_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash',
              `group_id` int DEFAULT NULL COMMENT 'typed reference for entity_type = group, ON DELETE SET NULL',
              PRIMARY KEY (`id`),
              KEY `idx_audit_log_entity` (`entity_type`, `entity_id`),
              KEY `idx_audit_log_actor` (`actor_id`),
              KEY `idx_audit_log_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SEC-57 append-only, hash-chained audit trail of admin actions and permission changes'
            SQL);
    }

    private function upPostgres(): void
    {
        $this->addSql(
            'CREATE TABLE activity (' .
            'activity_id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'object varchar(255) NOT NULL, ' .
            'object_id integer NOT NULL, ' .
            'action varchar(255) NOT NULL, ' .
            'performed_by integer DEFAULT NULL, ' .
            'session_idx varchar(255) NOT NULL, ' .
            'ip_address varchar(50) DEFAULT NULL, ' .
            'occured_on timestamp(0) NOT NULL DEFAULT now(), ' .
            'details jsonb DEFAULT NULL, ' .
            'user_agent varchar(255) DEFAULT NULL, ' .
            'user_id integer DEFAULT NULL, ' .
            'category_id integer DEFAULT NULL, ' .
            'image_id integer DEFAULT NULL, ' .
            'tag_id integer DEFAULT NULL, ' .
            'group_id integer DEFAULT NULL, ' .
            'system_scope smallint DEFAULT NULL, ' .
            'PRIMARY KEY (activity_id))'
        );
        $this->addSql("COMMENT ON TABLE activity IS 'general activity log of user and system actions, distinct from the tamper-evident audit_log'");
        $this->addSql("COMMENT ON COLUMN activity.activity_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN activity.object IS 'entity type the action applies to, e.g. user, photo, album, tag, plugin'");
        $this->addSql("COMMENT ON COLUMN activity.object_id IS 'id of the affected object, or the target user id on a logout action -- the durable record of which row this was about, kept when the typed reference column beside it is nulled by a deletion'");
        $this->addSql("COMMENT ON COLUMN activity.action IS 'action verb, e.g. add, delete, login, logout, autoupdate'");
        $this->addSql("COMMENT ON COLUMN activity.performed_by IS 'acting user id, null for an unresolved or system actor'");
        $this->addSql("COMMENT ON COLUMN activity.session_idx IS 'PHP session id active during the request, or none if there was no session'");
        $this->addSql("COMMENT ON COLUMN activity.ip_address IS 'REMOTE_ADDR of the request that triggered the action'");
        $this->addSql("COMMENT ON COLUMN activity.occured_on IS 'when the action was recorded'");
        $this->addSql("COMMENT ON COLUMN activity.details IS 'per-action heterogeneous payload, e.g. config diffs, batch-edit fields, install metadata'");
        $this->addSql("COMMENT ON COLUMN activity.user_agent IS 'browser user agent string, only captured on login actions'");
        $this->addSql("COMMENT ON COLUMN activity.user_id IS 'typed reference for object = user, ON DELETE SET NULL'");
        $this->addSql("COMMENT ON COLUMN activity.category_id IS 'typed reference for object = album, ON DELETE SET NULL'");
        $this->addSql("COMMENT ON COLUMN activity.image_id IS 'typed reference for object = photo, ON DELETE SET NULL'");
        $this->addSql("COMMENT ON COLUMN activity.tag_id IS 'typed reference for object = tag, ON DELETE SET NULL'");
        $this->addSql("COMMENT ON COLUMN activity.group_id IS 'typed reference for object = group, ON DELETE SET NULL'");
        $this->addSql("COMMENT ON COLUMN activity.system_scope IS 'ActivitySystem constant for object = system rows, which store no row id in object_id'");

        $this->addSql(
            'CREATE TABLE config (' .
            "param varchar(40) NOT NULL DEFAULT '', " .
            'value jsonb DEFAULT NULL, ' .
            'comment varchar(255) DEFAULT NULL, ' .
            'PRIMARY KEY (param))'
        );
        $this->addSql("COMMENT ON TABLE config IS 'configuration table'");
        $this->addSql("COMMENT ON COLUMN config.param IS 'configuration key'");
        $this->addSql("COMMENT ON COLUMN config.value IS 'JSON-encoded configuration value, see ConfigService::encode()/hydrate()'");
        $this->addSql("COMMENT ON COLUMN config.comment IS 'human-readable description of the param, seeded for built-in settings by install/config.sql'");

        // `section` deliberately has NO CHECK constraint, unlike every
        // other enum-shaped column in this schema: a plugin may introduce
        // its own section value, and a closed CHECK constraint would reject
        // exactly the extensibility this column exists to support --
        // `image_type` right below has a real, closed, core-only value set
        // (no equivalent widening mechanism exists for it), so it keeps
        // its CHECK constraint.
        $this->addSql(
            'CREATE TABLE history (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'date date DEFAULT NULL, ' .
            "\"time\" time NOT NULL DEFAULT '00:00:00', " .
            'user_id integer NOT NULL, ' .
            "ip char(39) NOT NULL DEFAULT '', " .
            'section varchar(20) DEFAULT NULL, ' .
            'category_id integer DEFAULT NULL, ' .
            'search_id integer DEFAULT NULL, ' .
            'tag_ids varchar(50) DEFAULT NULL, ' .
            'image_id integer DEFAULT NULL, ' .
            "image_type varchar(10) DEFAULT NULL CHECK (image_type IS NULL OR image_type IN ('picture', 'high', 'other')), " .
            'format_id integer DEFAULT NULL, ' .
            'auth_key_id integer DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX idx_history_date_desc ON history (date DESC, id DESC)');
        $this->addSql("COMMENT ON TABLE history IS 'per-visit page-view log, periodically rolled up into history_summary'");
        $this->addSql("COMMENT ON COLUMN history.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN history.date IS 'calendar date of the visit'");
        $this->addSql("COMMENT ON COLUMN history.\"time\" IS 'time of day of the visit'");
        $this->addSql("COMMENT ON COLUMN history.user_id IS 'visiting user id, the guest user id for anonymous visitors'");
        $this->addSql("COMMENT ON COLUMN history.ip IS 'REMOTE_ADDR of the request, truncated to fit an IPv6 address'");
        $this->addSql("COMMENT ON COLUMN history.section IS 'gallery navigation view the visit occurred in, plugin-defined sections stored as-is'");
        $this->addSql("COMMENT ON COLUMN history.category_id IS 'album being viewed, set when section is a category-based view'");
        $this->addSql("COMMENT ON COLUMN history.search_id IS 'search being viewed, set when section is search'");
        $this->addSql("COMMENT ON COLUMN history.tag_ids IS 'comma-separated tag ids being viewed, set when section is tags, truncated to fit'");
        $this->addSql("COMMENT ON COLUMN history.image_id IS 'viewed image id, null for a listing/section page-view'");
        $this->addSql("COMMENT ON COLUMN history.image_type IS 'size the image was viewed at'");
        $this->addSql("COMMENT ON COLUMN history.format_id IS 'image_format row downloaded or viewed, when applicable'");
        $this->addSql("COMMENT ON COLUMN history.auth_key_id IS 'API auth key the request was authenticated with, if any'");

        $this->addSql(
            'CREATE TABLE history_summary (' .
            'summary_id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'year smallint NOT NULL DEFAULT 0, ' .
            'month smallint DEFAULT NULL, ' .
            'day smallint DEFAULT NULL, ' .
            'hour smallint DEFAULT NULL, ' .
            'nb_pages integer DEFAULT NULL, ' .
            'history_id_from integer DEFAULT NULL, ' .
            'history_id_to integer DEFAULT NULL, ' .
            'PRIMARY KEY (summary_id))'
        );
        $this->addSql('ALTER TABLE history_summary ADD CONSTRAINT history_summary_ymdh UNIQUE (year, month, day, hour)');
        $this->addSql("COMMENT ON TABLE history_summary IS 'year/month/day/hour rollup of history, one row per granularity level, letting old detail rows be purged'");
        $this->addSql("COMMENT ON COLUMN history_summary.summary_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN history_summary.year IS 'rollup year'");
        $this->addSql("COMMENT ON COLUMN history_summary.month IS 'rollup month, null for a year-level summary row'");
        $this->addSql("COMMENT ON COLUMN history_summary.day IS 'rollup day, null for a year- or month-level summary row'");
        $this->addSql("COMMENT ON COLUMN history_summary.hour IS 'rollup hour, null for a year-, month-, or day-level summary row'");
        $this->addSql("COMMENT ON COLUMN history_summary.nb_pages IS 'number of history page-views folded into this summary row'");
        $this->addSql("COMMENT ON COLUMN history_summary.history_id_from IS 'lowest history.id folded into this summary row -- a watermark, not a reference: autopurge deliberately deletes the history rows this range covers, so no foreign key can exist here'");
        $this->addSql("COMMENT ON COLUMN history_summary.history_id_to IS 'highest history.id folded into this summary row, the next run resumes past this id -- a watermark, not a reference, and autopurge cursors on it, so it must survive the purge it drives'");

        $this->addSql(
            'CREATE TABLE languages (' .
            "id varchar(64) NOT NULL DEFAULT '', " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'name varchar(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE languages IS 'installed/active language packs, row deleted outright on deactivation'");
        $this->addSql("COMMENT ON COLUMN languages.id IS 'language directory-name identifier, e.g. en_UK, row existence alone means installed and active'");
        $this->addSql("COMMENT ON COLUMN languages.version IS 'installed language pack version string'");
        $this->addSql("COMMENT ON COLUMN languages.name IS 'human-readable language display name'");

        $this->addSql(
            'CREATE TABLE plugins (' .
            "id varchar(64) COLLATE \"C\" NOT NULL DEFAULT '', " .
            "state varchar(10) NOT NULL DEFAULT 'inactive' CHECK (state IN ('inactive', 'active')), " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE plugins IS 'installed plugins and their active/inactive state'");
        $this->addSql("COMMENT ON COLUMN plugins.id IS 'plugin directory-name identifier, row existence alone means installed, active or not'");
        $this->addSql("COMMENT ON COLUMN plugins.state IS 'whether the installed plugin is currently active'");
        $this->addSql("COMMENT ON COLUMN plugins.version IS 'installed plugin version string'");

        $this->addSql(
            'CREATE TABLE search (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'search_uuid char(23) DEFAULT NULL, ' .
            'created_on timestamp(0) DEFAULT NULL, ' .
            'created_by integer DEFAULT NULL, ' .
            'forked_from integer DEFAULT NULL, ' .
            'rules jsonb DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE search IS 'saved/shareable search queries'");
        $this->addSql("COMMENT ON COLUMN search.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN search.search_uuid IS 'public, shareable identifier for this saved search, used in URLs instead of id'");
        $this->addSql("COMMENT ON COLUMN search.created_on IS 'when the search was saved'");
        $this->addSql("COMMENT ON COLUMN search.created_by IS 'user id who saved the search, null for an anonymous search'");
        $this->addSql("COMMENT ON COLUMN search.forked_from IS 'search this one was refined/derived from, null for an original search'");
        $this->addSql("COMMENT ON COLUMN search.rules IS 'encoded search criteria (query terms, filters) evaluated by SearchService'");

        $this->addSql(
            'CREATE TABLE sites (' .
            'id smallint GENERATED BY DEFAULT AS IDENTITY, ' .
            "galleries_url varchar(255) NOT NULL DEFAULT '', " .
            'PRIMARY KEY (id))'
        );
        $this->addSql('ALTER TABLE sites ADD CONSTRAINT sites_ui1_unique UNIQUE (galleries_url)');
        $this->addSql("COMMENT ON TABLE sites IS 'multi-site photo sources synchronized into albums'");
        $this->addSql("COMMENT ON COLUMN sites.id IS 'surrogate primary key, referenced by categories.site_id'");
        $this->addSql("COMMENT ON COLUMN sites.galleries_url IS 'base path or URL this site synchronizes photos from, local or remote (see UrlService::urlIsRemote)'");

        $this->addSql(
            'CREATE TABLE themes (' .
            "id varchar(64) NOT NULL DEFAULT '', " .
            "version varchar(64) NOT NULL DEFAULT '0', " .
            'name varchar(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE themes IS 'installed/active themes, row deleted outright on deactivation'");
        $this->addSql("COMMENT ON COLUMN themes.id IS 'theme directory-name identifier, referenced by user_infos.theme, row existence alone means installed and active'");
        $this->addSql("COMMENT ON COLUMN themes.version IS 'installed theme version string'");
        $this->addSql("COMMENT ON COLUMN themes.name IS 'human-readable theme display name'");

        $this->addSql(
            'CREATE TABLE derivative_settings (' .
            'id smallint NOT NULL, ' .
            'default_quality integer NOT NULL DEFAULT 95, ' .
            'watermark_json jsonb NOT NULL, ' .
            'custom_json jsonb NOT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE derivative_settings IS 'global derivative-image generation settings, read and written by ImageStdParams via DerivativeSettingsRepository'");
        $this->addSql("COMMENT ON COLUMN derivative_settings.id IS 'settings row identifier'");
        $this->addSql("COMMENT ON COLUMN derivative_settings.default_quality IS 'default JPEG compression quality, 0 to 100, for generated derivative images'");
        $this->addSql("COMMENT ON COLUMN derivative_settings.watermark_json IS 'encoded watermark configuration'");
        $this->addSql("COMMENT ON COLUMN derivative_settings.custom_json IS 'encoded custom derivative-generation parameters'");

        $this->addSql(
            'CREATE TABLE derivative_size (' .
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
        $this->addSql("COMMENT ON TABLE derivative_size IS 'per-named derivative size definitions, read and written by ImageStdParams via DerivativeSizeRepository'");
        $this->addSql("COMMENT ON COLUMN derivative_size.name IS 'derivative size name, e.g. thumb, medium, xxlarge'");
        $this->addSql("COMMENT ON COLUMN derivative_size.enabled IS 'whether this derivative size is generated'");
        $this->addSql("COMMENT ON COLUMN derivative_size.max_width IS 'maximum output width in pixels, see SizingParams'");
        $this->addSql("COMMENT ON COLUMN derivative_size.max_height IS 'maximum output height in pixels, see SizingParams'");
        $this->addSql("COMMENT ON COLUMN derivative_size.max_crop IS 'cropping ratio from 0, no cropping, to 1, max cropping, see SizingParams::max_crop'");
        $this->addSql("COMMENT ON COLUMN derivative_size.min_width IS 'minimum output width required to allow cropping, see SizingParams::min_size'");
        $this->addSql("COMMENT ON COLUMN derivative_size.min_height IS 'minimum output height required to allow cropping, see SizingParams::min_size'");
        $this->addSql("COMMENT ON COLUMN derivative_size.sharpen IS 'sharpening amount from 0, none, to 1, max, see DerivativeParams::sharpen'");
        $this->addSql("COMMENT ON COLUMN derivative_size.last_mod_time IS 'unix timestamp of the last parameter change, used to invalidate cached derivatives, see DerivativeParams::last_mod_time'");

        $this->addSql(
            'CREATE TABLE extension_ignored_updates (' .
            'extension_type varchar(16) NOT NULL, ' .
            'extension_id varchar(64) NOT NULL, ' .
            'ignored_at timestamp NOT NULL, ' .
            'PRIMARY KEY (extension_type, extension_id))'
        );
        $this->addSql("COMMENT ON TABLE extension_ignored_updates IS 'extension updates an admin dismissed, read and written by ExtensionUpdateChecker via ExtensionIgnoredUpdateRepository'");
        $this->addSql("COMMENT ON COLUMN extension_ignored_updates.extension_type IS 'plugin, theme, or language, see ExtensionType'");
        $this->addSql("COMMENT ON COLUMN extension_ignored_updates.extension_id IS 'directory-name identifier of the extension whose update is being ignored'");
        $this->addSql("COMMENT ON COLUMN extension_ignored_updates.ignored_at IS 'when the update was dismissed'");

        $this->addSql(
            'CREATE TABLE integrity_ignored_anomalies (' .
            'anomaly_id varchar(64) NOT NULL, ' .
            'piwigo_version varchar(16) NOT NULL, ' .
            'ignored_at timestamp NOT NULL, ' .
            'PRIMARY KEY (anomaly_id, piwigo_version))'
        );
        $this->addSql("COMMENT ON TABLE integrity_ignored_anomalies IS 'integrity-check anomalies an admin dismissed, read and written by CheckIntegrity via IntegrityIgnoredAnomalyRepository'");
        $this->addSql("COMMENT ON COLUMN integrity_ignored_anomalies.anomaly_id IS 'add_anomaly()-generated md5 id, see CheckIntegrity'");
        $this->addSql("COMMENT ON COLUMN integrity_ignored_anomalies.piwigo_version IS 'Piwigo version the anomaly was ignored under'");
        $this->addSql("COMMENT ON COLUMN integrity_ignored_anomalies.ignored_at IS 'when the anomaly was dismissed'");

        $this->addSql(
            'CREATE TABLE plugin_migrations (' .
            'plugin_id varchar(64) NOT NULL, ' .
            'version varchar(191) NOT NULL, ' .
            'executed_at timestamp NOT NULL, ' .
            'PRIMARY KEY (plugin_id, version))'
        );
        $this->addSql("COMMENT ON TABLE plugin_migrations IS 'per-plugin install/update history, read and written by ExtensionLifecycle via PluginMigrationRepository, not a real migration runner'");
        $this->addSql("COMMENT ON COLUMN plugin_migrations.plugin_id IS 'directory-name identifier of the plugin that ran this migration'");
        $this->addSql("COMMENT ON COLUMN plugin_migrations.version IS 'plugin-internal migration version identifier'");
        $this->addSql("COMMENT ON COLUMN plugin_migrations.executed_at IS 'when this plugin migration ran'");

        $this->addSql(
            'CREATE TABLE audit_log (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'actor_id integer DEFAULT NULL, ' .
            'action varchar(64) NOT NULL, ' .
            'entity_type varchar(64) NOT NULL, ' .
            'entity_id integer DEFAULT NULL, ' .
            'before_json jsonb DEFAULT NULL, ' .
            'after_json jsonb DEFAULT NULL, ' .
            'ip_address varchar(45) DEFAULT NULL, ' .
            'created_at timestamp(0) NOT NULL, ' .
            'prev_hash varchar(64) DEFAULT NULL, ' .
            'row_hash varchar(64) NOT NULL, ' .
            'group_id integer DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_log_actor ON audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON audit_log (created_at)');
        $this->addSql("COMMENT ON TABLE audit_log IS 'SEC-57 append-only, hash-chained audit trail of admin actions and permission changes'");
        $this->addSql("COMMENT ON COLUMN audit_log.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN audit_log.actor_id IS 'acting user id, null for an unattributed or system action'");
        $this->addSql("COMMENT ON COLUMN audit_log.action IS 'action verb, e.g. delete, grant, revoke'");
        $this->addSql("COMMENT ON COLUMN audit_log.entity_type IS 'audited entity type, e.g. group, permission'");
        $this->addSql("COMMENT ON COLUMN audit_log.entity_id IS 'id of the audited entity, null when not applicable'");
        $this->addSql("COMMENT ON COLUMN audit_log.before_json IS 'entity-agnostic snapshot before the change, null for a creation, folded into row_hash so must stay exactly what was recorded'");
        $this->addSql("COMMENT ON COLUMN audit_log.after_json IS 'entity-agnostic snapshot after the change, null for a deletion, folded into row_hash so must stay exactly what was recorded'");
        $this->addSql("COMMENT ON COLUMN audit_log.ip_address IS 'REMOTE_ADDR of the request that performed the action'");
        $this->addSql("COMMENT ON COLUMN audit_log.created_at IS 'when the action was recorded'");
        $this->addSql("COMMENT ON COLUMN audit_log.prev_hash IS 'row_hash of the previous row, null for the first row, forms the hash chain'");
        $this->addSql("COMMENT ON COLUMN audit_log.row_hash IS 'sha256 of this row content plus prev_hash, tamper-evidence for the chain, see AuditService::computeHash'");
        $this->addSql("COMMENT ON COLUMN audit_log.group_id IS 'typed reference for entity_type = group, ON DELETE SET NULL'");
    }

    /**
     * See Version20260804122300's own upSqlite() docblock for the shared
     * translation rules (FK inlining, boolean/enum/JSON mapping, no
     * FULLTEXT here). `history.section` gets no CHECK, matching
     * upPostgres()'s own reasoning right above (plugin-extensible values).
     */
    private function upSqlite(): void
    {
        $this->addSql(
            'CREATE TABLE activity (' .
            'activity_id INTEGER PRIMARY KEY, ' .
            'object VARCHAR(255) NOT NULL, ' .
            'object_id INTEGER NOT NULL, ' .
            'action VARCHAR(255) NOT NULL, ' .
            'performed_by INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'performed_by') . ', ' .
            'session_idx VARCHAR(255) NOT NULL, ' .
            'ip_address VARCHAR(50) DEFAULT NULL, ' .
            'occured_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'details TEXT DEFAULT NULL, ' .
            'user_agent VARCHAR(255) DEFAULT NULL, ' .
            'user_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'user_id') . ', ' .
            'category_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'category_id') . ', ' .
            'image_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'image_id') . ', ' .
            'tag_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'tag_id') . ', ' .
            'group_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('activity', 'group_id') . ', ' .
            'system_scope SMALLINT DEFAULT NULL)'
        );
        foreach (Version20260804122303::sqliteExtraIndexes('activity') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE config (' .
            "param VARCHAR(40) NOT NULL DEFAULT '', " .
            'value TEXT DEFAULT NULL, ' .
            'comment VARCHAR(255) DEFAULT NULL, ' .
            'PRIMARY KEY (param))'
        );

        $this->addSql(
            'CREATE TABLE history (' .
            'id INTEGER PRIMARY KEY, ' .
            'date DATE DEFAULT NULL, ' .
            "time TEXT NOT NULL DEFAULT '00:00:00', " .
            'user_id INTEGER NOT NULL' . Version20260804122303::sqliteReferences('history', 'user_id') . ', ' .
            "ip CHAR(39) NOT NULL DEFAULT '', " .
            'section VARCHAR(20) DEFAULT NULL, ' .
            'category_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('history', 'category_id') . ', ' .
            'search_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('history', 'search_id') . ', ' .
            'tag_ids VARCHAR(50) DEFAULT NULL, ' .
            'image_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('history', 'image_id') . ', ' .
            "image_type TEXT DEFAULT NULL CHECK (image_type IS NULL OR image_type IN ('picture', 'high', 'other')), " .
            'format_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('history', 'format_id') . ', ' .
            'auth_key_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('history', 'auth_key_id') . ')'
        );
        $this->addSql('CREATE INDEX idx_history_date_desc ON history (date DESC, id DESC)');
        foreach (Version20260804122303::sqliteExtraIndexes('history') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE history_summary (' .
            'summary_id INTEGER PRIMARY KEY, ' .
            'year SMALLINT NOT NULL DEFAULT 0, ' .
            'month SMALLINT DEFAULT NULL, ' .
            'day SMALLINT DEFAULT NULL, ' .
            'hour SMALLINT DEFAULT NULL, ' .
            'nb_pages INTEGER DEFAULT NULL, ' .
            'history_id_from INTEGER DEFAULT NULL, ' .
            'history_id_to INTEGER DEFAULT NULL)'
        );
        $this->addSql('CREATE UNIQUE INDEX history_summary_ymdh ON history_summary (year, month, day, hour)');
        foreach (Version20260804122303::sqliteExtraIndexes('history_summary') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE languages (' .
            "id VARCHAR(64) NOT NULL DEFAULT '', " .
            "version VARCHAR(64) NOT NULL DEFAULT '0', " .
            'name VARCHAR(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );

        $this->addSql(
            'CREATE TABLE plugins (' .
            "id VARCHAR(64) NOT NULL DEFAULT '', " .
            "state TEXT NOT NULL DEFAULT 'inactive' CHECK (state IN ('inactive', 'active')), " .
            "version VARCHAR(64) NOT NULL DEFAULT '0', " .
            'PRIMARY KEY (id))'
        );

        $this->addSql(
            'CREATE TABLE search (' .
            'id INTEGER PRIMARY KEY, ' .
            'search_uuid CHAR(23) DEFAULT NULL, ' .
            'created_on DATETIME DEFAULT NULL, ' .
            'created_by INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('search', 'created_by') . ', ' .
            'forked_from INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('search', 'forked_from') . ', ' .
            'rules TEXT DEFAULT NULL)'
        );
        foreach (Version20260804122303::sqliteExtraIndexes('search') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE sites (' .
            'id INTEGER PRIMARY KEY, ' .
            "galleries_url VARCHAR(255) NOT NULL DEFAULT '' UNIQUE)"
        );
        foreach (Version20260804122303::sqliteExtraIndexes('sites') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE themes (' .
            "id VARCHAR(64) NOT NULL DEFAULT '', " .
            "version VARCHAR(64) NOT NULL DEFAULT '0', " .
            'name VARCHAR(64) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );

        $this->addSql(
            'CREATE TABLE derivative_settings (' .
            'id SMALLINT NOT NULL, ' .
            'default_quality INTEGER NOT NULL DEFAULT 95, ' .
            'watermark_json TEXT NOT NULL, ' .
            'custom_json TEXT NOT NULL, ' .
            'PRIMARY KEY (id))'
        );

        $this->addSql(
            'CREATE TABLE derivative_size (' .
            'name VARCHAR(32) NOT NULL, ' .
            'enabled SMALLINT NOT NULL DEFAULT 1, ' .
            'max_width INTEGER NOT NULL DEFAULT 0, ' .
            'max_height INTEGER NOT NULL DEFAULT 0, ' .
            'max_crop NUMERIC(5,4) NOT NULL DEFAULT 0, ' .
            'min_width INTEGER DEFAULT NULL, ' .
            'min_height INTEGER DEFAULT NULL, ' .
            'sharpen NUMERIC(5,4) NOT NULL DEFAULT 0, ' .
            'last_mod_time INTEGER NOT NULL DEFAULT 0, ' .
            'PRIMARY KEY (name))'
        );

        $this->addSql(
            'CREATE TABLE extension_ignored_updates (' .
            'extension_type VARCHAR(16) NOT NULL, ' .
            'extension_id VARCHAR(64) NOT NULL, ' .
            'ignored_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (extension_type, extension_id))'
        );

        $this->addSql(
            'CREATE TABLE integrity_ignored_anomalies (' .
            'anomaly_id VARCHAR(64) NOT NULL, ' .
            'piwigo_version VARCHAR(16) NOT NULL, ' .
            'ignored_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (anomaly_id, piwigo_version))'
        );

        $this->addSql(
            'CREATE TABLE plugin_migrations (' .
            'plugin_id VARCHAR(64) NOT NULL' . Version20260804122303::sqliteReferences('plugin_migrations', 'plugin_id') . ', ' .
            'version VARCHAR(191) NOT NULL, ' .
            'executed_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (plugin_id, version))'
        );
        foreach (Version20260804122303::sqliteExtraIndexes('plugin_migrations') as $sql) {
            $this->addSql($sql);
        }

        $this->addSql(
            'CREATE TABLE audit_log (' .
            'id INTEGER PRIMARY KEY, ' .
            'actor_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('audit_log', 'actor_id') . ', ' .
            'action VARCHAR(64) NOT NULL, ' .
            'entity_type VARCHAR(64) NOT NULL, ' .
            'entity_id INTEGER DEFAULT NULL, ' .
            'before_json TEXT DEFAULT NULL, ' .
            'after_json TEXT DEFAULT NULL, ' .
            'ip_address VARCHAR(45) DEFAULT NULL, ' .
            'created_at DATETIME NOT NULL, ' .
            'prev_hash VARCHAR(64) DEFAULT NULL, ' .
            'row_hash VARCHAR(64) NOT NULL, ' .
            'group_id INTEGER DEFAULT NULL' . Version20260804122303::sqliteReferences('audit_log', 'group_id') . ')'
        );
        $this->addSql('CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_log_actor ON audit_log (actor_id)');
        $this->addSql('CREATE INDEX idx_audit_log_created_at ON audit_log (created_at)');
        foreach (Version20260804122303::sqliteExtraIndexes('audit_log') as $sql) {
            $this->addSql($sql);
        }
    }
}
