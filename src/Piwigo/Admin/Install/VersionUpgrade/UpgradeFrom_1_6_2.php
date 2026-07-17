<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Piwigo\Config\ConfigDb;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.6.2.php (P23 sub-batch 8g-4): upgrade from
 * 1.6.2 to 1.7.0-era schema, then chain to UpgradeFrom_1_7_0. The bare
 * load_conf_from_db()/boolean_to_string() calls became
 * ConfigDb::loadConfFromDb()/MysqliDb::booleanToString(); the commented-out
 * ws_access CREATE (dropped upstream before the Butterfly release) is
 * preserved as history.
 */
final class UpgradeFrom_1_6_2 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.6.2';
    }

    #[\Override]
    public function apply(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var string $prefixeTable
         */
        global $conf, $prefixeTable;

        $queries = [
            '
ALTER TABLE `' . $prefixeTable . 'categories`
  ADD COLUMN `permalink` varchar(64) default NULL
;',

            '
ALTER TABLE `' . $prefixeTable . 'categories`
  ADD COLUMN `image_order` varchar(128) default NULL
;',

            '
ALTER TABLE `' . $prefixeTable . 'categories`
  ADD UNIQUE `categories_i3` (`permalink`)
;',

            '
ALTER TABLE `' . $prefixeTable . "groups`
  ADD COLUMN `is_default` enum('true','false') NOT NULL default 'false'
;",

            '
RENAME TABLE `' . $prefixeTable . 'history` TO `' . $prefixeTable . 'history_backup`
;',

            '
CREATE TABLE `' . $prefixeTable . "history` (
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
ALTER TABLE `' . $prefixeTable . 'image_category`
  DROP INDEX `image_category_i1`
;',

            '
ALTER TABLE `' . $prefixeTable . 'image_category`
  ADD INDEX `image_category_i1` (`category_id`)
;',

            '
ALTER TABLE `' . $prefixeTable . 'image_category`
  DROP INDEX `image_category_i2`
;',

            '
ALTER TABLE `' . $prefixeTable . 'images`
  ADD COLUMN `high_filesize` mediumint(9) unsigned default NULL
;',

            '
ALTER TABLE `' . $prefixeTable . "user_infos`
  CHANGE COLUMN `language`
    `language` varchar(50) NOT NULL default 'en_UK.iso-8859-1'
;",

            '
ALTER TABLE `' . $prefixeTable . 'user_infos`
  DROP COLUMN `auto_login_key`
;',

            '
ALTER TABLE `' . $prefixeTable . "user_infos`
  ADD COLUMN `show_nb_hits` enum('true','false') NOT NULL default 'false'
;",

            '
ALTER TABLE `' . $prefixeTable . 'user_mail_notification`
  DROP INDEX `uidx_check_key`
;',

            '
ALTER TABLE `' . $prefixeTable . 'user_mail_notification`
  ADD UNIQUE `user_mail_notification_ui1` (`check_key`)
;',

            '
CREATE TABLE `' . $prefixeTable . "history_summary` (
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
CREATE TABLE `' . $prefixeTable . "old_permalinks` (
  `cat_id` smallint(5) unsigned NOT NULL default '0',
  `permalink` varchar(64) NOT NULL default '',
  `date_deleted` datetime NOT NULL default '0000-00-00 00:00:00',
  `last_hit` datetime default NULL,
  `hit` int(10) unsigned NOT NULL default '0',
  PRIMARY KEY  (`permalink`)
) ENGINE=MyISAM
;",

            '
CREATE TABLE `' . $prefixeTable . "plugins` (
  `id` varchar(64) binary NOT NULL default '',
  `state` enum('inactive','active') NOT NULL default 'inactive',
  `version` varchar(64) NOT NULL default '0',
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM
;",

            '
CREATE TABLE `' . $prefixeTable . "user_cache_categories` (
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
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('show_nb_hits', 'false', 'Show hits count under thumbnails')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('history_admin','false','keep a history of administrator visits on your website')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('history_guest','true','keep a history of guest visits on your website')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('allow_user_registration','true','allow visitors to register?')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('secret_key', MD5(RAND()), 'a secret key specific to the gallery for internal use')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('nbm_send_html_mail','true','Send mail on HTML format for notification by mail')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('nbm_send_recent_post_dates','true','Send recent post by dates for notification by mail')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_new_user','false','Send an email to theadministrators when a user registers')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_comment','false','Send an email to the administrators when a valid comment is entered')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_comment_validation','false','Send an email to the administrators when a comment requires validation')
;",

            '
INSERT INTO ' . $prefixeTable . "config
  (param,value,comment)
  VALUES
  ('email_admin_on_picture_uploaded','false','Send an email to the administrators when a picture is uploaded')
;",

            '
UPDATE ' . $prefixeTable . "user_cache
  SET need_update = 'true'
;",

        ];

        foreach ($queries as $query) {
            MysqliDb::query($query);
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
UPDATE ' . $prefixeTable . 'comments
  SET content = REPLACE(content, "' .
              addslashes($replacement[0]) .
              '", "' .
              addslashes($replacement[1]) .
              '")
;';
            MysqliDb::query($query);
        }

        ConfigDb::loadConfFromDb();

        $query = '
UPDATE ' . Tables::userInfos() . "
SET
  template = '" . $conf['default_template'] . "',
  nb_image_line = " . $conf['nb_image_line'] . ',
  nb_line_page = ' . $conf['nb_line_page'] . ",
  language = '" . $conf['default_language'] . "',
  maxwidth = " .
          (empty($conf['default_maxwidth']) ? 'NULL' : $conf['default_maxwidth']) .
          ',
  maxheight = ' .
          (empty($conf['default_maxheight']) ? 'NULL' : $conf['default_maxheight']) .
          ',
  recent_period = ' . $conf['recent_period'] . ",
  expand = '" . MysqliDb::booleanToString($conf['auto_expand']) . "',
  show_nb_comments = '" . MysqliDb::booleanToString($conf['show_nb_comments']) . "',
  show_nb_hits = '" . MysqliDb::booleanToString($conf['show_nb_hits']) . "',
  enabled_high = '" . MysqliDb::booleanToString(
              ($conf['newuser_default_enabled_high'] ?? true)
          ) .
          "'
WHERE
  user_id = " . $conf['default_user_id'] . ';';
        MysqliDb::query($query);

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
        MysqliDb::query($query);

        // now we upgrade from 1.7.0
        new UpgradeFrom_1_7_0()
            ->apply();
    }
}
