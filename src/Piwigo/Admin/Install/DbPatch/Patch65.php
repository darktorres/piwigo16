<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/65-database.php (P23 sub-batch 8g-1) -- the 2.0-era
 * one-shot charset migration, ported with individual care:
 * - the two local upgrade65_* helper functions became private methods;
 * - the runtime define('PWG_CHARSET'/'DB_CHARSET'/'DB_COLLATE') calls
 *   (forbidden in src/Piwigo by SEC-60) became UpgradeCharset::set() --
 *   the later patches that read them (85, 90) consult UpgradeCharset;
 * - array_push($mysql_changes, ...) became DatabaseConfigChanges::push()
 *   (the runner-local was unreachable from a method);
 * - mysql_get_server_info(), the PHP-4-era mysql-extension function that
 *   no longer exists in PHP at all (this script was broken-at-HEAD the
 *   moment it ran), became MysqliDb::getDbVersion();
 * - the final `DB_CHARSET != ''` re-read of the just-defined constant
 *   reads the $db_charset local instead -- same value by construction.
 * The include of config_default.inc.php (a kept plain-data file) is
 * original behavior: with `global $conf` in scope it repopulates the
 * true global, exactly as when this body ran at include scope.
 */
final class Patch65 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '65';
    }

    #[\Override]
    public function description(): string
    {
        return 'PWG charset migration';
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

        if (UpgradeCharset::isResolved()) {
            echo 'PWG_CHARSET already defined - nada';

            return;
        }

        $upgrade_log = '';

        // +-----------------------------------------------------------------------+
        // load all the user languages
        $all_langs = [];
        $query = '
SELECT language, COUNT(user_id) AS count FROM ' . Tables::userInfos() . '
  GROUP BY language';
        $result = MysqliDb::query($query);
        while ($row = MysqliDb::fetchAssoc($result)) {
            $language = $row['language'];
            $lang_def = explode('.', $language);
            if (count($lang_def) == 2) {
                $new_lang = $lang_def[0];
                $charset = strtolower($lang_def[1]);
            } else {
                $new_lang = 'en_UK';
                $charset = 'iso-8859-1';
            }
            $all_langs[$language] = [
                'count' => $row['count'],
                'new_lang' => $new_lang,
                'charset' => $charset,
            ];
            $upgrade_log .= ">>user_lang\t" . $language . "\t" . $row['count'] . "\n";
        }
        $upgrade_log .= "\n";

        // +-----------------------------------------------------------------------+
        // get admin charset
        include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        $admin_charset = 'iso-8859-1';
        $query = '
SELECT language FROM ' . Tables::userInfos() . '
  WHERE user_id=' . $conf['webmaster_id'];
        $result = MysqliDb::query($query);
        if (MysqliDb::numRows($result) == 0) {
            $query = '
SELECT language FROM ' . Tables::userInfos() . '
  WHERE status="webmaster" and adviser="false"
  LIMIT 1';
            $result = MysqliDb::query($query);
        }

        if ($row = MysqliDb::fetchAssoc($result)) {
            $admin_charset = $all_langs[$row['language']]['charset'];
        }
        $upgrade_log .= ">>admin_charset\t" . $admin_charset . "\n";

        // +-----------------------------------------------------------------------+
        // get mysql version and structure of tables
        $mysql_version = MysqliDb::getDbVersion();
        $upgrade_log .= ">>mysql_ver\t" . $mysql_version . "\n";

        $all_tables = [];
        $query = 'SHOW TABLES LIKE "' . $prefixeTable . '%"';
        $result = MysqliDb::query($query);
        while ($row = MysqliDb::fetchRow($result)) {
            array_push($all_tables, $row[0]);
        }

        $all_tables_definition = [];
        foreach ($all_tables as $table) {
            $query = 'SHOW FULL COLUMNS FROM ' . $table;
            $result = MysqliDb::query($query);
            $field_definitions = [];
            while ($row = MysqliDb::fetchAssoc($result)) {
                if (! isset($row['Collation']) or $row['Collation'] == 'NULL') {
                    continue;
                }
                array_push($field_definitions, $row);
            }
            $all_tables_definition[$table] = $field_definitions;
        }

        // +-----------------------------------------------------------------------+
        // calculate the result and convert the tables

        // tables that can be converted without going through binary (they contain only ascii data)
        $safe_tables = ['history', 'history_backup', 'history_summary', 'old_permalinks', 'plugins', 'rate', 'upgrade', 'user_cache', 'user_feed', 'user_infos', 'user_mail_notification', 'users', 'waiting', 'ws_access'];
        $safe_tables = array_flip($safe_tables);

        $pwg_charset = 'iso-8859-1';
        $db_charset = 'latin1';
        $db_collate = '';
        if (version_compare($mysql_version, '4.1', '<')) { // below 4.1 no charset support
            $upgrade_log .= "< conversion\tnothing\n";
        } elseif ($admin_charset == 'iso-8859-1') {
            $pwg_charset = 'utf-8';
            $db_charset = 'utf8';
            foreach ($all_tables as $table) {
                $this->changeTableToCharset($table, $all_tables_definition[$table], 'utf8');
                $query = 'ALTER TABLE ' . $table . ' DEFAULT CHARACTER SET utf8';
                MysqliDb::query($query);
            }
            $upgrade_log .= "< conversion\tchange utf8\n";
        }
        /*ALTER TABLE tbl_name CONVERT TO CHARACTER SET charset_name; (or change column character set)
         * Warning: The preceding operation converts column values between the character sets. This is not what you want if you have a column in one character set (like latin1) but the stored values actually use some other, incompatible character set (like utf8). In this case, you have to do the following for each such column:
         * ALTER TABLE t1 CHANGE c1 c1 BLOB;
         * ALTER TABLE t1 CHANGE c1 c1 TEXT CHARACTER SET utf8;
         */
        elseif ($admin_charset == 'utf-8') {
            $pwg_charset = 'utf-8';
            $db_charset = 'utf8';
            foreach ($all_tables as $table) {
                if (! isset($safe_tables[substr($table, strlen($prefixeTable))])) {
                    $this->changeTableToBlob($table, $all_tables_definition[$table]);
                }
                $this->changeTableToCharset($table, $all_tables_definition[$table], 'utf8');
                $query = 'ALTER TABLE ' . $table . ' DEFAULT CHARACTER SET utf8';
                MysqliDb::query($query);
            }
            $upgrade_log .= "< conversion\tchange binary\n";
            $upgrade_log .= "< conversion\tchange utf8\n";
        } elseif ($admin_charset == 'iso-8859-2'/* Central European */) {
            $pwg_charset = 'utf-8';
            $db_charset = 'utf8';
            foreach ($all_tables as $table) {
                if (! isset($safe_tables[substr($table, strlen($prefixeTable))])) {
                    $this->changeTableToBlob($table, $all_tables_definition[$table]);
                    $this->changeTableToCharset($table, $all_tables_definition[$table], 'latin2');
                }
                $this->changeTableToCharset($table, $all_tables_definition[$table], 'utf8');
                $query = 'ALTER TABLE ' . $table . ' DEFAULT CHARACTER SET utf8';
                MysqliDb::query($query);
            }
            $upgrade_log .= "< conversion\tchange binary\n";
            $upgrade_log .= "< conversion\tchange latin2\n";
            $upgrade_log .= "< conversion\tchange utf8\n";
        }

        // +-----------------------------------------------------------------------+
        // changes to write in database.inc.php
        DatabaseConfigChanges::push(
            'define(\'PWG_CHARSET\', \'' . $pwg_charset . '\');
define(\'DB_CHARSET\',  \'' . $db_charset . '\');
define(\'DB_COLLATE\',  \'\');'
        );

        foreach ($all_langs as $old_lang => $lang_data) {
            $query = '
  UPDATE ' . Tables::userInfos() . ' SET language="' . $lang_data['new_lang'] . '"
    WHERE language="' . $old_lang . '"';
            MysqliDb::query($query);
        }

        UpgradeCharset::set($pwg_charset, $db_charset);

        if (version_compare(MysqliDb::getDbVersion(), '4.1.0', '>=') and $db_charset != '') {
            MysqliDb::query('SET NAMES "' . $db_charset . '"');
        }

        echo $upgrade_log;
        $fp = @fopen(PHPWG_ROOT_PATH . 'upgrade65.log', 'w');
        if ($fp) {
            @fputs($fp, $upgrade_log, strlen($upgrade_log));
            @fclose($fp);
        }

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }

    /**
     * Former upgrade65_change_table_to_blob().
     *
     * @param array<int, array<string, mixed>> $field_definitions
     */
    private function changeTableToBlob(string $table, array $field_definitions): void
    {
        $types = [
            'varchar' => 'varbinary',
            'text' => 'blob',
            'mediumtext' => 'mediumblob',
            'longtext' => 'longblob',
        ];

        $changes = [];
        foreach ($field_definitions as $row) {
            if (! isset($row['Collation']) or $row['Collation'] == 'NULL') {
                continue;
            }
            [$type] = explode('(', $row['Type']);
            if (! isset($types[$type])) {
                continue;
            } // no need
            $binaryType = preg_replace('/' . $type . '/i', $types[$type], $row['Type']);
            $changes[] = 'MODIFY COLUMN ' . $row['Field'] . ' ' . $binaryType;
        }
        if (count($changes)) {
            $query = 'ALTER TABLE ' . $table . ' ' . implode(', ', $changes);
            MysqliDb::query($query);
        }
    }

    /**
     * Former upgrade65_change_table_to_charset().
     *
     * @param array<int, array<string, mixed>> $field_definitions
     */
    private function changeTableToCharset(string $table, array $field_definitions, string $db_charset): void
    {
        $changes = [];
        foreach ($field_definitions as $row) {
            if (! isset($row['Collation']) or $row['Collation'] == 'NULL') {
                continue;
            }
            $query = $row['Field'] . ' ' . $row['Type'];
            $query .= ' CHARACTER SET ' . $db_charset;
            if (strpos($row['Collation'], '_bin') !== false) {
                $query .= ' BINARY';
            }
            if ($row['Null'] != 'YES') {
                $query .= ' NOT NULL';
                if (isset($row['Default'])) {
                    $query .= ' DEFAULT "' . addslashes($row['Default']) . '"';
                }
            } else {
                if (! isset($row['Default'])) {
                    $query .= ' DEFAULT NULL';
                } else {
                    $query .= ' DEFAULT "' . addslashes($row['Default']) . '"';
                }
            }

            if ($row['Extra'] == 'auto_increment') {
                $query .= ' auto_increment';
            }
            $changes[] = 'MODIFY COLUMN ' . $query;
        }

        if (count($changes)) {
            $query = 'ALTER TABLE `' . $table . '` ' . implode(', ', $changes);
            MysqliDb::query($query);
        }
    }
}
