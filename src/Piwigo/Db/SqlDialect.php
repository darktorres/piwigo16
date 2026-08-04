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
 * pgsql support pass: `getRecentPeriodExpression()`/`getHour()`/
 * `dateToTs()` are now real per-platform branches (Postgres has no
 * `SUBDATE()`/`HOUR()`/`UNIX_TIMESTAMP()` -- `- make_interval(...)`/
 * `EXTRACT(HOUR FROM ...)`/`EXTRACT(EPOCH FROM ...)` are the real
 * equivalents, verified live) -- selected via `DbCredentials::fromEnv()->
 * driver` (this class is a pure static string builder with no `Connection`
 * of its own to read `getDatabasePlatform()` from, unlike
 * `SqlDialectExecutor`/`DbInfo`/the DQL functions, which branch on the
 * real connection's platform directly). A real interface split (MySQL/
 * Postgres implementations selected by DI) remains a follow-up if this
 * class ever grows enough per-platform branches to justify it -- not
 * needed for 3 methods.
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
     *
     * pgsql support pass: Postgres has no `SUBDATE()` -- `$date -
     * make_interval(days => $period)` is the real equivalent. Needs an
     * explicit `::timestamp` cast on `$date` first, verified live: bare
     * subtraction of an `interval` from an untyped string literal/bound
     * parameter (`'2026-08-01' - make_interval(...)`) fails outright
     * (`ERROR: invalid input syntax for type interval`) -- Postgres can't
     * infer `date`/`timestamp` from context here the way MySQL's own
     * looser typing does, unlike the `CURRENT_DATE` keyword case (already
     * a real `date`, so the cast is a no-op there). `::timestamp`, not
     * `::date`, specifically: verified live that a `::date` cast would
     * silently truncate a caller-supplied value's time-of-day component
     * (`getRecentPhotosCondition()`'s own `last_photo_date` bind is a
     * full datetime, not a bare date) -- `::timestamp` preserves it,
     * matching `SUBDATE()`'s own type-preserving behavior on the MySQL
     * side exactly.
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

        if (self::isPostgres()) {
            return '(' . $date . ')::timestamp - make_interval(days => ' . $period . ')';
        }

        return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
    }

    public static function getHour(string $date): string
    {
        if (self::isPostgres()) {
            return 'EXTRACT(HOUR FROM ' . $date . ')';
        }

        return 'HOUR(' . $date . ')';
    }

    public static function dateToTs(string $date): string
    {
        if (self::isPostgres()) {
            return 'EXTRACT(EPOCH FROM ' . $date . ')';
        }

        return 'UNIX_TIMESTAMP(' . $date . ')';
    }

    /**
     * This class is a pure static string builder with no `Connection` of
     * its own (see class docblock) -- `DbCredentials::fromEnv()` (not the
     * `@deprecated ::current()` bridge, which this file isn't on the
     * allow-list for) is the only platform signal available here.
     */
    private static function isPostgres(): bool
    {
        return DbCredentials::fromEnv()->driver === 'pgsql';
    }
}
