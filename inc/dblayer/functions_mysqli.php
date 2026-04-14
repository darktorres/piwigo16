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

    public const string DB_RANDOM_FUNCTION = 'RAND()';

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
            $port = (int) $port;
        }

        $mysqli = new mysqli();
        $mysqli->options(MYSQLI_OPT_LOCAL_INFILE, true);

        // real_connect requires concrete defaults for optional params.
        $mysqli->real_connect(
            $host ?? 'localhost',
            $user,
            $password,
            '',
            $port ?? 0,
            $socket ?? ''
        );

        if (mysqli_connect_error()) {
            throw new Exception("Can't connect to server");
        }

        if (! empty($database) &&
            ! $mysqli->select_db($database)
        ) {
            throw new Exception('Connection to server succeed, but it was impossible to connect to database');
        }

        self::pwg_query("SET SESSION sql_mode = 'ANSI,TRADITIONAL,NO_ENGINE_SUBSTITUTION';");
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

        if ($conf->log_sql_queries) {
            $log_file = dirname(__DIR__, 2) . '/_data/sql/mysqli.sql';
            $log_dir = dirname($log_file);

            if (! is_dir($log_dir)) {
                mkdir($log_dir, 0777, true);
            }

            file_put_contents($log_file, $query . "\n\n", FILE_APPEND | LOCK_EX);
        }

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
            $output = '<pre>[' . $page['count_queries'] . '] ';
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
            SELECT COALESCE(MAX({$column}) + 1, 1)
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

        global $mysqli;

        [$packet_size] = self::pwg_db_fetch_row(
            self::pwg_query('SELECT @@max_allowed_packet;')
        );

        self::pwg_query('START TRANSACTION;');

        $batch = '';

        foreach ($datas as $data) {
            $is_first = true;

            $stmt = "UPDATE {$tablename} SET ";

            foreach ($dbfields['update'] as $key) {
                $separator = $is_first ? '' : ', ';

                if (isset($data[$key]) &&
                    $data[$key] != ''
                ) {
                    $escaped = self::pwg_db_real_escape_string((string) $data[$key]);
                    $stmt .= "{$separator}{$key} = '{$escaped}'";
                } else {
                    if (($flags & self::MASS_UPDATES_SKIP_EMPTY) !== 0) {
                        continue; // next field
                    }

                    $stmt .= "{$separator}{$key} = NULL";
                }

                $is_first = false;
            }

            if ($is_first) {
                continue; // no fields to update
            }

            $stmt .= ' WHERE ';
            $is_first = true;

            foreach ($dbfields['primary'] as $key) {
                if (! $is_first) {
                    $stmt .= ' AND ';
                }

                if (isset($data[$key])) {
                    $escaped = self::pwg_db_real_escape_string((string) $data[$key]);
                    $stmt .= "{$key} = '{$escaped}'";
                } else {
                    $stmt .= "{$key} IS NULL";
                }

                $is_first = false;
            }

            $stmt .= ";\n";

            if (strlen($batch) + strlen($stmt) >= $packet_size) {
                $mysqli->multi_query($batch);

                // drain all result sets from multi_query
                do {
                    $result = $mysqli->store_result();

                    if ($result) {
                        $result->free();
                    }
                } while ($mysqli->next_result());

                $batch = '';
            }

            $batch .= $stmt;
        }

        if ($batch !== '') {
            $mysqli->multi_query($batch);

            do {
                $result = $mysqli->store_result();

                if ($result) {
                    $result->free();
                }
            } while ($mysqli->next_result());
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
    /** Cached value of @@max_allowed_packet (session-lifetime). */
    private static ?int $cachedPacketSize = null;

    /** Cached regex word-boundary tokens (derived from DB version, set once per request). */
    private static ?array $cachedWordBoundary = null;

    public static function mass_inserts(
        string $table_name,
        array $dbfields,
        array $datas,
        array $options = [],
        ?\Closure $on_progress = null
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

        if (self::$cachedPacketSize === null) {
            [$pkt] = self::pwg_db_fetch_row(self::pwg_query('SELECT @@max_allowed_packet;'));
            self::$cachedPacketSize = (int) $pkt;
        }

        $packet_size = self::$cachedPacketSize;
        $dbfields_str = implode(', ', $dbfields);

        $insert_ignore = 'INSERT' . ($ignore !== '' ? " {$ignore}" : '');
        $queryBase = "{$insert_ignore} INTO {$table_name} ({$dbfields_str}) VALUES\n";
        $queryBaseLen = strlen($queryBase);

        // Cap batch size to keep individual INSERT statements under the
        // max_allowed_packet limit.  The packet_size check below is the
        // primary guard; max_rows is a secondary ceiling so we don't
        // build excessively large PHP strings in memory.
        $max_rows = 50000;
        $query = '';
        $currentLen = $queryBaseLen;
        $rowCount = 0;

        $bulk = ! empty($options['bulk']);
        // When the caller already manages the transaction (e.g. sync loop
        // wrapping inserts into images + image_category in one commit),
        // skip the internal START TRANSACTION / COMMIT to avoid extra
        // implicit commits and redundant disk syncs.
        $manage_txn = empty($options['no_transaction']);

        // For very large inserts, disable index checks and commit in
        // chunks to keep the redo log manageable.
        if ($bulk) {
            self::pwg_query('SET unique_checks=0, foreign_key_checks=0;');
        }

        if ($manage_txn) {
            self::pwg_query('START TRANSACTION;');
        }

        $totalRows = count($datas);
        $insertedRows = 0;
        $batchesSinceCommit = 0;

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
                    $queryTemp .= "'" . self::pwg_db_real_escape_string((string) $insert[$dbfield]) . "'";
                }
            }

            $queryTemp .= ')';
            $tempLen = strlen($queryTemp) + 2;
            $rowCount++;

            if (($currentLen + $tempLen >= $packet_size || $rowCount > $max_rows) && $query !== '') {
                self::pwg_query($queryBase . $query . ';');
                $insertedRows += $rowCount - 1;
                $batchesSinceCommit++;

                if ($on_progress !== null) {
                    $on_progress($insertedRows, $totalRows);
                }

                // Commit periodically in bulk mode to keep the redo log bounded.
                if ($manage_txn && $bulk && $batchesSinceCommit >= 10) {
                    self::pwg_query('COMMIT;');
                    self::pwg_query('START TRANSACTION;');
                    $batchesSinceCommit = 0;
                }

                $query = $queryTemp;
                $currentLen = $queryBaseLen + strlen($queryTemp);
                $rowCount = 1;
            } else {
                if ($query !== '') {
                    $query .= ",\n";
                }

                $query .= $queryTemp;
                $currentLen += $tempLen;
            }
        }

        if ($query !== '') {
            self::pwg_query($queryBase . $query . ';');
        }

        if ($manage_txn) {
            self::pwg_query('COMMIT;');
        }

        if ($bulk) {
            self::pwg_query('SET unique_checks=1, foreign_key_checks=1;');
        }
    }

    /**
     * Bulk-load rows from a TSV temp file via LOAD DATA LOCAL INFILE.
     *
     * Much faster than multi-row INSERT for large datasets (10k+ rows)
     * because it bypasses SQL parsing and PHP string-building entirely.
     *
     * Requires MYSQLI_OPT_LOCAL_INFILE (set in pwg_db_connect) and the
     * MySQL server to allow local_infile.  Returns false if LOAD DATA
     * is unavailable so the caller can fall back to mass_inserts.
     *
     * The caller is responsible for transaction management.
     *
     * @param string   $tsv_path   Absolute path to the TSV file.
     * @param string   $table_name Target table.
     * @param string[] $dbfields   Column names, matching TSV column order.
     * @return bool    true on success, false if LOAD DATA failed.
     */
    public static function load_data_local(
        string $tsv_path,
        string $table_name,
        array $dbfields
    ): bool {
        global $mysqli;

        $fields = implode(', ', $dbfields);
        // MySQL accepts forward slashes on all platforms.
        $path = str_replace('\\', '/', $tsv_path);

        $query = "LOAD DATA LOCAL INFILE '{$path}'"
            . " INTO TABLE {$table_name}"
            . " CHARACTER SET utf8mb4"
            . " FIELDS TERMINATED BY '\\t'"
            . " LINES TERMINATED BY '\\n'"
            . " ({$fields});";

        // Suppress the exception path — return false so the caller can
        // fall back to mass_inserts instead of dying.
        try {
            $result = $mysqli->query($query);
        } catch (Exception) {
            return false;
        }

        return $result !== false;
    }

    /**
     * Write one TSV row into an open file handle.
     *
     * NULL / empty values are written as \N (the LOAD DATA NULL marker).
     * Tabs, newlines and backslashes in values are escaped.
     *
     * @param resource $fp       fopen() handle opened with 'wb'.
     * @param array    $row      Associative row data.
     * @param string[] $dbfields Column order.
     */
    public static function write_tsv_row($fp, array $row, array $dbfields): void
    {
        $line = '';

        foreach ($dbfields as $i => $field) {
            if ($i > 0) {
                $line .= "\t";
            }

            if (! isset($row[$field]) || $row[$field] === '') {
                $line .= '\\N';
            } else {
                $line .= str_replace(
                    ['\\', "\t", "\n", "\r"],
                    ['\\\\', '\\t', '\\n', '\\r'],
                    (string) $row[$field]
                );
            }
        }

        fwrite($fp, $line . "\n");
    }

    // -----------------------------------------------------------------------
    // Cross-backend abstraction methods
    // -----------------------------------------------------------------------

    public static function index_exists(string $table, string $index_name): bool
    {
        $t = self::pwg_db_real_escape_string($table);
        $i = self::pwg_db_real_escape_string($index_name);
        $result = self::pwg_query("SHOW INDEX FROM {$t} WHERE Key_name = '{$i}';");
        return self::pwg_db_num_rows($result) > 0;
    }

    public static function drop_index(string $table, string $index_name): void
    {
        $t = self::pwg_db_real_escape_string($table);
        $i = self::pwg_db_real_escape_string($index_name);
        self::pwg_query("ALTER TABLE {$t} DROP INDEX {$i};");
    }

    public static function create_fulltext_index(string $table, string $index_name, array $columns): void
    {
        $t = self::pwg_db_real_escape_string($table);
        $i = self::pwg_db_real_escape_string($index_name);
        $colList = implode(', ', $columns);
        self::pwg_query("ALTER TABLE {$t} ADD FULLTEXT INDEX {$i} ({$colList});");
    }

    public static function set_bulk_insert_mode(bool $enable): void
    {
        $v = $enable ? 0 : 1;
        self::pwg_query("SET unique_checks={$v}, foreign_key_checks={$v};");
    }

    public static function add_enum_value(string $table, string $column, string $new_value): void
    {
        $existing = self::get_enums($table, $column);

        if (in_array($new_value, $existing)) {
            return;
        }

        $existing[] = $new_value;
        $values = implode(',', array_map(
            fn($v) => "'" . self::pwg_db_real_escape_string($v) . "'",
            $existing
        ));
        self::pwg_query("ALTER TABLE {$table} CHANGE {$column} {$column} ENUM({$values}) DEFAULT NULL;");
    }

    public static function sql_group_concat(string $column): string
    {
        return "GROUP_CONCAT({$column})";
    }

    public static function get_table_names(): array
    {
        $tables = [];
        $result = self::pwg_query('SHOW TABLES;');

        while ($row = self::pwg_db_fetch_row($result)) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public static function get_table_columns(string $table): array
    {
        $columns = [];
        $result = self::pwg_query("DESC {$table};");

        while ($row = self::pwg_db_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }

        return $columns;
    }

    /**
     * Returns regex word-boundary tokens suitable for REGEXP queries.
     *
     * MySQL 8.0.4+ uses the ICU regex engine where \b is a word boundary.
     * MariaDB and older MySQL use POSIX character classes [[:<:]] / [[:>:]].
     *
     * @return array{begin: string, end: string}
     */
    public static function sql_regex_word_boundary(): array
    {
        if (self::$cachedWordBoundary !== null) {
            return self::$cachedWordBoundary;
        }

        $version = self::pwg_get_db_version();
        $isMariaDB = stripos($version, 'mariadb') !== false;

        if (! $isMariaDB && version_compare($version, '8.0.4', '>=')) {
            // ICU regex: four PHP backslashes → \\b in the SQL literal → \b seen by ICU.
            self::$cachedWordBoundary = ['begin' => '\\\\b', 'end' => '\\\\b'];
        } else {
            self::$cachedWordBoundary = ['begin' => '[[:<:]]', 'end' => '[[:>:]]'];
        }

        return self::$cachedWordBoundary;
    }

    /**
     * Returns the SQL FULLTEXT search clause for MATCH … AGAINST in boolean mode.
     * The $table parameter is accepted for interface symmetry but ignored by MySQL.
     */
    public static function sql_fulltext_clause(array $fields, array $fts_terms, string $table): string
    {
        $fieldList = implode(', ', $fields);
        $termsStr = self::pwg_db_real_escape_string(implode(' ', $fts_terms));
        return "MATCH({$fieldList}) AGAINST('{$termsStr}' IN BOOLEAN MODE)";
    }

    /** No-op for MySQL: sequences are auto-incremented columns managed natively. */
    public static function sync_sequences(): void {}

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

        $columns = implode(', ', array_keys($data));
        $insert_ignore = 'INSERT' . ($ignore !== '' ? " {$ignore}" : '');
        $query = <<<SQL
            {$insert_ignore} INTO {$table_name}
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

        // Optimize all tables
        $allTablesList = implode(', ', $all_tables);
        $query = <<<SQL
            OPTIMIZE TABLE {$allTablesList};
            SQL;
        $mysqli_rc = self::pwg_query($query);

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

    public static function pwg_db_cast_to_text(string $string): string
    {
        return "CAST({$string} AS CHAR)";
    }

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
        if ($date !== 'CURRENT_DATE') {
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
