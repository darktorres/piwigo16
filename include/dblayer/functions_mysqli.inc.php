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
 * Legacy globals (pwg_query, pwg_db_*, mysqli helpers) are fully removed.
 * Permanent API: get_dbal_connection(), mass_inserts(), mass_updates(),
 * single_insert(), single_update(), protect_column_name().
 * Query results: use \Piwigo\Db\QueryHelper::fetch().
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
                    $query .= $separator . protect_column_name($key) . ' = ' . $conn->quote(is_scalar($val) ? (string) $val : '');
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
                        $query .= protect_column_name($key) . ' = ' . $conn->quote(is_scalar($kval) ? (string) $kval : '');
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

    $conn = get_dbal_connection();

    foreach ($datas as $key => $value) {
        $separator = $is_first ? '' : ",\n    ";
        if (isset($value) and $value !== '') {
            $query .= $separator . protect_column_name($key) . ' = ' . $conn->quote(is_scalar($value) ? (string) $value : '');
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
                $query .= protect_column_name($key) . ' = ' . $conn->quote(is_scalar($value) ? (string) $value : '');
            } else {
                $query .= protect_column_name($key) . ' IS NULL';
            }
            $is_first = false;
        }
        $conn->executeStatement($query);
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
                $vals[] = $conn->quote(is_scalar($dbVal) ? (string) $dbVal : '');
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
            $query .= get_dbal_connection()->quote(is_scalar($value) ? (string) $value : '');
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

