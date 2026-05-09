<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;

final class Dml
{
    public const int SKIP_EMPTY             = 1;
    public const string REGEX_OPERATOR         = 'REGEXP';
    public const string RANDOM_FUNCTION        = 'RAND';
    public const string REQUIRED_MYSQL_VERSION = '5.0.0';

    /**
     * Updates multiple rows in a table.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<array<mixed>> $datas
     */
    public static function massUpdates(string $tablename, array $dbfields, array $datas, int $flags = 0): void
    {
        if (count($datas) === 0) {
            return;
        }

        $conn = DbConnection::get();

        if (count($datas) < 10) {
            foreach ($datas as $data) {
                $is_first = true;
                $query = 'UPDATE ' . self::protectColumnName($tablename) . ' SET ';

                foreach ($dbfields['update'] as $key) {
                    $separator = $is_first ? '' : ",\n    ";
                    if (isset($data[$key]) and $data[$key] != '') {
                        $val = $data[$key];
                        $query .= $separator . self::protectColumnName($key) . ' = ' . $conn->quote(is_scalar($val) ? (string) $val : '');
                    } else {
                        if ($flags & self::SKIP_EMPTY) {
                            continue;
                        }
                        $query .= $separator . self::protectColumnName($key) . ' = NULL';
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
                            $query .= self::protectColumnName($key) . ' = ' . $conn->quote(is_scalar($kval) ? (string) $kval : '');
                        } else {
                            $query .= self::protectColumnName($key) . ' IS NULL';
                        }
                        $is_first = false;
                    }
                    $conn->executeStatement($query);
                }
            }
        } else {
            $all_fields = array_merge($dbfields['primary'], $dbfields['update']);
            $columns = [];

            foreach ($conn->executeQuery('SHOW FULL COLUMNS FROM ' . self::protectColumnName($tablename))->fetchAllAssociative() as $row) {
                if (!in_array($row['Field'], $all_fields)) {
                    continue;
                }
                $col = '`' . (is_string($row['Field'] ?? null) ? $row['Field'] : '') . '` ' . (is_string($row['Type'] ?? null) ? $row['Type'] : '');
                $nullable = !isset($row['Null']) || $row['Null'] === '' || $row['Null'] === 'NO' ? false : true;
                if (!$nullable) {
                    $col .= ' NOT NULL';
                }
                if (isset($row['Default'])) {
                    $col .= " default '" . (is_string($row['Default']) ? $row['Default'] : '') . "'";
                } elseif ($nullable) {
                    $col .= ' default NULL';
                }
                if (isset($row['Collation']) && $row['Collation'] !== 'NULL') {
                    $col .= " collate '" . (is_string($row['Collation']) ? $row['Collation'] : '') . "'";
                }
                $columns[] = $col;
            }

            $tmp = $tablename . '_' . ServiceLocator::get(StringUtil::class)->microSeconds();
            $conn->executeStatement(
                'CREATE TABLE ' . $tmp . ' (' .
                implode(",\n  ", $columns) . ',' .
                ' UNIQUE KEY the_key (' . implode(',', $dbfields['primary']) . '))'
            );
            self::massInserts($tmp, $all_fields, $datas);

            $funcSet = ($flags & self::SKIP_EMPTY)
                ? fn (string $s): string => "t1.$s = IFNULL(t2.$s, t1.$s)"
                : fn (string $s): string => "t1.$s = t2.$s";

            $conn->executeStatement(
                'UPDATE ' . self::protectColumnName($tablename) . ' AS t1, ' . $tmp . ' AS t2 SET ' .
                implode(', ', array_map($funcSet, $dbfields['update'])) .
                ' WHERE ' . implode(' AND ', array_map(fn (string $s): string => "t1.$s = t2.$s", $dbfields['primary']))
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
    public static function singleUpdate(string $tablename, array $datas, array $where, int $flags = 0): void
    {
        if (count($datas) === 0) {
            return;
        }

        $is_first = true;
        $query = 'UPDATE ' . self::protectColumnName($tablename) . ' SET ';
        $conn = DbConnection::get();

        foreach ($datas as $key => $value) {
            $separator = $is_first ? '' : ",\n    ";
            if (isset($value) and $value !== '') {
                $query .= $separator . self::protectColumnName($key) . ' = ' . $conn->quote(is_scalar($value) ? (string) $value : '');
            } else {
                if ($flags & self::SKIP_EMPTY) {
                    continue;
                }
                $query .= $separator . self::protectColumnName($key) . ' = NULL';
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
                    $query .= self::protectColumnName($key) . ' = ' . $conn->quote(is_scalar($value) ? (string) $value : '');
                } else {
                    $query .= self::protectColumnName($key) . ' IS NULL';
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
    public static function massInserts(string $table_name, array $dbfields, array $datas, array $options = []): void
    {
        if (count($datas) === 0) {
            return;
        }

        $ignore = isset($options['ignore']) && $options['ignore'] ? 'IGNORE' : '';
        $conn = DbConnection::get();

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
                $cols = implode(',', array_map(self::protectColumnName(...), $dbfields));
                $query = "INSERT $ignore INTO " . self::protectColumnName($table_name) . " ($cols) VALUES";
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
    public static function singleInsert(string $table_name, array $data, array $options = []): void
    {
        if (count($data) === 0) {
            return;
        }

        $ignore = isset($options['ignore']) && $options['ignore'] ? 'IGNORE' : '';
        $cols = implode(',', array_map(self::protectColumnName(...), array_keys($data)));
        $query = "INSERT $ignore INTO " . self::protectColumnName($table_name) . " ($cols) VALUES (";

        $conn = DbConnection::get();
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
                $query .= $conn->quote(is_scalar($value) ? (string) $value : '');
            }
        }
        $query .= ')';

        $conn->executeStatement($query);
    }

    public static function protectColumnName(string $column_name): string
    {
        if ('`' !== $column_name[0]) {
            $column_name = '`' . $column_name . '`';
        }
        return $column_name;
    }
}
