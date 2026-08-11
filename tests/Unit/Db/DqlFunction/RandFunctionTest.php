<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function randDql(): string
{
    return 'SELECT RAND() FROM ' . TagEntity::class . ' t';
}

test('generates a real RAND() call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), randDql()))
        ->toContain('RAND()');
});

test('generates a real RANDOM() call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), randDql()))
        ->toContain('RANDOM()');
});

test('generates a real RANDOM() call on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), randDql()))
        ->toContain('RANDOM()');
});

test('throws NotSupported on an unhandled platform', function (): void {
    DqlPlatformQueryTestFactory::generatedSql(new OraclePlatform(), randDql());
})->throws(NotSupported::class, 'RandFunction::getSql() for Doctrine\DBAL\Platforms\OraclePlatform');
