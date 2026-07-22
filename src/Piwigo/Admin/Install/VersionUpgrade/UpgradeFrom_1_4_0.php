<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.4.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.4.0 to 1.5.0-era schema, then chain to UpgradeFrom_1_5_0. The
 * original's include_once of admin/include/functions.php (needed for
 * mass_inserts() back when it lived there) is gone -- the call targets
 * MysqliDb::massInserts() directly. The former `global $last_time;` was
 * confirmed provably dead (assigned, never read anywhere in the repo --
 * the runner-scope timing echo it fed no longer exists) and deleted
 * outright, not retargeted.
 */
final class UpgradeFrom_1_4_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.4.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // will the user have to edit include/config_local.inc.php for
        // prefix_thumbnail configuration parameter
        $query = '
SELECT value
  FROM ' . Tables::config() . '
  WHERE param = :param
;';
        $prefix_thumbnail_row = $conn->fetchNumeric($query, [
            'param' => 'prefix_thumbnail',
        ]);
        $prefix_thumbnail = $prefix_thumbnail_row !== false && is_scalar($prefix_thumbnail_row[0]) ? (string) $prefix_thumbnail_row[0] : '';

        // delete obsolete configuration
        $query = '
DELETE
  FROM ' . Tables::config() . '
  WHERE param IN (:params)
;';
        $conn->executeStatement($query, [
            'params' => [
                'prefix_thumbnail',
                'mail_webmaster',
                'upload_maxfilesize',
                'upload_maxwidth',
                'upload_maxheight',
                'upload_maxwidth_thumbnail',
                'upload_maxheight_thumbnail',
                'mail_notification',
                'use_iptc',
                'use_exif',
                'show_iptc',
                'show_exif',
                'authorize_remembering',
            ],
        ], [
            'params' => ArrayParameterType::STRING,
        ]);

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

        foreach ($queries as $queryTemplate) {
            $query = str_replace('piwigo_', Config::dbPrefix(), $queryTemplate);
            $conn->executeStatement($query);
        }

        // user datas migration from piwigo_users to piwigo_user_infos
        $query = '
SELECT *
  FROM ' . Tables::users() . '
;';

        $datas = [];
        $now_row = $conn->fetchNumeric('SELECT NOW();');
        $dbnow = $now_row !== false ? $now_row[0] : null;

        foreach ($conn->fetchAllAssociative($query) as $row) {
            $row['user_id'] = $row['id'];
            $row['registration_date'] = $dbnow;
            array_push($datas, $row);
        }

        new BatchWriter($conn)
            ->massInsert(
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

        $conn->executeStatement('
UPDATE ' . Tables::userInfos() . '
  SET template = :template
;', [
            'template' => 'yoga',
        ]);

        $conn->executeStatement('
UPDATE ' . Tables::userInfos() . '
  SET language = :language
  WHERE language NOT IN (:keptLanguages)
;', [
            'language' => 'en_UK.iso-8859-1',
            'keptLanguages' => ['en_UK.iso-8859-1', 'fr_FR.iso-8859-1'],
        ], [
            'keptLanguages' => ArrayParameterType::STRING,
        ]);

        $conn->executeStatement('
UPDATE ' . Tables::config() . '
  SET value = :value
  WHERE param = :param
    AND value NOT IN (:keptLanguages)
;', [
            'value' => 'en_UK.iso-8859-1',
            'param' => 'default_language',
            'keptLanguages' => ['en_UK.iso-8859-1', 'fr_FR.iso-8859-1'],
        ], [
            'keptLanguages' => ArrayParameterType::STRING,
        ]);

        $conn->executeStatement('
UPDATE ' . Tables::config() . '
  SET value = :value
  WHERE param = :param
;', [
            'value' => 'yoga',
            'param' => 'default_template',
        ]);

        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'gallery_title',
            'value' => 'Piwigo demonstration site',
            'comment' => 'Title at top of each page and for RSS feed',
        ]);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'gallery_description',
            'value' => 'My photos web site',
            'comment' => 'Short description displayed with gallery title',
        ]);

        if ($prefix_thumbnail !== 'TN-') {
            \Piwigo\Core\PageState::current()->addInfo(
                'the thumbnail prefix configuration parameter was moved to configuration
file, copy config.inc.php from "tools" directory to "local/config" directory
and edit $conf[\'prefix_thumbnail\'] = ' . $prefix_thumbnail
            );
        }

        // now we upgrade from 1.5.0 to 1.6.0
        new UpgradeFrom_1_5_0()
            ->apply($conn);
    }
}
