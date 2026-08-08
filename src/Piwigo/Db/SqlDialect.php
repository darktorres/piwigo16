<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Core\Env;

/**
 * SQL-dialect-fragment generators: pure string builders with no
 * connection dependency of their own (none of them call a DB query
 * method internally).
 *
 * `getRecentPeriodExpression()`/`getHour()`/`dateToTs()` are per-platform
 * branches -- Postgres has no `SUBDATE()`/`HOUR()`/`UNIX_TIMESTAMP()`;
 * `- make_interval(...)`/`EXTRACT(HOUR FROM ...)`/`EXTRACT(EPOCH FROM ...)`
 * are the real equivalents -- selected via `DbCredentials::fromEnv()->
 * driver`, since this class has no `Connection` of its own to read
 * `getDatabasePlatform()` from, unlike `SqlDialectExecutor`/`DbInfo`/the
 * DQL functions, which branch on the real connection's platform directly.
 *
 * `getBoolean()`/`booleanToString()`/`booleanToInt()` have no equivalent
 * in DBAL's `AbstractPlatform` -- they solve a MySQL-schema-internal
 * problem, so they stay as this codebase's own domain-specific additions
 * rather than framework reinvention.
 */
final class SqlDialect
{
    public const string DB_ENGINE = 'MySQL';

    public const string REQUIRED_MYSQL_VERSION = '5.0.0';

    /**
     * pgsql support pass: 13.0 specifically -- the real, discovered floor
     * for a feature this codebase actually depends on
     * (`DROP DATABASE ... WITH (FORCE)`, verified live this session as
     * genuinely needed by IntegrationTestCase::resetDatabase() and
     * unavailable before Postgres 13), not an arbitrary/aspirational
     * number -- same "loose sanity floor, not a strict
     * hard-required-stack-matching gate" philosophy `REQUIRED_MYSQL_VERSION`
     * above already has (that constant's own 5.0.0 is far below this
     * codebase's real MySQL 9.7 target too).
     */
    public const string REQUIRED_POSTGRES_VERSION = '13.0';

    /**
     * pgsql support pass: real bug found live -- callers building a raw
     * SQL fragment from this (not DQL, which already has its own portable
     * {@see \Piwigo\Db\DqlFunction\RandFunction}) always got the literal
     * MySQL name, so a real WS "sort by random" request (via
     * Ws\WsHelper::stdImageSqlOrder() -> Image\PhotoSortField::column())
     * and CategoryRepository::findImageIdsForCategories()'s own raw-DBAL
     * fallback for a `RAND()`-valued `order_by`/`order_by_custom` config
     * both failed against a real Postgres server with "function rand()
     * does not exist" -- Postgres's own random-ordering function is
     * `RANDOM()`, a different name entirely, not just a dialect quirk of
     * the same function. Was a bare `const string` (no way to branch)
     * before this pass; converted to a method, matching this class's own
     * established `DbCredentials::fromEnv()->driver`-branch pattern
     * ({@see getRecentPeriodExpression()}/{@see getHour()}/{@see dateToTs()}).
     */
    public static function randomFunction(): string
    {
        return DbCredentials::fromEnv()->driver === 'pgsql' ? 'RANDOM' : 'RAND';
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
