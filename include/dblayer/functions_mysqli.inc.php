<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\mysql
 *
 * Compatibility shim — all functions are now backed by Doctrine DBAL.
 * pwg_query(), pwg_db_connect(), and the mysqli result-set helpers have
 * been removed.  Callers of query2array / mass_inserts / mass_updates /
 * single_update / single_insert are unaffected.
 */

define('DB_ENGINE', 'MySQL');
define('REQUIRED_MYSQL_VERSION', '5.0.0');

define('DB_REGEX_OPERATOR', 'REGEXP');
define('DB_RANDOM_FUNCTION', 'RAND');

define('MASS_UPDATES_SKIP_EMPTY', 1);

/**
 * Return the shared DBAL connection.
 *
 * Post-boot: returns the container-managed Connection (no overhead).
 * Pre-boot (install, upgrade, upgrade_feed): lazily builds one from Config
 * and caches it in a static so we pay the connect cost only once.
 */
function get_dbal_connection(): \Doctrine\DBAL\Connection
{
    if (\Piwigo\Core\ServiceLocator::has(\Doctrine\DBAL\Connection::class)) {
        return \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    }
    static $conn = null;
    if ($conn === null) {
        $conn = \Piwigo\Db\DbConnection::build();
    }
    return $conn;
}

// ---------------------------------------------------------------------------
// DB version / error reporting
// ---------------------------------------------------------------------------

function pwg_get_db_version(): string
{
    $v = get_dbal_connection()->executeQuery('SELECT VERSION()')->fetchOne();
    return is_string($v) ? $v : '';
}

function pwg_db_check_version(): void
{
    $current = pwg_get_db_version();
    if (version_compare($current, REQUIRED_MYSQL_VERSION, '<')) {
        fatal_error(sprintf(
            'your MySQL version is too old, you have "%s" and you need at least "%s"',
            $current,
            REQUIRED_MYSQL_VERSION
        ));
    }
}

function my_error(string $header, bool $die): void
{
    if ($die) {
        fatal_error($header);
    }
    echo '<pre>';
    trigger_error($header, E_USER_WARNING);
    echo '</pre>';
}

// ---------------------------------------------------------------------------
// Escaping / last-insert-id / next-value helpers
// ---------------------------------------------------------------------------

function pwg_db_real_escape_string(string $s): string
{
    // Connection::quote() returns 'value' (with surrounding quotes); strip them.
    $quoted = get_dbal_connection()->quote($s);
    return substr($quoted, 1, -1);
}

function pwg_db_insert_id(): int|string
{
    $id = get_dbal_connection()->lastInsertId();
    return is_numeric($id) ? (int) $id : (string) $id;
}

function pwg_db_nextval(string $column, string $table): int
{
    $value = get_dbal_connection()
        ->executeQuery("SELECT IF(MAX($column)+1 IS NULL, 1, MAX($column)+1) FROM $table")
        ->fetchOne();
    return is_numeric($value) ? (int) $value : 1;
}

// ---------------------------------------------------------------------------
// Bulk DML helpers
// ---------------------------------------------------------------------------

/**
 * Updates multiple rows in a table.
 *
 * @param array{primary: string[], update: string[]} $dbfields
 * @param array<array<mixed>> $datas
 */
function mass_updates(string $tablename, array $dbfields, array $datas, int $flags = 0): void
{
    if (count($datas) === 0) {
        return;
    }

    $conn = get_dbal_connection();

    if (count($datas) < 10) {
        foreach ($datas as $data) {
            $is_first = true;
            $query = 'UPDATE ' . protect_column_name($tablename) . ' SET ';

            foreach ($dbfields['update'] as $key) {
                $separator = $is_first ? '' : ",\n    ";
                if (isset($data[$key]) and $data[$key] != '') {
                    $val = $data[$key];
                    $query .= $separator . protect_column_name($key) . " = '" . (is_scalar($val) ? $val : '') . "'";
                } else {
                    if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                        continue;
                    }
                    $query .= $separator . protect_column_name($key) . ' = NULL';
                }
                $is_first = false;
            }

            if (!$is_first) {
                $is_first = true;
                $query .= ' WHERE ';
                foreach ($dbfields['primary'] as $key) {
                    if (!$is_first) {
                        $query .= ' AND ';
                    }
                    if (isset($data[$key])) {
                        $kval = $data[$key];
                        $query .= protect_column_name($key) . " = '" . (is_scalar($kval) ? $kval : '') . "'";
                    } else {
                        $query .= protect_column_name($key) . ' IS NULL';
                    }
                    $is_first = false;
                }
                $conn->executeStatement($query);
            }
        }
    } else {
        // >=10 rows: temp-table JOIN UPDATE strategy
        $all_fields = array_merge($dbfields['primary'], $dbfields['update']);
        $columns = [];

        foreach ($conn->executeQuery('SHOW FULL COLUMNS FROM ' . protect_column_name($tablename))->fetchAllAssociative() as $row) {
            if (!in_array($row['Field'], $all_fields)) {
                continue;
            }
            $col = '`' . (is_scalar($row['Field']) ? (string) $row['Field'] : '') . '` ' . (is_scalar($row['Type']) ? (string) $row['Type'] : '');
            $nullable = !isset($row['Null']) || $row['Null'] === '' || $row['Null'] === 'NO' ? false : true;
            if (!$nullable) {
                $col .= ' NOT NULL';
            }
            if (isset($row['Default'])) {
                $col .= " default '" . (is_scalar($row['Default']) ? (string) $row['Default'] : '') . "'";
            } elseif ($nullable) {
                $col .= ' default NULL';
            }
            if (isset($row['Collation']) && $row['Collation'] !== 'NULL') {
                $col .= " collate '" . (is_scalar($row['Collation']) ? (string) $row['Collation'] : '') . "'";
            }
            $columns[] = $col;
        }

        $tmp = $tablename . '_' . micro_seconds();
        $conn->executeStatement(
            'CREATE TABLE ' . $tmp . ' (' .
            implode(",\n  ", $columns) . ',' .
            ' UNIQUE KEY the_key (' . implode(',', $dbfields['primary']) . '))'
        );
        mass_inserts($tmp, $all_fields, $datas);

        $funcSet = ($flags & MASS_UPDATES_SKIP_EMPTY)
            ? fn ($s): string => "t1.$s = IFNULL(t2.$s, t1.$s)"
            : fn ($s): string => "t1.$s = t2.$s";

        $conn->executeStatement(
            'UPDATE ' . protect_column_name($tablename) . ' AS t1, ' . $tmp . ' AS t2 SET ' .
            implode(', ', array_map($funcSet, $dbfields['update'])) .
            ' WHERE ' . implode(' AND ', array_map(fn ($s): string => "t1.$s = t2.$s", $dbfields['primary']))
        );
        $conn->executeStatement('DROP TABLE ' . $tmp);
    }
}

/**
 * Updates one row in a table.
 *
 * @param array<string,mixed> $datas
 * @param array<string,mixed> $where
 */
function single_update(string $tablename, array $datas, array $where, int $flags = 0): void
{
    if (count($datas) === 0) {
        return;
    }

    $is_first = true;
    $query = 'UPDATE ' . protect_column_name($tablename) . ' SET ';

    foreach ($datas as $key => $value) {
        $separator = $is_first ? '' : ",\n    ";
        if (isset($value) and $value !== '') {
            $query .= $separator . protect_column_name($key) . " = '" . (is_scalar($value) ? $value : '') . "'";
        } else {
            if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                continue;
            }
            $query .= $separator . protect_column_name($key) . ' = NULL';
        }
        $is_first = false;
    }

    if (!$is_first) {
        $is_first = true;
        $query .= ' WHERE ';
        foreach ($where as $key => $value) {
            if (!$is_first) {
                $query .= ' AND ';
            }
            if (isset($value)) {
                $query .= protect_column_name($key) . " = '" . (is_scalar($value) ? $value : '') . "'";
            } else {
                $query .= protect_column_name($key) . ' IS NULL';
            }
            $is_first = false;
        }
        get_dbal_connection()->executeStatement($query);
    }
}

/**
 * Inserts multiple rows into a table, batched by max_allowed_packet.
 *
 * @param string[] $dbfields
 * @param array<array<mixed>> $datas
 * @param array<mixed> $options  ['ignore' => bool]
 */
function mass_inserts(string $table_name, array $dbfields, array $datas, array $options = []): void
{
    if (count($datas) === 0) {
        return;
    }

    $ignore = isset($options['ignore']) && $options['ignore'] ? 'IGNORE' : '';
    $conn = get_dbal_connection();

    $packetRow = $conn->executeQuery("SHOW VARIABLES LIKE 'max_allowed_packet'")->fetchAssociative();
    $packetSize = (is_array($packetRow) && is_numeric($packetRow['Value'] ?? null) ? (int) $packetRow['Value'] : 1048576) - 2000;

    $first = true;
    $query = '';

    foreach ($datas as $insert) {
        if (!$first && strlen($query) >= $packetSize) {
            $conn->executeStatement($query);
            $first = true;
            $query = '';
        }

        if ($first) {
            $cols = implode(',', array_map(protect_column_name(...), $dbfields));
            $query = "INSERT $ignore INTO " . protect_column_name($table_name) . " ($cols) VALUES";
            $first = false;
        } else {
            $query .= ', ';
        }

        $vals = [];
        foreach ($dbfields as $dbfield) {
            if (!isset($insert[$dbfield]) || $insert[$dbfield] === '') {
                $vals[] = 'NULL';
            } else {
                $dbVal = $insert[$dbfield];
                $vals[] = "'" . (is_scalar($dbVal) ? $dbVal : '') . "'";
            }
        }
        $query .= '(' . implode(',', $vals) . ')';
    }

    $conn->executeStatement($query);
}

/**
 * Inserts one row into a table.
 *
 * @param array<string,mixed> $data
 * @param array<mixed> $options  ['ignore' => bool]
 */
function single_insert(string $table_name, array $data, array $options = []): void
{
    if (count($data) === 0) {
        return;
    }

    $ignore = isset($options['ignore']) && $options['ignore'] ? 'IGNORE' : '';
    $cols = implode(',', array_map(protect_column_name(...), array_keys($data)));
    $query = "INSERT $ignore INTO " . protect_column_name($table_name) . " ($cols) VALUES (";

    $is_first = true;
    foreach ($data as $value) {
        if (!$is_first) {
            $query .= ',';
        } else {
            $is_first = false;
        }
        if ($value === '' || $value === null) {
            $query .= 'NULL';
        } else {
            $query .= "'" . (is_scalar($value) ? $value : '') . "'";
        }
    }
    $query .= ')';

    get_dbal_connection()->executeStatement($query);
}

function protect_column_name(string $column_name): string
{
    if ('`' !== $column_name[0]) {
        $column_name = '`' . $column_name . '`';
    }
    return $column_name;
}

// ---------------------------------------------------------------------------
// Maintenance
// ---------------------------------------------------------------------------

function do_maintenance_all_tables(): void
{
    $prefixeTable = is_string($GLOBALS['prefixeTable'] ?? null) ? $GLOBALS['prefixeTable'] : 'piwigo_';
    $conn = get_dbal_connection();

    $allTables = $conn->executeQuery("SHOW TABLES LIKE '" . $prefixeTable . "%'")->fetchFirstColumn();
    if ($allTables === []) {
        return;
    }

    $success = true;
    try {
        $allTablesStr = array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $allTables);
        $conn->executeStatement('REPAIR TABLE ' . implode(', ', $allTablesStr));

        foreach ($allTablesStr as $tableName) {
            $primaryKeys = [];
            foreach ($conn->executeQuery('DESC ' . $tableName)->fetchAllAssociative() as $row) {
                if ($row['Key'] === 'PRI') {
                    $primaryKeys[] = is_scalar($row['Field']) ? (string) $row['Field'] : '';
                }
            }
            if ($primaryKeys !== []) {
                $conn->executeStatement('ALTER TABLE ' . $tableName . ' ORDER BY ' . implode(', ', $primaryKeys));
            }
        }

        $conn->executeStatement('OPTIMIZE TABLE ' . implode(', ', $allTablesStr));
    } catch (\Exception) {
        $success = false;
    }

    if ($success) {
        \Piwigo\Core\PageState::current()->addInfo(l10n('All optimizations have been successfully completed.'));
    } else {
        \Piwigo\Core\PageState::current()->addError(l10n('Optimizations have been completed with some errors.'));
    }
}

// ---------------------------------------------------------------------------
// Schema helpers
// ---------------------------------------------------------------------------

/**
 * Returns the possible values of an enum column.
 *
 * @return string[]
 */
function get_enums(string $table, string $field): array
{
    $options = [];
    foreach (get_dbal_connection()->executeQuery('DESC ' . $table)->fetchAllAssociative() as $row) {
        if ($row['Field'] === $field) {
            // parse enum('blue','green','black')
            $raw = explode(',', substr(is_scalar($row['Type']) ? (string) $row['Type'] : '', 5, -1));
            foreach ($raw as $option) {
                $options[] = str_replace("'", '', $option);
            }
        }
    }
    return $options;
}

// ---------------------------------------------------------------------------
// Pure-SQL expression builders (unchanged — return SQL fragments, no DB I/O)
// ---------------------------------------------------------------------------

/** @param string[] $array */
function pwg_db_concat(array $array): string
{
    return 'CONCAT(' . implode(',', $array) . ')';
}

/** @param string[] $array */
function pwg_db_concat_ws(array $array, string $separator): string
{
    return 'CONCAT_WS(\'' . $separator . '\',' . implode(',', $array) . ')';
}

function pwg_db_cast_to_text(string $string): string
{
    return $string;
}

function get_boolean(mixed $input): bool
{
    if (is_scalar($input) && 'false' === strtolower((string) $input)) {
        return false;
    }
    return (bool) $input;
}

function boolean_to_string(bool|string $var): string
{
    if (is_bool($var)) {
        return $var ? 'true' : 'false';
    }
    return $var;
}

function pwg_db_get_recent_period_expression(int|string $period, string $date = 'CURRENT_DATE'): string
{
    if ($date !== 'CURRENT_DATE') {
        $date = "'" . $date . "'";
    }
    return 'SUBDATE(' . $date . ', INTERVAL ' . $period . ' DAY)';
}

function pwg_db_get_recent_period(string $period, string $date = 'CURRENT_DATE'): string
{
    $d = get_dbal_connection()
        ->executeQuery('SELECT ' . pwg_db_get_recent_period_expression($period, $date))
        ->fetchOne();
    return is_scalar($d) ? (string) $d : '';
}

function pwg_db_get_flood_period_expression(int|string $seconds): string
{
    return 'SUBDATE(NOW(), INTERVAL ' . $seconds . ' SECOND)';
}

function pwg_db_get_hour(string $date): string
{
    return 'HOUR(' . $date . ')';
}

function pwg_db_get_date_YYYYMM(string $date): string
{
    return "DATE_FORMAT($date, '%Y%m')";
}

function pwg_db_get_date_MMDD(string $date): string
{
    return "DATE_FORMAT($date, '%m%d')";
}

function pwg_db_get_year(string $date): string
{
    return 'YEAR(' . $date . ')';
}

function pwg_db_get_month(string $date): string
{
    return 'MONTH(' . $date . ')';
}

function pwg_db_get_week(string $date, ?int $mode = null): string
{
    return $mode !== null ? "WEEK($date, $mode)" : "WEEK($date)";
}

function pwg_db_get_dayofmonth(string $date): string
{
    return 'DAYOFMONTH(' . $date . ')';
}

function pwg_db_get_dayofweek(string $date): string
{
    return 'DAYOFWEEK(' . $date . ')';
}

function pwg_db_get_weekday(string $date): string
{
    return 'WEEKDAY(' . $date . ')';
}

function pwg_db_date_to_ts(string $date): string
{
    return 'UNIX_TIMESTAMP(' . $date . ')';
}

// ---------------------------------------------------------------------------
// query2array — convenience wrapper backed by DBAL
// ---------------------------------------------------------------------------

/**
 * Executes $query and returns the result as a PHP array.
 *
 * @phpstan-return (
 *   $key_name is null
 *     ? ($value_name is null ? list<array<string, float|int|string|null>> : list<float|int|string|null>)
 *     : ($value_name is null ? array<string, array<string, float|int|string|null>> : array<string, float|int|string|null>)
 * )
 */
function query2array(string $query, ?string $key_name = null, ?string $value_name = null): array
{
    $rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
    $data = [];

    $sv = fn(mixed $v): float|int|string|null => (is_float($v) || is_int($v) || is_string($v)) ? $v : null;
    /** @param array<string,mixed> $row @return array<string,float|int|string|null> */
    $sr = fn(array $row): array => array_map($sv, $row);

    if (isset($key_name)) {
        if (isset($value_name)) {
            foreach ($rows as $row) {
                $data[is_scalar($row[$key_name]) ? (string) $row[$key_name] : ''] = $sv($row[$value_name]);
            }
        } else {
            foreach ($rows as $row) {
                $data[is_scalar($row[$key_name]) ? (string) $row[$key_name] : ''] = $sr($row);
            }
        }
    } else {
        if (isset($value_name)) {
            foreach ($rows as $row) {
                $data[] = $sv($row[$value_name]);
            }
        } else {
            $data = array_map($sr, $rows);
        }
    }

    return $data;
}
