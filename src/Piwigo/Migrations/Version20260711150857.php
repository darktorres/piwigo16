<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Bootstraps the 34 origin tables when they don't already exist. Real
 * installs and the test fixture always start from the loaded origin
 * schema (tests/Fixtures/piwigo-17.0.sql), so this fires only for a
 * genuinely blank database.
 *
 * MySQL/MariaDB: raw SQL matching the current origin
 * `install/piwigo_structure-mysql.sql` verbatim (MyISAM, explicit
 * DEFAULT CHARSET=latin1 -- Migration 20260711150858 converts both to
 * InnoDB+utf8mb4 next). The explicit latin1 (rather than leaving it
 * undeclared, matching the origin file literally) is a real, empirically
 * found fix: origin's undeclared charset historically meant "whatever
 * the server default is," which was latin1-era MySQL when this schema
 * was designed -- but a modern container's database defaults to
 * utf8mb4, and MyISAM's 1000-byte max key length rejects
 * `sites.galleries_url varchar(255)` under a 4-byte-per-char charset
 * (255*4=1020 > 1000) while it fits comfortably under 1-byte latin1.
 * Transient either way since the very next migration converts
 * everything to utf8mb4. This is NOT a portable Schema-API description
 * on purpose: it must produce byte-identical
 * column widths (`mediumint`/`smallint`/`tinyint`) to what a real
 * origin-schema-then-migrate path produces, since every later migration
 * (type-alignment, FK constraints) hand-writes raw SQL/Schema-API calls
 * against those EXACT widths. An earlier version of this migration used
 * the portable Schema API here too (INTEGER/SMALLINT, since DBAL has no
 * distinct mediumint/tinyint type) -- caught empirically against a real
 * blank MySQL container: `activity.performed_by` (raw-SQL `mediumint`)
 * and `users.id` (portable-API `int`) ended up different column widths,
 * and MySQL rejects the FK between them as "incompatible" even though
 * both are logically unsigned non-negative ranges. Real bug, not a test
 * artifact -- fixed by making this migration's MySQL/MariaDB path match
 * origin exactly instead of going through the portable abstraction.
 *
 * PostgreSQL: has no origin baseline to inherit at all (unlike
 * MySQL/MariaDB), so it's the one real path that needs the portable
 * Schema API's translation -- mediumint/smallint/tinyint all collapse to
 * INTEGER/SMALLINT there (PG has no 1/2/3-byte-int distinction to
 * preserve, so there's no equivalent widths-must-match risk). No
 * distinct portable ENUM type either -- enum's value-list constraint
 * isn't enforced outside MySQL, matching enum->tinyint being deferred to
 * P17-23 anyway. Column DEFAULT values are intentionally not set on the
 * PostgreSQL path -- it only matters for a from-scratch database with no
 * data being inserted yet, and no application code targets a
 * non-MySQL/MariaDB connection today (only ConfigEntry is a mapped
 * entity). Documented gap, not an oversight.
 */
final class Version20260711150857 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Bootstrap the 34 origin tables on a blank database (origin-exact DDL on MySQL/MariaDB, portable Schema API on PostgreSQL)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($this->platform instanceof PostgreSQLPlatform) {
            $this->bootstrapPortable($schema, $prefix);

            return;
        }

        // === activity ===
        if (! $schema->hasTable($prefix . 'activity')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'activity ('
                . '`activity_id` int(11) unsigned NOT NULL AUTO_INCREMENT, '
                . '`object` varchar(255) NOT NULL, '
                . '`object_id` int(11) unsigned NOT NULL, '
                . '`action` varchar(255) NOT NULL, '
                . '`performed_by` mediumint(8) unsigned NOT NULL, '
                . '`session_idx` varchar(255) NOT NULL, '
                . '`ip_address` varchar(50) DEFAULT NULL, '
                . '`occured_on` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
                . '`details` varchar(255) DEFAULT NULL, '
                . '`user_agent` varchar(255) DEFAULT NULL, '
                . 'PRIMARY KEY (`activity_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === caddie ===
        if (! $schema->hasTable($prefix . 'caddie')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'caddie ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`element_id` mediumint(8) NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`user_id`,`element_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === categories ===
        if (! $schema->hasTable($prefix . 'categories')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'categories ('
                . '`id` smallint(5) unsigned NOT NULL auto_increment, '
                . '`name` varchar(255) NOT NULL default \'\', '
                . '`id_uppercat` smallint(5) unsigned default NULL, '
                . '`comment` text, '
                . '`dir` varchar(255) default NULL, '
                . '`rank` smallint(5) unsigned default NULL, '
                . '`status` enum(\'public\',\'private\') NOT NULL default \'public\', '
                . '`site_id` tinyint(4) unsigned default NULL, '
                . '`visible` enum(\'true\',\'false\') NOT NULL default \'true\', '
                . '`representative_picture_id` mediumint(8) unsigned default NULL, '
                . '`uppercats` varchar(255) NOT NULL default \'\', '
                . '`commentable` enum(\'true\',\'false\') NOT NULL default \'true\', '
                . '`global_rank` varchar(255) default NULL, '
                . '`image_order` varchar(128) default NULL, '
                . '`permalink` varchar(64) binary default NULL, '
                . '`lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY  (`id`), '
                . 'UNIQUE KEY `categories_i3` (`permalink`), '
                . 'KEY `categories_i2` (`id_uppercat`), '
                . 'KEY `lastmodified` (`lastmodified`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === comments ===
        if (! $schema->hasTable($prefix . 'comments')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'comments ('
                . '`id` int(11) unsigned NOT NULL auto_increment, '
                . '`image_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`date` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . '`author` varchar(255) default NULL, '
                . '`email` varchar(255) default NULL, '
                . '`author_id` mediumint(8) unsigned DEFAULT NULL, '
                . '`anonymous_id` varchar(45) NOT NULL, '
                . '`website_url` varchar(255) DEFAULT NULL, '
                . '`content` longtext, '
                . '`validated` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`validation_date` datetime default NULL, '
                . 'PRIMARY KEY  (`id`), '
                . 'KEY `comments_i2` (`validation_date`), '
                . 'KEY `comments_i1` (`image_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === config ===
        if (! $schema->hasTable($prefix . 'config')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'config ('
                . '`param` varchar(40) NOT NULL default \'\', '
                . '`value` text, '
                . '`comment` varchar(255) default NULL, '
                . 'PRIMARY KEY  (`param`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === favorites ===
        if (! $schema->hasTable($prefix . 'favorites')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'favorites ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`image_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`user_id`,`image_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === group_access ===
        if (! $schema->hasTable($prefix . 'group_access')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'group_access ('
                . '`group_id` smallint(5) unsigned NOT NULL default \'0\', '
                . '`cat_id` smallint(5) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`group_id`,`cat_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === groups ===
        if (! $schema->hasTable($prefix . 'groups')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'groups ('
                . '`id` smallint(5) unsigned NOT NULL auto_increment, '
                . '`name` varchar(255) NOT NULL default \'\', '
                . '`is_default` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY  (`id`), '
                . 'UNIQUE KEY `groups_ui1` (`name`), '
                . 'KEY `lastmodified` (`lastmodified`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === history ===
        if (! $schema->hasTable($prefix . 'history')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'history ('
                . '`id` int(10) unsigned NOT NULL auto_increment, '
                . '`date` date NOT NULL default \'1970-01-01\', '
                . '`time` time NOT NULL default \'00:00:00\', '
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`IP` char(39) NOT NULL default \'\', '
                . '`section` enum(\'categories\',\'tags\',\'search\',\'list\',\'favorites\',\'most_visited\',\'best_rated\',\'recent_pics\',\'recent_cats\') default NULL, '
                . '`category_id` smallint(5) default NULL, '
                . '`search_id` int(10) unsigned default NULL, '
                . '`tag_ids` varchar(50) default NULL, '
                . '`image_id` mediumint(8) default NULL, '
                . '`image_type` enum(\'picture\',\'high\',\'other\') default NULL, '
                . '`format_id` int(11) unsigned default NULL, '
                . '`auth_key_id` int(11) unsigned DEFAULT NULL, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === history_summary ===
        if (! $schema->hasTable($prefix . 'history_summary')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'history_summary ('
                . '`year` smallint(4) NOT NULL default \'0\', '
                . '`month` tinyint(2) default NULL, '
                . '`day` tinyint(2) default NULL, '
                . '`hour` tinyint(2) default NULL, '
                . '`nb_pages` int(11) default NULL, '
                . '`history_id_from` int(10) unsigned default NULL, '
                . '`history_id_to` int(10) unsigned default NULL, '
                . 'UNIQUE KEY history_summary_ymdh (`year`,`month`,`day`,`hour`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === image_category ===
        if (! $schema->hasTable($prefix . 'image_category')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'image_category ('
                . '`image_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`category_id` smallint(5) unsigned NOT NULL default \'0\', '
                . '`rank` mediumint(8) unsigned default NULL, '
                . 'PRIMARY KEY  (`image_id`,`category_id`), '
                . 'KEY `image_category_i1` (`category_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === image_format ===
        if (! $schema->hasTable($prefix . 'image_format')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'image_format ('
                . '`format_id` int(11) unsigned NOT NULL auto_increment, '
                . '`image_id` mediumint(8) unsigned NOT NULL DEFAULT \'0\', '
                . '`ext` varchar(255) NOT NULL, '
                . '`filesize` mediumint(9) unsigned DEFAULT NULL, '
                . 'PRIMARY KEY  (`format_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === image_tag ===
        if (! $schema->hasTable($prefix . 'image_tag')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'image_tag ('
                . '`image_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`tag_id` smallint(5) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`image_id`,`tag_id`), '
                . 'KEY `image_tag_i1` (`tag_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === images ===
        if (! $schema->hasTable($prefix . 'images')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'images ('
                . '`id` mediumint(8) unsigned NOT NULL auto_increment, '
                . '`file` varchar(255) binary NOT NULL default \'\', '
                . '`date_available` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . '`date_creation` datetime default NULL, '
                . '`name` varchar(255) default NULL, '
                . '`comment` text, '
                . '`author` varchar(255) default NULL, '
                . '`hit` int(10) unsigned NOT NULL default \'0\', '
                . '`filesize` mediumint(9) unsigned default NULL, '
                . '`width` smallint(9) unsigned default NULL, '
                . '`height` smallint(9) unsigned default NULL, '
                . '`coi` char(4) default NULL COMMENT \'center of interest\', '
                . '`representative_ext` varchar(4) default NULL, '
                . '`date_metadata_update` date default NULL, '
                . '`rating_score` float(5,2) unsigned default NULL, '
                . '`path` varchar(255) NOT NULL default \'\', '
                . '`storage_category_id` smallint(5) unsigned default NULL, '
                . '`level` tinyint unsigned NOT NULL default \'0\', '
                . '`md5sum` char(32) default NULL, '
                . '`added_by` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`rotation` tinyint unsigned default NULL, '
                . '`latitude` double(8, 6) default NULL, '
                . '`longitude` double(9, 6) default NULL, '
                . '`lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY  (`id`), '
                . 'KEY `images_i2` (`date_available`), '
                . 'KEY `images_i3` (`rating_score`), '
                . 'KEY `images_i4` (`hit`), '
                . 'KEY `images_i5` (`date_creation`), '
                . 'KEY `images_i1` (`storage_category_id`), '
                . 'KEY `images_i6` (`latitude`), '
                . 'KEY `images_i7` (`path`), '
                . 'KEY `lastmodified` (`lastmodified`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === languages ===
        if (! $schema->hasTable($prefix . 'languages')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'languages ('
                . '`id` varchar(64) NOT NULL default \'\', '
                . '`version` varchar(64) NOT NULL default \'0\', '
                . '`name` varchar(64) default NULL, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === lounge ===
        if (! $schema->hasTable($prefix . 'lounge')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'lounge ('
                . '`image_id` mediumint(8) unsigned NOT NULL DEFAULT \'0\', '
                . '`category_id` smallint(5) unsigned NOT NULL DEFAULT \'0\', '
                . 'PRIMARY KEY (`image_id`,`category_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === old_permalinks ===
        if (! $schema->hasTable($prefix . 'old_permalinks')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'old_permalinks ('
                . '`cat_id` smallint(5) unsigned NOT NULL default \'0\', '
                . '`permalink` varchar(64) binary NOT NULL default \'\', '
                . '`date_deleted` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . '`last_hit` datetime default NULL, '
                . '`hit` int(10) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`permalink`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === plugins ===
        if (! $schema->hasTable($prefix . 'plugins')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'plugins ('
                . '`id` varchar(64) binary NOT NULL default \'\', '
                . '`state` enum(\'inactive\',\'active\') NOT NULL default \'inactive\', '
                . '`version` varchar(64) NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === rate ===
        if (! $schema->hasTable($prefix . 'rate')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'rate ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`element_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`anonymous_id` varchar(45) NOT NULL default \'\', '
                . '`rate` tinyint(2) unsigned NOT NULL default \'0\', '
                . '`date` date NOT NULL default \'1970-01-01\', '
                . 'PRIMARY KEY  (`element_id`,`user_id`,`anonymous_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === search ===
        if (! $schema->hasTable($prefix . 'search')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'search ('
                . '`id` int(10) unsigned NOT NULL auto_increment, '
                . '`search_uuid` CHAR(23) DEFAULT NULL, '
                . '`created_on` DATETIME DEFAULT NULL, '
                . '`created_by` MEDIUMINT(8) UNSIGNED, '
                . '`forked_from` INT(10) UNSIGNED, '
                . '`rules` text, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === sessions ===
        if (! $schema->hasTable($prefix . 'sessions')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'sessions ('
                . '`id` varchar(50) binary NOT NULL default \'\', '
                . '`data` mediumtext NOT NULL, '
                . '`expiration` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === sites ===
        if (! $schema->hasTable($prefix . 'sites')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'sites ('
                . '`id` tinyint(4) NOT NULL auto_increment, '
                . '`galleries_url` varchar(255) NOT NULL default \'\', '
                . 'PRIMARY KEY  (`id`), '
                . 'UNIQUE KEY `sites_ui1` (`galleries_url`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === tags ===
        if (! $schema->hasTable($prefix . 'tags')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'tags ('
                . '`id` smallint(5) unsigned NOT NULL auto_increment, '
                . '`name` varchar(255) NOT NULL default \'\', '
                . '`url_name` varchar(255) binary NOT NULL default \'\', '
                . '`lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY  (`id`), '
                . 'KEY `tags_i1` (`url_name`), '
                . 'KEY `lastmodified` (`lastmodified`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === themes ===
        if (! $schema->hasTable($prefix . 'themes')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'themes ('
                . '`id` varchar(64) NOT NULL default \'\', '
                . '`version` varchar(64) NOT NULL default \'0\', '
                . '`name` varchar(64) default NULL, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === upgrade ===
        if (! $schema->hasTable($prefix . 'upgrade')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'upgrade ('
                . '`id` varchar(20) NOT NULL default \'\', '
                . '`applied` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . '`description` varchar(255) default NULL, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_access ===
        if (! $schema->hasTable($prefix . 'user_access')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_access ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`cat_id` smallint(5) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`user_id`,`cat_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_auth_keys ===
        if (! $schema->hasTable($prefix . 'user_auth_keys')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_auth_keys ('
                . '`auth_key_id` int(11) unsigned NOT NULL AUTO_INCREMENT, '
                . '`auth_key` varchar(255) NOT NULL, '
                . '`apikey_secret` VARCHAR(255) DEFAULT NULL, '
                . '`user_id` mediumint(8) unsigned NOT NULL, '
                . '`created_on` datetime NOT NULL, '
                . '`duration` int(11) unsigned DEFAULT NULL, '
                . '`expired_on` datetime NOT NULL, '
                . '`apikey_name` VARCHAR(100) DEFAULT NULL, '
                . '`key_type` VARCHAR(40) DEFAULT NULL, '
                . '`revoked_on`  datetime DEFAULT NULL, '
                . '`last_used_on` datetime DEFAULT NULL, '
                . '`last_notified_on` datetime DEFAULT NULL, '
                . 'PRIMARY KEY (`auth_key_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_cache ===
        if (! $schema->hasTable($prefix . 'user_cache')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_cache ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`need_update` enum(\'true\',\'false\') NOT NULL default \'true\', '
                . '`cache_update_time` integer unsigned NOT NULL default 0, '
                . '`forbidden_categories` mediumtext, '
                . '`nb_total_images` mediumint(8) unsigned default NULL, '
                . '`last_photo_date` datetime DEFAULT NULL, '
                . '`nb_available_tags` INT(5) DEFAULT NULL, '
                . '`nb_available_comments` INT(5) DEFAULT NULL, '
                . '`image_access_type` enum(\'NOT IN\',\'IN\') NOT NULL default \'NOT IN\', '
                . '`image_access_list` mediumtext default NULL, '
                . 'PRIMARY KEY  (`user_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_cache_categories ===
        if (! $schema->hasTable($prefix . 'user_cache_categories')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_cache_categories ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`cat_id` smallint(5) unsigned NOT NULL default \'0\', '
                . '`date_last` datetime default NULL, '
                . '`max_date_last` datetime default NULL, '
                . '`nb_images` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`count_images` mediumint(8) unsigned default \'0\', '
                . '`nb_categories` mediumint(8) unsigned default \'0\', '
                . '`count_categories` mediumint(8) unsigned default \'0\', '
                . '`user_representative_picture_id` mediumint(8) unsigned default NULL, '
                . 'PRIMARY KEY  (`user_id`,`cat_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_feed ===
        if (! $schema->hasTable($prefix . 'user_feed')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_feed ('
                . '`id` varchar(50) binary NOT NULL default \'\', '
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`last_check` datetime default NULL, '
                . 'PRIMARY KEY  (`id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_group ===
        if (! $schema->hasTable($prefix . 'user_group')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_group ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`group_id` smallint(5) unsigned NOT NULL default \'0\', '
                . 'PRIMARY KEY  (`group_id`,`user_id`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_infos ===
        if (! $schema->hasTable($prefix . 'user_infos')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_infos ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`nb_image_page` smallint(3) unsigned NOT NULL default \'15\', '
                . '`status` enum(\'webmaster\',\'admin\',\'normal\',\'generic\',\'guest\') NOT NULL default \'guest\', '
                . '`language` varchar(50) NOT NULL default \'en_UK\', '
                . '`expand` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`show_nb_comments` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`show_nb_hits` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`recent_period` tinyint(3) unsigned NOT NULL default \'7\', '
                . '`theme` varchar(255) NOT NULL default \'modus\', '
                . '`registration_date` datetime NOT NULL default \'1970-01-01 00:00:00\', '
                . '`enabled_high` enum(\'true\',\'false\') NOT NULL default \'true\', '
                . '`level` tinyint unsigned NOT NULL default \'0\', '
                . '`activation_key` varchar(255) default NULL, '
                . '`activation_key_expire` datetime default NULL, '
                . '`last_visit` datetime default NULL, '
                . '`last_visit_from_history` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`lastmodified` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
                . '`preferences` TEXT default NULL, '
                . 'PRIMARY KEY (`user_id`), '
                . 'KEY `lastmodified` (`lastmodified`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === user_mail_notification ===
        if (! $schema->hasTable($prefix . 'user_mail_notification')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'user_mail_notification ('
                . '`user_id` mediumint(8) unsigned NOT NULL default \'0\', '
                . '`check_key` varchar(16) binary NOT NULL default \'\', '
                . '`enabled` enum(\'true\',\'false\') NOT NULL default \'false\', '
                . '`last_send` datetime default NULL, '
                . 'PRIMARY KEY  (`user_id`), '
                . 'UNIQUE KEY `user_mail_notification_ui1` (`check_key`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

        // === users ===
        if (! $schema->hasTable($prefix . 'users')) {
            $this->addSql('CREATE TABLE ' . $prefix . 'users ('
                . '`id` mediumint(8) unsigned NOT NULL auto_increment, '
                . '`username` varchar(100) binary NOT NULL default \'\', '
                . '`password` varchar(255) default NULL, '
                . '`mail_address` varchar(255) default NULL, '
                . 'PRIMARY KEY  (`id`), '
                . 'UNIQUE KEY `users_ui1` (`username`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=latin1');
        }

    }

    private function bootstrapPortable(Schema $schema, string $prefix): void
    {
        // === activity ===
        if (! $schema->hasTable($prefix . 'activity')) {
            $t = $schema->createTable($prefix . 'activity');
            $t->addColumn('activity_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('object', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('object_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('action', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('performed_by', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('session_idx', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('ip_address', Types::STRING, [
                'notnull' => false,
                'length' => 50,
            ]);
            $t->addColumn('occured_on', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('details', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('user_agent', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->setPrimaryKey(['activity_id']);
        }

        // === caddie ===
        if (! $schema->hasTable($prefix . 'caddie')) {
            $t = $schema->createTable($prefix . 'caddie');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('element_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['user_id', 'element_id']);
        }

        // === categories ===
        if (! $schema->hasTable($prefix . 'categories')) {
            $t = $schema->createTable($prefix . 'categories');
            $t->addColumn('id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('id_uppercat', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('comment', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->addColumn('dir', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('rank', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('site_id', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('visible', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('representative_picture_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('uppercats', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('commentable', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('global_rank', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('image_order', Types::STRING, [
                'notnull' => false,
                'length' => 128,
            ]);
            $t->addColumn('permalink', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $t->addColumn('lastmodified', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === comments ===
        if (! $schema->hasTable($prefix . 'comments')) {
            $t = $schema->createTable($prefix . 'comments');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('date', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('author', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('email', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('author_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('anonymous_id', Types::STRING, [
                'notnull' => true,
                'length' => 45,
            ]);
            $t->addColumn('website_url', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('content', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->addColumn('validated', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('validation_date', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === config ===
        if (! $schema->hasTable($prefix . 'config')) {
            $t = $schema->createTable($prefix . 'config');
            $t->addColumn('param', Types::STRING, [
                'notnull' => true,
                'length' => 40,
            ]);
            $t->addColumn('value', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->addColumn('comment', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->setPrimaryKey(['param']);
        }

        // === favorites ===
        if (! $schema->hasTable($prefix . 'favorites')) {
            $t = $schema->createTable($prefix . 'favorites');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['user_id', 'image_id']);
        }

        // === group_access ===
        if (! $schema->hasTable($prefix . 'group_access')) {
            $t = $schema->createTable($prefix . 'group_access');
            $t->addColumn('group_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('cat_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['group_id', 'cat_id']);
        }

        // === groups ===
        if (! $schema->hasTable($prefix . 'groups')) {
            $t = $schema->createTable($prefix . 'groups');
            $t->addColumn('id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('is_default', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('lastmodified', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === history ===
        if (! $schema->hasTable($prefix . 'history')) {
            $t = $schema->createTable($prefix . 'history');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('date', Types::DATE_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('time', Types::TIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('IP', Types::STRING, [
                'notnull' => true,
                'length' => 39,
            ]);
            $t->addColumn('section', Types::STRING, [
                'notnull' => false,
                'length' => 12,
            ]);
            $t->addColumn('category_id', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $t->addColumn('search_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('tag_ids', Types::STRING, [
                'notnull' => false,
                'length' => 50,
            ]);
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('image_type', Types::STRING, [
                'notnull' => false,
                'length' => 8,
            ]);
            $t->addColumn('format_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('auth_key_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === history_summary === (no PK in origin -- gains summary_id AUTO_INCREMENT PK in P17-23 per docs/PLAN-REPLAY.md)
        if (! $schema->hasTable($prefix . 'history_summary')) {
            $t = $schema->createTable($prefix . 'history_summary');
            $t->addColumn('year', Types::SMALLINT, [
                'notnull' => true,
            ]);
            $t->addColumn('month', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $t->addColumn('day', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $t->addColumn('hour', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $t->addColumn('nb_pages', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('history_id_from', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('history_id_to', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addUniqueIndex(['year', 'month', 'day', 'hour'], 'history_summary_ymdh');
        }

        // === image_category ===
        if (! $schema->hasTable($prefix . 'image_category')) {
            $t = $schema->createTable($prefix . 'image_category');
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('category_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('rank', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['image_id', 'category_id']);
        }

        // === image_format ===
        if (! $schema->hasTable($prefix . 'image_format')) {
            $t = $schema->createTable($prefix . 'image_format');
            $t->addColumn('format_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('ext', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('filesize', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['format_id']);
        }

        // === image_tag ===
        if (! $schema->hasTable($prefix . 'image_tag')) {
            $t = $schema->createTable($prefix . 'image_tag');
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('tag_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['image_id', 'tag_id']);
        }

        // === images ===
        if (! $schema->hasTable($prefix . 'images')) {
            $t = $schema->createTable($prefix . 'images');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('file', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('date_available', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('date_creation', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('comment', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->addColumn('author', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('hit', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('filesize', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('width', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('height', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('coi', Types::STRING, [
                'notnull' => false,
                'length' => 4,
            ]);
            $t->addColumn('representative_ext', Types::STRING, [
                'notnull' => false,
                'length' => 4,
            ]);
            $t->addColumn('date_metadata_update', Types::DATE_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('rating_score', Types::FLOAT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('storage_category_id', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('level', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('md5sum', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $t->addColumn('added_by', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('rotation', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('latitude', Types::FLOAT, [
                'notnull' => false,
            ]);
            $t->addColumn('longitude', Types::FLOAT, [
                'notnull' => false,
            ]);
            $t->addColumn('lastmodified', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === languages ===
        if (! $schema->hasTable($prefix . 'languages')) {
            $t = $schema->createTable($prefix . 'languages');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('version', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === lounge ===
        if (! $schema->hasTable($prefix . 'lounge')) {
            $t = $schema->createTable($prefix . 'lounge');
            $t->addColumn('image_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('category_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['image_id', 'category_id']);
        }

        // === old_permalinks ===
        if (! $schema->hasTable($prefix . 'old_permalinks')) {
            $t = $schema->createTable($prefix . 'old_permalinks');
            $t->addColumn('cat_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('permalink', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('date_deleted', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('last_hit', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('hit', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['permalink']);
        }

        // === plugins ===
        if (! $schema->hasTable($prefix . 'plugins')) {
            $t = $schema->createTable($prefix . 'plugins');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('state', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('version', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === rate ===
        if (! $schema->hasTable($prefix . 'rate')) {
            $t = $schema->createTable($prefix . 'rate');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('element_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('anonymous_id', Types::STRING, [
                'notnull' => true,
                'length' => 45,
            ]);
            $t->addColumn('rate', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('date', Types::DATE_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['element_id', 'user_id', 'anonymous_id']);
        }

        // === search ===
        if (! $schema->hasTable($prefix . 'search')) {
            $t = $schema->createTable($prefix . 'search');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('search_uuid', Types::STRING, [
                'notnull' => false,
                'length' => 23,
            ]);
            $t->addColumn('created_on', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('created_by', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('forked_from', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('rules', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === sessions ===
        if (! $schema->hasTable($prefix . 'sessions')) {
            $t = $schema->createTable($prefix . 'sessions');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $t->addColumn('data', Types::TEXT, [
                'notnull' => true,
            ]);
            $t->addColumn('expiration', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === sites ===
        if (! $schema->hasTable($prefix . 'sites')) {
            $t = $schema->createTable($prefix . 'sites');
            $t->addColumn('id', Types::SMALLINT, [
                'notnull' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('galleries_url', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === tags ===
        if (! $schema->hasTable($prefix . 'tags')) {
            $t = $schema->createTable($prefix . 'tags');
            $t->addColumn('id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('url_name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('lastmodified', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === themes ===
        if (! $schema->hasTable($prefix . 'themes')) {
            $t = $schema->createTable($prefix . 'themes');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('version', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('name', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === upgrade ===
        if (! $schema->hasTable($prefix . 'upgrade')) {
            $t = $schema->createTable($prefix . 'upgrade');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 20,
            ]);
            $t->addColumn('applied', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === user_access ===
        if (! $schema->hasTable($prefix . 'user_access')) {
            $t = $schema->createTable($prefix . 'user_access');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('cat_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['user_id', 'cat_id']);
        }

        // === user_auth_keys ===
        if (! $schema->hasTable($prefix . 'user_auth_keys')) {
            $t = $schema->createTable($prefix . 'user_auth_keys');
            $t->addColumn('auth_key_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('auth_key', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('apikey_secret', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('created_on', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('duration', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('expired_on', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('apikey_name', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $t->addColumn('key_type', Types::STRING, [
                'notnull' => false,
                'length' => 40,
            ]);
            $t->addColumn('revoked_on', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('last_used_on', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('last_notified_on', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['auth_key_id']);
        }

        // === user_cache ===
        if (! $schema->hasTable($prefix . 'user_cache')) {
            $t = $schema->createTable($prefix . 'user_cache');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('need_update', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('cache_update_time', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('forbidden_categories', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->addColumn('nb_total_images', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('last_photo_date', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('nb_available_tags', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('nb_available_comments', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('image_access_type', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('image_access_list', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['user_id']);
        }

        // === user_cache_categories ===
        if (! $schema->hasTable($prefix . 'user_cache_categories')) {
            $t = $schema->createTable($prefix . 'user_cache_categories');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('cat_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('date_last', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('max_date_last', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('nb_images', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('count_images', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('nb_categories', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('count_categories', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->addColumn('user_representative_picture_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['user_id', 'cat_id']);
        }

        // === user_feed ===
        if (! $schema->hasTable($prefix . 'user_feed')) {
            $t = $schema->createTable($prefix . 'user_feed');
            $t->addColumn('id', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('last_check', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['id']);
        }

        // === user_group ===
        if (! $schema->hasTable($prefix . 'user_group')) {
            $t = $schema->createTable($prefix . 'user_group');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('group_id', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->setPrimaryKey(['group_id', 'user_id']);
        }

        // === user_infos ===
        if (! $schema->hasTable($prefix . 'user_infos')) {
            $t = $schema->createTable($prefix . 'user_infos');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('nb_image_page', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 9,
            ]);
            $t->addColumn('language', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $t->addColumn('expand', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('show_nb_comments', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('show_nb_hits', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('recent_period', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('theme', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $t->addColumn('registration_date', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->addColumn('enabled_high', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('level', Types::SMALLINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('activation_key', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('activation_key_expire', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('last_visit', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('last_visit_from_history', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('lastmodified', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->addColumn('preferences', Types::TEXT, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['user_id']);
        }

        // === user_mail_notification ===
        if (! $schema->hasTable($prefix . 'user_mail_notification')) {
            $t = $schema->createTable($prefix . 'user_mail_notification');
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $t->addColumn('check_key', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $t->addColumn('enabled', Types::STRING, [
                'notnull' => true,
                'length' => 8,
            ]);
            $t->addColumn('last_send', Types::DATETIME_MUTABLE, [
                'notnull' => false,
            ]);
            $t->setPrimaryKey(['user_id']);
        }

        // === users ===
        if (! $schema->hasTable($prefix . 'users')) {
            $t = $schema->createTable($prefix . 'users');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('username', Types::STRING, [
                'notnull' => true,
                'length' => 100,
            ]);
            $t->addColumn('password', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->addColumn('mail_address', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $t->setPrimaryKey(['id']);
        }

    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping the bootstrapped origin tables is not supported -- there is no upgrade path back to origin/16.x.'
        );
    }
}
