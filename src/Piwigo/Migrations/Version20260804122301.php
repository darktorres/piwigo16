<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Baseline bootstrap, users/auth domain: users, user_infos, groups,
 * user_group, user_access, group_access, user_auth_keys,
 * user_failed_logins, sessions, user_feed, user_mail_notification -- 11
 * of the 39 tables. See Version20260804122300's own docblock for the
 * full translation-rule rationale shared by every migration in this set
 * (unsigned widening table, boolean mapping, COLLATE "C", JSONB,
 * lastmodified trigger, FULLTEXT->tsvector+GIN, per-schema-unique index
 * naming).
 */
final class Version20260804122301 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Baseline bootstrap: users/auth domain (users, user_infos, groups, and 8 more)';
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
        $this->addSql(<<<'SQL'
            CREATE TABLE `group_access` (
              `group_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'granted group id',
              `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'private album the group is granted access to',
              PRIMARY KEY  (`group_id`,`cat_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-group private album permission grants'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `groups` (
              `id` smallint(5) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key',
              `name` varchar(255) NOT NULL default '' COMMENT 'group display name, unique',
              `is_default` tinyint(1) NOT NULL default '0' COMMENT 'every newly registered user is automatically added to groups marked default',
              `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp, set on insert only',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `groups_ui1` (`name`),
              KEY `lastmodified` (`lastmodified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user groups for bulk permission and membership management'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `sessions` (
              `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'composite PHP session id, IP-hash-prefixed by SessionService',
              `data` mediumtext NOT NULL COMMENT 'serialized PHP session payload',
              `expiration` datetime default NULL COMMENT 'when this session becomes invalid',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP session storage backend'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_access` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'granted user id',
              `cat_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'private album the user is granted access to',
              PRIMARY KEY  (`user_id`,`cat_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private album permission grants'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_auth_keys` (
              `auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `auth_key` varchar(255) NOT NULL COMMENT 'the token value: a random persistent-login token for key_type=auth_key, or the public pkid-... identifier for key_type=api_key',
              `apikey_secret` VARCHAR(255) DEFAULT NULL COMMENT 'hashed secret half of a key_type=api_key pair, null for auth_key rows',
              `user_id` mediumint(8) unsigned NOT NULL COMMENT 'owning user id',
              `created_on` datetime NOT NULL COMMENT 'when the key was issued',
              `duration` int(11) unsigned DEFAULT NULL COMMENT 'requested key lifetime, seconds for auth_key rows or days for api_key rows, see expired_on for the actual cutoff',
              `expired_on` datetime NOT NULL COMMENT 'when the key stops being valid',
              `apikey_name` VARCHAR(100) DEFAULT NULL COMMENT 'user-given label for a key_type=api_key row, null for auth_key rows',
              `key_type` VARCHAR(40) DEFAULT NULL COMMENT 'auth_key for a persistent-login/URL-login token, api_key for a personal API key',
              `revoked_on`  datetime DEFAULT NULL COMMENT 'when the key was manually revoked, null if still live',
              `last_used_on` datetime DEFAULT NULL COMMENT 'when the key last authenticated a request',
              `last_notified_on` datetime DEFAULT NULL COMMENT 'when the owner was last emailed an expiration notice',
              PRIMARY KEY (`auth_key_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='persistent-login tokens and personal API keys, two row shapes sharing one table'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_feed` (
              `id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'private feed token, passed as ?feed= to authenticate as the owning user without a login',
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'user this feed token authenticates as',
              `last_check` datetime default NULL COMMENT 'when this feed URL was last polled',
              PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user private RSS feed tokens'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_group` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'member user id',
              `group_id` smallint(5) unsigned NOT NULL default '0' COMMENT 'group the user belongs to',
              PRIMARY KEY  (`group_id`,`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user to group membership'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_infos` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'the owning users.id row, application-assigned, never auto-generated here',
              `nb_image_page` smallint(3) unsigned NOT NULL default '15' COMMENT 'photos per page preference',
              `status` enum('webmaster','admin','normal','generic','guest') NOT NULL default 'guest' COMMENT 'account role, gates admin access and permission checks',
              `language` varchar(50) NOT NULL default 'en_UK' COMMENT 'interface language, references languages.id',
              `expand` tinyint(1) NOT NULL default '0' COMMENT 'whether the album tree auto-expands in the menu',
              `show_nb_comments` tinyint(1) NOT NULL default '0' COMMENT 'whether comment counts are shown alongside thumbnails',
              `show_nb_hits` tinyint(1) NOT NULL default '0' COMMENT 'whether view counts are shown alongside thumbnails',
              `recent_period` tinyint(3) unsigned NOT NULL default '7' COMMENT 'number of days considered recent for the recent photos/albums views',
              `theme` varchar(255) NOT NULL default 'modus' COMMENT 'interface theme, references themes.id',
              `registration_date` datetime default NULL COMMENT 'account creation date',
              `enabled_high` tinyint(1) NOT NULL default '1' COMMENT 'whether the user may view/download the original, high-definition photo',
              `level` tinyint unsigned NOT NULL default '0' COMMENT 'effective permission level, gates access to images.level-restricted photos',
              `activation_key` varchar(255) default NULL COMMENT 'hashed password-reset token, see AuthService::setActivationKey and password.php',
              `activation_key_expire` datetime default NULL COMMENT 'when activation_key stops being valid',
              `last_visit` datetime default NULL COMMENT 'when the user was last seen, refreshed once per session length',
              `last_visit_from_history` tinyint(1) NOT NULL default '0' COMMENT 'whether last_visit was already backfilled from the history table, avoids repeating that lookup',
              `lastmodified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'row last-update timestamp',
              `preferences` JSON default NULL COMMENT 'generic per-user key-value bag for preferences with no dedicated column',
              PRIMARY KEY (`user_id`),
              KEY `lastmodified` (`lastmodified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user profile and preferences, one row per users.id'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_mail_notification` (
              `user_id` mediumint(8) unsigned NOT NULL default '0' COMMENT 'subscribing user id',
              `check_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'private token used in subscribe/unsubscribe confirmation email links',
              `enabled` tinyint(1) NOT NULL default '0' COMMENT 'whether the user currently receives new-photo notification emails',
              `last_send` datetime default NULL COMMENT 'when a notification email was last sent to this user',
              PRIMARY KEY  (`user_id`),
              UNIQUE KEY `user_mail_notification_ui1` (`check_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='per-user new-photo email notification subscriptions'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `users` (
              `id` mediumint(8) unsigned NOT NULL auto_increment COMMENT 'surrogate primary key, referenced by user_id everywhere else',
              `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL default '' COMMENT 'login name, unique',
              `password` varchar(255) default NULL COMMENT 'hashed login password',
              `mail_address` varchar(255) default NULL COMMENT 'account email address',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `users_ui1` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='core login accounts, column names configurable via CurrentConfig::userFields for multi-auth integrations'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE `user_failed_logins` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'surrogate primary key',
              `user_id` mediumint(8) unsigned DEFAULT NULL COMMENT 'targeted user id, if the attempted username resolved to a real account',
              `ip` varchar(45) NOT NULL COMMENT 'REMOTE_ADDR the failed login attempt came from',
              `attempted_at` DATETIME NOT NULL COMMENT 'when the failed attempt occurred',
              PRIMARY KEY (`id`),
              KEY `idx_user_failed_logins_user_time` (`user_id`, `attempted_at`),
              KEY `idx_user_failed_logins_ip_time` (`ip`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='failed login attempts, read and written by AuthService::pwgLogin() via UserFailedLoginRepository to back its dual-scope (username + IP) lockout'
            SQL);
    }

    private function upPostgres(): void
    {
        $this->addSql('CREATE TABLE group_access (group_id integer NOT NULL, cat_id integer NOT NULL, PRIMARY KEY (group_id, cat_id))');
        $this->addSql("COMMENT ON TABLE group_access IS 'per-group private album permission grants'");
        $this->addSql("COMMENT ON COLUMN group_access.group_id IS 'granted group id'");
        $this->addSql("COMMENT ON COLUMN group_access.cat_id IS 'private album the group is granted access to'");

        $this->addSql(
            'CREATE TABLE groups (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            "name varchar(255) NOT NULL DEFAULT '', " .
            'is_default boolean NOT NULL DEFAULT false, ' .
            'lastmodified timestamp(0) NOT NULL DEFAULT now(), ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('ALTER TABLE groups ADD CONSTRAINT groups_ui1_unique UNIQUE (name)');
        $this->addSql('CREATE INDEX groups_lastmodified_idx ON groups (lastmodified)');
        $this->addSql('CREATE TRIGGER trg_groups_lastmodified BEFORE UPDATE ON groups FOR EACH ROW EXECUTE FUNCTION set_lastmodified()');
        $this->addSql("COMMENT ON TABLE groups IS 'user groups for bulk permission and membership management'");
        $this->addSql("COMMENT ON COLUMN groups.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN groups.name IS 'group display name, unique'");
        $this->addSql("COMMENT ON COLUMN groups.is_default IS 'every newly registered user is automatically added to groups marked default'");
        $this->addSql("COMMENT ON COLUMN groups.lastmodified IS 'row last-update timestamp, set on insert only'");

        $this->addSql(
            'CREATE TABLE sessions (' .
            "id varchar(50) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'data text NOT NULL, ' .
            'expiration timestamp DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE sessions IS 'PHP session storage backend'");
        $this->addSql("COMMENT ON COLUMN sessions.id IS 'composite PHP session id, IP-hash-prefixed by SessionService'");
        $this->addSql("COMMENT ON COLUMN sessions.data IS 'serialized PHP session payload'");
        $this->addSql("COMMENT ON COLUMN sessions.expiration IS 'when this session becomes invalid'");

        $this->addSql('CREATE TABLE user_access (user_id integer NOT NULL, cat_id integer NOT NULL, PRIMARY KEY (user_id, cat_id))');
        $this->addSql("COMMENT ON TABLE user_access IS 'per-user private album permission grants'");
        $this->addSql("COMMENT ON COLUMN user_access.user_id IS 'granted user id'");
        $this->addSql("COMMENT ON COLUMN user_access.cat_id IS 'private album the user is granted access to'");

        $this->addSql(
            'CREATE TABLE user_auth_keys (' .
            'auth_key_id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'auth_key varchar(255) NOT NULL, ' .
            'apikey_secret varchar(255) DEFAULT NULL, ' .
            'user_id integer NOT NULL, ' .
            'created_on timestamp(0) NOT NULL, ' .
            'duration bigint DEFAULT NULL, ' .
            'expired_on timestamp(0) NOT NULL, ' .
            'apikey_name varchar(100) DEFAULT NULL, ' .
            'key_type varchar(40) DEFAULT NULL, ' .
            'revoked_on timestamp DEFAULT NULL, ' .
            'last_used_on timestamp DEFAULT NULL, ' .
            'last_notified_on timestamp DEFAULT NULL, ' .
            'PRIMARY KEY (auth_key_id))'
        );
        $this->addSql("COMMENT ON TABLE user_auth_keys IS 'persistent-login tokens and personal API keys, two row shapes sharing one table'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.auth_key_id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.auth_key IS 'the token value: a random persistent-login token for key_type=auth_key, or the public pkid-... identifier for key_type=api_key'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.apikey_secret IS 'hashed secret half of a key_type=api_key pair, null for auth_key rows'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.user_id IS 'owning user id'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.created_on IS 'when the key was issued'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.duration IS 'requested key lifetime, seconds for auth_key rows or days for api_key rows, see expired_on for the actual cutoff'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.expired_on IS 'when the key stops being valid'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.apikey_name IS 'user-given label for a key_type=api_key row, null for auth_key rows'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.key_type IS 'auth_key for a persistent-login/URL-login token, api_key for a personal API key'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.revoked_on IS 'when the key was manually revoked, null if still live'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.last_used_on IS 'when the key last authenticated a request'");
        $this->addSql("COMMENT ON COLUMN user_auth_keys.last_notified_on IS 'when the owner was last emailed an expiration notice'");

        $this->addSql(
            'CREATE TABLE user_feed (' .
            "id varchar(50) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'user_id integer NOT NULL, ' .
            'last_check timestamp DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql("COMMENT ON TABLE user_feed IS 'per-user private RSS feed tokens'");
        $this->addSql("COMMENT ON COLUMN user_feed.id IS 'private feed token, passed as ?feed= to authenticate as the owning user without a login'");
        $this->addSql("COMMENT ON COLUMN user_feed.user_id IS 'user this feed token authenticates as'");
        $this->addSql("COMMENT ON COLUMN user_feed.last_check IS 'when this feed URL was last polled'");

        $this->addSql('CREATE TABLE user_group (user_id integer NOT NULL, group_id integer NOT NULL, PRIMARY KEY (group_id, user_id))');
        $this->addSql("COMMENT ON TABLE user_group IS 'user to group membership'");
        $this->addSql("COMMENT ON COLUMN user_group.user_id IS 'member user id'");
        $this->addSql("COMMENT ON COLUMN user_group.group_id IS 'group the user belongs to'");

        $this->addSql(
            'CREATE TABLE user_infos (' .
            'user_id integer NOT NULL, ' .
            'nb_image_page integer NOT NULL DEFAULT 15, ' .
            "status varchar(10) NOT NULL DEFAULT 'guest' CHECK (status IN ('webmaster', 'admin', 'normal', 'generic', 'guest')), " .
            "language varchar(50) NOT NULL DEFAULT 'en_UK', " .
            'expand boolean NOT NULL DEFAULT false, ' .
            'show_nb_comments boolean NOT NULL DEFAULT false, ' .
            'show_nb_hits boolean NOT NULL DEFAULT false, ' .
            'recent_period smallint NOT NULL DEFAULT 7, ' .
            "theme varchar(255) NOT NULL DEFAULT 'modus', " .
            'registration_date timestamp(0) DEFAULT NULL, ' .
            'enabled_high boolean NOT NULL DEFAULT true, ' .
            'level smallint NOT NULL DEFAULT 0, ' .
            'activation_key varchar(255) DEFAULT NULL, ' .
            'activation_key_expire timestamp(0) DEFAULT NULL, ' .
            'last_visit timestamp(0) DEFAULT NULL, ' .
            'last_visit_from_history boolean NOT NULL DEFAULT false, ' .
            'lastmodified timestamp(0) NOT NULL DEFAULT now(), ' .
            'preferences jsonb DEFAULT NULL, ' .
            'PRIMARY KEY (user_id))'
        );
        $this->addSql('CREATE INDEX user_infos_lastmodified_idx ON user_infos (lastmodified)');
        $this->addSql('CREATE TRIGGER trg_user_infos_lastmodified BEFORE UPDATE ON user_infos FOR EACH ROW EXECUTE FUNCTION set_lastmodified()');
        $this->addSql("COMMENT ON TABLE user_infos IS 'per-user profile and preferences, one row per users.id'");
        $this->addSql("COMMENT ON COLUMN user_infos.user_id IS 'the owning users.id row, application-assigned, never auto-generated here'");
        $this->addSql("COMMENT ON COLUMN user_infos.nb_image_page IS 'photos per page preference'");
        $this->addSql("COMMENT ON COLUMN user_infos.status IS 'account role, gates admin access and permission checks'");
        $this->addSql("COMMENT ON COLUMN user_infos.language IS 'interface language, references languages.id'");
        $this->addSql("COMMENT ON COLUMN user_infos.expand IS 'whether the album tree auto-expands in the menu'");
        $this->addSql("COMMENT ON COLUMN user_infos.show_nb_comments IS 'whether comment counts are shown alongside thumbnails'");
        $this->addSql("COMMENT ON COLUMN user_infos.show_nb_hits IS 'whether view counts are shown alongside thumbnails'");
        $this->addSql("COMMENT ON COLUMN user_infos.recent_period IS 'number of days considered recent for the recent photos/albums views'");
        $this->addSql("COMMENT ON COLUMN user_infos.theme IS 'interface theme, references themes.id'");
        $this->addSql("COMMENT ON COLUMN user_infos.registration_date IS 'account creation date'");
        $this->addSql("COMMENT ON COLUMN user_infos.enabled_high IS 'whether the user may view/download the original, high-definition photo'");
        $this->addSql("COMMENT ON COLUMN user_infos.level IS 'effective permission level, gates access to images.level-restricted photos'");
        $this->addSql("COMMENT ON COLUMN user_infos.activation_key IS 'hashed password-reset token, see AuthService::setActivationKey and password.php'");
        $this->addSql("COMMENT ON COLUMN user_infos.activation_key_expire IS 'when activation_key stops being valid'");
        $this->addSql("COMMENT ON COLUMN user_infos.last_visit IS 'when the user was last seen, refreshed once per session length'");
        $this->addSql("COMMENT ON COLUMN user_infos.last_visit_from_history IS 'whether last_visit was already backfilled from the history table, avoids repeating that lookup'");
        $this->addSql("COMMENT ON COLUMN user_infos.lastmodified IS 'row last-update timestamp'");
        $this->addSql("COMMENT ON COLUMN user_infos.preferences IS 'generic per-user key-value bag for preferences with no dedicated column'");

        $this->addSql(
            'CREATE TABLE user_mail_notification (' .
            'user_id integer NOT NULL, ' .
            "check_key varchar(16) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'enabled boolean NOT NULL DEFAULT false, ' .
            'last_send timestamp DEFAULT NULL, ' .
            'PRIMARY KEY (user_id))'
        );
        $this->addSql('ALTER TABLE user_mail_notification ADD CONSTRAINT user_mail_notification_ui1_unique UNIQUE (check_key)');
        $this->addSql("COMMENT ON TABLE user_mail_notification IS 'per-user new-photo email notification subscriptions'");
        $this->addSql("COMMENT ON COLUMN user_mail_notification.user_id IS 'subscribing user id'");
        $this->addSql("COMMENT ON COLUMN user_mail_notification.check_key IS 'private token used in subscribe/unsubscribe confirmation email links'");
        $this->addSql("COMMENT ON COLUMN user_mail_notification.enabled IS 'whether the user currently receives new-photo notification emails'");
        $this->addSql("COMMENT ON COLUMN user_mail_notification.last_send IS 'when a notification email was last sent to this user'");

        $this->addSql(
            'CREATE TABLE users (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            "username varchar(100) COLLATE \"C\" NOT NULL DEFAULT '', " .
            'password varchar(255) DEFAULT NULL, ' .
            'mail_address varchar(255) DEFAULT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('ALTER TABLE users ADD CONSTRAINT users_ui1_unique UNIQUE (username)');
        $this->addSql("COMMENT ON TABLE users IS 'core login accounts, column names configurable via CurrentConfig::userFields for multi-auth integrations'");
        $this->addSql("COMMENT ON COLUMN users.id IS 'surrogate primary key, referenced by user_id everywhere else'");
        $this->addSql("COMMENT ON COLUMN users.username IS 'login name, unique'");
        $this->addSql("COMMENT ON COLUMN users.password IS 'hashed login password'");
        $this->addSql("COMMENT ON COLUMN users.mail_address IS 'account email address'");

        $this->addSql(
            'CREATE TABLE user_failed_logins (' .
            'id integer GENERATED BY DEFAULT AS IDENTITY, ' .
            'user_id integer DEFAULT NULL, ' .
            'ip varchar(45) NOT NULL, ' .
            'attempted_at timestamp NOT NULL, ' .
            'PRIMARY KEY (id))'
        );
        $this->addSql('CREATE INDEX idx_user_failed_logins_user_time ON user_failed_logins (user_id, attempted_at)');
        $this->addSql('CREATE INDEX idx_user_failed_logins_ip_time ON user_failed_logins (ip, attempted_at)');
        $this->addSql("COMMENT ON TABLE user_failed_logins IS 'failed login attempts, read and written by AuthService::pwgLogin() via UserFailedLoginRepository to back its dual-scope (username + IP) lockout'");
        $this->addSql("COMMENT ON COLUMN user_failed_logins.id IS 'surrogate primary key'");
        $this->addSql("COMMENT ON COLUMN user_failed_logins.user_id IS 'targeted user id, if the attempted username resolved to a real account'");
        $this->addSql("COMMENT ON COLUMN user_failed_logins.ip IS 'REMOTE_ADDR the failed login attempt came from'");
        $this->addSql("COMMENT ON COLUMN user_failed_logins.attempted_at IS 'when the failed attempt occurred'");
    }
}
