<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function weekDql(?int $mode = null): string
{
    $args = $mode === null ? 't.lastmodified' : "t.lastmodified, {$mode}";

    return "SELECT WEEK({$args}) FROM " . TagEntity::class . ' t';
}

test('generates a real WEEK() call on MySQL/MariaDB with no mode', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), weekDql()))
        ->toContain('WEEK(');
});

test('generates a real WEEK(date, mode) call on MySQL/MariaDB with an explicit mode', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), weekDql(5)))
        ->toContain('WEEK(')
        ->toContain(', 5)');
});

test('generates the hand-derived mode-5 expression on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), weekDql(5)))
        ->toContain('FLOOR(')
        ->toContain('ISODOW');
});

test('generates a real EXTRACT(WEEK FROM ...) call on PostgreSQL with no mode', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), weekDql()))
        ->toContain('EXTRACT(WEEK FROM');
});

test('throws NotSupported for any mode other than 5 on PostgreSQL', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), weekDql(3));
})->throws(NotSupported::class, 'WeekFunction::getSql() with a mode argument for Doctrine\DBAL\Platforms\PostgreSQLPlatform');

test('generates a real strftime(%W, ...) call on SQLite with no mode', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), weekDql()))
        ->toContain("strftime('%W',");
});

test('throws NotSupported for any mode argument at all on SQLite', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), weekDql(5));
})->throws(NotSupported::class, 'WeekFunction::getSql() with a mode argument for Doctrine\DBAL\Platforms\SQLitePlatform');

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), weekDql());
})->throws(NotSupported::class, 'WeekFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
