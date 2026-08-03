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
 * MySQL-specific today (`DB_RANDOM_FUNCTION`/`getRecentPeriodExpression()`'s
 * `SUBDATE()`), same as the class this was extracted from --
 * `DbCredentials::current()->driver` already supports `'pgsql'` for the
 * DBAL connection layer itself, but nothing under `src/Piwigo/` ever
 * exercised pgsql beyond `DbConnection::build()`'s own driver branch
 * supporting it -- no `install/schema/pgsql.sql` or equivalent exists in
 * this repo; a real multi-dialect split (a `Piwigo\Db\SqlDialect`
 * interface with MySQL/Postgres implementations, selected by
 * `DbCredentials::current()->driver`) is out of scope for this pass and
 * left as a follow-up once a real pgsql install path is exercised end to
 * end.
 *
 * Further SQL-modernization audit, Item 15G: the 10 Calendar-exclusive
 * date-part/`CONCAT_WS`/cast-passthrough methods this class used to carry
 * (`getYear`/`getMonth`/`getWeek`/`getWeekday`/`getDayOfMonth`/
 * `getDayOfWeek`/`getDateYYYYMM`/`getDateMMDD`/`concatWs`/`castToText`)
 * moved to `Piwigo\Db\DqlFunction\*` (real DQL functions, same per-
 * platform-`instanceof`-branch shape as `RegexpFunction`/`RandFunction`/
 * `GroupConcatFunction`) once the Calendar subsystem -- their own real
 * caller -- became real DQL.
 *
 * Phase 4 Item 16: `protectColumnName()`/`concat()`/`DB_REGEX_OPERATOR`
 * removed -- confirmed direct duplicates of `Doctrine\DBAL\Platforms\
 * AbstractMySQLPlatform::quoteSingleIdentifier()`/`getConcatExpression()`/
 * `getRegexpExpression()`, real per-vendor framework primitives accessible
 * via `$connection->getDatabasePlatform()` rather than hand-rolled,
 * permanently-MySQL-only string builders. `protectColumnName()`'s only
 * real callers ({@see \Piwigo\Db\BatchWriter}) now call
 * `quoteSingleIdentifier()` directly (also a real correctness fix: it
 * escapes an embedded backtick character, which the hand-rolled version
 * never did). `concat()`/`DB_REGEX_OPERATOR` had zero real callers left to
 * migrate -- `CategoryRepository::updateImagePathsForCategory()`'s own DQL
 * conversion and {@see \Piwigo\Db\DqlFunction\RegexpFunction} had already
 * independently arrived at calling the framework's own equivalents during
 * earlier DQL-modernization items. `getBoolean()`/`booleanToString()`/
 * `booleanToInt()`, `concatWs()`, the 9 date-part-extraction methods (moved
 * to `DqlFunction\*` per Item 15G above), and `DB_RANDOM_FUNCTION` were all
 * checked against `AbstractPlatform` too and confirmed to have no
 * equivalent there, or to solve a genuinely different, MySQL-schema-
 * internal problem (`getBoolean()`'s family) -- they stay exactly as this
 * codebase's own domain-specific additions, not framework reinvention.
 */
final class SqlDialect
{
    public const string DB_ENGINE = 'MySQL';

    public const string REQUIRED_MYSQL_VERSION = '5.0.0';

    public const string DB_RANDOM_FUNCTION = 'RAND';

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

    /**
     * SQL-modernization audit (recursive-cuddling-squid.md): $period was
     * `int|string` -- tightened to `int` since every real caller (checked
     * directly: `Core\RecentIconResolver`, `Filter\FilterService`,
     * `Category\CategoryRepository::findComputedCategoriesRollup()`,
     * `Controller\CommentsController`, `Users\UserService`,
     * `Image\ImageRepository`) either already passes a real `int` or, in
     * `UserService`'s case, had its own loose `is_numeric(...) ? (int) ...
     * : (is_string(...) ? $recent_period : 0)` cast that silently let a
     * non-numeric string through untouched -- fixed at that call site (P24
     * gap-closure-adjacent bug found via this audit, not merely a type
     * annotation tightening).
     *
     * Further SQL-modernization audit, Item 11: $date's own manual
     * `'\'' . $date . '\''` quote-wrap used to apply to *any* non-default
     * $date, including a caller-supplied raw literal date value -- a real,
     * separate raw-string-splice defect. Fixed by changing the contract: a
     * non-default $date is now always a bound-parameter placeholder (e.g.
     * `:lastDate`) the caller has already declared and will bind via their
     * own query's existing params/types, never a literal value handed to
     * this method directly -- so it's spliced into the returned expression
     * unquoted, same as the `CURRENT_DATE` keyword always was. The 2 real
     * non-default-$date callers (`Image\ImageRepository::
     * findIdsAddedSameDayAsLatest()`, `Users\UserService::
     * getRecentPhotosCondition()`) converted to this placeholder contract
     * in this same pass. Quoting is still needed -- and still applied --
     * for the one remaining literal-date case: the internal test-mode
     * override below, a controlled `Y-m-d`-formatted string, not caller
     * input.
     */
    public static function getRecentPeriodExpression(int $period, string $date = 'CURRENT_DATE'): string
    {
        // Route the default through Env::now() rather than the raw
        // SQL keyword: Env::now() already resolves to PIWIGO_TEST_NOW in test
        // mode (same mechanism time_since()-based "recent" text already relies
        // on for deterministic rendering) -- CURRENT_DATE is the DB SERVER's
        // real wall-clock date, which drifts out of sync with fixture data
        // dated relative to PIWIGO_TEST_NOW once real time catches up to it.
        // A caller-supplied $date (a bound-parameter placeholder) is left
        // untouched either way.
        if ($date === 'CURRENT_DATE' && Env::testModeIsActive()) {
            $date = '\'' . Env::now()->format('Y-m-d') . '\'';
        }

        return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
    }

    public static function getHour(string $date): string
    {
        return 'HOUR(' . $date . ')';
    }

    public static function dateToTs(string $date): string
    {
        return 'UNIX_TIMESTAMP(' . $date . ')';
    }
}
