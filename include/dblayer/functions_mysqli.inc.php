<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8f-2: real logic ported to Piwigo\Db\MysqliDb. Every real call
// site outside install/db/*.php and install/upgrade_*.php (frozen
// historical scripts, out of P23's migration scope) was retargeted
// directly to MysqliDb::method(). These 45 functions stay defined here,
// each a thin one-line facade, purely so those excluded scripts -- which
// still unconditionally call them by their original bare names -- keep
// working unchanged. Not a general "too many call sites" facade like
// fatal_error()/l10n(): the real blocker here is specifically the
// excluded install/db family, confirmed via a full-repo retarget of every
// other real caller.

use Piwigo\Db\MysqliDb;

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
    MysqliDb::connect($host, $user, $password, $database);
}

/**
 * Set charset for database connection.
 */
function pwg_db_check_charset(): void
{
    MysqliDb::checkCharset();
}

/**
 * Check MySQL version. Can call fatal_error().
 */
function pwg_db_check_version(): void
{
    MysqliDb::checkVersion();
}

/**
 * Get Mysql Version.
 *
 * @return string
 */
function pwg_get_db_version()
{
    return MysqliDb::getDbVersion();
}

/**
 * Execute a query
 *
 * @param string $query
 * @return mysqli_result|bool
 */
function pwg_query($query)
{
    return MysqliDb::query($query);
}

/**
 * Get max value plus one of a particular column.
 *
 * @param string $column
 * @param string $table
 */
function pwg_db_nextval($column, $table): int
{
    return MysqliDb::nextval($column, $table);
}

function pwg_db_changes(): int|string
{
    return MysqliDb::changes();
}

function pwg_db_num_rows(mysqli_result|bool $result): int|string
{
    return MysqliDb::numRows($result);
}

/**
 * @return array<int|string, string|null>|null|false
 */
function pwg_db_fetch_array(mysqli_result|bool $result): array|null|false
{
    return MysqliDb::fetchArray($result);
}

/**
 * @return array<string, string|null>|null|false
 */
function pwg_db_fetch_assoc(mysqli_result|bool $result): array|null|false
{
    return MysqliDb::fetchAssoc($result);
}

/**
 * @return array<int, string|null>|null
 */
function pwg_db_fetch_row(mysqli_result|bool $result): array|null
{
    return MysqliDb::fetchRow($result);
}

function pwg_db_fetch_object(mysqli_result|bool $result): object|null|false
{
    return MysqliDb::fetchObject($result);
}

function pwg_db_free_result(mysqli_result|bool $result): void
{
    MysqliDb::freeResult($result);
}

function pwg_db_real_escape_string(?string $s): ?string
{
    return MysqliDb::realEscapeString($s);
}

function pwg_db_insert_id(): int|string
{
    return MysqliDb::insertId();
}

function pwg_db_errno(): int
{
    return MysqliDb::errno();
}

function pwg_db_error(): string
{
    return MysqliDb::error();
}

function pwg_db_close(): bool
{
    return MysqliDb::close();
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
    MysqliDb::massUpdates($tablename, $dbfields, $datas, $flags);
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
    MysqliDb::singleUpdate($tablename, $datas, $where, $flags);
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
    MysqliDb::massInserts($table_name, $dbfields, $datas, $options);
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
    MysqliDb::singleInsert($table_name, $data, $options);
}

function protect_column_name(string $column_name): string
{
    return MysqliDb::protectColumnName($column_name);
}

/**
 * Do maintenance on all Piwigo tables
 */
function do_maintenance_all_tables(): void
{
    MysqliDb::doMaintenanceAllTables();
}

/**
 * @param string[] $array
 */
function pwg_db_concat(array $array): string
{
    return MysqliDb::concat($array);
}

/**
 * @param string[] $array
 */
function pwg_db_concat_ws(array $array, string $separator): string
{
    return MysqliDb::concatWs($array, $separator);
}

function pwg_db_cast_to_text(string $string): string
{
    return MysqliDb::castToText($string);
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
    return MysqliDb::getEnums($table, $field);
}

/**
 * Checks if a variable is equivalent to true or false.
 *
 * @param mixed $input
 * @return bool
 */
function get_boolean($input)
{
    return MysqliDb::getBoolean($input);
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
    return MysqliDb::booleanToString($var);
}

function pwg_db_get_recent_period_expression(int|string $period, string $date = 'CURRENT_DATE'): string
{
    return MysqliDb::getRecentPeriodExpression($period, $date);
}

function pwg_db_get_recent_period(int|string $period, string $date = 'CURRENT_DATE'): mixed
{
    return MysqliDb::getRecentPeriod($period, $date);
}

function pwg_db_get_flood_period_expression(int|string $seconds): string
{
    return MysqliDb::getFloodPeriodExpression($seconds);
}

function pwg_db_get_hour(string $date): string
{
    return MysqliDb::getHour($date);
}

function pwg_db_get_date_YYYYMM(string $date): string
{
    return MysqliDb::getDateYYYYMM($date);
}

function pwg_db_get_date_MMDD(string $date): string
{
    return MysqliDb::getDateMMDD($date);
}

function pwg_db_get_year(string $date): string
{
    return MysqliDb::getYear($date);
}

function pwg_db_get_month(string $date): string
{
    return MysqliDb::getMonth($date);
}

function pwg_db_get_week(string $date, ?int $mode = null): string
{
    return MysqliDb::getWeek($date, $mode);
}

function pwg_db_get_dayofmonth(string $date): string
{
    return MysqliDb::getDayOfMonth($date);
}

function pwg_db_get_dayofweek(string $date): string
{
    return MysqliDb::getDayOfWeek($date);
}

function pwg_db_get_weekday(string $date): string
{
    return MysqliDb::getWeekday($date);
}

function pwg_db_date_to_ts(string $date): string
{
    return MysqliDb::dateToTs($date);
}

/**
 * Returns (or send to standard output) the message concerning the
 * error occured for the last mysql query.
 *
 * @phpstan-return ($die is true ? never : void)
 */
function my_error(string $header, bool $die = true): void
{
    MysqliDb::myError($header, $die);
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
    return MysqliDb::query2Array($query, $key_name, $value_name);
}
