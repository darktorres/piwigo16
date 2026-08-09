<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function groupConcatDql(): string
{
    return 'SELECT GROUP_CONCAT(t.name) FROM ' . TagEntity::class . ' t';
}

test('generates a real GROUP_CONCAT(... SEPARATOR ,) call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), groupConcatDql()))
        ->toContain("GROUP_CONCAT(")
        ->toContain("SEPARATOR ',')");
});

test('generates a real GROUP_CONCAT(..., ,) positional-separator call on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), groupConcatDql()))
        ->toContain('GROUP_CONCAT(')
        ->toContain(", ',')");
});

test('generates a real STRING_AGG(CAST(... AS TEXT), ,) call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), groupConcatDql()))
        ->toContain('STRING_AGG(CAST(')
        ->toContain("AS TEXT), ',')");
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), groupConcatDql());
})->throws(NotSupported::class, 'GroupConcatFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
