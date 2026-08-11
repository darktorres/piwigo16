<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function monthDql(): string
{
    return 'SELECT MONTH(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real MONTH() call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), monthDql()))
        ->toContain('MONTH(');
});

test('generates a real EXTRACT(MONTH FROM ...) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), monthDql()))
        ->toContain('EXTRACT(MONTH FROM');
});

test('generates a real strftime() call cast to INTEGER on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), monthDql()))
        ->toContain("CAST(strftime('%m',")
        ->toContain('AS INTEGER)');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), monthDql());
})->throws(NotSupported::class, 'MonthFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
