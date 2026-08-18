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
    /**
     * Pinned to the exact validated MySQL version, not just its window-functions floor (8.0.0).
     */
    public const string REQUIRED_MYSQL_VERSION = '8.4.10';

    /**
     * Pinned to the exact validated PostgreSQL version, not just its `DROP DATABASE ... WITH (FORCE)` floor (13.0).
     */
    public const string REQUIRED_POSTGRES_VERSION = '18.4';

    /**
     * Returns the *complete* random-ordering expression (including its own
     * parens/arguments), not just the bare function name -- every real
     * caller (`Sort\OrderBy::toSqlBody()` -> `Sort\PhotoSortField::column()`,
     * `ImageRepository`, `UserRepository`, `CalendarRepository`,
     * `public/random.php`) previously appended `'()'` itself; they now
     * splice this return value directly.
     *
     * MySQL's own `RAND(seed)` accepts an inline seed argument, so test
     * mode is routed through `Env::now()` the same way
     * {@see getRecentPeriodExpression()} already does for `CURRENT_DATE}`
     * -- `public/random.php`'s own golden-HTML capture is otherwise
     * non-deterministic by nature (a fresh shuffle every request),
     * confirmed live via 3 different orderings across 3 consecutive runs
     * before this fix.
     *
     * Neither Postgres nor SQLite has an equivalent inline-seed syntax --
     * `RANDOM()` (the same bare function name on both, verified live
     * against a real sqlite3 connection, matching
     * {@see \Piwigo\Db\DqlFunction\RandFunction}'s own already-established
     * "PostgreSQL/SQLite" grouping) only becomes deterministic after a
     * *separate*, preceding seeding step (Postgres's own `SELECT
     * setseed(...)`; SQLite has no SQL-level seeding mechanism at all),
     * which this bare string builder (no `Connection` of its own, see this
     * class's own docblock) can't issue. `.env.test`'s only configured
     * driver is `mysqli`; both staying unseeded here is a known, undone
     * gap, not a silent one.
     *
     * Was a bare `const string` (no way to branch at all); converted to a
     * method, matching this class's own established
     * `DbCredentials::fromEnv()->driver`-branch pattern
     * ({@see getRecentPeriodExpression()}/{@see getHour()}/{@see dateToTs()}).
     */
    public static function randomFunction(): string
    {
        return self::randomFunctionFor(self::isPostgres() || self::isSqlite());
    }

    /**
     * {@see randomFunction()} with the platform supplied rather than read
     * from the environment, so a caller that already holds a real
     * `Connection` can use its platform instead
     * ({@see SortRenderer::randomExpression()}).
     *
     * $usesAnsiRandom (not $isPostgres -- renamed once a 3rd platform
     * needed this same branch): true for Postgres *and* SQLite, both of
     * which use the bare `RANDOM()` keyword with no inline-seed form;
     * false for MySQL/MariaDB's own `RAND()`, which does have one (see
     * this method's own seeding-policy paragraph above).
     *
     * The seeding policy lives here, in one place, rather than being
     * duplicated by each caller that knows its own platform.
     */
    public static function randomFunctionFor(bool $usesAnsiRandom): string
    {
        if ($usesAnsiRandom) {
            return 'RANDOM()';
        }

        if (Env::testModeIsActive()) {
            return 'RAND(' . Env::now()->getTimestamp() . ')';
        }

        return 'RAND()';
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
     * §15 removed every other caller (the entities involved already map
     * real booleans; the string round trip only ever existed for a
     * `enum('true','false')` schema shape this project no longer has) --
     * one real caller remains, {@see \Piwigo\Admin\AlbumsPageRenderer}'s
     * `visible` field, kept as a string on purpose:
     * `themes/admin/default/js/albums.js`'s `node.visible == 'false'`
     * check is a loose JS string comparison against the JSON tree this
     * renderer builds, and a real JSON boolean there would silently break
     * it (`true == 'false'` is `true` under JS's own loose-equality string
     * coercion) unless that JS file changed too, which is out of scope for
     * a PHP-side SQL-modernization pass.
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
     * retyped from enum('true','false') to
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
     * $period is `int` -- every real caller (`Core\RecentIconResolver`,
     * `Filter\FilterService`,
     * `Category\CategoryRepository::findComputedCategoriesRollup()`,
     * `Controller\CommentsController`, `Users\UserService`,
     * `Image\ImageRepository`) either already passes a real `int` or, in
     * `UserService`'s case, is fixed at that call site to fall back to
     * `0` rather than silently pass a non-numeric string through
     * untouched.
     *
     * $date's contract: the default (`CURRENT_DATE`) is routed through
     * Env::now() in test mode (see this method's own body); any
     * non-default $date is always a bound-parameter placeholder (e.g.
     * `:lastDate`) the caller has already declared and will bind via
     * their own query's existing params/types, never a literal value
     * handed to this method directly -- so it's spliced into the
     * returned expression unquoted, same as the `CURRENT_DATE` keyword
     * always is. The 2 real non-default-$date callers are
     * `Image\ImageRepository::findIdsAddedSameDayAsLatest()` and
     * `Users\UserService::getRecentPhotosCondition()`. Quoting is still
     * applied for the one remaining literal-date case: the internal
     * test-mode override below, a controlled `Y-m-d`-formatted string,
     * not caller input.
     *
     * Postgres has no `SUBDATE()` -- `$date - make_interval(days =>
     * $period)` is the real equivalent, with an explicit `::timestamp`
     * cast on `$date` first: bare subtraction of an
     * `interval` from an untyped string literal/bound parameter fails
     * outright with `ERROR: invalid input syntax for type interval` --
     * Postgres can't infer `date`/`timestamp` from context here the way
     * MySQL's own looser typing does, unlike the `CURRENT_DATE` keyword
     * case, which is already a real `date`, so the cast is a no-op
     * there). `::timestamp`, not `::date`, specifically: a `::date` cast
     * would silently truncate a caller-supplied value's time-of-day
     * component (`getRecentPhotosCondition()`'s own `last_photo_date`
     * bind is a full datetime, not a bare date) -- `::timestamp`
     * preserves it, matching `SUBDATE()`'s own type-preserving behavior
     * on the MySQL side exactly.
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

        // SQLite's own datetime() modifier syntax -- verified live: no
        // explicit cast needed the way Postgres's own branch above
        // requires (SQLite has no real DATE/TIMESTAMP column type to
        // disambiguate against in the first place, matching this whole
        // SQLite campaign's own established "no native date type"
        // finding), and the bare `CURRENT_DATE` keyword this method's own
        // default resolves to is already a valid datetime() argument on
        // this platform too.
        if (self::isSqlite()) {
            return 'datetime(' . $date . ", '-" . $period . " days')";
        }

        return 'SUBDATE(' . $date . ',INTERVAL ' . $period . ' DAY)';
    }

    /**
     * {@see getRecentPeriodExpression()}'s DQL equivalent -- unlike that
     * one, needs no per-platform branch of its own: DQL's registered
     * `DATE_SUB()` ({@see \Piwigo\Db\DqlFunction\DateSubFunction}) already
     * compiles to the identical per-platform SQL
     * (`(date)::timestamp - make_interval(days => n)` on Postgres,
     * `SUBDATE()`'s own native equivalent on MySQL/MariaDB) that method's
     * two raw-SQL branches hand-roll.
     *
     * $date's contract matches getRecentPeriodExpression()'s own: a bound
     * parameter placeholder the caller has already declared (e.g.
     * `:lastDate`), or the DQL `CURRENT_DATE()` function call -- callers
     * choose between the two the same way getRecentPeriodExpression()'s
     * own `Env::testModeIsActive()` check does, since this method has no
     * default of its own to make that call implicitly.
     */
    public static function getRecentPeriodDqlExpression(int $period, string $date): string
    {
        return "DATE_SUB({$date}, {$period}, 'day')";
    }

    public static function getHour(string $date): string
    {
        if (self::isPostgres()) {
            return 'EXTRACT(HOUR FROM ' . $date . ')';
        }

        // strftime('%H', ...) -- verified live -- returns a zero-padded
        // text hour ('00'-'23'); CAST ... AS INTEGER matches HOUR()'s/
        // EXTRACT(HOUR FROM ...)'s own real numeric return type on the
        // other 2 platforms.
        if (self::isSqlite()) {
            return "CAST(strftime('%H', " . $date . ') AS INTEGER)';
        }

        return 'HOUR(' . $date . ')';
    }

    public static function dateToTs(string $date): string
    {
        if (self::isPostgres()) {
            return 'EXTRACT(EPOCH FROM ' . $date . ')';
        }

        // strftime('%s', ...) -- verified live against a real known epoch
        // -- returns a text unix-timestamp string; CAST ... AS INTEGER
        // matches UNIX_TIMESTAMP()'s/EXTRACT(EPOCH FROM ...)'s own real
        // numeric return type on the other 2 platforms, same reasoning as
        // getHour()'s own cast above.
        if (self::isSqlite()) {
            return "CAST(strftime('%s', " . $date . ') AS INTEGER)';
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

    private static function isSqlite(): bool
    {
        return DbCredentials::fromEnv()->driver === 'sqlite3';
    }
}
