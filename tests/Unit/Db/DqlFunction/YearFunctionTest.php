<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function yearDql(): string
{
    return 'SELECT YEAR(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real YEAR() call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), yearDql()))
        ->toContain('YEAR(');
});

test('generates a real EXTRACT(YEAR FROM ...) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), yearDql()))
        ->toContain('EXTRACT(YEAR FROM');
});

test('generates a real strftime() call cast to INTEGER on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), yearDql()))
        ->toContain("CAST(strftime('%Y',")
        ->toContain('AS INTEGER)');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), yearDql());
})->throws(NotSupported::class, 'YearFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
