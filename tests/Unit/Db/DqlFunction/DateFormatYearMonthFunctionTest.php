<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function dateFormatYearMonthDql(): string
{
    return 'SELECT DATE_FORMAT_YEAR_MONTH(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real DATE_FORMAT(..., %Y%m) call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dateFormatYearMonthDql()))
        ->toContain('DATE_FORMAT(')
        ->toContain("'%Y%m')");
});

test('generates a real TO_CHAR(..., YYYYMM) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateFormatYearMonthDql()))
        ->toContain('TO_CHAR(')
        ->toContain("'YYYYMM')");
});

test('generates a real strftime(%Y%m, ...) call on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), dateFormatYearMonthDql()))
        ->toContain("strftime('%Y%m',");
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), dateFormatYearMonthDql());
})->throws(NotSupported::class, 'DateFormatYearMonthFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
