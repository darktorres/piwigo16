<?php

declare(strict_types=1);

use Piwigo\Db\SqlDialect;

/**
 * Piwigo\Db\SqlDialect -- pure SQL-fragment string builders, no connection
 * dependency.
 *
 * getHour()/dateToTs()/getRecentPeriodExpression() branch on
 * PIWIGO_DB_DRIVER (via DbCredentials::fromEnv() -- this class has no
 * Connection of its own), so beforeEach/afterEach save and restore just
 * that one env var, since it's the only one this class reads.
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
    putenv('PIWIGO_DB_DRIVER=mysqli');

    expect(SqlDialect::getHour('images.date_available'))->toBe('HOUR(images.date_available)');
});

test('getHour wraps a date expression in EXTRACT(HOUR FROM ...) on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::getHour('images.date_available'))->toBe('EXTRACT(HOUR FROM images.date_available)');
});

test('getHour wraps a date expression in a strftime(%H, ...) cast to INTEGER on sqlite3', function (): void {
    putenv('PIWIGO_DB_DRIVER=sqlite3');

    expect(SqlDialect::getHour('images.date_available'))->toBe("CAST(strftime('%H', images.date_available) AS INTEGER)");
});

test('dateToTs wraps a date expression in UNIX_TIMESTAMP()', function (): void {
    putenv('PIWIGO_DB_DRIVER=mysqli');

    expect(SqlDialect::dateToTs('d'))->toBe('UNIX_TIMESTAMP(d)');
});

test('dateToTs wraps a date expression in EXTRACT(EPOCH FROM ...) on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::dateToTs('d'))->toBe('EXTRACT(EPOCH FROM d)');
});

test('dateToTs wraps a date expression in a strftime(%s, ...) cast to INTEGER on sqlite3', function (): void {
    putenv('PIWIGO_DB_DRIVER=sqlite3');

    expect(SqlDialect::dateToTs('d'))->toBe("CAST(strftime('%s', d) AS INTEGER)");
});

/**
 * A non-default $date is a bound-parameter placeholder the caller
 * already declares (see getRecentPeriodExpression()'s own docblock),
 * spliced into the returned expression unquoted.
 */
test('getRecentPeriodExpression builds a SUBDATE(...) fragment for a caller-supplied bound-parameter placeholder', function (): void {
    putenv('PIWIGO_DB_DRIVER=mysqli');

    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe('SUBDATE(:lastDate,INTERVAL 7 DAY)');
});

/**
 * SUBDATE() has no Postgres equivalent: `$date - make_interval(...)`
 * requires an explicit `::timestamp` cast on $date, since Postgres
 * cannot subtract an interval from an untyped literal or bound
 * parameter ("invalid input syntax for type interval"). `::timestamp`,
 * not `::date`, is required to avoid truncating a caller-supplied
 * datetime's time-of-day component -- see that method's own docblock
 * for further detail.
 */
test('getRecentPeriodExpression builds a make_interval(...) fragment on pgsql', function (): void {
    putenv('PIWIGO_DB_DRIVER=pgsql');

    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe('(:lastDate)::timestamp - make_interval(days => 7)');
});

/**
 * SQLite has no SUBDATE()/make_interval() -- datetime(...)'s own
 * modifier syntax is the real equivalent, verified live. No cast is
 * needed on $date the way Postgres's own branch requires: SQLite has no
 * real DATE/TIMESTAMP column type to disambiguate against in the first
 * place (this whole SQLite campaign's own established finding).
 */
test('getRecentPeriodExpression builds a datetime(..., \'-N days\') fragment on sqlite3', function (): void {
    putenv('PIWIGO_DB_DRIVER=sqlite3');

    expect(SqlDialect::getRecentPeriodExpression(7, ':lastDate'))->toBe("datetime(:lastDate, '-7 days')");
});

test('randomFunctionFor(true) returns the bare RANDOM() keyword, matching both Postgres and SQLite', function (): void {
    expect(SqlDialect::randomFunctionFor(true))->toBe('RANDOM()');
});
