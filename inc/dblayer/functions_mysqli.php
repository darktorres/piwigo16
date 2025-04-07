<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\dblayer;

use Exception;
use mysqli;
use mysqli_result;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;

final class functions_mysqli
{
    public const string DB_ENGINE = 'MySQL';

    public const string REQUIRED_MYSQL_VERSION = '8.4.4';

    public const string DB_REGEX_OPERATOR = 'REGEXP';

    public const string DB_RANDOM_FUNCTION = 'RAND';

    public const int MASS_UPDATES_SKIP_EMPTY = 1;

    /**
     * Connect to database and store MySQLi resource in __$mysqli__ global variable.
     *
     * @param string $host
     *    - localhost
     *    - 1.2.3.4:3405
     *    - /path/to/socket
     *
     * @throws Exception
     */
    public static function pwg_db_connect(
        string $host,
        string $user,
        string $password,
        string $database
    ): void {
        global $mysqli;

        $port = null;
        $socket = null;

        if (str_starts_with($host, '/')) {
            $socket = $host;
            $host = null;
        } elseif (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host);
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
        [$sql_mode_current] = self::pwg_db_fetch_row(self::pwg_query('SELECT @@SESSION.sql_mode;'));

        // remove ONLY_FULL_GROUP_BY from the list
        $sql_mode_altered = implode(',', array_diff(explode(',', $sql_mode_current), ['ONLY_FULL_GROUP_BY']));

        if ($sql_mode_altered != $sql_mode_current) {
            self::pwg_query("SET SESSION sql_mode = '{$sql_mode_altered}';");
        }
    }

    /**
     * Set charset for database connection.
     */
    public static function pwg_db_check_charset(): void
    {
        global $mysqli;

        $db_charset = 'utf8';

        if (defined('DB_CHARSET') &&
            DB_CHARSET != ''
        ) {
            $db_charset = DB_CHARSET;
        }

        $mysqli->set_charset($db_charset);
    }

    /**
     * Check MySQL version. Can call fatal_error().
     */
    public static function pwg_db_check_version(): void
    {
        $current_mysql = self::pwg_get_db_version();

        if (version_compare($current_mysql, self::REQUIRED_MYSQL_VERSION, '<')) {
            functions_html::fatal_error(
                sprintf(
                    'your MySQL version is too old, you have "%s" and you need at least "%s"',
                    $current_mysql,
                    self::REQUIRED_MYSQL_VERSION
                )
            );
        }
    }

    /**
     * Get Mysql Version.
     */
    public static function pwg_get_db_version(): string
    {
        global $mysqli;

        return $mysqli->server_info;
    }

    /**
     * Execute a query
     */
    public static function pwg_query(
        string $query
    ): mysqli_result|bool|null {
        global $mysqli, $conf, $page, $debug, $t2;

        $start = microtime(true);

        $log_file = dirname(__DIR__, 2) . '/_data/sql/mysqli.sql';
        $log_dir = dirname($log_file);

        if (! is_dir($log_dir)) {
            mkdir($log_dir, 0777, true);
        }

        file_put_contents($log_file, $query . "\n\n", FILE_APPEND | LOCK_EX);

        try {
            $result = $mysqli->query($query);
        } catch (Exception $exception) {
            self::my_error($exception->getMessage() . "\n" . $query, $conf->die_on_sql_error);
        }

        $time = microtime(true) - $start;

        if (! isset($page['count_queries'])) {
            $page['count_queries'] = 0;
            $page['queries_time'] = 0;
        }

        $page['count_queries']++;
        $page['queries_time'] += $time;

        if ($conf->show_queries) {
            $output = '';
            $output .= '<pre>[' . $page['count_queries'] . '] ';
            $output .= "\n" . $query;
            $output .= "\n" . '(this query time : ';
            $output .= '<b>' . number_format($time, 3, '.', ' ') . ' s)</b>';
            $output .= "\n" . '(total SQL time  : ';
            $output .= number_format($page['queries_time'], 3, '.', ' ') . ' s)';
            $output .= "\n" . '(total time      : ';
            $output .= number_format(($time + $start - $t2), 3, '.', ' ') . ' s)';

            if ($result != null &&
                preg_match('/\s*SELECT\s+/i', $query)
            ) {
                $output .= "\n" . '(num rows        : ';
                $output .= self::pwg_db_num_rows($result) . ' )';
            } elseif ($result != null &&
                      preg_match('/\s*INSERT|UPDATE|REPLACE|DELETE\s+/i', $query)
            ) {
                $output .= "\n" . '(affected rows   : ';
                $output .= self::pwg_db_changes() . ' )';
            }

            $output .= "</pre>\n";

            $debug .= $output;
        }

        return $result;
    }

    /**
     * Get max value plus one of a particular column.
     */
    public static function pwg_db_nextval(
        string $column,
        string $table
    ): array|bool|string|null {
        $query = <<<SQL
            SELECT IF(MAX({$column}) + 1 IS NULL, 1, MAX({$column}) + 1)
            FROM {$table};
            SQL;
        [$next] = self::pwg_db_fetch_row(self::pwg_query($query));

        return $next;
    }

    public static function pwg_db_changes(): int|string
    {
        global $mysqli;

        return $mysqli->affected_rows;
    }

    public static function pwg_db_num_rows(
        mysqli_result $result
    ): int|string {
        return $result->num_rows;
    }

    // public static function pwg_db_fetch_array(
    //     mysqli_result $result
    // ): array|bool|null {
    //     return $result->fetch_array();
    // }

    public static function pwg_db_fetch_assoc(
        mysqli_result $result
    ): array|bool|null {
        return $result->fetch_assoc();
    }

    public static function pwg_db_fetch_row(
        mysqli_result $result
    ): array|bool|null {
        return $result->fetch_row();
    }

    // public static function pwg_db_fetch_object(
    //     mysqli_result $result
    // ): bool|object|null {
    //     return $result->fetch_object();
    // }

    // public static function pwg_db_free_result(
    //     mysqli_result $result
    // ): void {
    //     $result->free_result();
    // }

    public static function pwg_db_real_escape_string(
        ?string $s
    ): ?string {
        global $mysqli;

        return isset($s) ? $mysqli->real_escape_string($s) : null;
    }

    public static function pwg_db_insert_id(): int|string
    {
        global $mysqli;

        return $mysqli->insert_id;
    }

    // public static function pwg_db_errno(): int
    // {
    //     global $mysqli;

    //     return $mysqli->errno;
    // }

    // public static function pwg_db_error(): string
    // {
    //     global $mysqli;

    //     return $mysqli->error;
    // }

    public static function pwg_db_close(): true
    {
        global $mysqli;

        return $mysqli->close();
    }

    /**
     * Updates multiple lines in a table.
     *
     * @param array<array<int, string>> $dbfields - contains 'primary' and 'update' arrays
     * @param array<array<string, string>> $datas - indexed by column names
     * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
     */
    public static function mass_updates(
        string $tablename,
        array $dbfields,
        array $datas,
        int $flags = 0
    ): void {
        if (count($datas) == 0) {
            return;
        }

        // we use the multi table update or N update queries
        if (count($datas) < 10) {
            foreach ($datas as $data) {
                $is_first = true;

                $escapedTablename = self::protect_column_name($tablename);
                $query = <<<SQL
                    UPDATE {$escapedTablename}
                    SET

                    SQL;

                foreach ($dbfields['update'] as $key) {
                    $separator = $is_first ? '' : ",\n";
                    $escapedKey = self::protect_column_name($key);

                    if (isset($data[$key]) &&
                        $data[$key] != ''
                    ) {
                        $query .= "{$separator}{$escapedKey} = '{$data[$key]}'";
                    } else {
                        if ($flags & self::MASS_UPDATES_SKIP_EMPTY) {
                            continue; // next field
                        }

                        $query .= "{$separator}{$escapedKey} = NULL";
                    }

                    $is_first = false;
                }

                if (! $is_first) { // only if one field at least updated
                    $is_first = true;

                    $query .= "\nWHERE\n";

                    foreach ($dbfields['primary'] as $key) {
                        $escapedKey = self::protect_column_name($key);

                        if (! $is_first) {
                            $query .= ' AND ';
                        }

                        if (isset($data[$key])) {
                            $query .= "{$escapedKey} = '{$data[$key]}'\n";
                        } else {
                            $query .= "{$escapedKey} IS NULL\n";
                        }

                        $is_first = false;
                    }

                    $query = trim($query) . ';';
                    self::pwg_query($query);
                }
            }
        } else {
            // creation of the temporary table
            $result = self::pwg_query('SHOW FULL COLUMNS FROM ' . self::protect_column_name($tablename) . ';');
            $columns = [];
            $all_fields = array_merge($dbfields['primary'], $dbfields['update']);

            while ($row = self::pwg_db_fetch_assoc($result)) {
                if (in_array($row['Field'], $all_fields)) {
                    $column = "`{$row['Field']}`";
                    $column .= " {$row['Type']}";

                    $nullable = true;

                    if (! isset($row['Null']) ||
                        $row['Null'] == '' ||
                        $row['Null'] == 'NO'
                    ) {
                        $column .= ' NOT NULL';
                        $nullable = false;
                    }

                    if (isset($row['Default'])) {
                        $column .= " default '{$row['Default']}'";
                    } elseif ($nullable) {
                        $column .= ' default NULL';
                    }

                    if (isset($row['Collation']) &&
                        $row['Collation'] != 'NULL'
                    ) {
                        $column .= " collate '{$row['Collation']}'";
                    }

                    $columns[] = $column;
                }
            }

            $temporary_tablename = $tablename . '_' . functions::micro_seconds();

            $columnsList = implode(",\n  ", $columns);
            $primaryKeys = implode(', ', $dbfields['primary']);
            $query = <<<SQL
                CREATE TABLE {$temporary_tablename}
                    (
                        {$columnsList},
                        UNIQUE KEY the_key ({$primaryKeys})
                    );
                SQL;

            self::pwg_query($query);
            self::mass_inserts($temporary_tablename, $all_fields, $datas);

            if ($flags & self::MASS_UPDATES_SKIP_EMPTY) {
                $func_set = (fn (string $s): string => "t1.{$s} = IFNULL(t2.{$s}, t1.{$s})");
            } else {
                $func_set = (fn (string $s): string => "t1.{$s} = t2.{$s}");
            }

            // update of table by joining with temporary table
            $escapedTablename = self::protect_column_name($tablename);
            $updateFields = implode(
                ",\n    ",
                array_map($func_set, $dbfields['update'])
            );

            $primaryConditions = implode(
                "\n    AND ",
                array_map(
                    fn (string $s): string => "t1.{$s} = t2.{$s}",
                    $dbfields['primary']
                )
            );

            $query = <<<SQL
                UPDATE {$escapedTablename} AS t1, {$temporary_tablename} AS t2
                SET {$updateFields}
                WHERE {$primaryConditions};
                SQL;
            self::pwg_query($query);

            self::pwg_query("DROP TABLE {$temporary_tablename};");
        }
    }

    /**
     * Updates one line in a table.
     *
     * @param int $flags - if MASS_UPDATES_SKIP_EMPTY, empty values do not overwrite existing ones
     */
    public static function single_update(
        string $tablename,
        array $datas,
        array $where,
        int $flags = 0
    ): void {
        if (count($datas) == 0) {
            return;
        }

        $is_first = true;
        $escapedTablename = self::protect_column_name($tablename);

        $query = <<<SQL
            UPDATE {$escapedTablename}
            SET

            SQL;

        foreach ($datas as $key => $value) {
            $separator = $is_first ? '' : ",\n";
            $escapedKey = self::protect_column_name($key);

            if (isset($value) &&
                $value !== ''
            ) {
                $query .= "{$separator}{$escapedKey} = '{$value}'";
            } else {
                if ($flags & self::MASS_UPDATES_SKIP_EMPTY) {
                    continue; // next field
                }

                $query .= "{$separator}{$escapedKey} = NULL";
            }

            $is_first = false;
        }

        if (! $is_first) { // only if one field at least updated
            $is_first = true;

            $query .= "\nWHERE\n";

            foreach ($where as $key => $value) {
                $escapedKey = self::protect_column_name($key);

                if (! $is_first) {
                    $query .= ' AND ';
                }

                if (isset($value)) {
                    $query .= "{$escapedKey} = '{$value}'\n";
                } else {
                    $query .= "{$escapedKey} IS NULL\n";
                }

                $is_first = false;
            }

            $query = trim($query) . ';';
            self::pwg_query($query);
        }
    }

    /**
     * Inserts multiple lines in a table.
     *
     * @param array $dbfields - fields from $datas which will be used
     * @param array{
     *     ignore: bool,
     * } $options
     */
    public static function mass_inserts(
        string $table_name,
        array $dbfields,
        array $datas,
        array $options = []
    ): void {
        if (count($datas) == 0) {
            return;
        }

        $ignore = '';

        if (isset($options['ignore']) &&
            $options['ignore']
        ) {
            $ignore = 'IGNORE';
        }

        $query = <<<SQL
            SELECT @@max_allowed_packet;
            SQL;
        [$packet_size] = self::pwg_db_fetch_row(self::pwg_query($query));
        $escapedTablename = self::protect_column_name($table_name);
        $escapeddbfields = implode(', ', array_map(self::protect_column_name(...), $dbfields));

        $insert_ignore = 'INSERT' . ($ignore ? " {$ignore}" : '');
        $queryBase = "{$insert_ignore} INTO {$escapedTablename} ({$escapeddbfields}) VALUES\n";
        $query = '';

        foreach ($datas as $insert) {
            $queryTemp = '(';

            foreach ($dbfields as $field_id => $dbfield) {
                if ($field_id > 0) {
                    $queryTemp .= ', ';
                }

                if (! isset($insert[$dbfield]) ||
                    $insert[$dbfield] === ''
                ) {
                    $queryTemp .= 'NULL';
                } else {
                    $queryTemp .= "'{$insert[$dbfield]}'";
                }
            }

            $queryTemp .= ')';

            $len = strlen($queryBase . $query . ', ' . $queryTemp);

            if ($len >= $packet_size) { // delay $insert to next query
                $query = trim($query) . ';';
                self::pwg_query($queryBase . $query);
                $query = $queryTemp;
            } else {
                if ($query !== '' &&
                    $query !== '0'
                ) {
                    $query .= ",\n";
                }

                $query .= $queryTemp;
            }
        }

        $query = trim($query) . ';';
        self::pwg_query($queryBase . $query);
    }

    /**
     * Inserts one line in a table.
     *
     * @param array{
     *     ignore: bool,
     * } $options
     */
    public static function single_insert(
        string $table_name,
        array $data,
        array $options = []
    ): void {
        if (count($data) == 0) {
            return;
        }

        $ignore = '';

        if (isset($options['ignore']) &&
            $options['ignore']
        ) {
            $ignore = 'IGNORE';
        }

        $escapedTablename = self::protect_column_name($table_name);
        $columns = implode(', ', array_map(self::protect_column_name(...), array_keys($data)));
        $insert_ignore = 'INSERT' . ($ignore ? " {$ignore}" : '');
        $query = <<<SQL
            {$insert_ignore} INTO {$escapedTablename}
                ({$columns})
            VALUES

            SQL;

        $query .= '(';
        $is_first = true;

        foreach ($data as $key => $value) {
            if (! $is_first) {
                $query .= ', ';
            } else {
                $is_first = false;
            }

            if ($value === '' ||
                $value === null
            ) {
                $query .= 'NULL';
            } else {
                $query .= "'{$value}'";
            }
        }

        $query .= ')';

        $query = trim($query) . ';';
        self::pwg_query($query);
    }

    public static function protect_column_name(
        string $column_name
    ): string {
        if ($column_name[0] != '`') {
            $column_name = '`' . $column_name . '`';
        }

        return $column_name;
    }

    /**
     * Do maintenance on all Piwigo tables
     */
    public static function do_maintenance_all_tables(): void
    {
        global $page;

        $all_tables = [];

        // List all tables
        $query = 'SHOW TABLES';
        $result = self::pwg_query($query);

        while ($row = self::pwg_db_fetch_row($result)) {
            $all_tables[] = $row[0];
        }

        // Repair all tables
        $all_tables = array_map(self::protect_column_name(...), $all_tables);
        $allTablesList = implode(', ', $all_tables);
        $query = <<<SQL
            REPAIR TABLE {$allTablesList};
            SQL;
        $mysqli_rc = self::pwg_query($query);

        // Re-Order all tables
        foreach ($all_tables as $table_name) {
            $all_primary_key = [];

            $query = <<<SQL
                DESC {$table_name};
                SQL;
            $result = self::pwg_query($query);

            while ($row = self::pwg_db_fetch_assoc($result)) {
                if ($row['Key'] == 'PRI') {
                    $all_primary_key[] = $row['Field'];
                }
            }

            if (count($all_primary_key) != 0) {
                $allPrimaryKeyList = implode(', ', $all_primary_key);
                $query = <<<SQL
                    ALTER TABLE {$table_name}
                    ORDER BY {$allPrimaryKeyList};
                    SQL;
                $mysqli_rc = $mysqli_rc && self::pwg_query($query);
            }
        }

        // Optimize all tables
        $allTablesList = implode(', ', $all_tables);
        $query = <<<SQL
            OPTIMIZE TABLE {$allTablesList};
            SQL;
        $mysqli_rc = $mysqli_rc && self::pwg_query($query);

        if ($mysqli_rc) {
            $page['infos'][] = functions::l10n('All optimizations have been successfully completed.');
        } else {
            $page['errors'][] = functions::l10n('Optimizations have been completed with some errors.');
        }
    }

    public static function pwg_db_concat(
        array $array
    ): string {
        $string = implode(', ', $array);
        return "CONCAT({$string})";
    }

    public static function pwg_db_concat_ws(
        array $array,
        string $separator
    ): string {
        $string = implode(', ', $array);
        return "CONCAT_WS('{$separator}', {$string})";
    }

    // public static function pwg_db_cast_to_text(
    //     string $string
    // ): string {
    //     return $string;
    // }

    /**
     * Returns an array containing the possible values of an enum field.
     *
     * @return array<string>
     */
    public static function get_enums(
        string $table,
        string $field
    ): array {
        $result = self::pwg_query("DESC {$table};");

        while ($row = self::pwg_db_fetch_assoc($result)) {
            if ($row['Field'] == $field) {
                // parse enum('blue','green','black')
                $options = explode(',', substr($row['Type'], 5, -1));

                foreach ($options as $i => $option) {
                    $options[$i] = str_replace("'", '', $option);
                }
            }
        }

        $result->free_result();
        return $options;
    }

    /**
     * Checks if a variable is equivalent to true or false.
     */
    public static function get_boolean(
        array|string $input
    ): bool {
        return strtolower($input) === 'false' ? false : (bool) $input;
    }

    /**
     * Returns string 'true' or 'false' if the given var is boolean.
     * If the input is another type, it is not changed.
     */
    public static function boolean_to_string(
        bool|int|string $var
    ): bool|int|string {
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }

        return $var;
    }

    public static function pwg_db_get_recent_period_expression(
        string|int $period,
        string $date = 'CURRENT_DATE'
    ): string {
        if ($date != 'CURRENT_DATE') {
            $date = "'{$date}'";
        }

        return <<<SQL
            SUBDATE({$date}, INTERVAL {$period} DAY)
            SQL;
    }

    public static function pwg_db_get_recent_period(
        string|int $period,
        string $date = 'CURRENT_DATE'
    ): array|bool|string|null {
        $recentPeriodExpression = self::pwg_db_get_recent_period_expression($period);
        $query = <<<SQL
            SELECT {$recentPeriodExpression};
            SQL;
        [$d] = self::pwg_db_fetch_row(self::pwg_query($query));

        return $d;
    }

    public static function pwg_db_get_flood_period_expression(
        int|string $seconds
    ): string {
        return <<<SQL
            SUBDATE(NOW(), INTERVAL {$seconds} SECOND)
            SQL;
    }

    public static function pwg_db_get_hour(
        string $date
    ): string {
        return <<<SQL
            HOUR({$date})
            SQL;
    }

    public static function pwg_db_get_date_YYYYMM(
        string $date
    ): string {
        return <<<SQL
            DATE_FORMAT({$date}, '%Y%m')
            SQL;
    }

    public static function pwg_db_get_date_MMDD(
        string $date
    ): string {
        return <<<SQL
            DATE_FORMAT({$date}, '%m%d')
            SQL;
    }

    public static function pwg_db_get_year(
        string $date
    ): string {
        return <<<SQL
            YEAR({$date})
            SQL;
    }

    public static function pwg_db_get_month(
        string $date
    ): string {
        return <<<SQL
            MONTH({$date})
            SQL;
    }

    public static function pwg_db_get_week(
        string $date,
        ?int $mode = null
    ): string {
        if ($mode) {
            return <<<SQL
                WEEK({$date}, {$mode})
                SQL;
        }

        return <<<SQL
            WEEK({$date})
            SQL;
    }

    public static function pwg_db_get_dayofmonth(
        string $date
    ): string {
        return <<<SQL
            DAYOFMONTH({$date})
            SQL;
    }

    public static function pwg_db_get_dayofweek(
        string $date
    ): string {
        return <<<SQL
            DAYOFWEEK({$date})
            SQL;
    }

    public static function pwg_db_get_weekday(
        string $date
    ): string {
        return <<<SQL
            WEEKDAY({$date})
            SQL;
    }

    public static function pwg_db_date_to_ts(
        string $date
    ): string {
        return <<<SQL
            UNIX_TIMESTAMP({$date})
            SQL;
    }

    /**
     * Returns (or send to standard output) the message concerning the
     * error occurred for the last mysql query.
     */
    public static function my_error(
        string $header,
        bool $die
    ): void {
        global $mysqli;

        $error = '[mysql error ' . $mysqli->errno . '] ' . $mysqli->error . "\n";
        $error .= $header;

        if ($die) {
            functions_html::fatal_error($error);
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
     */
    public static function query2array(
        string $query,
        ?string $key_name = null,
        ?string $value_name = null
    ): array {
        $result = self::pwg_query($query);
        $data = [];

        while ($row = self::pwg_db_fetch_assoc($result)) {
            if (isset($key_name)) {
                $data[$row[$key_name]] = isset($value_name) ? $row[$value_name] : $row;
            } elseif (isset($value_name)) {
                $data[] = $row[$value_name];
            } else {
                $data[] = $row;
            }
        }

        return $data;
    }
}
