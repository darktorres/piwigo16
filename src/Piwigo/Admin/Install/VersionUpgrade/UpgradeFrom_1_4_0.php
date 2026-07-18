<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Piwigo\Core\TimingHelper;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.4.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.4.0 to 1.5.0-era schema, then chain to UpgradeFrom_1_5_0. The
 * original's include_once of admin/include/functions.php (needed for
 * mass_inserts() back when it lived there) is gone -- the call targets
 * MysqliDb::massInserts() directly. $last_time is the true global the
 * runner declares (the top-level assignment fed later timing echoes).
 */
final class UpgradeFrom_1_4_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.4.0';
    }

    #[\Override]
    public function apply(): void
    {
        /**
         * @var string
         */
        global $prefixeTable;
        /**
         * @var array<string, mixed>
         */
        global $page;
        global $last_time;

        $last_time = TimingHelper::getMoment();

        // will the user have to edit include/config_local.inc.php for
        // prefix_thumbnail configuration parameter
        $query = '
SELECT value
  FROM ' . Tables::config() . '
  WHERE param = \'prefix_thumbnail\'
;';
        [$prefix_thumbnail] = MysqliDb::fetchRow(MysqliDb::query($query));

        // delete obsolete configuration
        $query = '
DELETE
  FROM ' . $prefixeTable . 'config
  WHERE param IN (
   \'prefix_thumbnail\',
   \'mail_webmaster\',
   \'upload_maxfilesize\',
   \'upload_maxwidth\',
   \'upload_maxheight\',
   \'upload_maxwidth_thumbnail\',
   \'upload_maxheight_thumbnail\',
   \'mail_notification\',
   \'use_iptc\',
   \'use_exif\',
   \'show_iptc\',
   \'show_exif\',
   \'authorize_remembering\'
   )
;';
        MysqliDb::query($query);

        $queries = [

            '
ALTER TABLE piwigo_categories
  CHANGE COLUMN date_last date_last datetime default NULL
;',

            '
ALTER TABLE piwigo_comments
  ADD COLUMN validation_date datetime default NULL
;',

            '
UPDATE piwigo_comments
  SET validation_date = date
',

            '
ALTER TABLE piwigo_comments
  ADD INDEX comments_i1 (image_id)
;',

            '
ALTER TABLE piwigo_comments
  ADD INDEX comments_i2 (validation_date)
;',

            "
ALTER TABLE piwigo_favorites
  CHANGE COLUMN user_id user_id smallint(5) NOT NULL default '0'
;",

            "
ALTER TABLE piwigo_images
  CHANGE COLUMN date_available
    date_available datetime NOT NULL default '0000-00-00 00:00:00'
;",

            "
ALTER TABLE piwigo_rate
  CHANGE COLUMN user_id user_id smallint(5) NOT NULL default '0'
;",

            "
ALTER TABLE piwigo_sessions
  CHANGE COLUMN user_id user_id smallint(5) NOT NULL default '0'
;",

            "
ALTER TABLE piwigo_user_access
  CHANGE COLUMN user_id user_id smallint(5) NOT NULL default '0'
;",

            '
DROP TABLE piwigo_user_forbidden
;',

            "
ALTER TABLE piwigo_user_group
 CHANGE COLUMN user_id user_id smallint(5) NOT NULL default '0'
;",

            '
ALTER TABLE piwigo_users
  CHANGE COLUMN id id smallint(5) NOT NULL auto_increment
;',

            "
CREATE TABLE piwigo_caddie (
  user_id smallint(5) NOT NULL default '0',
  element_id mediumint(8) NOT NULL default '0',
  PRIMARY KEY  (user_id,element_id)
) ENGINE=MyISAM
;",

            "
CREATE TABLE piwigo_user_cache (
  user_id smallint(5) NOT NULL default '0',
  need_update enum('true','false') NOT NULL default 'true',
  forbidden_categories text,
  PRIMARY KEY  (user_id)
) ENGINE=MyISAM
;",

            "
CREATE TABLE piwigo_user_feed (
  id varchar(50) binary NOT NULL default '',
  user_id smallint(5) NOT NULL default '0',
  last_check datetime default NULL,
  PRIMARY KEY  (id)
) ENGINE=MyISAM
;",

            "
CREATE TABLE piwigo_user_infos (
  user_id smallint(5) NOT NULL default '0',
  nb_image_line tinyint(1) unsigned NOT NULL default '5',
  nb_line_page tinyint(3) unsigned NOT NULL default '3',
  status enum('admin','guest') NOT NULL default 'guest',
  language varchar(50) NOT NULL default 'english',
  maxwidth smallint(6) default NULL,
  maxheight smallint(6) default NULL,
  expand enum('true','false') NOT NULL default 'false',
  show_nb_comments enum('true','false') NOT NULL default 'false',
  recent_period tinyint(3) unsigned NOT NULL default '7',
  template varchar(255) NOT NULL default 'yoga',
  registration_date datetime NOT NULL default '0000-00-00 00:00:00',
  UNIQUE KEY user_infos_ui1 (user_id)
) ENGINE=MyISAM
;",
        ];

        foreach ($queries as $query) {
            $query = str_replace('piwigo_', $prefixeTable, $query);
            MysqliDb::query($query);
        }

        // user datas migration from piwigo_users to piwigo_user_infos
        $query = '
SELECT *
  FROM ' . Tables::users() . '
;';

        $datas = [];
        [$dbnow] = MysqliDb::fetchRow(MysqliDb::query('SELECT NOW();'));

        $result = MysqliDb::query($query);
        while ($row = MysqliDb::fetchAssoc($result)) {
            $row['user_id'] = $row['id'];
            $row['registration_date'] = $dbnow;
            array_push($datas, $row);
        }

        MysqliDb::massInserts(
            Tables::userInfos(),
            [
                'user_id',
                'nb_image_line',
                'nb_line_page',
                'status',
                'language',
                'maxwidth',
                'maxheight',
                'expand',
                'show_nb_comments',
                'recent_period',
                'template',
                'registration_date',
            ],
            $datas
        );

        $queries = [

            '
UPDATE ' . Tables::userInfos() . "
  SET template = 'yoga'
;",

            '
UPDATE ' . Tables::userInfos() . "
  SET language = 'en_UK.iso-8859-1'
  WHERE language NOT IN ('en_UK.iso-8859-1', 'fr_FR.iso-8859-1')
;",

            '
UPDATE ' . Tables::config() . "
  SET value = 'en_UK.iso-8859-1'
  WHERE param = 'default_language'
    AND value NOT IN ('en_UK.iso-8859-1', 'fr_FR.iso-8859-1')
;",

            '
UPDATE ' . Tables::config() . "
  SET value = 'yoga'
  WHERE param = 'default_template'
;",

            '
INSERT INTO ' . Tables::config() . "
  (param,value,comment)
  VALUES
  (
    'gallery_title',
    'Piwigo demonstration site',
    'Title at top of each page and for RSS feed'
  )
;",

            '
INSERT INTO ' . Tables::config() . "
  (param,value,comment)
  VALUES
  (
    'gallery_description',
    'My photos web site',
    'Short description displayed with gallery title'
  )
;",

        ];

        foreach ($queries as $query) {
            $query = str_replace('piwigo_', $prefixeTable, $query);
            MysqliDb::query($query);
        }

        if ($prefix_thumbnail != 'TN-') {
            array_push(
                $page['infos'],
                'the thumbnail prefix configuration parameter was moved to configuration
file, copy config.inc.php from "tools" directory to "local/config" directory
and edit $conf[\'prefix_thumbnail\'] = ' . $prefix_thumbnail
            );
        }

        // now we upgrade from 1.5.0 to 1.6.0
        new UpgradeFrom_1_5_0()
            ->apply();
    }
}
