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
 */

define('DB_ENGINE', 'MySQL');
define('REQUIRED_MYSQL_VERSION', '5.0.0');

define('DB_REGEX_OPERATOR', 'REGEXP');
define('DB_RANDOM_FUNCTION', 'RAND');


/**
 * Connect to database and store MySQLi resource in __$mysqli__ global variable.
 *
 * @param string $host
 *    - localhost
 *    - 1.2.3.4:3405
 *    - /path/to/socket
 * @param string $user
 * @param string $password
 * @param string $database
 *
 * @throws \Piwigo\Exception\DbException
 */
function pwg_db_connect($host, $user, $password, $database): void
{
    $port = null;
    $socket = null;

    if (str_starts_with($host, '/')) {
        $socket = $host;
        $host = null;
    } elseif (str_contains($host, ':')) {
        [$host, $port_str] = explode(':', $host);
        $port = (int)$port_str;
    }

    $dbname = '';

    $mysqli = new mysqli($host, $user, $password, $dbname, $port, $socket);
    if (mysqli_connect_error()) {
        throw new \Piwigo\Exception\DbException("Can't connect to server");
    }
    if (!$mysqli->select_db($database)) {
        throw new \Piwigo\Exception\DbException('Connection to server succeed, but it was impossible to connect to database');
    }
    \Piwigo\Core\MysqliRegistry::set($mysqli);

    // MySQL 5.7 default settings forbid to select a colum that is not in the
    // group by. We've used that in Piwigo, for years. As an immediate solution
    // we can remove this constraint in the current MySQL session.
    [$sql_mode_current] = pwg_db_fetch_row(pwg_query('SELECT @@SESSION.sql_mode')) ?? [null];

    // remove ONLY_FULL_GROUP_BY from the list
    $sql_mode_altered = implode(',', array_diff(explode(',', (string) $sql_mode_current), ['ONLY_FULL_GROUP_BY']));

    if ($sql_mode_altered != $sql_mode_current) {
        pwg_query("SET SESSION sql_mode='".$sql_mode_altered."'");
    }
}

/**
 * Set charset for database connection.
 */
function pwg_db_check_charset(): void
{
    \Piwigo\Core\MysqliRegistry::current()->set_charset('utf8');
}

/**
 * Check MySQL version. Can call fatal_error().
 */
function pwg_db_check_version(): void
{
    $current_mysql = pwg_get_db_version();
    if (version_compare($current_mysql, REQUIRED_MYSQL_VERSION, '<')) {
        fatal_error(
            sprintf(
                'your MySQL version is too old, you have "%s" and you need at least "%s"',
                $current_mysql,
                REQUIRED_MYSQL_VERSION
            )
        );
    }
}

/**
 * Get Mysql Version.
 *
 * @return string
 */
function pwg_get_db_version()
{
    return \Piwigo\Core\MysqliRegistry::current()->server_info;
}

/**
 * Execute a query
 *
 * @return mysqli_result|bool
 */
function pwg_query(string $query)
{
    $mysqli = \Piwigo\Core\MysqliRegistry::current();
    $start = microtime(true);
    ($result = $mysqli->query($query)) or my_error($query, \Piwigo\Config\Config::dieOnSqlError());

    $time = microtime(true) - $start;

    $pageState = \Piwigo\Core\PageState::current();
    $pageState->countQueries++;
    $pageState->queriesTime += $time;

    if (\Piwigo\Config\Config::showQueries()) {
        $t2 = is_numeric($GLOBALS['t2'] ?? null) ? (float) $GLOBALS['t2'] : 0.0;
        $output = '';
        $output .= '<pre>['.$pageState->countQueries.'] ';
        $output .= "\n".$query;
        $output .= "\n".'(this query time : ';
        $output .= '<b>'.number_format($time, 3, '.', ' ').' s)</b>';
        $output .= "\n".'(total SQL time  : ';
        $output .= number_format($pageState->queriesTime, 3, '.', ' ').' s)';
        $output .= "\n".'(total time      : ';
        $output .= number_format(($time + $start - $t2), 3, '.', ' ').' s)';
        if ($result != null and preg_match('/\s*SELECT\s+/i', $query)) {
            $output .= "\n".'(num rows        : ';
            $output .= pwg_db_num_rows($result).' )';
        } elseif ($result != null
          and preg_match('/\s*INSERT|UPDATE|REPLACE|DELETE\s+/i', $query)) {
            $output .= "\n".'(affected rows   : ';
            $output .= pwg_db_changes().' )';
        }
        $output .= "</pre>\n";

        if (!isset($GLOBALS['debug']) || !is_string($GLOBALS['debug'])) {
            $GLOBALS['debug'] = '';
        }
        $GLOBALS['debug'] .= $output;
    }

    return $result;
}

/**
 * Get max value plus one of a particular column.
 *
 * @param string $column
 */
function pwg_db_nextval(string $column, string $table): int
{
    $query = '
SELECT IF(MAX('.$column.')+1 IS NULL, 1, MAX('.$column.')+1)
  FROM '.$table;
    [$next] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    return (int) ($next ?? '1');
}

function pwg_db_changes(): int
{
    return (int) \Piwigo\Core\MysqliRegistry::current()->affected_rows;
}

function pwg_db_num_rows(bool|mysqli_result $result): int
{
    if (!($result instanceof mysqli_result)) {
        return 0;
    }
    return (int) $result->num_rows;
}

/** @return array<mixed>|false */
function pwg_db_fetch_array(bool|mysqli_result $result): array|false
{
    if (!($result instanceof mysqli_result)) {
        return false;
    }
    return $result->fetch_array() ?? false;
}

/** @return array<string, float|int|string|null>|null */
function pwg_db_fetch_assoc(bool|mysqli_result $result): ?array
{
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row !== false ? $row : null;
}

/** @return array<int, float|int|string|null>|null */
function pwg_db_fetch_row(bool|mysqli_result $result): ?array
{
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    return $result->fetch_row();
}

function pwg_db_fetch_object(bool|mysqli_result $result): ?stdClass
{
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $obj = $result->fetch_object();
    return $obj !== false ? $obj : null;
}

function pwg_db_free_result(bool|mysqli_result $result): void
{
    if ($result instanceof mysqli_result) {
        $result->free_result();
    }
}

function pwg_db_real_escape_string(string $s): string
{
    return \Piwigo\Core\MysqliRegistry::current()->real_escape_string($s);
}

function pwg_db_insert_id(): int|string
{
    return \Piwigo\Core\MysqliRegistry::current()->insert_id;
}

function pwg_db_errno(): int
{
    return \Piwigo\Core\MysqliRegistry::current()->errno;
}

function pwg_db_error(): string
{
    return \Piwigo\Core\MysqliRegistry::current()->error;
}

function pwg_db_close(): bool
{
    return \Piwigo\Core\MysqliRegistry::current()->close();
}


define('MASS_UPDATES_SKIP_EMPTY', 1);

/**
 * Updates multiple lines in a table.
 *
 * @param array $dbfields - contains 'primary' and 'update' arrays
 * @param array $datas - indexed by column names
 * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
 */
/**
 * @param array<string, string[]> $dbfields
 * @param array<array<mixed>> $datas
 */
function mass_updates(string $tablename, array $dbfields, array $datas, int $flags = 0): void
{
    if (count($datas) == 0) {
        return;
    }

    // we use the multi table update or N update queries
    if (count($datas) < 10) {
        foreach ($datas as $data) {
            $is_first = true;

            $query = '
UPDATE '.protect_column_name($tablename).'
  SET ';

            foreach ($dbfields['update'] as $key) {
                $separator = $is_first ? '' : ",\n    ";

                if (isset($data[$key]) and $data[$key] != '') {
                    $val = $data[$key];
                    $query .= $separator.protect_column_name($key).' = \''.(is_scalar($val) ? $val : '').'\'';
                } else {
                    if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                        continue; // next field
                    }
                    $query .= $separator.protect_column_name($key).' = NULL';
                }
                $is_first = false;
            }

            if (!$is_first) {// only if one field at least updated
                $is_first = true;

                $query .= '
  WHERE ';
                foreach ($dbfields['primary'] as $key) {
                    if (!$is_first) {
                        $query .= ' AND ';
                    }
                    if (isset($data[$key])) {
                        $kval = $data[$key];
                        $query .= protect_column_name($key).' = \''.(is_scalar($kval) ? $kval : '').'\'';
                    } else {
                        $query .= protect_column_name($key).' IS NULL';
                    }
                    $is_first = false;
                }

                pwg_query($query);
            }
        } // foreach update
    } // if count<X
    else {
        // creation of the temporary table
        $result = pwg_query('SHOW FULL COLUMNS FROM '.protect_column_name($tablename));
        $columns = [];
        $all_fields = array_merge($dbfields['primary'], $dbfields['update']);

        while ($row = pwg_db_fetch_assoc($result)) {
            if (in_array($row['Field'], $all_fields)) {
                $column = '`'.$row['Field'].'`';
                $column .= ' '.$row['Type'];

                $nullable = true;
                if (!isset($row['Null']) or $row['Null'] == '' or $row['Null'] == 'NO') {
                    $column .= ' NOT NULL';
                    $nullable = false;
                }
                if (isset($row['Default'])) {
                    $column .= " default '".$row['Default']."'";
                } elseif ($nullable) {
                    $column .= ' default NULL';
                }
                if (isset($row['Collation']) and $row['Collation'] != 'NULL') {
                    $column .= " collate '".$row['Collation']."'";
                }
                $columns[] = $column;
            }
        }

        $temporary_tablename = $tablename.'_'.micro_seconds();

        $query = '
CREATE TABLE '.$temporary_tablename.'
(
  '.implode(",\n  ", $columns).',
  UNIQUE KEY the_key ('.implode(',', $dbfields['primary']).')
)';

        pwg_query($query);
        mass_inserts($temporary_tablename, $all_fields, $datas);

        if ($flags & MASS_UPDATES_SKIP_EMPTY) {
            $func_set = (fn ($s): string => "t1.$s = IFNULL(t2.$s, t1.$s)");
        } else {
            $func_set = (fn ($s): string => "t1.$s = t2.$s");
        }

        // update of table by joining with temporary table
        $query = '
UPDATE '.protect_column_name($tablename).' AS t1, '.$temporary_tablename.' AS t2
  SET '.
          implode(
              "\n    , ",
              array_map($func_set, $dbfields['update'])
          ).'
  WHERE '.
          implode(
              "\n    AND ",
              array_map(
                  fn ($s): string => "t1.$s = t2.$s",
                  $dbfields['primary']
              )
          );
        pwg_query($query);

        pwg_query('DROP TABLE '.$temporary_tablename);
    }
}

/**
 * Updates one line in a table.
 *
 * @param string $tablename
 * @param array $datas
 * @param array $where
 * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
 */
/**
 * @param array<string,mixed> $datas
 * @param array<string,mixed> $where
 */
function single_update(string $tablename, array $datas, array $where, int $flags = 0): void
{
    if (count($datas) == 0) {
        return;
    }

    $is_first = true;

    $query = '
UPDATE '.protect_column_name($tablename).'
  SET ';

    foreach ($datas as $key => $value) {
        $separator = $is_first ? '' : ",\n    ";

        if (isset($value) and $value !== '') {
            $query .= $separator.protect_column_name($key).' = \''.(is_scalar($value) ? $value : '').'\'';
        } else {
            if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                continue; // next field
            }
            $query .= $separator.protect_column_name($key).' = NULL';
        }
        $is_first = false;
    }

    if (!$is_first) {// only if one field at least updated
        $is_first = true;

        $query .= '
  WHERE ';

        foreach ($where as $key => $value) {
            if (!$is_first) {
                $query .= ' AND ';
            }
            if (isset($value)) {
                $query .= protect_column_name($key).' = \''.(is_scalar($value) ? $value : '').'\'';
            } else {
                $query .= protect_column_name($key).' IS NULL';
            }
            $is_first = false;
        }

        pwg_query($query);
    }
}

/**
 * Inserts multiple lines in a table.
 *
 * @param string $table_name
 * @param array $dbfields - fields from $datas which will be used
 * @param array $datas
 * @param array $options
 *    - boolean ignore - use "INSERT IGNORE"
 */
/**
 * @param string[] $dbfields
 * @param array<array<mixed>> $datas
 * @param array<mixed> $options
 */
function mass_inserts(string $table_name, array $dbfields, array $datas, array $options = []): void
{
    $ignore = '';
    if (isset($options['ignore']) and $options['ignore']) {
        $ignore = 'IGNORE';
    }

    if (count($datas) != 0) {
        $first = true;

        $query = 'SHOW VARIABLES LIKE \'max_allowed_packet\'';
        list(, $packet_size) = pwg_db_fetch_row(pwg_query($query)) ?? [null, null];
        $packet_size = (int) ($packet_size ?? '0') - 2000; // The last list of values MUST not exceed 2000 character*/
        $query = '';

        foreach ($datas as $insert) {
            if (strlen($query) >= $packet_size) {
                pwg_query($query);
                $first = true;
            }

            if ($first) {
                $query = '
INSERT '.$ignore.' INTO '.protect_column_name($table_name).'
  ('.implode(',', array_map(protect_column_name(...), $dbfields)).')
  VALUES';
                $first = false;
            } else {
                $query .= '
  , ';
            }

            $query .= '(';
            foreach ($dbfields as $field_id => $dbfield) {
                if ($field_id > 0) {
                    $query .= ',';
                }

                if (!isset($insert[$dbfield]) or $insert[$dbfield] === '') {
                    $query .= 'NULL';
                } else {
                    $dbVal = $insert[$dbfield];
                    $query .= "'".(is_scalar($dbVal) ? $dbVal : '')."'";
                }
            }
            $query .= ')';
        }

        pwg_query($query);
    }
}

/**
 * Inserts one line in a table.
 *
 * @param string $table_name
 * @param array $data
 * @param array $options
 *    - boolean ignore - use "INSERT IGNORE"
 */
/**
 * @param array<string,mixed> $data
 * @param array<mixed> $options
 */
function single_insert(string $table_name, array $data, array $options = []): void
{
    $ignore = '';
    if (isset($options['ignore']) and $options['ignore']) {
        $ignore = 'IGNORE';
    }

    if (count($data) != 0) {
        $query = '
INSERT '.$ignore.' INTO '.protect_column_name($table_name).'
  ('.implode(',', array_map(protect_column_name(...), array_keys($data))).')
  VALUES';

        $query .= '(';
        $is_first = true;
        foreach ($data as $key => $value) {
            if (!$is_first) {
                $query .= ',';
            } else {
                $is_first = false;
            }

            if ($value === '' || is_null($value)) {
                $query .= 'NULL';
            } else {
                $query .= "'".(is_scalar($value) ? $value : '')."'";
            }
        }
        $query .= ')';

        pwg_query($query);
    }
}

function protect_column_name(string $column_name): string
{
    if ('`' != $column_name[0]) {
        $column_name = '`'.$column_name.'`';
    }

    return $column_name;
}

/**
 * Do maintenance on all Piwigo tables
 */
function do_maintenance_all_tables(): void
{
    $prefixeTable = is_string($GLOBALS['prefixeTable'] ?? null) ? $GLOBALS['prefixeTable'] : 'piwigo_';
    $all_tables = [];

    // List all tables
    $query = 'SHOW TABLES LIKE \''.$prefixeTable.'%\'';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_row($result)) {
        $all_tables[] = $row[0];
    }

    // Repair all tables
    $query = 'REPAIR TABLE '.implode(', ', $all_tables);
    $mysqli_rc = pwg_query($query);

    // Re-Order all tables
    foreach ($all_tables as $table_name) {
        $all_primary_key = [];

        $query = 'DESC '.$table_name.';';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            if ($row['Key'] == 'PRI') {
                $all_primary_key[] = $row['Field'];
            }
        }

        if (count($all_primary_key) != 0) {
            $query = 'ALTER TABLE '.$table_name.' ORDER BY '.implode(', ', $all_primary_key).';';
            $mysqli_rc = $mysqli_rc && pwg_query($query);
        }
    }

    // Optimize all tables
    $query = 'OPTIMIZE TABLE '.implode(', ', $all_tables);
    $mysqli_rc = $mysqli_rc && pwg_query($query);
    if ($mysqli_rc) {
        \Piwigo\Core\PageState::current()->addInfo(l10n('All optimizations have been successfully completed.'));
    } else {
        \Piwigo\Core\PageState::current()->addError(l10n('Optimizations have been completed with some errors.'));
    }
}

/** @param string[] $array */
function pwg_db_concat(array $array): string
{
    $string = implode(',', $array);
    return 'CONCAT('. $string.')';
}

/** @param string[] $array */
function pwg_db_concat_ws(array $array, string $separator): string
{
    $string = implode(',', $array);
    return 'CONCAT_WS(\''.$separator.'\','. $string.')';
}

function pwg_db_cast_to_text(string $string): string
{
    return $string;
}

/**
 * Returns an array containing the possible values of an enum field.
 *
 * @param string $field
 * @return string[]
 */
function get_enums(string $table, $field)
{
    $options = [];
    $result = pwg_query('DESC '.$table);
    while ($row = pwg_db_fetch_assoc($result)) {
        if ($row['Field'] == $field) {
            // parse enum('blue','green','black')
            $options = explode(',', substr((string) $row['Type'], 5, -1));
            foreach ($options as $i => $option) {
                $options[$i] = str_replace("'", '', $option);
            }
        }
    }

    pwg_db_free_result($result);
    return $options;
}

/**
 * Checks if a variable is equivalent to true or false.
 *
 * @param mixed $input
 * @return bool
 */
function get_boolean(mixed $input): bool
{
    if (is_scalar($input) && 'false' === strtolower((string) $input)) {
        return false;
    }

    return (bool) $input;
}

/**
 * Returns string 'true' or 'false' if the given var is boolean, else returns it as string.
 */
function boolean_to_string(bool|string $var): string
{
    if (is_bool($var)) {
        return $var ? 'true' : 'false';
    }
    return $var;
}

function pwg_db_get_recent_period_expression(int|string $period, string $date = 'CURRENT_DATE'): string
{
    if ($date != 'CURRENT_DATE') {
        $date = '\''.$date.'\'';
    }

    return 'SUBDATE('.$date.',INTERVAL '.$period.' DAY)';
}

function pwg_db_get_recent_period(string $period, string $date = 'CURRENT_DATE'): string
{
    $query = '
SELECT '.pwg_db_get_recent_period_expression($period);
    [$d] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    return is_scalar($d) ? (string) $d : '';
}

function pwg_db_get_flood_period_expression(int|string $seconds): string
{
    return 'SUBDATE(NOW(), INTERVAL '.$seconds.' SECOND)';
}

function pwg_db_get_hour(string $date): string
{
    return 'HOUR('.$date.')';
}

function pwg_db_get_date_YYYYMM(string $date): string
{
    return 'DATE_FORMAT('.$date.', \'%Y%m\')';
}

function pwg_db_get_date_MMDD(string $date): string
{
    return 'DATE_FORMAT('.$date.', \'%m%d\')';
}

function pwg_db_get_year(string $date): string
{
    return 'YEAR('.$date.')';
}

function pwg_db_get_month(string $date): string
{
    return 'MONTH('.$date.')';
}

function pwg_db_get_week(string $date, ?int $mode = null): string
{
    if ($mode) {
        return 'WEEK('.$date.', '.$mode.')';
    } else {
        return 'WEEK('.$date.')';
    }
}

function pwg_db_get_dayofmonth(string $date): string
{
    return 'DAYOFMONTH('.$date.')';
}

function pwg_db_get_dayofweek(string $date): string
{
    return 'DAYOFWEEK('.$date.')';
}

function pwg_db_get_weekday(string $date): string
{
    return 'WEEKDAY('.$date.')';
}

function pwg_db_date_to_ts(string $date): string
{
    return 'UNIX_TIMESTAMP('.$date.')';
}

/**
 * Returns (or send to standard output) the message concerning the
 * error occured for the last mysql query.
 */
function my_error(string $header, bool $die): void
{
    $mysqli = \Piwigo\Core\MysqliRegistry::current();
    $error = '[mysql error '.$mysqli->errno.'] '.$mysqli->error."\n";
    $error .= $header;

    if ($die) {
        fatal_error($error);
    }
    echo('<pre>');
    trigger_error($error, E_USER_WARNING);
    echo('</pre>');
}

/**
 * Builds an data array from a SQL query.
 * Depending on $key_name and $value_name it can return :
 *
 *    - an array of arrays of all fields (key=null, value=null)
 *        array(
 *          array('id'=>1, 'name'=>'DSC8956', ...),
 *          array('id'=>2, 'name'=>'DSC8957', ...),
 *          ...
 *          )
 *
 *    - an array of a single field (key=null, value='...')
 *        array('DSC8956', 'DSC8957', ...)
 *
 *    - an associative array of array of all fields (key='...', value=null)
 *        array(
 *          'DSC8956' => array('id'=>1, 'name'=>'DSC8956', ...),
 *          'DSC8957' => array('id'=>2, 'name'=>'DSC8957', ...),
 *          ...
 *          )
 *
 *    - an associative array of a single field (key='...', value='...')
 *        array(
 *          'DSC8956' => 1,
 *          'DSC8957' => 2,
 *          ...
 *          )
 *
 * @since 2.6
 *
 * @param string $key_name
 * @param string $value_name
 */
/**
 * @phpstan-return (
 *   $key_name is null
 *     ? ($value_name is null ? list<array<string, float|int|string|null>> : list<float|int|string|null>)
 *     : ($value_name is null ? array<string, array<string, float|int|string|null>> : array<string, float|int|string|null>)
 * )
 */
function query2array(string $query, ?string $key_name = null, ?string $value_name = null): array
{
    $result = pwg_query($query);
    $data = [];
    if (!($result instanceof mysqli_result)) {
        return $data;
    }

    if (isset($key_name)) {
        if (isset($value_name)) {
            while ($row = $result->fetch_assoc()) {
                $data[(string) $row[$key_name]] = $row[$value_name];
            }
        } else {
            while ($row = $result->fetch_assoc()) {
                $data[(string) $row[$key_name]] = $row;
            }
        }
    } else {
        if (isset($value_name)) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row[$value_name];
            }
        } else {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
    }

    return $data;
}
