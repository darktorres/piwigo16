<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\Connection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.6.2.php (P23 sub-batch 8g-4): upgrade from
 * 1.6.2 to 1.7.0-era schema, then chain to UpgradeFrom_1_7_0. The bare
 * load_conf_from_db()/boolean_to_string() calls became
 * ConfigDb::loadConfFromDb()/MysqliDb::booleanToString(), later retargeted
 * onto CurrentConfigService::get() (Legacy Coupling Retirement Phase 8,
 * 8d) -- ConfigService::loadConfFromDb() never dual-writes $conf, so the
 * bulk of DB-persisted keys this step reads straight afterward are
 * captured once via Config::all() into a local $dbconf instead, same
 * shape as the dbconf capture already used by Patch123/Patch125. The
 * commented-out ws_access CREATE (dropped upstream before the Butterfly
 * release) is preserved as history.
 */
final class UpgradeFrom_1_6_2 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.6.2';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $queries = [
            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'categories`
  ADD COLUMN `permalink` varchar(64) default NULL
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'categories`
  ADD COLUMN `image_order` varchar(128) default NULL
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'categories`
  ADD UNIQUE `categories_i3` (`permalink`)
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . "groups`
  ADD COLUMN `is_default` enum('true','false') NOT NULL default 'false'
;",

            '
RENAME TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'history` TO `' . \Piwigo\Config\Config::dbPrefix() . 'history_backup`
;',

            '
CREATE TABLE `' . \Piwigo\Config\Config::dbPrefix() . "history` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `date` date NOT NULL default '0000-00-00',
  `time` time NOT NULL default '00:00:00',
  `year` smallint(4) NOT NULL default '0',
  `month` tinyint(2) NOT NULL default '0',
  `day` tinyint(2) NOT NULL default '0',
  `hour` tinyint(2) NOT NULL default '0',
  `user_id` smallint(5) NOT NULL default '0',
  `IP` varchar(15) NOT NULL default '',
  `section` enum('categories','tags','search','list','favorites','most_visited','best_rated','recent_pics','recent_cats') default NULL,
  `category_id` smallint(5) default NULL,
  `tag_ids` varchar(50) default NULL,
  `image_id` mediumint(8) default NULL,
  `summarized` enum('true','false') default 'false',
  `image_type` enum('picture','high','other') default NULL,
  PRIMARY KEY  (`id`),
  KEY `history_i1` (`summarized`)
) ENGINE=MyISAM
;",

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'image_category`
  DROP INDEX `image_category_i1`
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'image_category`
  ADD INDEX `image_category_i1` (`category_id`)
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'image_category`
  DROP INDEX `image_category_i2`
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'images`
  ADD COLUMN `high_filesize` mediumint(9) unsigned default NULL
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . "user_infos`
  CHANGE COLUMN `language`
    `language` varchar(50) NOT NULL default 'en_UK.iso-8859-1'
;",

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'user_infos`
  DROP COLUMN `auto_login_key`
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . "user_infos`
  ADD COLUMN `show_nb_hits` enum('true','false') NOT NULL default 'false'
;",

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'user_mail_notification`
  DROP INDEX `uidx_check_key`
;',

            '
ALTER TABLE `' . \Piwigo\Config\Config::dbPrefix() . 'user_mail_notification`
  ADD UNIQUE `user_mail_notification_ui1` (`check_key`)
;',

            '
CREATE TABLE `' . \Piwigo\Config\Config::dbPrefix() . "history_summary` (
  `id` varchar(13) NOT NULL default '',
  `year` smallint(4) NOT NULL default '0',
  `month` tinyint(2) default NULL,
  `day` tinyint(2) default NULL,
  `hour` tinyint(2) default NULL,
  `nb_pages` int(11) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM
;",

            '
CREATE TABLE `' . \Piwigo\Config\Config::dbPrefix() . "old_permalinks` (
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  `permalink` varchar(64) NOT NULL default '',
  `date_deleted` datetime NOT NULL default '0000-00-00 00:00:00',
  `last_hit` datetime default NULL,
  `hit` int(10) unsigned NOT NULL default '0',
  PRIMARY KEY  (`permalink`)
) ENGINE=MyISAM
;",

            '
CREATE TABLE `' . \Piwigo\Config\Config::dbPrefix() . "plugins` (
  `id` varchar(64) binary NOT NULL default '',
  `state` enum('inactive','active') NOT NULL default 'inactive',
  `version` varchar(64) NOT NULL default '0',
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM
;",

            '
CREATE TABLE `' . \Piwigo\Config\Config::dbPrefix() . "user_cache_categories` (
  `user_id` smallint(5) NOT NULL default '0',
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  `max_date_last` datetime default NULL,
  `count_images` mediumint(8) unsigned default '0',
  `count_categories` mediumint(8) unsigned default '0',
  PRIMARY KEY  (`user_id`,`cat_id`)
) ENGINE=MyISAM
;",

            /* TABLE DROPPED BEFORE Butterfly/Piwigo release - see later DROP IF EXISTS
        "
        CREATE TABLE `".PREFIX_TABLE."ws_access` (
          `id` smallint(5) unsigned NOT NULL auto_increment,
          `name` varchar(32) NOT NULL default '',
          `access` varchar(255) default NULL,
          `start` datetime default NULL,
          `end` datetime default NULL,
          `request` varchar(255) default NULL,
          `limit` smallint(5) unsigned default NULL,
          `comment` varchar(255) default NULL,
          PRIMARY KEY  (`id`),
          UNIQUE KEY `ws_access_ui1` (`name`)
        ) ENGINE=MyISAM COMMENT='Access for Web Services'
        ;",*/

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('show_nb_hits', 'false', 'Show hits count under thumbnails')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('history_admin','false','keep a history of administrator visits on your website')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('history_guest','true','keep a history of guest visits on your website')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('allow_user_registration','true','allow visitors to register?')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('secret_key', MD5(RAND()), 'a secret key specific to the gallery for internal use')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('nbm_send_html_mail','true','Send mail on HTML format for notification by mail')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_new_user','false','Send an email to theadministrators when a user registers')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_comment_validation','false','Send an email to the administrators when a comment requires validation')
;",

            '
INSERT INTO ' . \Piwigo\Config\Config::dbPrefix() . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_picture_uploaded','false','Send an email to the administrators when a picture is uploaded')
;",

            '
UPDATE ' . \Piwigo\Config\Config::dbPrefix() . "user_cache
  SET need_update = 'true'
;",

        ];

        foreach ($queries as $query) {
            $conn->executeStatement($query);
        }

        $replacements = [
            ['&#039;', '\''],
            ['&quot;', '"'],
            ['&lt;',   '<'],
            ['&gt;',   '>'],
            ['&amp;',  '&'], // <- this must be the last one
        ];

        foreach ($replacements as $replacement) {
            $query = '
UPDATE ' . \Piwigo\Config\Config::dbPrefix() . 'comments
  SET content = REPLACE(content, "' .
              addslashes($replacement[0]) .
              '", "' .
              addslashes($replacement[1]) .
              '")
;';
            $conn->executeStatement($query);
        }

        \Piwigo\Config\CurrentConfigService::get()->loadConfFromDb();
        $dbconf = \Piwigo\Config\Config::all();

        $query = '
UPDATE ' . Tables::userInfos() . "
SET
  template = '" . $dbconf['default_template'] . "',
  nb_image_line = " . $dbconf['nb_image_line'] . ',
  nb_line_page = ' . $dbconf['nb_line_page'] . ",
  language = '" . $dbconf['default_language'] . "',
  maxwidth = " .
          (empty($dbconf['default_maxwidth']) ? 'NULL' : $dbconf['default_maxwidth']) .
          ',
  maxheight = ' .
          (empty($dbconf['default_maxheight']) ? 'NULL' : $dbconf['default_maxheight']) .
          ',
  recent_period = ' . $dbconf['recent_period'] . ",
  expand = '" . SqlDialect::booleanToString($dbconf['auto_expand']) . "',
  show_nb_comments = '" . SqlDialect::booleanToString($dbconf['show_nb_comments']) . "',
  show_nb_hits = '" . SqlDialect::booleanToString($dbconf['show_nb_hits']) . "',
  enabled_high = '" . SqlDialect::booleanToString(
              ($dbconf['newuser_default_enabled_high'] ?? true)
          ) .
          "'
WHERE
  user_id = " . $dbconf['default_user_id'] . ';';
        $conn->executeStatement($query);

        $query = '
DELETE FROM ' . Tables::config() . "
WHERE
  param IN
(
  'default_template',
  'nb_image_line',
  'nb_line_page',
  'default_language',
  'default_maxwidth',
  'default_maxheight',
  'recent_period',
  'auto_expand',
  'show_nb_comments',
  'show_nb_hits'
)
;";
        $conn->executeStatement($query);

        // now we upgrade from 1.7.0
        new UpgradeFrom_1_7_0()
            ->apply($conn);
    }
}
