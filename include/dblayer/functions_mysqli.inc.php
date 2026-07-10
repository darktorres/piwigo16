<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

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
 * @throws Exception
 */
function pwg_db_connect($host, $user, $password, $database): void
{
    global $mysqli;

    $port = null;
    $socket = null;

    if (str_starts_with($host, '/')) {
        $socket = $host;
        $host = null;
    } elseif (str_contains($host, ':')) {
        [$host, $port] = explode(':', $host);
        $port = (int) $port;
    }

    $dbname = '';

    $mysqli = new mysqli($host, $user, $password, $dbname, $port, $socket);
    if (mysqli_connect_error()) {
        throw new Exception("Can't connect to server");
    }
    if (! $mysqli->select_db($database)) {
        throw new Exception('Connection to server succeed, but it was impossible to connect to database');
    }

    // MySQL 5.7 default settings forbid to select a colum that is not in the
    // group by. We've used that in Piwigo, for years. As an immediate solution
    // we can remove this constraint in the current MySQL session.
    $row = pwg_db_fetch_row(pwg_query('SELECT @@SESSION.sql_mode'));
    assert($row !== null);
    [$sql_mode_current] = $row;

    // remove ONLY_FULL_GROUP_BY from the list
    $sql_mode_altered = implode(',', array_diff(explode(',', (string) $sql_mode_current), ['ONLY_FULL_GROUP_BY']));

    if ($sql_mode_altered != $sql_mode_current) {
        pwg_query("SET SESSION sql_mode='" . $sql_mode_altered . "'");
    }
}

/**
 * Set charset for database connection.
 */
function pwg_db_check_charset(): void
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    $db_charset = 'utf8';
    if (defined('DB_CHARSET')) {
        $db_charset = DB_CHARSET;
    }
    $mysqli->set_charset($db_charset);
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
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->server_info;
}

/**
 * Execute a query
 *
 * @param string $query
 * @return mysqli_result|bool
 */
function pwg_query($query)
{
    /**
     * @var \mysqli $mysqli
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $page
     * @var string $debug
     * @var float $t2
     */
    global $mysqli, $conf, $page, $debug, $t2;

    $die_on_sql_error = (bool) ($conf['die_on_sql_error'] ?? false);

    $start = microtime(true);
    ($result = $mysqli->query($query)) or my_error($query, $die_on_sql_error);

    $time = microtime(true) - $start;

    $count_queries = $page['count_queries'] ?? null;
    if (! is_int($count_queries)) {
        $count_queries = 0;
        $page['queries_time'] = 0;
    }

    $count_queries++;
    $page['count_queries'] = $count_queries;

    $queries_time = $page['queries_time'] ?? 0;
    $queries_time = is_numeric($queries_time) ? (float) $queries_time : 0.0;
    $queries_time += $time;
    $page['queries_time'] = $queries_time;

    if ($conf['show_queries']) {
        $output = '';
        $output .= '<pre>[' . $count_queries . '] ';
        $output .= "\n" . $query;
        $output .= "\n" . '(this query time : ';
        $output .= '<b>' . number_format($time, 3, '.', ' ') . ' s)</b>';
        $output .= "\n" . '(total SQL time  : ';
        $output .= number_format($queries_time, 3, '.', ' ') . ' s)';
        $output .= "\n" . '(total time      : ';
        $output .= number_format(($time + $start - $t2), 3, '.', ' ') . ' s)';
        if ($result != null and preg_match('/\s*SELECT\s+/i', $query)) {
            $output .= "\n" . '(num rows        : ';
            $output .= pwg_db_num_rows($result) . ' )';
        } elseif ($result != null
          and preg_match('/\s*INSERT|UPDATE|REPLACE|DELETE\s+/i', $query)) {
            $output .= "\n" . '(affected rows   : ';
            $output .= pwg_db_changes() . ' )';
        }
        $output .= "</pre>\n";

        $debug .= $output;
    }

    return $result;
}

/**
 * Get max value plus one of a particular column.
 *
 * @param string $column
 * @param string $table
 */
function pwg_db_nextval($column, $table): int
{
    $query = '
SELECT IF(MAX(' . $column . ')+1 IS NULL, 1, MAX(' . $column . ')+1)
  FROM ' . $table;
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$next] = $row;

    // The IF(...) wrapper guarantees a non-NULL numeric result; fall back to
    // 1 (the same value the query itself uses when MAX() is NULL) if that
    // invariant is ever violated.
    return is_numeric($next) ? (int) $next : 1;
}

function pwg_db_changes(): int|string
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->affected_rows;
}

function pwg_db_num_rows(mysqli_result|bool $result): int|string
{
    return $result->num_rows ?? 0;
}

/**
 * Every column value comes back as string|null: this driver never enables
 * MYSQLI_OPT_INT_AND_FLOAT_NATIVE (grepped, confirmed absent), and this was
 * verified empirically against the real test DB across int/timestamp/
 * computed-aggregate columns -- fetch_assoc()/fetch_row() never return
 * bool/int/float, only string or NULL. fetch_array() is called with the
 * default MYSQLI_BOTH mode, so each row is keyed by both the numeric
 * column index and the column name.
 *
 * @return array<int|string, string|null>|null|false
 */
function pwg_db_fetch_array(mysqli_result|bool $result): array|null|false
{
    if (! $result instanceof mysqli_result) {
        return false;
    }

    $row = $result->fetch_array();
    if (! is_array($row)) {
        return $row;
    }

    // See the invariant documented above -- normalize defensively so the
    // return type holds even if that assumption is ever violated.
    return array_map(
        static fn (mixed $value): string|null => is_scalar($value) ? (string) $value : null,
        $row
    );
}

/**
 * @return array<string, string|null>|null|false
 */
function pwg_db_fetch_assoc(mysqli_result|bool $result): array|null|false
{
    if (! $result instanceof mysqli_result) {
        return false;
    }

    $row = $result->fetch_assoc();
    if (! is_array($row)) {
        return $row;
    }

    // mysqli_result::fetch_assoc() is declared to allow int/float column
    // values (relevant only if MYSQLI_OPT_INT_AND_FLOAT_NATIVE were set,
    // which this driver never does -- see the invariant documented above
    // pwg_db_fetch_array()). Normalize defensively so the return type holds
    // even if that assumption is ever violated. Rebuilt via array_map()
    // (rather than a mutating foreach) so the callback's own return type
    // lets PHPStan re-infer $row as array<string, string|null> directly.
    $row = array_map(
        static fn (mixed $value): string|null => is_scalar($value) ? (string) $value : null,
        $row
    );

    return $row;
}

/**
 * @return array<int, string|null>|null
 */
function pwg_db_fetch_row(mysqli_result|bool $result): array|null
{
    if (! $result instanceof mysqli_result) {
        return null;
    }

    $row = $result->fetch_row();
    if (! is_array($row)) {
        return $row;
    }

    // See the invariant documented above pwg_db_fetch_array() -- normalize
    // defensively so the return type holds even if that assumption is ever
    // violated. array_values() guarantees the declared int-keyed return
    // type (fetch_row()'s own stub doesn't specify a list key type).
    return array_values(array_map(
        static fn (mixed $value): string|null => is_scalar($value) ? (string) $value : null,
        $row
    ));
}

function pwg_db_fetch_object(mysqli_result|bool $result): object|null|false
{
    if (! $result instanceof mysqli_result) {
        return false;
    }
    return $result->fetch_object();
}

function pwg_db_free_result(mysqli_result|bool $result): void
{
    if (! $result instanceof mysqli_result) {
        return;
    }
    $result->free_result();
}

function pwg_db_real_escape_string(?string $s): ?string
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return isset($s) ? $mysqli->real_escape_string($s) : null;
}

function pwg_db_insert_id(): int|string
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->insert_id;
}

function pwg_db_errno(): int
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->errno;
}

function pwg_db_error(): string
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->error;
}

function pwg_db_close(): bool
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    return $mysqli->close();
}

define('MASS_UPDATES_SKIP_EMPTY', 1);

/**
 * Updates multiple lines in a table.
 *
 * @param array{primary: string[], update: string[]} $dbfields
 * @param array<int, array<string, mixed>> $datas - indexed by column names
 * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
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
UPDATE ' . protect_column_name($tablename) . '
  SET ';

            foreach ($dbfields['update'] as $key) {
                $separator = $is_first ? '' : ",\n    ";

                if (isset($data[$key]) and $data[$key] != '' and is_scalar($data[$key])) {
                    $query .= $separator . protect_column_name($key) . ' = \'' . $data[$key] . '\'';
                } else {
                    if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                        continue; // next field
                    }
                    $query .= $separator . protect_column_name($key) . ' = NULL';
                }
                $is_first = false;
            }

            if (! $is_first) {// only if one field at least updated
                $is_first = true;

                $query .= '
  WHERE ';
                foreach ($dbfields['primary'] as $key) {
                    if (! $is_first) {
                        $query .= ' AND ';
                    }
                    if (isset($data[$key]) and is_scalar($data[$key])) {
                        $query .= protect_column_name($key) . ' = \'' . $data[$key] . '\'';
                    } else {
                        $query .= protect_column_name($key) . ' IS NULL';
                    }
                    $is_first = false;
                }

                pwg_query($query);
            }
        } // foreach update
    } // if count<X
    else {
        // creation of the temporary table
        $result = pwg_query('SHOW FULL COLUMNS FROM ' . protect_column_name($tablename));
        $columns = [];
        $all_fields = array_merge($dbfields['primary'], $dbfields['update']);

        while ($row = pwg_db_fetch_assoc($result)) {
            if (in_array($row['Field'], $all_fields)) {
                $column = '`' . $row['Field'] . '`';
                $column .= ' ' . $row['Type'];

                $nullable = true;
                if (! isset($row['Null']) or $row['Null'] == '' or $row['Null'] == 'NO') {
                    $column .= ' NOT NULL';
                    $nullable = false;
                }
                if (isset($row['Default'])) {
                    $column .= " default '" . $row['Default'] . "'";
                } elseif ($nullable) {
                    $column .= ' default NULL';
                }
                if (isset($row['Collation']) and $row['Collation'] != 'NULL') {
                    $column .= " collate '" . $row['Collation'] . "'";
                }
                $columns[] = $column;
            }
        }

        $temporary_tablename = $tablename . '_' . micro_seconds();

        $query = '
CREATE TABLE ' . $temporary_tablename . '
(
  ' . implode(",\n  ", $columns) . ',
  UNIQUE KEY the_key (' . implode(',', $dbfields['primary']) . ')
)';

        pwg_query($query);
        mass_inserts($temporary_tablename, $all_fields, $datas);

        if ($flags & MASS_UPDATES_SKIP_EMPTY) {
            $func_set = (fn (string $s): string => "t1.{$s} = IFNULL(t2.{$s}, t1.{$s})");
        } else {
            $func_set = (fn (string $s): string => "t1.{$s} = t2.{$s}");
        }

        // update of table by joining with temporary table
        $query = '
UPDATE ' . protect_column_name($tablename) . ' AS t1, ' . $temporary_tablename . ' AS t2
  SET ' .
          implode(
              "\n    , ",
              array_map($func_set, $dbfields['update'])
          ) . '
  WHERE ' .
          implode(
              "\n    AND ",
              array_map(
                  fn (string $s): string => "t1.{$s} = t2.{$s}",
                  $dbfields['primary']
              )
          );
        pwg_query($query);

        pwg_query('DROP TABLE ' . $temporary_tablename);
    }
}

/**
 * Updates one line in a table.
 *
 * @param array<string, mixed> $datas
 * @param array<string, mixed> $where
 * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
 */
function single_update(string $tablename, array $datas, array $where, int $flags = 0): void
{
    if (count($datas) == 0) {
        return;
    }

    $is_first = true;

    $query = '
UPDATE ' . protect_column_name($tablename) . '
  SET ';

    foreach ($datas as $key => $value) {
        $separator = $is_first ? '' : ",\n    ";

        if (isset($value) and $value !== '' and is_scalar($value)) {
            $query .= $separator . protect_column_name($key) . ' = \'' . $value . '\'';
        } else {
            if ($flags & MASS_UPDATES_SKIP_EMPTY) {
                continue; // next field
            }
            $query .= $separator . protect_column_name($key) . ' = NULL';
        }
        $is_first = false;
    }

    if (! $is_first) {// only if one field at least updated
        $is_first = true;

        $query .= '
  WHERE ';

        foreach ($where as $key => $value) {
            if (! $is_first) {
                $query .= ' AND ';
            }
            if (isset($value) and is_scalar($value)) {
                $query .= protect_column_name($key) . ' = \'' . $value . '\'';
            } else {
                $query .= protect_column_name($key) . ' IS NULL';
            }
            $is_first = false;
        }

        pwg_query($query);
    }
}

/**
 * Inserts multiple lines in a table.
 *
 * @param string[] $dbfields - fields from $datas which will be used
 * @param array<int, array<string, mixed>> $datas
 * @param array<string, mixed> $options
 *    - boolean ignore - use "INSERT IGNORE"
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
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [, $packet_size] = $row;
        // max_allowed_packet is always a numeric byte count and this system
        // variable always exists; fall back to MySQL's historical default
        // (1 MiB) if that invariant is ever violated.
        $packet_size = is_numeric($packet_size) ? (int) $packet_size : 1_048_576;
        $packet_size -= 2000; // The last list of values MUST not exceed 2000 character*/
        $query = '';

        foreach ($datas as $insert) {
            if (strlen($query) >= $packet_size) {
                pwg_query($query);
                $first = true;
            }

            if ($first) {
                $query = '
INSERT ' . $ignore . ' INTO ' . protect_column_name($table_name) . '
  (' . implode(',', array_map(protect_column_name(...), $dbfields)) . ')
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

                if (! isset($insert[$dbfield]) or $insert[$dbfield] === '' or ! is_scalar($insert[$dbfield])) {
                    $query .= 'NULL';
                } else {
                    $query .= "'" . $insert[$dbfield] . "'";
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
 * @param array<string, mixed> $data
 * @param array<string, mixed> $options
 *    - boolean ignore - use "INSERT IGNORE"
 */
function single_insert(string $table_name, array $data, array $options = []): void
{
    $ignore = '';
    if (isset($options['ignore']) and $options['ignore']) {
        $ignore = 'IGNORE';
    }

    if (count($data) != 0) {
        $query = '
INSERT ' . $ignore . ' INTO ' . protect_column_name($table_name) . '
  (' . implode(',', array_map(protect_column_name(...), array_keys($data))) . ')
  VALUES';

        $query .= '(';
        $is_first = true;
        foreach ($data as $key => $value) {
            if (! $is_first) {
                $query .= ',';
            } else {
                $is_first = false;
            }

            if ($value === '' || $value === null || ! is_scalar($value)) {
                $query .= 'NULL';
            } else {
                $query .= "'" . $value . "'";
            }
        }
        $query .= ')';

        pwg_query($query);
    }
}

function protect_column_name(string $column_name): string
{
    if ($column_name[0] != '`') {
        $column_name = '`' . $column_name . '`';
    }

    return $column_name;
}

/**
 * Do maintenance on all Piwigo tables
 */
function do_maintenance_all_tables(): void
{
    /**
     * @var string $prefixeTable
     * @var array<string, mixed> $page
     */
    global $prefixeTable, $page;

    // $page['infos']/$page['errors'] are always initialized to arrays by
    // common.inc.php (see the PAGE default there), but that isn't visible
    // from this function's own scope -- narrow it once here so the
    // $page['infos'][]/$page['errors'][] appends below type-check.
    $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];
    $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

    $all_tables = [];

    // List all tables
    $query = 'SHOW TABLES LIKE \'' . $prefixeTable . '%\'';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_row($result)) {
        $all_tables[] = $row[0];
    }

    // Repair all tables
    $query = 'REPAIR TABLE ' . implode(', ', $all_tables);
    $mysqli_rc = pwg_query($query);

    // Re-Order all tables
    foreach ($all_tables as $table_name) {
        $all_primary_key = [];

        $query = 'DESC ' . $table_name . ';';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            if ($row['Key'] == 'PRI') {
                $all_primary_key[] = $row['Field'];
            }
        }

        if (count($all_primary_key) != 0) {
            $query = 'ALTER TABLE ' . $table_name . ' ORDER BY ' . implode(', ', $all_primary_key) . ';';
            $mysqli_rc = $mysqli_rc && pwg_query($query);
        }
    }

    // Optimize all tables
    $query = 'OPTIMIZE TABLE ' . implode(', ', $all_tables);
    $mysqli_rc = $mysqli_rc && pwg_query($query);
    if ($mysqli_rc) {
        $page['infos'][] = l10n('All optimizations have been successfully completed.');
    } else {
        $page['errors'][] = l10n('Optimizations have been completed with some errors.');
    }
}

/**
 * @param string[] $array
 */
function pwg_db_concat(array $array): string
{
    $string = implode(',', $array);
    return 'CONCAT(' . $string . ')';
}

/**
 * @param string[] $array
 */
function pwg_db_concat_ws(array $array, string $separator): string
{
    $string = implode(',', $array);
    return 'CONCAT_WS(\'' . $separator . '\',' . $string . ')';
}

function pwg_db_cast_to_text(string $string): string
{
    return $string;
}

/**
 * Returns an array containing the possible values of an enum field.
 *
 * @param string $table
 * @param string $field
 * @return string[]
 */
function get_enums($table, $field): array
{
    $options = [];
    $result = pwg_query('DESC ' . $table);
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
function get_boolean($input)
{
    if (is_string($input) && strtolower($input) === 'false') {
        return false;
    }

    return (bool) $input;
}

/**
 * Returns string 'true' or 'false' if the given var is boolean.
 * If the input is another type, it is not changed.
 *
 * @param mixed $var
 * @return mixed
 */
function boolean_to_string($var)
{
    if (is_bool($var)) {
        return $var ? 'true' : 'false';
    } else {
        return $var;
    }
}

function pwg_db_get_recent_period_expression(int|string $period, string $date = 'CURRENT_DATE'): string
{
    if ($date != 'CURRENT_DATE') {
        $date = '\'' . $date . '\'';
    }

    return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
}

function pwg_db_get_recent_period(int|string $period, string $date = 'CURRENT_DATE'): mixed
{
    $query = '
SELECT ' . pwg_db_get_recent_period_expression($period);
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$d] = $row;

    return $d;
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
    return 'DATE_FORMAT(' . $date . ', \'%Y%m\')';
}

function pwg_db_get_date_MMDD(string $date): string
{
    return 'DATE_FORMAT(' . $date . ', \'%m%d\')';
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
    if ($mode) {
        return 'WEEK(' . $date . ', ' . $mode . ')';
    } else {
        return 'WEEK(' . $date . ')';
    }
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

/**
 * Returns (or send to standard output) the message concerning the
 * error occured for the last mysql query.
 *
 * @phpstan-return ($die is true ? never : void)
 */
function my_error(string $header, bool $die = true): void
{
    /** @var \mysqli $mysqli */
    global $mysqli;

    $error = '[mysql error ' . $mysqli->errno . '] ' . $mysqli->error . "\n";
    $error .= $header;

    if ($die) {
        fatal_error($error);
    }
    echo '<pre>';
    trigger_error($error, E_USER_WARNING);
    echo '</pre>';
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
 * @phpstan-return ($key_name is null
 *   ? ($value_name is null ? list<array<string, string|null>> : list<string|null>)
 *   : ($value_name is null ? array<int|string, array<string, string|null>> : array<int|string, string|null>))
 */
function query2array(string $query, ?string $key_name = null, ?string $value_name = null): array
{
    $result = pwg_query($query);
    $data = [];
    if (! $result instanceof mysqli_result) {
        return $data;
    }

    if (isset($key_name)) {
        if (isset($value_name)) {
            while ($row = pwg_db_fetch_assoc($result)) {
                $key = $row[$key_name];
                // matches PHP's own implicit null-key coercion; made
                // explicit here only to satisfy the array key type.
                $key ??= '';
                $data[$key] = $row[$value_name];
            }
        } else {
            while ($row = pwg_db_fetch_assoc($result)) {
                $key = $row[$key_name];
                $key ??= '';
                $data[$key] = $row;
            }
        }
    } else {
        if (isset($value_name)) {
            while ($row = pwg_db_fetch_assoc($result)) {
                $data[] = $row[$value_name];
            }
        } else {
            while ($row = pwg_db_fetch_assoc($result)) {
                $data[] = $row;
            }
        }
    }

    return $data;
}
