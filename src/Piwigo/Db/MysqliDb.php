<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * P23 batch 8f-2: real logic ported verbatim from
 * include/dblayer/functions_mysqli.inc.php's 45 free functions (the raw
 * mysqli database driver, config-selected via $conf['dblayer'] and
 * dynamically `include`d by common.inc.php/i.php/install.php/upgrade.php/
 * upgrade_feed.php -- unchanged, still the only real dblayer). Every real
 * call site outside install/db/*.php and install/upgrade_*.php (frozen
 * historical scripts, out of P23's migration scope) retargets here
 * directly; those excluded scripts keep calling the original global
 * function names, which now delegate here as thin facades (see that
 * file's own docblock). No behavior change: still reads/writes the same
 * `global $mysqli` connection resource this driver has always used --
 * the mysqli-to-DBAL rewrite itself is separately tracked (Step 5, ORM
 * migration), not part of this refactor-only pass.
 */
final class MysqliDb
{
    /**
     * Connect to database and store MySQLi resource in the $mysqli global.
     *
     * @param string $host
     *    - localhost
     *    - 1.2.3.4:3405
     *    - /path/to/socket
     *
     * @throws \Exception
     */
    public static function connect(string $host, string $user, string $password, string $database): void
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

        $mysqli = new \mysqli($host, $user, $password, $dbname, $port, $socket);
        if ((bool) mysqli_connect_error()) {
            throw new \Exception("Can't connect to server");
        }
        if (! $mysqli->select_db($database)) {
            throw new \Exception('Connection to server succeed, but it was impossible to connect to database');
        }

        // MySQL 5.7 default settings forbid to select a colum that is not in the
        // group by. We've used that in Piwigo, for years. As an immediate solution
        // we can remove this constraint in the current MySQL session.
        $row = self::fetchRow(self::query('SELECT @@SESSION.sql_mode'));
        assert($row !== null);
        [$sql_mode_current] = $row;

        // remove ONLY_FULL_GROUP_BY from the list
        $sql_mode_altered = implode(',', array_diff(explode(',', $sql_mode_current), ['ONLY_FULL_GROUP_BY']));

        if ($sql_mode_altered != $sql_mode_current) {
            self::query("SET SESSION sql_mode='" . $sql_mode_altered . "'");
        }
    }

    /**
     * Set charset for database connection.
     */
    public static function checkCharset(): void
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
    public static function checkVersion(): void
    {
        $current_mysql = self::getDbVersion();
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
     */
    public static function getDbVersion(): string
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->server_info;
    }

    /**
     * Execute a query.
     */
    public static function query(string $query): \mysqli_result|bool
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
        if (! (bool) ($result = $mysqli->query($query))) {
            self::myError($query, $die_on_sql_error);
        }

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

        if ((bool) $conf['show_queries']) {
            $output = '';
            $output .= '<pre>[' . $count_queries . '] ';
            $output .= "\n" . $query;
            $output .= "\n" . '(this query time : ';
            $output .= '<b>' . number_format($time, 3, '.', ' ') . ' s)</b>';
            $output .= "\n" . '(total SQL time  : ';
            $output .= number_format($queries_time, 3, '.', ' ') . ' s)';
            $output .= "\n" . '(total time      : ';
            $output .= number_format(($time + $start - $t2), 3, '.', ' ') . ' s)';
            if ($result != null and (bool) preg_match('/\s*SELECT\s+/i', $query)) {
                $output .= "\n" . '(num rows        : ';
                $output .= self::numRows($result) . ' )';
            } elseif ($result != null
              and (bool) preg_match('/\s*INSERT|UPDATE|REPLACE|DELETE\s+/i', $query)) {
                $output .= "\n" . '(affected rows   : ';
                $output .= self::changes() . ' )';
            }
            $output .= "</pre>\n";

            $debug .= $output;
        }

        return $result;
    }

    /**
     * Get max value plus one of a particular column.
     */
    public static function nextval(string $column, string $table): int
    {
        $query = '
SELECT IF(MAX(' . $column . ')+1 IS NULL, 1, MAX(' . $column . ')+1)
  FROM ' . $table;
        $row = self::fetchRow(self::query($query));
        assert($row !== null);
        [$next] = $row;

        // The IF(...) wrapper guarantees a non-NULL numeric result; fall back to
        // 1 (the same value the query itself uses when MAX() is NULL) if that
        // invariant is ever violated.
        return is_numeric($next) ? (int) $next : 1;
    }

    public static function changes(): int|string
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->affected_rows;
    }

    public static function numRows(\mysqli_result|bool $result): int|string
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
    public static function fetchArray(\mysqli_result|bool $result): array|null|false
    {
        if (! $result instanceof \mysqli_result) {
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
    public static function fetchAssoc(\mysqli_result|bool $result): array|null|false
    {
        if (! $result instanceof \mysqli_result) {
            return false;
        }

        $row = $result->fetch_assoc();
        if (! is_array($row)) {
            return $row;
        }

        // mysqli_result::fetch_assoc() is declared to allow int/float column
        // values (relevant only if MYSQLI_OPT_INT_AND_FLOAT_NATIVE were set,
        // which this driver never does -- see the invariant documented above
        // fetchArray()). Normalize defensively so the return type holds
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
    public static function fetchRow(\mysqli_result|bool $result): array|null
    {
        if (! $result instanceof \mysqli_result) {
            return null;
        }

        $row = $result->fetch_row();
        if (! is_array($row)) {
            return $row;
        }

        // See the invariant documented above fetchArray() -- normalize
        // defensively so the return type holds even if that assumption is ever
        // violated. array_values() guarantees the declared int-keyed return
        // type (fetch_row()'s own stub doesn't specify a list key type).
        return array_values(array_map(
            static fn (mixed $value): string|null => is_scalar($value) ? (string) $value : null,
            $row
        ));
    }

    public static function fetchObject(\mysqli_result|bool $result): object|null|false
    {
        if (! $result instanceof \mysqli_result) {
            return false;
        }
        return $result->fetch_object();
    }

    public static function freeResult(\mysqli_result|bool $result): void
    {
        if (! $result instanceof \mysqli_result) {
            return;
        }
        $result->free_result();
    }

    public static function realEscapeString(?string $s): ?string
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return isset($s) ? $mysqli->real_escape_string($s) : null;
    }

    public static function insertId(): int|string
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->insert_id;
    }

    public static function errno(): int
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->errno;
    }

    public static function error(): string
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->error;
    }

    public static function close(): bool
    {
        /** @var \mysqli $mysqli */
        global $mysqli;

        return $mysqli->close();
    }

    /**
     * Updates multiple lines in a table.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<int, array<string, mixed>> $datas - indexed by column names
     * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
     */
    public static function massUpdates(string $tablename, array $dbfields, array $datas, int $flags = 0): void
    {
        if (count($datas) == 0) {
            return;
        }

        // we use the multi table update or N update queries
        if (count($datas) < 10) {
            foreach ($datas as $data) {
                $is_first = true;

                $query = '
UPDATE ' . self::protectColumnName($tablename) . '
  SET ';

                foreach ($dbfields['update'] as $key) {
                    $separator = $is_first ? '' : ",\n    ";

                    if (isset($data[$key]) and $data[$key] != '' and is_scalar($data[$key])) {
                        $query .= $separator . self::protectColumnName($key) . ' = \'' . $data[$key] . '\'';
                    } else {
                        if ((bool) ($flags & MASS_UPDATES_SKIP_EMPTY)) {
                            continue; // next field
                        }
                        $query .= $separator . self::protectColumnName($key) . ' = NULL';
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
                            $query .= self::protectColumnName($key) . ' = \'' . $data[$key] . '\'';
                        } else {
                            $query .= self::protectColumnName($key) . ' IS NULL';
                        }
                        $is_first = false;
                    }

                    self::query($query);
                }
            } // foreach update
        } // if count<X
        else {
            // creation of the temporary table
            $result = self::query('SHOW FULL COLUMNS FROM ' . self::protectColumnName($tablename));
            $columns = [];
            $all_fields = array_merge($dbfields['primary'], $dbfields['update']);

            while ((bool) ($row = self::fetchAssoc($result))) {
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

            $temporary_tablename = $tablename . '_' . \Piwigo\Core\TimingHelper::microSeconds();

            $query = '
CREATE TABLE ' . $temporary_tablename . '
(
  ' . implode(",\n  ", $columns) . ',
  UNIQUE KEY the_key (' . implode(',', $dbfields['primary']) . ')
)';

            self::query($query);
            self::massInserts($temporary_tablename, $all_fields, $datas);

            if ((bool) ($flags & MASS_UPDATES_SKIP_EMPTY)) {
                $func_set = (fn (string $s): string => "t1.{$s} = IFNULL(t2.{$s}, t1.{$s})");
            } else {
                $func_set = (fn (string $s): string => "t1.{$s} = t2.{$s}");
            }

            // update of table by joining with temporary table
            $query = '
UPDATE ' . self::protectColumnName($tablename) . ' AS t1, ' . $temporary_tablename . ' AS t2
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
            self::query($query);

            self::query('DROP TABLE ' . $temporary_tablename);
        }
    }

    /**
     * Updates one line in a table.
     *
     * @param array<string, mixed> $datas
     * @param array<string, mixed> $where
     * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
     */
    public static function singleUpdate(string $tablename, array $datas, array $where, int $flags = 0): void
    {
        if (count($datas) == 0) {
            return;
        }

        $is_first = true;

        $query = '
UPDATE ' . self::protectColumnName($tablename) . '
  SET ';

        foreach ($datas as $key => $value) {
            $separator = $is_first ? '' : ",\n    ";

            if (isset($value) and $value !== '' and is_scalar($value)) {
                $query .= $separator . self::protectColumnName($key) . ' = \'' . $value . '\'';
            } else {
                if ((bool) ($flags & MASS_UPDATES_SKIP_EMPTY)) {
                    continue; // next field
                }
                $query .= $separator . self::protectColumnName($key) . ' = NULL';
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
                    $query .= self::protectColumnName($key) . ' = \'' . $value . '\'';
                } else {
                    $query .= self::protectColumnName($key) . ' IS NULL';
                }
                $is_first = false;
            }

            self::query($query);
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
    public static function massInserts(string $table_name, array $dbfields, array $datas, array $options = []): void
    {
        $ignore = '';
        if (isset($options['ignore']) and (bool) $options['ignore']) {
            $ignore = 'IGNORE';
        }

        if (count($datas) != 0) {
            $first = true;

            $query = 'SHOW VARIABLES LIKE \'max_allowed_packet\'';
            $row = self::fetchRow(self::query($query));
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
                    self::query($query);
                    $first = true;
                }

                if ($first) {
                    $query = '
INSERT ' . $ignore . ' INTO ' . self::protectColumnName($table_name) . '
  (' . implode(',', array_map(self::protectColumnName(...), $dbfields)) . ')
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

            self::query($query);
        }
    }

    /**
     * Inserts one line in a table.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     *    - boolean ignore - use "INSERT IGNORE"
     */
    public static function singleInsert(string $table_name, array $data, array $options = []): void
    {
        $ignore = '';
        if (isset($options['ignore']) and (bool) $options['ignore']) {
            $ignore = 'IGNORE';
        }

        if (count($data) != 0) {
            $query = '
INSERT ' . $ignore . ' INTO ' . self::protectColumnName($table_name) . '
  (' . implode(',', array_map(self::protectColumnName(...), array_keys($data))) . ')
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

            self::query($query);
        }
    }

    public static function protectColumnName(string $column_name): string
    {
        if ($column_name[0] != '`') {
            $column_name = '`' . $column_name . '`';
        }

        return $column_name;
    }

    /**
     * Do maintenance on all Piwigo tables.
     */
    public static function doMaintenanceAllTables(): void
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
        $result = self::query($query);
        while ((bool) ($row = self::fetchRow($result))) {
            $all_tables[] = $row[0];
        }

        // Repair all tables
        $query = 'REPAIR TABLE ' . implode(', ', $all_tables);
        $mysqli_rc = self::query($query);

        // Re-Order all tables
        foreach ($all_tables as $table_name) {
            $all_primary_key = [];

            $query = 'DESC ' . $table_name . ';';
            $result = self::query($query);
            while ((bool) ($row = self::fetchAssoc($result))) {
                if ($row['Key'] == 'PRI') {
                    $all_primary_key[] = $row['Field'];
                }
            }

            if (count($all_primary_key) != 0) {
                $query = 'ALTER TABLE ' . $table_name . ' ORDER BY ' . implode(', ', $all_primary_key) . ';';
                $mysqli_rc = ((bool) $mysqli_rc) && ((bool) self::query($query));
            }
        }

        // Optimize all tables
        $query = 'OPTIMIZE TABLE ' . implode(', ', $all_tables);
        $mysqli_rc = ((bool) $mysqli_rc) && ((bool) self::query($query));
        if ($mysqli_rc) {
            $page['infos'][] = l10n('All optimizations have been successfully completed.');
        } else {
            $page['errors'][] = l10n('Optimizations have been completed with some errors.');
        }
    }

    /**
     * @param string[] $array
     */
    public static function concat(array $array): string
    {
        $string = implode(',', $array);
        return 'CONCAT(' . $string . ')';
    }

    /**
     * @param string[] $array
     */
    public static function concatWs(array $array, string $separator): string
    {
        $string = implode(',', $array);
        return 'CONCAT_WS(\'' . $separator . '\',' . $string . ')';
    }

    public static function castToText(string $string): string
    {
        return $string;
    }

    /**
     * Returns an array containing the possible values of an enum field.
     *
     * @return string[]
     */
    public static function getEnums(string $table, string $field): array
    {
        $options = [];
        $result = self::query('DESC ' . $table);
        while ((bool) ($row = self::fetchAssoc($result))) {
            if ($row['Field'] == $field) {
                // parse enum('blue','green','black')
                $options = explode(',', substr((string) $row['Type'], 5, -1));
                foreach ($options as $i => $option) {
                    $options[$i] = str_replace("'", '', $option);
                }
            }
        }

        self::freeResult($result);
        return $options;
    }

    /**
     * Checks if a variable is equivalent to true or false.
     */
    public static function getBoolean(mixed $input): bool
    {
        if (is_string($input) && strtolower($input) === 'false') {
            return false;
        }

        return (bool) $input;
    }

    /**
     * Returns string 'true' or 'false' if the given var is boolean.
     * If the input is another type, it is not changed.
     */
    public static function booleanToString(mixed $var): mixed
    {
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        } else {
            return $var;
        }
    }

    public static function getRecentPeriodExpression(int|string $period, string $date = 'CURRENT_DATE'): string
    {
        // Route the default through pwg_now() (env.inc.php) rather than the raw
        // SQL keyword: pwg_now() already resolves to PIWIGO_TEST_NOW in test
        // mode (same mechanism time_since()-based "recent" text already relies
        // on for deterministic rendering) -- CURRENT_DATE is the DB SERVER's
        // real wall-clock date, which drifts out of sync with fixture data
        // dated relative to PIWIGO_TEST_NOW once real time catches up to it.
        // A caller-supplied $date is left untouched either way.
        if ($date === 'CURRENT_DATE' && pwg_test_mode_is_active()) {
            $date = pwg_now()
                ->format('Y-m-d');
        }

        if ($date != 'CURRENT_DATE') {
            $date = '\'' . $date . '\'';
        }

        return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
    }

    public static function getRecentPeriod(int|string $period, string $date = 'CURRENT_DATE'): mixed
    {
        $query = '
SELECT ' . self::getRecentPeriodExpression($period);
        $row = self::fetchRow(self::query($query));
        assert($row !== null);
        [$d] = $row;

        return $d;
    }

    public static function getFloodPeriodExpression(int|string $seconds): string
    {
        return 'SUBDATE(NOW(), INTERVAL ' . $seconds . ' SECOND)';
    }

    public static function getHour(string $date): string
    {
        return 'HOUR(' . $date . ')';
    }

    public static function getDateYYYYMM(string $date): string
    {
        return 'DATE_FORMAT(' . $date . ', \'%Y%m\')';
    }

    public static function getDateMMDD(string $date): string
    {
        return 'DATE_FORMAT(' . $date . ', \'%m%d\')';
    }

    public static function getYear(string $date): string
    {
        return 'YEAR(' . $date . ')';
    }

    public static function getMonth(string $date): string
    {
        return 'MONTH(' . $date . ')';
    }

    public static function getWeek(string $date, ?int $mode = null): string
    {
        if ((bool) $mode) {
            return 'WEEK(' . $date . ', ' . $mode . ')';
        } else {
            return 'WEEK(' . $date . ')';
        }
    }

    public static function getDayOfMonth(string $date): string
    {
        return 'DAYOFMONTH(' . $date . ')';
    }

    public static function getDayOfWeek(string $date): string
    {
        return 'DAYOFWEEK(' . $date . ')';
    }

    public static function getWeekday(string $date): string
    {
        return 'WEEKDAY(' . $date . ')';
    }

    public static function dateToTs(string $date): string
    {
        return 'UNIX_TIMESTAMP(' . $date . ')';
    }

    /**
     * Returns (or send to standard output) the message concerning the
     * error occured for the last mysql query.
     *
     * @phpstan-return ($die is true ? never : void)
     */
    public static function myError(string $header, bool $die = true): void
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
    public static function query2Array(string $query, ?string $key_name = null, ?string $value_name = null): array
    {
        $result = self::query($query);
        $data = [];
        if (! $result instanceof \mysqli_result) {
            return $data;
        }

        if (isset($key_name)) {
            if (isset($value_name)) {
                while ((bool) ($row = self::fetchAssoc($result))) {
                    $key = $row[$key_name];
                    // matches PHP's own implicit null-key coercion; made
                    // explicit here only to satisfy the array key type.
                    $key ??= '';
                    $data[$key] = $row[$value_name];
                }
            } else {
                while ((bool) ($row = self::fetchAssoc($result))) {
                    $key = $row[$key_name];
                    $key ??= '';
                    $data[$key] = $row;
                }
            }
        } else {
            if (isset($value_name)) {
                while ((bool) ($row = self::fetchAssoc($result))) {
                    $data[] = $row[$value_name];
                }
            } else {
                while ((bool) ($row = self::fetchAssoc($result))) {
                    $data[] = $row;
                }
            }
        }

        return $data;
    }
}
