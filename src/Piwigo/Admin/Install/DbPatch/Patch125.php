<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigDb;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\MysqliDb;

/**
 * Former install/db/125-database.php (P23 sub-batch 8g-2). The original
 * ACCUMULATED its $upgrade_description while running (appending
 * ', Additional Pages'/', PWG Stuffs' per detected plugin table, then a
 * closing paren), and that final value is what reached the ledger row --
 * so description() reads a property apply() builds up. The runner calls
 * apply() before description(), matching the original include-then-insert
 * order. The local replace_hotlinks() helper became a private method.
 */
final class Patch125 implements DbPatchInterface
{
    private string $description = 'derivatives: search and replace hotlinks inside Piwigo (page_banner';

    #[\Override]
    public function id(): string
    {
        return '125';
    }

    #[\Override]
    public function description(): string
    {
        return $this->description;
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var string
         */
        global $prefixeTable;

        if (! isset($conf['prefix_thumbnail'])) {
            $conf['prefix_thumbnail'] = 'TN-';
        }

        if (! isset($conf['dir_thumbnail'])) {
            $conf['dir_thumbnail'] = 'thumbnail';
        }

        $dbconf = [];
        $conf_orig = $conf;
        ConfigDb::loadConfFromDb();
        $dbconf = $conf;
        $conf = $conf_orig;

        $banner_orig = $dbconf['page_banner'];
        $banner_new = $this->replaceHotlinks($dbconf['page_banner']);
        if ($banner_orig != $banner_new) {
            ConfigDb::confUpdateParam('page_banner', MysqliDb::realEscapeString($banner_new));
        }

        //
        // Additional Pages
        //
        $is_plugin_installed = false;
        $plugin_table = $prefixeTable . 'additionalpages';

        $query = 'SHOW TABLES LIKE \'' . $plugin_table . '\';';

        foreach ($conn->fetchFirstColumn($query) as $table_name) {
            if ($plugin_table === $table_name) {
                $is_plugin_installed = true;
            }
        }

        if ($is_plugin_installed) {
            $query = '
SELECT
    id,
    content
  FROM ' . $plugin_table . '
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $content_orig = is_scalar($row['content']) ? (string) $row['content'] : '';
                $content_new = $this->replaceHotlinks($content_orig);
                if (is_string($content_new) && $content_orig !== $content_new) {
                    new BatchWriter($conn)
                        ->singleUpdate(
                            $plugin_table,
                            [
                                'content' => $content_new,
                            ],
                            [
                                'id' => $row['id'],
                            ]
                        );
                }
            }

            $this->description .= ', Additional Pages';
        }

        //
        // PWG Stuffs
        //
        $is_plugin_installed = false;
        $plugin_table = $prefixeTable . 'stuffs';

        $query = 'SHOW TABLES LIKE \'' . $plugin_table . '\';';

        foreach ($conn->fetchFirstColumn($query) as $table_name) {
            if ($plugin_table === $table_name) {
                $is_plugin_installed = true;
            }
        }

        if ($is_plugin_installed) {
            $query = '
SELECT
    id,
    datas
  FROM ' . $plugin_table . '
  WHERE path LIKE \'%plugins/PWG_Stuffs/modules/Personal%\'
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                $content_orig = is_scalar($row['datas']) ? (string) $row['datas'] : '';
                $unserialized = unserialize($content_orig);
                $content_new = serialize($this->replaceHotlinks(is_array($unserialized) || is_string($unserialized) ? $unserialized : ''));
                if ($content_orig !== $content_new) {
                    new BatchWriter($conn)
                        ->singleUpdate(
                            $plugin_table,
                            [
                                'datas' => $content_new,
                            ],
                            [
                                'id' => $row['id'],
                            ]
                        );
                }
            }

            $this->description .= ', PWG Stuffs';
        }

        $this->description .= ')';

        echo "\n" . $this->description . "\n";
    }

    /**
     * Former local replace_hotlinks() function.
     */
    private function replaceHotlinks(array|string $string): string|array|null
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // websize 2.3 = medium 2.4
        $string = preg_replace(
            '#(upload/\d{4}/\d{2}/\d{2}/\d{14}-\w{8})(\.(jpg|png))#',
            'i.php?/$1-me$2',
            $string
        );

        // I've tried but I didn't find the way to do it correctly
        // $string = preg_replace(
        //   '#(galleries/.*?/)(?!:(pwg_high|'.$conf['dir_thumbnail'].')/)([^/]*?)(\.[a-z0-9]{3,4})([\'"])#',
        //   'i.php?/(1=$1)(2=$2)-me(3=$3)(4=$4)', // 'i.php?/$1$2-me$3',
        //   $string
        //   );

        // thumbnail 2.3 = th size 2.4
        $string = preg_replace(
            '#(upload/\d{4}/\d{2}/\d{2}/)' . $conf['dir_thumbnail'] . '/' . $conf['prefix_thumbnail'] . '(\d{14}-\w{8})(\.(jpg|png))#',
            'i.php?/$1$2-th$3',
            $string
        );

        $string = preg_replace(
            '#(galleries/.*?/)' . $conf['dir_thumbnail'] . '/' . $conf['prefix_thumbnail'] . '(.*?)(\.[a-z0-9]{3,4})([\'"])#',
            'i.php?/$1$2-th$3$4',
            $string
        );

        // HD 2.3 = original 2.4
        $string = preg_replace(
            '#(upload/\d{4}/\d{2}/\d{2}/)pwg_high/(\d{14}-\w{8}\.(jpg|png))#',
            '$1$2',
            $string
        );

        $string = preg_replace(
            '#(galleries/.*?)/pwg_high(/.*?\.[a-z0-9]{3,4})#',
            '$1$2',
            $string
        );

        return $string;
    }
}
