<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function weekdayDql(): string
{
    return 'SELECT WEEKDAY(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real WEEKDAY() call on MySQL/MariaDB (already 0=Monday..6=Sunday)', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), weekdayDql()))
        ->toContain('WEEKDAY(');
});

test('generates a real EXTRACT(ISODOW ...) call on PostgreSQL, -1 to remap 1-indexed to MySQL 0-indexed', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), weekdayDql()))
        ->toContain('(EXTRACT(ISODOW FROM')
        ->toContain('- 1)');
});

test('generates a real strftime()-based remap to MySQL 0-indexed Monday-first on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), weekdayDql()))
        ->toContain("strftime('%w',")
        ->toContain('+ 6) % 7)');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), weekdayDql());
})->throws(NotSupported::class, 'WeekdayFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
