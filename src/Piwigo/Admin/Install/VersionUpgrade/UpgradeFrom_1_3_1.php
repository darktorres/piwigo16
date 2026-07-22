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
use Piwigo\Admin\Install\DbPatch\DatabaseConfigChanges;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Former install/upgrade_1.3.1.php (P23 sub-batch 8g-4): upgrade from
 * 1.3.x (x >= 1) to 1.4.0, then chain to UpgradeFrom_1_4_0. The bare
 * get_subcat_ids() call became CategoryService::getSubcatIds() (on the
 * instance the original itself already constructed);
 * array_push($mysql_changes, ...) became DatabaseConfigChanges::push().
 */
final class UpgradeFrom_1_3_1 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.3.1';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // save data before deletion
        $query = '
SELECT prefix_thumbnail, mail_webmaster
  FROM ' . Tables::config() . '
;';
        $save = $conn->fetchAssociative($query);
        if ($save === false) {
            $save = [];
        }

        $queries = [
            '
DROP TABLE phpwebgallery_config
;',

            "
CREATE TABLE phpwebgallery_config (
  param varchar(40) NOT NULL default '',
  value varchar(255) default NULL,
  comment varchar(255) default NULL,
  PRIMARY KEY  (param)
) ENGINE=MyISAM COMMENT='configuration table'
;",

            "
ALTER TABLE phpwebgallery_categories
  CHANGE COLUMN site_id site_id tinyint(4) unsigned default '1'
;",

            "
ALTER TABLE phpwebgallery_categories
  ADD COLUMN commentable enum('true','false') NOT NULL default 'true'
;",

            '
ALTER TABLE phpwebgallery_categories
  ADD COLUMN global_rank varchar(255) default NULL
;',

            '
ALTER TABLE phpwebgallery_categories
  ADD INDEX categories_i2 (id_uppercat)
;',

            '
ALTER TABLE phpwebgallery_comments
  ADD COLUMN date_temp int(11) unsigned
;',

            '
UPDATE phpwebgallery_comments
  SET date_temp = date
;',

            "
ALTER TABLE phpwebgallery_comments
  CHANGE COLUMN date date datetime NOT NULL default '0000-00-00 00:00:00'
;",

            '
UPDATE phpwebgallery_comments
  SET date = FROM_UNIXTIME(date_temp)
;',

            '
ALTER TABLE phpwebgallery_comments
  DROP COLUMN date_temp
;',

            '
ALTER TABLE phpwebgallery_favorites
  DROP INDEX user_id
;',

            '
ALTER TABLE phpwebgallery_favorites
  ADD PRIMARY KEY (user_id,image_id)
;',

            '
ALTER TABLE phpwebgallery_history
  ADD COLUMN date_temp int(11) unsigned
;',

            '
UPDATE phpwebgallery_history
  SET date_temp = date
;',

            "
ALTER TABLE phpwebgallery_history
  CHANGE COLUMN date date datetime NOT NULL default '0000-00-00 00:00:00'
;",

            '
UPDATE phpwebgallery_history
  SET date = FROM_UNIXTIME(date_temp)
;',

            '
ALTER TABLE phpwebgallery_history
  DROP COLUMN date_temp
;',

            '
ALTER TABLE phpwebgallery_history
  ADD INDEX history_i1 (date)
;',

            '
ALTER TABLE phpwebgallery_image_category
  ADD INDEX image_category_i1 (image_id),
  ADD INDEX image_category_i2 (category_id)
;',

            "
ALTER TABLE phpwebgallery_images
  CHANGE COLUMN tn_ext tn_ext varchar(4) default ''
;",

            "
ALTER TABLE phpwebgallery_images
  ADD COLUMN path varchar(255) NOT NULL default ''
;",

            '
ALTER TABLE phpwebgallery_images
  ADD COLUMN date_metadata_update date default NULL
;',

            '
ALTER TABLE phpwebgallery_images
  ADD COLUMN average_rate float(5,2) unsigned default NULL
;',

            '
ALTER TABLE phpwebgallery_images
  ADD COLUMN representative_ext varchar(4) default NULL
;',

            '
ALTER TABLE phpwebgallery_images
  DROP INDEX storage_category_id
;',

            '
ALTER TABLE phpwebgallery_images
  ADD INDEX images_i1 (storage_category_id)
;',

            '
ALTER TABLE phpwebgallery_images
  ADD INDEX images_i2 (date_available)
;',

            '
ALTER TABLE phpwebgallery_images
  ADD INDEX images_i3 (average_rate)
;',

            '
ALTER TABLE phpwebgallery_images
  ADD INDEX images_i4 (hit)
;',

            '
ALTER TABLE phpwebgallery_images
  ADD INDEX images_i5 (date_creation)
;',

            '
ALTER TABLE phpwebgallery_sessions
  DROP COLUMN ip
;',

            '
ALTER TABLE phpwebgallery_sessions
  ADD COLUMN expiration_temp int(11) unsigned
;',

            '
UPDATE phpwebgallery_sessions
  SET expiration_temp = expiration
;',

            "
ALTER TABLE phpwebgallery_sessions
  CHANGE COLUMN expiration expiration datetime NOT NULL default '0000-00-00 00:00:00'
;",

            '
UPDATE phpwebgallery_sessions
  SET expiration = FROM_UNIXTIME(expiration_temp)
;',

            '
ALTER TABLE phpwebgallery_sessions
  DROP COLUMN expiration_temp
;',

            '
ALTER TABLE phpwebgallery_sites
  DROP INDEX galleries_url
;',

            '
ALTER TABLE phpwebgallery_sites
  ADD UNIQUE sites_ui1 (galleries_url)
;',

            '
DROP TABLE phpwebgallery_user_category
;',

            '
ALTER TABLE phpwebgallery_users
  DROP COLUMN long_period
;',

            '
ALTER TABLE phpwebgallery_users
  DROP COLUMN short_period
;',

            "
ALTER TABLE phpwebgallery_users
  ADD COLUMN recent_period tinyint(3) unsigned NOT NULL default '7'
;",

            '
ALTER TABLE phpwebgallery_users
  DROP INDEX username
;',

            '
ALTER TABLE phpwebgallery_users
  ADD UNIQUE users_ui1 (username)
;',

            "
CREATE TABLE phpwebgallery_rate (
  user_id smallint(5) unsigned NOT NULL default '0',
  element_id mediumint(8) unsigned NOT NULL default '0',
  rate tinyint(2) unsigned NOT NULL default '0',
  PRIMARY KEY  (user_id,element_id)
) ENGINE=MyISAM
;",

            "
CREATE TABLE phpwebgallery_user_forbidden (
  user_id smallint(5) unsigned NOT NULL default '0',
  need_update enum('true','false') NOT NULL default 'true',
  forbidden_categories text,
  PRIMARY KEY  (user_id)
) ENGINE=MyISAM
;",

            '
DELETE FROM phpwebgallery_user_access
;',

            '
DELETE FROM phpwebgallery_group_access
;',

        ];

        foreach ($queries as $query) {
            $query = str_replace('phpwebgallery_', Config::dbPrefix(), $query);
            $conn->executeStatement($query);
        }

        $conn->executeStatement('
UPDATE ' . Tables::users() . '
  SET language = :language
    , template = :template
;', [
            'language' => 'en_UK.iso-8859-1',
            'template' => 'default',
        ]);

        //
        // check indexes
        //
        $indexes_of = [
            'categories' => [
                'categories_i2' => [
                    'columns' => ['id_uppercat'],
                    'unique' => false,
                ],
            ],
            'image_category' => [
                'image_category_i1' => [
                    'columns' => ['image_id'],
                    'unique' => false,
                ],
                'image_category_i2' => [
                    'columns' => ['category_id'],
                    'unique' => false,
                ],
            ],
        ];

        foreach (array_keys($indexes_of) as $table) {
            $existing_indexes = [];

            $query = '
SHOW INDEX
  FROM ' . Config::dbPrefix() . $table . '
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $key_name = is_scalar($row['Key_name']) ? (string) $row['Key_name'] : '';
                if ($key_name !== 'PRIMARY') {
                    if (! in_array($key_name, array_keys($indexes_of[$table]))) {
                        $query = '
ALTER TABLE ' . Config::dbPrefix() . $table . '
  DROP INDEX ' . $key_name . '
;';
                        $conn->executeStatement($query);
                    } else {
                        array_push($existing_indexes, $key_name);
                    }
                }
            }

            foreach ($indexes_of[$table] as $index_name => $index) {
                if (! in_array($index_name, $existing_indexes)) {
                    $query = '
ALTER TABLE ' . Config::dbPrefix() . $table . '
  ADD ' . ($index['unique'] ? 'UNIQUE' : 'INDEX') . ' '
                      . $index_name . ' (' . implode(',', $index['columns']) . ')
;';
                    $conn->executeStatement($query);
                }
            }
        }

        //
        // insert params in new configuration table
        //
        $params = [
            [
                'param' => 'prefix_thumbnail',
                'value' => $save['prefix_thumbnail'],
                'comment' => 'thumbnails filename prefix',
            ],
            [
                'param' => 'mail_webmaster',
                'value' => $save['mail_webmaster'],
                'comment' => 'webmaster mail',
            ],
            [
                'param' => 'default_language',
                'value' => 'en_UK.iso-8859-1',
                'comment' => 'Default gallery language',
            ],
            [
                'param' => 'default_template',
                'value' => 'default',
                'comment' => 'Default gallery style',
            ],
            [
                'param' => 'default_maxwidth',
                'value' => '',
                'comment' => 'maximum width authorized for displaying images',
            ],
            [
                'param' => 'default_maxheight',
                'value' => '',
                'comment' => 'maximum height authorized for the displaying images',
            ],
            [
                'param' => 'nb_comment_page',
                'value' => '10',
                'comment' => 'number of comments to display on each page',
            ],
            [
                'param' => 'upload_maxfilesize',
                'value' => '150',
                'comment' => 'maximum filesize for the uploaded pictures',
            ],
            [
                'param' => 'upload_maxwidth',
                'value' => '800',
                'comment' => 'maximum width authorized for the uploaded images',
            ],
            [
                'param' => 'upload_maxheight',
                'value' => '600',
                'comment' => 'maximum height authorized for the uploaded images',
            ],
            [
                'param' => 'upload_maxwidth_thumbnail',
                'value' => '150',
                'comment' => 'maximum width authorized for the uploaded thumbnails',
            ],
            [
                'param' => 'upload_maxheight_thumbnail',
                'value' => '100',
                'comment' => 'maximum height authorized for the uploaded thumbnails',
            ],
            [
                'param' => 'log',
                'value' => 'false',
                'comment' => 'keep an history of visits on your website',
            ],
            [
                'param' => 'comments_validation',
                'value' => 'false',
                'comment' => 'administrators validate users comments before becoming visible',
            ],
            [
                'param' => 'comments_forall',
                'value' => 'false',
                'comment' => 'even guest not registered can post comments',
            ],
            [
                'param' => 'mail_notification',
                'value' => 'false',
                'comment' => 'automated mail notification for adminsitrators',
            ],
            [
                'param' => 'nb_image_line',
                'value' => '5',
                'comment' => 'Number of images displayed per row',
            ],
            [
                'param' => 'nb_line_page',
                'value' => '3',
                'comment' => 'Number of rows displayed per page',
            ],
            [
                'param' => 'recent_period',
                'value' => '7',
                'comment' => 'Period within which pictures are displayed as new (in days)',
            ],
            [
                'param' => 'auto_expand',
                'value' => 'false',
                'comment' => 'Auto expand of the category tree',
            ],
            [
                'param' => 'show_nb_comments',
                'value' => 'false',
                'comment' => 'Show the number of comments under the thumbnails',
            ],
            [
                'param' => 'use_iptc',
                'value' => 'false',
                'comment' => 'Use IPTC data during database synchronization with files metadata',
            ],
            [
                'param' => 'use_exif',
                'value' => 'false',
                'comment' => 'Use EXIF data during database synchronization with files metadata',
            ],
            [
                'param' => 'show_iptc',
                'value' => 'false',
                'comment' => 'Show IPTC metadata on picture.php if asked by user',
            ],
            [
                'param' => 'show_exif',
                'value' => 'true',
                'comment' => 'Show EXIF metadata on picture.php if asked by user',
            ],
            [
                'param' => 'authorize_remembering',
                'value' => 'true',
                'comment' => 'Authorize users to be remembered, see $conf{remember_me_length}',
            ],
            [
                'param' => 'gallery_locked',
                'value' => 'false',
                'comment' => 'Lock your gallery temporary for non admin users',
            ],
        ];

        new BatchWriter($conn)
            ->massInsert(
                Tables::config(),
                array_keys($params[0]),
                $params
            );

        // refresh calculated datas
        $upgradeCategoryService = new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
        );
        $upgradeCategoryService->updateGlobalRank();
        $upgradeCategoryService->updateCategory();

        // update calculated field "images.path"
        $query = '
SELECT DISTINCT(storage_category_id) AS unique_storage_category_id
  FROM ' . Tables::images() . '
;';
        $cat_ids = array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $conn->fetchFirstColumn($query)
        );
        $fulldirs = $upgradeCategoryService->getFulldirs($cat_ids);

        foreach ($cat_ids as $cat_id) {
            $query = '
UPDATE ' . Tables::images() . '
  SET path = CONCAT(:fulldir, :sep, file)
  WHERE storage_category_id = :catId
;';
            $conn->executeStatement($query, [
                'fulldir' => $fulldirs[$cat_id],
                'sep' => '/',
                'catId' => $cat_id,
            ]);
        }

        // all sub-categories of private categories become private
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE status = :status
;';
        $private_cat_ids = array_map(
            static fn (mixed $v): int|string => is_int($v) || is_string($v) ? $v : '',
            $conn->fetchFirstColumn($query, [
                'status' => 'private',
            ])
        );

        if (count($private_cat_ids) > 0) {
            $privates = $upgradeCategoryService->getSubcatIds($private_cat_ids);

            $query = '
UPDATE ' . Tables::categories() . '
  SET status = :status
  WHERE id IN (:privates)
;';
            $conn->executeStatement($query, [
                'status' => 'private',
                'privates' => $privates,
            ], [
                'privates' => ArrayParameterType::INTEGER,
            ]);
        }

        // load the config file
        $config_file = \Piwigo\Core\CurrentPaths::get()->siteLocal . 'config/database.inc.php';
        $config_file_contents = @file_get_contents($config_file);
        if ($config_file_contents === false) {
            die('CANNOT LOAD ' . $config_file);
        }
        $php_end_tag = strrpos($config_file_contents, '?>');
        if ($php_end_tag === false) {
            die('CANNOT FIND PHP END TAG IN ' . $config_file);
        }
        if (! is_writable($config_file)) {
            die('FILE NOT WRITABLE ' . $config_file);
        }

        // changes to write in database.inc.php
        DatabaseConfigChanges::push('define(\'PHPWG_INSTALLED\', true);');

        // Send infos
        \Piwigo\Core\PageState::current()->infos = array_merge(
            \Piwigo\Core\PageState::current()->infos,
            [
                Lang::t('All sub-albums of private albums become private'),
                Lang::t('User permissions and group permissions have been erased'),
                Lang::t('Only thumbnails prefix and webmaster mail address have been saved from previous configuration'),
            ]
        );

        // now we upgrade from 1.4.0
        new UpgradeFrom_1_4_0()
            ->apply($conn);
    }
}
