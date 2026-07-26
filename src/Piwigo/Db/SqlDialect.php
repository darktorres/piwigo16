<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Core\Env;

/**
 * SQL-dialect-fragment generators extracted from `Piwigo\Db\MysqliDb`
 * (Legacy Coupling Retirement: DI+DBAL migration, Phase 1a pilot) --
 * every one of these is a pure string builder with no connection
 * dependency (confirmed by reading `MysqliDb`'s own source: none of them
 * call `self::query()`/`self::fetchAssoc()` internally), unlike
 * `MysqliDb::getEnums()`/`getRecentPeriod()`/`doMaintenanceAllTables()`,
 * which stay on the DB-access side of the migration since they do.
 *
 * MySQL-specific today (`DB_RANDOM_FUNCTION`/date-function names), same
 * as the class this was extracted from -- `DbCredentials::current()->driver`
 * already supports `'pgsql'` for the DBAL connection layer itself, but
 * nothing under `src/Piwigo/` ever exercised pgsql beyond
 * `DbConnection::build()`'s own driver branch supporting it -- no
 * `install/schema/pgsql.sql` or equivalent exists in this repo; a real
 * multi-dialect split (a
 * `Piwigo\Db\SqlDialect` interface with MySQL/Postgres implementations,
 * selected by `DbCredentials::current()->driver`) is out of scope for this pass and left as a
 * follow-up once a real pgsql install path is exercised end-to-end.
 */
final class SqlDialect
{
    public const string DB_ENGINE = 'MySQL';

    public const string REQUIRED_MYSQL_VERSION = '5.0.0';

    public const string DB_REGEX_OPERATOR = 'REGEXP';

    public const string DB_RANDOM_FUNCTION = 'RAND';

    public static function protectColumnName(string $column_name): string
    {
        if ($column_name[0] !== '`') {
            $column_name = '`' . $column_name . '`';
        }

        return $column_name;
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
     * If the input is another type, it is not changed -- an identity
     * passthrough for any non-bool BatchWriter column value (int, string,
     * float, null), so the return type mirrors the param type by design.
     *
     * @phpstan-return ($var is bool ? string : mixed)
     */
    public static function booleanToString(mixed $var): mixed
    {
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }

        return $var;
    }

    /**
     * Returns int 1 or 0 if the given var is boolean, for the columns
     * User domain Stage 1a retyped from enum('true','false') to
     * tinyint(1) (enabled_high/expand/last_visit_from_history/
     * show_nb_comments/show_nb_hits) -- booleanToString()'s 'true'/
     * 'false' strings are non-numeric and strict SQL mode rejects them
     * against an int column. If the input is another type, it is not
     * changed -- same identity-passthrough-for-non-bool rationale as
     * booleanToString() above.
     *
     * @phpstan-return ($var is bool ? int : mixed)
     */
    public static function booleanToInt(mixed $var): mixed
    {
        if (is_bool($var)) {
            return $var ? 1 : 0;
        }

        return $var;
    }

    public static function getRecentPeriodExpression(int|string $period, string $date = 'CURRENT_DATE'): string
    {
        // Route the default through Env::now() rather than the raw
        // SQL keyword: Env::now() already resolves to PIWIGO_TEST_NOW in test
        // mode (same mechanism time_since()-based "recent" text already relies
        // on for deterministic rendering) -- CURRENT_DATE is the DB SERVER's
        // real wall-clock date, which drifts out of sync with fixture data
        // dated relative to PIWIGO_TEST_NOW once real time catches up to it.
        // A caller-supplied $date is left untouched either way.
        if ($date === 'CURRENT_DATE' && Env::testModeIsActive()) {
            $date = Env::now()
                ->format('Y-m-d');
        }

        if ($date !== 'CURRENT_DATE') {
            $date = '\'' . $date . '\'';
        }

        return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
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
        }

        return 'WEEK(' . $date . ')';
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
}
