<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function countOverDql(): string
{
    return 'SELECT COUNT_OVER() AS total, t.name FROM ' . TagEntity::class . ' t';
}

test('compiles to a real COUNT(*) OVER() window function on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), countOverDql()))
        ->toContain('COUNT(*) OVER()');
});

test('compiles to a real COUNT(*) OVER() window function on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), countOverDql()))
        ->toContain('COUNT(*) OVER()');
});

test('compiles to a real COUNT(*) OVER() window function on SQLite', function (): void {
    // SQLite has supported window functions since 3.25.0 (2018), verified
    // live -- COUNT(*) OVER() needs no per-platform branch at all.
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), countOverDql()))
        ->toContain('COUNT(*) OVER()');
});

test('does not override the real built-in COUNT aggregate', function (): void {
    $sql = DqlPlatformQueryTestFactory::generatedSql(
        new MySQLPlatform(),
        'SELECT COUNT(t.id) FROM ' . TagEntity::class . ' t'
    );

    expect($sql)
        ->toContain('COUNT(')
        ->and($sql)
        ->not->toContain('OVER()');
});
