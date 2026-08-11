<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function dayOfMonthDql(): string
{
    return 'SELECT DAYOFMONTH(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real DAYOFMONTH() call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dayOfMonthDql()))
        ->toContain('DAYOFMONTH(');
});

test('generates a real EXTRACT(DAY FROM ...) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dayOfMonthDql()))
        ->toContain('EXTRACT(DAY FROM');
});

test('generates a real strftime() call cast to INTEGER on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), dayOfMonthDql()))
        ->toContain("CAST(strftime('%d',")
        ->toContain('AS INTEGER)');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), dayOfMonthDql());
})->throws(NotSupported::class, 'DayOfMonthFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
