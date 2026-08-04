<?php

declare(strict_types=1);

use Piwigo\Db\SqlDialect;

/**
 * Piwigo\Db\SqlDialect -- pure SQL-fragment string builders, no connection
 * dependency. getHour()/dateToTs() had zero coverage and booleanToInt()
 * only its bool branch (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); every
 * other method here is already indirectly exercised (Calendar/C13yInternal
 * tests), but a full, direct pass is cheap and removes any doubt.
 *
 * Phase 4 Item 16: protectColumnName()/concat()/DB_REGEX_OPERATOR removed
 * -- confirmed-duplicate hand-rolling of
 * `Doctrine\DBAL\Platforms\AbstractMySQLPlatform::quoteSingleIdentifier()`/
 * `getConcatExpression()`/`getRegexpExpression()`. protectColumnName()'s
 * only real callers (Db\BatchWriter) now call the real platform method
 * directly; concat()/DB_REGEX_OPERATOR had zero real callers left --
 * CategoryRepository's own DQL conversion (Item 14/15) and
 * Db\DqlFunction\RegexpFunction had already independently arrived at
 * calling the framework's own equivalents.
 *
 * pgsql support pass: getHour()/dateToTs()/getRecentPeriodExpression()
 * branch on PIWIGO_DB_DRIVER (via DbCredentials::fromEnv() -- this class
 * has no Connection of its own, see its class docblock) -- same
 * save/restore-around-a-scenario shape DbConnectionTest.php's own
 * $envVars/beforeEach/afterEach already establishes for this exact env
 * var, scoped to just PIWIGO_DB_DRIVER since that's the only one this
 * class reads.
 */
$originalDbDriver = null;

beforeEach(function () use (&$originalDbDriver): void {
    $value = getenv('PIWIGO_DB_DRIVER');
    $originalDbDriver = $value === false ? null : $value;
});

afterEach(function () use (&$originalDbDriver): void {
    putenv($originalDbDriver === null ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDbDriver);
});
test('getBoolean treats the string "false" as false, everything else by normal truthiness', function (): void {
    expect(SqlDialect::getBoolean('false'))->toBeFalse();
    expect(SqlDialect::getBoolean('FALSE'))->toBeFalse();
    expect(SqlDialect::getBoolean('true'))->toBeTrue();
    expect(SqlDialect::getBoolean(1))->toBeTrue();
    expect(SqlDialect::getBoolean(0))->toBeFalse();
    expect(SqlDialect::getBoolean(''))->toBeFalse();
});

test('booleanToString converts a real bool to the literal string, and passes everything else through', function (): void {
    expect(SqlDialect::booleanToString(true))->toBe('true');
    expect(SqlDialect::booleanToString(false))->toBe('false');
    expect(SqlDialect::booleanToString('already-a-string'))->toBe('already-a-string');
    expect(SqlDialect::booleanToString(42))->toBe(42);
});

test('booleanToInt converts a real bool to 1/0, and passes everything else through', function (): void {
    expect(SqlDialect::booleanToInt(true))->toBe(1);
    expect(SqlDialect::booleanToInt(false))->toBe(0);
    expect(SqlDialect::booleanToInt('42'))->toBe('42');
    expect(SqlDialect::booleanToInt(null))->toBeNull();
});

test('getHour wraps a date expression in HOUR()', function (): void {
    expect(SqlDialect::getHour('images.date_available'))->toBe('HOUR(images.date_available)');
});

test('getHour wraps a date expression in EXTRACT(HOUR FROM ...) on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::getHour('images.date_available'))->toBe('EXTRACT(HOUR FROM images.date_available)');
});

test('dateToTs wraps a date expression in UNIX_TIMESTAMP()', function (): void {
    expect(SqlDialect::dateToTs('d'))->toBe('UNIX_TIMESTAMP(d)');
});

test('dateToTs wraps a date expression in EXTRACT(EPOCH FROM ...) on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::dateToTs('d'))->toBe('EXTRACT(EPOCH FROM d)');
});

/**
 * Further SQL-modernization audit, Item 11: a non-default $date is now
 * always a bound-parameter placeholder the caller already declared (see
 * getRecentPeriodExpression()'s own docblock) -- spliced in unquoted,
 * unlike the old quote-wrap-any-literal-value defect this replaced.
 */
test('getRecentPeriodExpression builds a SUBDATE(...) fragment for a caller-supplied bound-parameter placeholder', function (): void {
    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe('SUBDATE(:lastDate,INTERVAL 7 DAY)');
});

/**
 * pgsql support pass: SUBDATE() has no Postgres equivalent -- verified
 * live that `$date - make_interval(...)` needs an explicit `::timestamp`
 * cast on $date first (a bare untyped literal/bound parameter minus an
 * interval fails outright: "invalid input syntax for type interval"),
 * and that `::timestamp` (not `::date`) is required specifically to avoid
 * silently truncating a caller-supplied datetime's time-of-day component
 * -- see that method's own docblock for the full verification.
 */
test('getRecentPeriodExpression builds a make_interval(...) fragment on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe('(:lastDate)::timestamp - make_interval(days => 7)');
});
