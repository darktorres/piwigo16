<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function substringIndexDql(int $count): string
{
    return "SELECT SUBSTRING_INDEX(t.name, '.', {$count}) FROM " . TagEntity::class . ' t';
}

test('generates a real SUBSTRING_INDEX() call on MySQL/MariaDB for a negative count', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), substringIndexDql(-1)))
        ->toContain('SUBSTRING_INDEX(');
});

test('generates a real SUBSTRING_INDEX() call on MySQL/MariaDB for a non-negative count too', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), substringIndexDql(1)))
        ->toContain('SUBSTRING_INDEX(');
});

test('generates a real split_part() call on PostgreSQL for the only supported (negative) count shape', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), substringIndexDql(-1)))
        ->toContain('split_part(');
});

test('throws NotSupported on PostgreSQL for a non-negative count (version-dependent, unverified)', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), substringIndexDql(1));
})->throws(NotSupported::class, 'SubstringIndexFunction::getSql() with a non-negative count for Doctrine\DBAL\Platforms\PostgreSQLPlatform');

test('throws NotSupported on SQLite -- no equivalent primitive exists at all', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), substringIndexDql(-1));
})->throws(NotSupported::class, 'SubstringIndexFunction::getSql() for Doctrine\DBAL\Platforms\SQLitePlatform');
