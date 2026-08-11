<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function dayOfWeekDql(): string
{
    return 'SELECT DAYOFWEEK(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real DAYOFWEEK() call on MySQL/MariaDB (already 1=Sunday..7=Saturday)', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dayOfWeekDql()))
        ->toContain('DAYOFWEEK(');
});

test('generates a real EXTRACT(DOW ...) call on PostgreSQL, +1 to remap 0-indexed to MySQL 1-indexed', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dayOfWeekDql()))
        ->toContain('(EXTRACT(DOW FROM')
        ->toContain('+ 1)');
});

test('generates a real strftime() call, +1 to remap 0-indexed to MySQL 1-indexed, on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), dayOfWeekDql()))
        ->toContain("strftime('%w',")
        ->toContain('+ 1)');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), dayOfWeekDql());
})->throws(NotSupported::class, 'DayOfWeekFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
