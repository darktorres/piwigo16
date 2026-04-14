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
use PgSql\Result;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;

final class functions_pgsql
{
    public const string DB_ENGINE = 'PostgreSQL';

    public const string REQUIRED_POSTGRESQL_VERSION = '17.4';

    public const string DB_REGEX_OPERATOR = '~';

    public const string DB_RANDOM_FUNCTION = 'CAST(random() AS text)';

    public const int MASS_UPDATES_SKIP_EMPTY = 1;

    private static ?Result $last_result = null;

    /**
     * Connect to database and store PostgreSQL resource in __$pg__ global variable.
     *
     * @param string $host
     *    - localhost
     *    - 1.2.3.4:5432
     *    - /path/to/socket
     */
    public static function pwg_db_connect(
        string $host,
        string $user,
        string $password,
        string $database
    ): void {
        global $pg;

        $port = null;

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host);
        }

        if ($database === '') {
            $database = 'postgres';
        }

        $database = "dbname={$database}";

        if ($password !== '') {
            $password = "password={$password}";
        }

        if ($port !== null &&
            $port !== ''
        ) {
            $port = "port={$port}";
        }

        $connection_string = "host={$host} {$port} {$database} user={$user} {$password}";
        $pg = pg_connect($connection_string);

        if ($pg === false) {
            throw new Exception("Can't connect to server.");
        }

        // PostgreSQL doesn't require a specific session setting for group by
    }

    /**
     * Check PostgreSQL version. Can call fatal_error().
     */
    public static function pwg_db_check_version(): void
    {
        $current_pg = self::pwg_get_db_version();

        if (version_compare($current_pg, self::REQUIRED_POSTGRESQL_VERSION, '<')) {
            functions_html::fatal_error(
                sprintf(
                    'Your PostgreSQL version is too old, you have "%s" and you need at least "%s"',
                    $current_pg,
                    self::REQUIRED_POSTGRESQL_VERSION
                )
            );
        }
    }

    /**
     * Get PostgreSQL Version.
     */
    public static function pwg_get_db_version(): string
    {
        global $pg;

        return pg_version($pg)['server'];
    }

    /**
     * Execute a query
     */
    public static function pwg_query(
        string $query
    ): Result|bool {
        global $pg, $conf, $page, $debug, $t2;

        $start = microtime(true);

        if ($conf->log_sql_queries) {
            $log_file = dirname(__DIR__, 2) . '/_data/sql/pgsql.sql';
            $log_dir = dirname($log_file);

            if (! is_dir($log_dir)) {
                mkdir($log_dir, 0777, true);
            }

            file_put_contents($log_file, $query . "\n\n", FILE_APPEND | LOCK_EX);
        }

        $result = pg_query($pg, $query);
        self::$last_result = $result;

        if ($result === false) {
            self::my_error(pg_last_error($pg) . "\n" . $query, $conf->die_on_sql_error);
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

            if ($result != false &&
                preg_match('/\s*SELECT\s+/i', $query)
            ) {
                $output .= "\n" . '(num rows        : ';
                $output .= self::pwg_db_num_rows($result) . ' )';
            } elseif ($result != false &&
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
    ): string {
        $query = <<<SQL
            SELECT COALESCE(MAX({$column}) + 1, 1)
            FROM {$table};
            SQL;
        [$next] = self::pwg_db_fetch_row(self::pwg_query($query));

        return $next;
    }

    public static function pwg_db_changes(): int|string
    {
        return isset(self::$last_result) ? pg_affected_rows(self::$last_result) : 0;
    }

    public static function pwg_db_num_rows(
        Result $result
    ): int {
        return pg_num_rows($result);
    }

    // public static function pwg_db_fetch_array(
    //     Result $result
    // ): array|bool|null {
    //     return pg_fetch_array($result);
    // }

    public static function pwg_db_fetch_assoc(
        Result $result
    ): array|bool {
        $row = pg_fetch_assoc($result);
        self::convert_bool($row);
        return $row;
    }

    public static function pwg_db_fetch_row(
        Result $result
    ): array|bool {
        $row = pg_fetch_row($result);
        self::convert_bool($row);
        return $row;
    }

    // public static function pwg_db_fetch_object(
    //     Result $result
    // ): bool|object|null {
    //     return pg_fetch_object($result);
    // }

    // public static function pwg_db_free_result(
    //     Result $result
    // ): void {
    //     pg_free_result($result);
    // }

    public static function pwg_db_real_escape_string(
        string|int|float|null $s
    ): string|null {
        global $pg;

        if (is_int($s) ||
            is_float($s)
        ) {
            return (string) $s;
        }

        return isset($s) ? pg_escape_string($pg, $s) : null;
    }

    public static function pwg_db_insert_id(): int|string
    {
        global $pg;

        $result = pg_query($pg, 'SELECT lastval()');

        if ($result === false) {
            throw new Exception('Failed to get last insert id');
        }

        [$row] = pg_fetch_row($result);

        return $row;
    }

    // public static function pwg_db_errno(): int
    // {
    //     // no error code support in pgsql
    // }

    // public static function pwg_db_error(): string
    // {
    //     global $pg;

    //     return pg_last_error($pg);
    // }

    public static function pwg_db_close(): bool
    {
        global $pg;

        return pg_close($pg);
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

        self::pwg_query('BEGIN;');

        foreach ($datas as $data) {
            $is_first = true;

            $query = "UPDATE {$tablename} SET\n";

            foreach ($dbfields['update'] as $key) {
                $separator = $is_first ? '' : ",\n";

                if (isset($data[$key]) &&
                    $data[$key] != ''
                ) {
                    $escaped = self::pwg_db_real_escape_string((string) $data[$key]);
                    $query .= "{$separator}{$key} = '{$escaped}'";
                } else {
                    if (($flags & self::MASS_UPDATES_SKIP_EMPTY) !== 0) {
                        continue; // next field
                    }

                    $query .= "{$separator}{$key} = NULL";
                }

                $is_first = false;
            }

            if (! $is_first) { // only if one field at least updated
                $is_first = true;

                $query .= "\nWHERE\n";

                foreach ($dbfields['primary'] as $key) {
                    if (! $is_first) {
                        $query .= ' AND ';
                    }

                    if (isset($data[$key])) {
                        $escaped = self::pwg_db_real_escape_string((string) $data[$key]);
                        $query .= "{$key} = '{$escaped}'\n";
                    } else {
                        $query .= "{$key} IS NULL\n";
                    }

                    $is_first = false;
                }

                $query = trim($query) . ';';
                self::pwg_query($query);
            }
        }

        self::pwg_query('COMMIT;');
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

        $query = <<<SQL
            UPDATE {$tablename}
            SET

            SQL;

        foreach ($datas as $key => $value) {
            $separator = $is_first ? '' : ",\n";

            if (isset($value) &&
                $value !== ''
            ) {
                $query .= "{$separator}{$key} = '{$value}'";
            } else {
                if (($flags & self::MASS_UPDATES_SKIP_EMPTY) !== 0) {
                    continue; // next field
                }

                $query .= "{$separator}{$key} = NULL";
            }

            $is_first = false;
        }

        if (! $is_first) { // only if one field at least updated
            $is_first = true;

            $query .= "\nWHERE\n";

            foreach ($where as $key => $value) {
                if (! $is_first) {
                    $query .= ' AND ';
                }

                if (isset($value)) {
                    $query .= "{$key} = '{$value}'\n";
                } else {
                    $query .= "{$key} IS NULL\n";
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
        string $tablename,
        array $dbfields,
        array $datas,
        array $options = []
    ): void {
        if (count($datas) == 0) {
            return;
        }

        $dbfields_str = implode(', ', $dbfields);
        $query = "INSERT INTO {$tablename} ({$dbfields_str}) VALUES\n";

        $values = [];

        foreach ($datas as $data) {
            $row_values = [];

            foreach ($dbfields as $key) {
                $row_values[] = isset($data[$key]) ? "'" . self::pwg_db_real_escape_string($data[$key]) . "'" : 'NULL';
            }

            $values[] = '(' . implode(', ', $row_values) . ')';
        }

        $query .= implode(",\n", $values);
        $query = trim($query) . ';';

        self::pwg_query($query);
        self::sync_sequences();
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

        $on_conflict = '';

        if (isset($options['ignore']) &&
            $options['ignore']
        ) {
            $on_conflict = 'ON CONFLICT DO NOTHING';
        }

        $columns = implode(', ', array_keys($data));
        $query = <<<SQL
            INSERT INTO {$table_name}
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

        $query .= ") {$on_conflict}";
        $query = trim($query) . ';';

        self::pwg_query($query);
        self::sync_sequences();
    }

    /**
     * Do maintenance on all Piwigo tables
     */
    public static function do_maintenance_all_tables(): void
    {
        global $page;

        $all_tables = [];

        // List all user tables (excluding system catalogs)
        $query = <<<SQL
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = 'public';
            SQL;
        $result = self::pwg_query($query);

        while ($row = self::pwg_db_fetch_assoc($result)) {
            $all_tables[] = $row['tablename'];
        }

        $ok = true;

        // VACUUM and ANALYZE each table
        foreach ($all_tables as $table_name) {
            $query = <<<SQL
                VACUUM FULL {$table_name};
                SQL;
            $ok = $ok && self::pwg_query($query);

            $query = <<<SQL
                ANALYZE {$table_name};
                SQL;
            $ok = $ok && self::pwg_query($query);
        }

        if ($ok) {
            $page['infos'][] = functions::l10n('All PostgreSQL maintenance tasks completed successfully.');
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
    ): array|bool {
        $options = [];
        $query = <<<SQL
            SELECT unnest(enum_range(NULL::{$table}_{$field}));
            SQL;
        $result = self::pwg_query($query);

        while ($row = self::pwg_db_fetch_assoc($result)) {
            $options[] = $row['unnest'];
        }

        pg_free_result($result);
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
        string|bool|int $var
    ): string|int {
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }

        return $var;
    }

    public static function pwg_db_get_recent_period_expression(
        int|string $period,
        string $date = 'CURRENT_DATE'
    ): string {
        if ($date !== 'CURRENT_DATE') {
            $date = "'{$date}'";
        }

        return <<<SQL
            CURRENT_DATE - INTERVAL '{$period} days'
            SQL;
    }

    public static function pwg_db_get_recent_period(
        int|string $period,
        string $date = 'CURRENT_DATE'
    ): string {
        $recentPeriodExpression = self::pwg_db_get_recent_period_expression($period);
        $query = <<<SQL
            SELECT {$recentPeriodExpression} AS date;
            SQL;
        [$d] = self::pwg_db_fetch_row(self::pwg_query($query));

        return $d;
    }

    public static function pwg_db_get_flood_period_expression(
        int|string $seconds
    ): string {
        return <<<SQL
            CURRENT TIMESTAMP - INTERVAL '{$seconds} seconds'
            SQL;
    }

    public static function pwg_db_get_hour(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(HOUR FROM {$date})
            SQL;
    }

    public static function pwg_db_get_date_YYYYMM(
        string $date
    ): string {
        return <<<SQL
            TO_CHAR({$date}, 'YYYYMM')
            SQL;
    }

    public static function pwg_db_get_date_MMDD(
        string $date
    ): string {
        return <<<SQL
            TO_CHAR({$date}, 'MMDD')
            SQL;
    }

    public static function pwg_db_get_year(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(YEAR FROM {$date})
            SQL;
    }

    public static function pwg_db_get_month(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(MONTH FROM {$date})
            SQL;
    }

    public static function pwg_db_get_week(
        string $date,
        ?int $mode = null
    ): string {
        return <<<SQL
            EXTRACT(WEEK FROM {$date})
            SQL;
    }

    public static function pwg_db_get_dayofmonth(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(DAY FROM {$date})
            SQL;
    }

    public static function pwg_db_get_dayofweek(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(DOW FROM {$date})
            SQL;
    }

    public static function pwg_db_get_weekday(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(DOW FROM {$date})
            SQL;
    }

    public static function pwg_db_date_to_ts(
        string $date
    ): string {
        return <<<SQL
            EXTRACT(EPOCH FROM {$date})
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
        $error = '[pgsql error] ' . $header . "\n";

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

    private static function convert_bool(array|bool &$row): void
    {
        if ($row) {
            foreach ($row as $key => $value) {
                if ($value === 't') {
                    $row[$key] = 'true';
                } elseif ($value === 'f') {
                    $row[$key] = 'false';
                }
            }
        }
    }

    private static function sync_sequences(): void
    {
        $query = <<<SQL
            SELECT
                'SELECT SETVAL(' ||
                quote_literal(quote_ident(sequence_namespace.nspname) || '.' || quote_ident(class_sequence.relname)) ||
                ', COALESCE(MAX(' ||quote_ident(pg_attribute.attname)|| '), 1) ) FROM ' ||
                quote_ident(table_namespace.nspname)|| '.'||quote_ident(class_table.relname)|| ';'
            FROM pg_depend
                INNER JOIN pg_class AS class_sequence
                    ON class_sequence.oid = pg_depend.objid
                        AND class_sequence.relkind = 'S'
                INNER JOIN pg_class AS class_table
                    ON class_table.oid = pg_depend.refobjid
                INNER JOIN pg_attribute
                    ON pg_attribute.attrelid = class_table.oid
                        AND pg_depend.refobjsubid = pg_attribute.attnum
                INNER JOIN pg_namespace as table_namespace
                    ON table_namespace.oid = class_table.relnamespace
                INNER JOIN pg_namespace AS sequence_namespace
                    ON sequence_namespace.oid = class_sequence.relnamespace
            ORDER BY sequence_namespace.nspname, class_sequence.relname;
            SQL;

        $res = self::pwg_query($query);

        while ($row = pg_fetch_row($res)) {
            self::pwg_query($row[0]);
        }
    }
}
