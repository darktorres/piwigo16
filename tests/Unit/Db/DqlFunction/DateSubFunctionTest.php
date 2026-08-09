<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\QueryException;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function dateSubDql(int $amount, string $unit): string
{
    return "SELECT DATE_SUB(t.lastmodified, {$amount}, '{$unit}') FROM " . TagEntity::class . ' t';
}

test('generates a real platform DATE_SUB()/INTERVAL expression on MySQL/MariaDB for the day unit', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dateSubDql(5, 'day')))
        ->toContain('DATE_SUB(')
        ->toContain('INTERVAL 5 DAY');
});

test('generates a real platform expression on MySQL/MariaDB for the second unit too', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dateSubDql(30, 'second')))
        ->toContain('INTERVAL 30 SECOND');
});

test('generates a real DATETIME()-based expression on SQLite for the day unit', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), dateSubDql(5, 'day')))
        ->toContain('DATETIME(')
        ->toContain("|| 5 || ' DAY')");
});

test('generates a real ::timestamp - make_interval(days => ...) expression on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateSubDql(5, 'day')))
        ->toContain('::timestamp')
        ->toContain('make_interval(days => 5)');
});

test('maps the second unit to make_interval()s own secs parameter name on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateSubDql(30, 'second')))
        ->toContain('make_interval(secs => 30)');
});

test('the unit literal is matched case-insensitively (lowercased before dispatch)', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateSubDql(5, 'DAY')))
        ->toContain('make_interval(days => 5)');
});

test('throws a semantical QueryException for an unsupported unit on PostgreSQL', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateSubDql(5, 'decade'));
})->throws(QueryException::class, 'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.');

test('throws a semantical QueryException for an unsupported unit on MySQL/MariaDB too (independent check, not shared with the PostgreSQL branch)', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dateSubDql(5, 'decade'));
})->throws(QueryException::class, 'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.');

test('throws a semantical QueryException when the unit argument is not a literal string', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), 'SELECT DATE_SUB(t.lastmodified, 5, t.name) FROM ' . TagEntity::class . ' t');
})->throws(QueryException::class, "DATE_SUB()'s unit argument must be a literal string.");
