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
use Piwigo\Admin\Install\DbPatch\LegacyFileConf;
use Piwigo\Config\Config;
use Piwigo\Core\StringHelper;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/upgrade_1.5.0.php (P23 sub-batch 8g-4): upgrade from
 * 1.5.0 to 1.6.0-era schema, then chain to UpgradeFrom_1_6_0. The local
 * tag_replace_keywords() function became a private method. The former
 * $conf_save/unset($conf)/@include/restore dance (needed because
 * `global $conf` meant unset()/re-include/restore only ever touched a
 * local alias, never the true global) is gone -- guest_id/webmaster_id/
 * gallery_url/rate/rate_anonymous are read via LegacyFileConf::read(),
 * same reasoning as the rest of this file family (Config:: doesn't see a
 * site's local/config/config.inc.php override on this path).
 */
final class UpgradeFrom_1_5_0 implements VersionUpgradeInterface
{
    #[\Override]
    public function versionFrom(): string
    {
        return '1.5.0';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $this->tagReplaceKeywords($conn);

        $localConf = LegacyFileConf::read();
        $guest_id = is_scalar($localConf['guest_id'] ?? null) ? (string) $localConf['guest_id'] : (string) Config::guestId();
        $webmaster_id = is_scalar($localConf['webmaster_id'] ?? null) ? (string) $localConf['webmaster_id'] : (string) Config::webmasterId();

        $ddlQueries = [
            '
CREATE TABLE ' . Config::dbPrefix() . 'search (
  id int UNSIGNED NOT NULL AUTO_INCREMENT,
  last_seen date DEFAULT NULL,
  rules text,
  PRIMARY KEY  (id)
);',

            '
CREATE TABLE ' . Config::dbPrefix() . "user_mail_notification (
  user_id smallint(5) NOT NULL default '0',
  check_key varchar(16) binary NOT NULL default '',
  enabled enum('true','false') NOT NULL default 'false',
  last_send datetime default NULL,
  PRIMARY KEY  (user_id),
  UNIQUE KEY uidx_check_key (check_key)
);",

            '
CREATE TABLE ' . Config::dbPrefix() . "upgrade (
  id varchar(20) NOT NULL default '',
  applied datetime NOT NULL default '0000-00-00 00:00:00',
  description varchar(255) default NULL,
  PRIMARY KEY  (`id`)
);",

            '
ALTER TABLE ' . Config::dbPrefix() . 'config
  MODIFY COLUMN value TEXT
;',

            '
ALTER TABLE ' . Config::dbPrefix() . "images
  ADD COLUMN has_high enum('true') default NULL
;",

            '
ALTER TABLE ' . Config::dbPrefix() . "rate
  ADD COLUMN anonymous_id varchar(45) NOT NULL default ''
;",
            '
ALTER TABLE ' . Config::dbPrefix() . "rate
  ADD COLUMN date date NOT NULL default '0000-00-00'
;",
            '
ALTER TABLE ' . Config::dbPrefix() . 'rate
  DROP PRIMARY KEY
;',
            '
ALTER TABLE ' . Config::dbPrefix() . 'rate
  ADD PRIMARY KEY (element_id,user_id,anonymous_id)
;',
            '
UPDATE ' . Config::dbPrefix() . 'rate
  SET date = CURDATE()
;',

            '
DELETE
  FROM ' . Config::dbPrefix() . 'sessions
;',
            '
ALTER TABLE ' . Config::dbPrefix() . 'sessions
  DROP COLUMN user_id
;',
            '
ALTER TABLE ' . Config::dbPrefix() . 'sessions
  ADD COLUMN data text NOT NULL
;',

            '
ALTER TABLE ' . Config::dbPrefix() . 'user_cache
  ADD COLUMN nb_total_images mediumint(8) unsigned default NULL
;',

            '
ALTER TABLE ' . Config::dbPrefix() . "user_infos
  CHANGE COLUMN status
     status enum('webmaster','admin','normal','generic','guest')
     NOT NULL default 'guest'
;",

            '
ALTER TABLE ' . Config::dbPrefix() . "user_infos
   CHANGE COLUMN template template varchar(255) NOT NULL default 'yoga/clear'
;",

            '
ALTER TABLE ' . Config::dbPrefix() . "user_infos
  ADD COLUMN adviser enum('true','false') NOT NULL default 'false'
;",
            '
ALTER TABLE ' . Config::dbPrefix() . "user_infos
  ADD COLUMN enabled_high enum('true','false') NOT NULL default 'true'
;",
            '
ALTER TABLE ' . Config::dbPrefix() . 'categories
  CHANGE COLUMN rank rank SMALLINT(5) UNSIGNED DEFAULT NULL
;',
        ];

        foreach ($ddlQueries as $query) {
            $conn->executeStatement($query);
        }

        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'user_infos
  SET status = :status
  WHERE status = :oldStatus
;', [
            'status' => 'normal',
            'oldStatus' => 'guest',
        ]);
        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'user_infos
  SET status = :status
  WHERE user_id = :userId
;', [
            'status' => 'guest',
            'userId' => $guest_id,
        ]);
        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'user_infos
  SET status = :status
  WHERE user_id = :userId
;', [
            'status' => 'webmaster',
            'userId' => $webmaster_id,
        ]);
        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'user_infos
  SET template = :template
  WHERE template = :oldTemplate
;', [
            'template' => 'yoga/dark',
            'oldTemplate' => 'yoga-dark',
        ]);
        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'user_infos
  SET template = :template
  WHERE template != :excludedTemplate
;', [
            'template' => 'yoga/clear',
            'excludedTemplate' => 'yoga/dark',
        ]);
        // configuration table
        $conn->executeStatement('
UPDATE ' . Config::dbPrefix() . 'config
  SET value = :value
  WHERE param = :param
;', [
            'value' => 'yoga/clear',
            'param' => 'default_template',
        ]);

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
        // Get real values from config file via LegacyFileConf::read() --
        // gallery_url's config_default.inc.php default is null, so isset()
        // still correctly reads false unless a site's local file overrides
        // it to a non-null value; rate/rate_anonymous have no
        // config_default.inc.php default at all.
        $localConf = LegacyFileConf::read();
        if (isset($localConf['gallery_url'])) {
            $params['gallery_url'][0] = $localConf['gallery_url'];
        }
        if (isset($localConf['rate']) and is_bool($localConf['rate'])) {
            $params['rate'][0] = $localConf['rate'] ? 'true' : 'false';
        }
        if (isset($localConf['rate_anonymous']) and is_bool($localConf['rate_anonymous'])) {
            $params['rate_anonymous'][0] = $localConf['rate_anonymous'] ? 'true' : 'false';
        }

        // Do I already have them in DB ?
        $query = 'SELECT param FROM ' . Config::dbPrefix() . 'config';
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $param_name = is_scalar($row['param']) ? (string) $row['param'] : '';
            unset($params[$param_name]);
        }

        // Perform the insert query -- bound directly (not via
        // BatchWriter::singleInsert()) because gallery_url's own accessor
        // (Config::galleryUrl()) treats a stored NULL and a stored '' as
        // genuinely different ("no override" vs "override to empty"), so
        // singleInsert()'s empty-string-to-NULL coercion isn't safe here.
        foreach ($params as $param_key => $param_values) {
            $query = '
INSERT INTO ' . Config::dbPrefix() . 'config
  (param, value, comment)
  VALUES
 (:param, :value, :comment)
;';
            $conn->executeStatement($query, [
                'param' => $param_key,
                'value' => $param_values[0],
                'comment' => $param_values[1],
            ]);
        }

        $query = '
ALTER TABLE ' . Config::dbPrefix() . 'config MODIFY COLUMN `value` TEXT;';
        $conn->executeStatement($query);

        //
        // replace gallery_description by page_banner
        //
        $query = '
SELECT value
  FROM ' . Config::dbPrefix() . 'config
  WHERE param = :param
;';
        $t = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query, [
                'param' => 'gallery_title',
            ]), 'value')
        )[0] ?? '';

        $query = '
SELECT value
  FROM ' . Config::dbPrefix() . 'config
  WHERE param = :param
;';
        $d = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query, [
                'param' => 'gallery_description',
            ]), 'value')
        )[0] ?? '';

        // No addslashes() here (unlike the original raw-SQL version) --
        // that call was doing SQL-string-literal-escaping duty for the
        // interpolated query text below; a bound parameter handles
        // escaping itself, so keeping addslashes() too would double-
        // escape the stored value.
        $page_banner = '<h1>' . $t . '</h1><p>' . $d . '</p>';
        $query = '
INSERT INTO ' . Config::dbPrefix() . 'config
  (param, value, comment)
  VALUES
  (:param, :value, :comment)
;';
        $conn->executeStatement($query, [
            'param' => 'page_banner',
            'value' => $page_banner,
            'comment' => 'html displayed on the top each page of your gallery',
        ]);

        $query = '
DELETE FROM ' . Config::dbPrefix() . 'config
  WHERE param = :param
;';
        $conn->executeStatement($query, [
            'param' => 'gallery_description',
        ]);

        //
        // configuration for notification by mail
        //
        // nbm_send_mail_as/nbm_complementary_mail_content's '' values are
        // safe under BatchWriter's empty-string-to-NULL coercion here --
        // both are read back via Config::getString(key, '') (see
        // Config.php), which falls back to the same '' default whether
        // the stored value is NULL or ''.
        $nbmBatchWriter = new BatchWriter($conn);
        $nbmBatchWriter->massInsert(Tables::config(), ['param', 'value', 'comment'], [
            [
                'param' => 'nbm_send_mail_as',
                'value' => '',
                'comment' => 'Send mail as param value for notification by mail',
            ],
            [
                'param' => 'nbm_send_detailed_content',
                'value' => 'true',
                'comment' => 'Send detailed content for notification by mail',
            ],
            [
                'param' => 'nbm_complementary_mail_content',
                'value' => '',
                'comment' => 'Complementary mail content for notification by mail',
            ],
        ]);

        // depending on the way the 1.5.0 was installed (from scratch or by upgrade)
        // the database structure has small differences that should be corrected.

        $query = '
ALTER TABLE ' . Config::dbPrefix() . 'users
  CHANGE COLUMN password password varchar(32) default NULL
;';
        $conn->executeStatement($query);

        $to_keep = ['id', 'username', 'password', 'mail_address'];

        $query = '
DESC ' . Config::dbPrefix() . 'users
;';

        foreach ($conn->fetchAllAssociative($query) as $row) {
            $field = is_scalar($row['Field']) ? (string) $row['Field'] : '';
            if (! in_array($field, $to_keep)) {
                $query = '
ALTER TABLE ' . Config::dbPrefix() . 'users
  DROP COLUMN ' . $field . '
;';
                $conn->executeStatement($query);
            }
        }

        // now we upgrade from 1.6.0 to 1.6.2
        new UpgradeFrom_1_6_0()
            ->apply($conn);
    }

    /**
     * Former local tag_replace_keywords() function: replace old style
     * #images.keywords by #tags. Requires a big data migration.
     */
    private function tagReplaceKeywords(Connection $conn): void
    {
        // code taken from upgrades 19 and 22

        $query = '
CREATE TABLE ' . Config::dbPrefix() . 'tags (
  id smallint(5) UNSIGNED NOT NULL auto_increment,
  name varchar(255) BINARY NOT NULL,
  url_name varchar(255) BINARY NOT NULL,
  PRIMARY KEY (id)
)
;';
        $conn->executeStatement($query);

        $query = '
CREATE TABLE ' . Config::dbPrefix() . 'image_tag (
  image_id mediumint(8) UNSIGNED NOT NULL,
  tag_id smallint(5) UNSIGNED NOT NULL,
  PRIMARY KEY (image_id,tag_id)
)
;';
        $conn->executeStatement($query);

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
  FROM ' . Config::dbPrefix() . 'images
  WHERE keywords IS NOT NULL
;';
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $keywords = is_scalar($row['keywords']) ? (string) $row['keywords'] : '';
            $image_id = is_scalar($row['id']) ? (string) $row['id'] : '';
            foreach (preg_split('/[,]+/', $keywords) as $keyword) {
                if (! isset($tag_id[$keyword])) {
                    $tag_id[$keyword] = $current_id++;
                }

                if (! isset($tag_images[$tag_id[$keyword]])) {
                    $tag_images[$tag_id[$keyword]] = [];
                }

                array_push(
                    $tag_images[$tag_id[$keyword]],
                    $image_id
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
            new BatchWriter($conn)
                ->massInsert(
                    Config::dbPrefix() . 'tags',
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
            new BatchWriter($conn)
                ->massInsert(
                    Config::dbPrefix() . 'image_tag',
                    array_keys($datas[0]),
                    $datas
                );
        }

        //
        // Delete images.keywords
        //
        $query = '
ALTER TABLE ' . Config::dbPrefix() . 'images DROP COLUMN keywords
;';
        $conn->executeStatement($query);

        //
        // Add useful indexes
        //
        $query = '
ALTER TABLE ' . Config::dbPrefix() . 'tags
  ADD INDEX tags_i1(url_name)
;';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Config::dbPrefix() . 'image_tag
  ADD INDEX image_tag_i1(tag_id)
;';
        $conn->executeStatement($query);

        // print_time('tags have replaced keywords');
    }
}
