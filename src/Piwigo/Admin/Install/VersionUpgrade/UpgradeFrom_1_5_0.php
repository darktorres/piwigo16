<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Piwigo\Core\StringHelper;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.5.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.5.0 to 1.6.0-era schema, then chain to UpgradeFrom_1_6_0. The local
 * tag_replace_keywords() function became a private method. The
 * $conf_save/unset($conf)/@include/restore dance is verbatim: inside a
 * method with `global $conf`, unset() unbinds only the local alias, the
 * include then reads local/config/config.inc.php into an isolated local
 * $conf, and the restore re-fills the local -- the true global is never
 * modified, which is also exactly what the original scope achieved
 * (saved and restored values are identical by construction).
 */
final class UpgradeFrom_1_5_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.5.0';
    }

    #[\Override]
    public function apply(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var string
         */
        global $prefixeTable;

        $this->tagReplaceKeywords();

        $queries = [
            '
CREATE TABLE ' . $prefixeTable . 'search (
  id int UNSIGNED NOT NULL AUTO_INCREMENT,
  last_seen date DEFAULT NULL,
  rules text,
  PRIMARY KEY  (id)
);',

            '
CREATE TABLE ' . $prefixeTable . "user_mail_notification (
  user_id smallint(5) NOT NULL default '0',
  check_key varchar(16) binary NOT NULL default '',
  enabled enum('true','false') NOT NULL default 'false',
  last_send datetime default NULL,
  PRIMARY KEY  (user_id),
  UNIQUE KEY uidx_check_key (check_key)
);",

            '
CREATE TABLE ' . $prefixeTable . "upgrade (
  id varchar(20) NOT NULL default '',
  applied datetime NOT NULL default '0000-00-00 00:00:00',
  description varchar(255) default NULL,
  PRIMARY KEY  (`id`)
);",

            '
ALTER TABLE ' . $prefixeTable . 'config
  MODIFY COLUMN value TEXT
;',

            '
ALTER TABLE ' . $prefixeTable . "images
  ADD COLUMN has_high enum('true') default NULL
;",

            '
ALTER TABLE ' . $prefixeTable . "rate
  ADD COLUMN anonymous_id varchar(45) NOT NULL default ''
;",
            '
ALTER TABLE ' . $prefixeTable . "rate
  ADD COLUMN date date NOT NULL default '0000-00-00'
;",
            '
ALTER TABLE ' . $prefixeTable . 'rate
  DROP PRIMARY KEY
;',
            '
ALTER TABLE ' . $prefixeTable . 'rate
  ADD PRIMARY KEY (element_id,user_id,anonymous_id)
;',
            '
UPDATE ' . $prefixeTable . 'rate
  SET date = CURDATE()
;',

            '
DELETE
  FROM ' . $prefixeTable . 'sessions
;',
            '
ALTER TABLE ' . $prefixeTable . 'sessions
  DROP COLUMN user_id
;',
            '
ALTER TABLE ' . $prefixeTable . 'sessions
  ADD COLUMN data text NOT NULL
;',

            '
ALTER TABLE ' . $prefixeTable . 'user_cache
  ADD COLUMN nb_total_images mediumint(8) unsigned default NULL
;',

            '
ALTER TABLE ' . $prefixeTable . "user_infos
  CHANGE COLUMN status
     status enum('webmaster','admin','normal','generic','guest')
     NOT NULL default 'guest'
;",
            '
UPDATE ' . $prefixeTable . "user_infos
  SET status = 'normal'
  WHERE status = 'guest'
;",
            '
UPDATE ' . $prefixeTable . "user_infos
  SET status = 'guest'
  WHERE user_id = " . $conf['guest_id'] . '
;',
            '
UPDATE ' . $prefixeTable . "user_infos
  SET status = 'webmaster'
  WHERE user_id = " . $conf['webmaster_id'] . '
;',

            '
ALTER TABLE ' . $prefixeTable . "user_infos
   CHANGE COLUMN template template varchar(255) NOT NULL default 'yoga/clear'
;",

            '
UPDATE ' . $prefixeTable . "user_infos
  SET template = 'yoga/dark'
  WHERE template = 'yoga-dark'
;",
            '
UPDATE ' . $prefixeTable . "user_infos
  SET template = 'yoga/clear'
  WHERE template != 'yoga/dark'
;",
            '
ALTER TABLE ' . $prefixeTable . "user_infos
  ADD COLUMN adviser enum('true','false') NOT NULL default 'false'
;",
            '
ALTER TABLE ' . $prefixeTable . "user_infos
  ADD COLUMN enabled_high enum('true','false') NOT NULL default 'true'
;",
            '
ALTER TABLE ' . $prefixeTable . 'categories
  CHANGE COLUMN rank rank SMALLINT(5) UNSIGNED DEFAULT NULL
;',
            // configuration table
            '
UPDATE ' . $prefixeTable . "config
  SET value = 'yoga/clear'
  WHERE param = 'default_template'
;",
        ];

        foreach ($queries as $query) {
            MysqliDb::query($query);
        }

        //
        // Move rate, rate_anonymous and gallery_url from config file to database
        //
        $params = [
            'gallery_url' => [
                '',
                'Optional alternate homepage for the gallery',
            ],
            'rate' => [
                'true',
                'Rating pictures feature is enabled',
            ],
            'rate_anonymous' => [
                'true',
                'Rating pictures feature is also enabled for visitors',
            ],
        ];
        // Get real values from config file
        $conf_save = $conf;
        unset($conf);
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        if (isset($conf['gallery_url'])) {
            $params['gallery_url'][0] = $conf['gallery_url'];
        }
        if (isset($conf['rate']) and is_bool($conf['rate'])) {
            $params['rate'][0] = $conf['rate'] ? 'true' : 'false';
        }
        if (isset($conf['rate_anonymous']) and is_bool($conf['rate_anonymous'])) {
            $params['rate_anonymous'][0] = $conf['rate_anonymous'] ? 'true' : 'false';
        }
        $conf = $conf_save;

        // Do I already have them in DB ?
        $query = 'SELECT param FROM ' . $prefixeTable . 'config';
        $result = MysqliDb::query($query);
        while ($row = MysqliDb::fetchAssoc($result)) {
            unset($params[$row['param']]);
        }

        // Perform the insert query
        foreach ($params as $param_key => $param_values) {
            $query = '
INSERT INTO ' . $prefixeTable . 'config
  (param,value,comment)
  VALUES
 (' . "'{$param_key}','{$param_values[0]}','{$param_values[1]}')
;";
            MysqliDb::query($query);
        }

        $query = '
ALTER TABLE ' . $prefixeTable . 'config MODIFY COLUMN `value` TEXT;';
        MysqliDb::query($query);

        //
        // replace gallery_description by page_banner
        //
        $query = '
SELECT value
  FROM ' . $prefixeTable . 'config
  WHERE param=\'gallery_title\'
;';
        [$t] = MysqliDb::query2Array($query, null, 'value');

        $query = '
SELECT value
  FROM ' . $prefixeTable . 'config
  WHERE param=\'gallery_description\'
;';
        [$d] = MysqliDb::query2Array($query, null, 'value');

        $page_banner = '<h1>' . $t . '</h1><p>' . $d . '</p>';
        $page_banner = addslashes($page_banner);
        $query = '
INSERT INTO ' . $prefixeTable . 'config
  (param,value,comment)
  VALUES
  (
    \'page_banner\',
    \'' . $page_banner . '\',
    \'html displayed on the top each page of your gallery\'
  )
;';
        MysqliDb::query($query);

        $query = '
DELETE FROM ' . $prefixeTable . 'config
  WHERE param=\'gallery_description\'
;';
        MysqliDb::query($query);

        //
        // configuration for notification by mail
        //
        $query = '
INSERT INTO ' . Tables::config() . "
  (param,value,comment)
  VALUES
  (
    'nbm_send_mail_as',
    '',
    'Send mail as param value for notification by mail'
  ),
  (
    'nbm_send_detailed_content',
    'true',
    'Send detailed content for notification by mail'
  ),
  (
    'nbm_complementary_mail_content',
    '',
    'Complementary mail content for notification by mail'
  )
;";
        MysqliDb::query($query);

        // depending on the way the 1.5.0 was installed (from scratch or by upgrade)
        // the database structure has small differences that should be corrected.

        $query = '
ALTER TABLE ' . $prefixeTable . 'users
  CHANGE COLUMN password password varchar(32) default NULL
;';
        MysqliDb::query($query);

        $to_keep = ['id', 'username', 'password', 'mail_address'];

        $query = '
DESC ' . $prefixeTable . 'users
;';

        $result = MysqliDb::query($query);

        while ($row = MysqliDb::fetchAssoc($result)) {
            if (! in_array($row['Field'], $to_keep)) {
                $query = '
ALTER TABLE ' . $prefixeTable . 'users
  DROP COLUMN ' . $row['Field'] . '
;';
                MysqliDb::query($query);
            }
        }

        // now we upgrade from 1.6.0 to 1.6.2
        new UpgradeFrom_1_6_0()
            ->apply();
    }

    /**
     * Former local tag_replace_keywords() function: replace old style
     * #images.keywords by #tags. Requires a big data migration.
     */
    private function tagReplaceKeywords(): void
    {
        /** @var string $prefixeTable */
        global $prefixeTable;

        // code taken from upgrades 19 and 22

        $query = '
CREATE TABLE ' . $prefixeTable . 'tags (
  id smallint(5) UNSIGNED NOT NULL auto_increment,
  name varchar(255) BINARY NOT NULL,
  url_name varchar(255) BINARY NOT NULL,
  PRIMARY KEY (id)
)
;';
        MysqliDb::query($query);

        $query = '
CREATE TABLE ' . $prefixeTable . 'image_tag (
  image_id mediumint(8) UNSIGNED NOT NULL,
  tag_id smallint(5) UNSIGNED NOT NULL,
  PRIMARY KEY (image_id,tag_id)
)
;';
        MysqliDb::query($query);

        //
        // Move keywords to tags
        //

        // each tag label is associated to a numeric identifier
        $tag_id = [];
        // to each tag id (key) a list of image ids (value) is associated
        $tag_images = [];

        $current_id = 1;

        $query = '
SELECT id, keywords
  FROM ' . $prefixeTable . 'images
  WHERE keywords IS NOT NULL
;';
        $result = MysqliDb::query($query);
        while ($row = MysqliDb::fetchAssoc($result)) {
            foreach (preg_split('/[,]+/', (string) $row['keywords']) as $keyword) {
                if (! isset($tag_id[$keyword])) {
                    $tag_id[$keyword] = $current_id++;
                }

                if (! isset($tag_images[$tag_id[$keyword]])) {
                    $tag_images[$tag_id[$keyword]] = [];
                }

                array_push(
                    $tag_images[$tag_id[$keyword]],
                    $row['id']
                );
            }
        }

        $datas = [];
        foreach ($tag_id as $tag_name => $tag_id) {
            array_push(
                $datas,
                [
                    'id' => $tag_id,
                    'name' => $tag_name,
                    'url_name' => StringHelper::str2url($tag_name),
                ]
            );
        }

        if (! empty($datas)) {
            MysqliDb::massInserts(
                $prefixeTable . 'tags',
                array_keys($datas[0]),
                $datas
            );
        }

        $datas = [];
        foreach ($tag_images as $tag_id => $images) {
            foreach (array_unique($images) as $image_id) {
                array_push(
                    $datas,
                    [
                        'tag_id' => $tag_id,
                        'image_id' => $image_id,
                    ]
                );
            }
        }

        if (! empty($datas)) {
            MysqliDb::massInserts(
                $prefixeTable . 'image_tag',
                array_keys($datas[0]),
                $datas
            );
        }

        //
        // Delete images.keywords
        //
        $query = '
ALTER TABLE ' . $prefixeTable . 'images DROP COLUMN keywords
;';
        MysqliDb::query($query);

        //
        // Add useful indexes
        //
        $query = '
ALTER TABLE ' . $prefixeTable . 'tags
  ADD INDEX tags_i1(url_name)
;';
        MysqliDb::query($query);

        $query = '
ALTER TABLE ' . $prefixeTable . 'image_tag
  ADD INDEX image_tag_i1(tag_id)
;';
        MysqliDb::query($query);

        // print_time('tags have replaced keywords');
    }
}
