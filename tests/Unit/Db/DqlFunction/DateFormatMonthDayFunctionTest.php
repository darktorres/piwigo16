<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function dateFormatMonthDayDql(): string
{
    return 'SELECT DATE_FORMAT_MONTH_DAY(t.lastmodified) FROM ' . TagEntity::class . ' t';
}

test('generates a real DATE_FORMAT(..., %m%d) call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), dateFormatMonthDayDql()))
        ->toContain("DATE_FORMAT(")
        ->toContain("'%m%d')");
});

test('generates a real TO_CHAR(..., MMDD) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), dateFormatMonthDayDql()))
        ->toContain('TO_CHAR(')
        ->toContain("'MMDD')");
});

test('generates a real strftime(%m%d, ...) call on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), dateFormatMonthDayDql()))
        ->toContain("strftime('%m%d',");
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), dateFormatMonthDayDql());
})->throws(NotSupported::class, 'DateFormatMonthDayFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
