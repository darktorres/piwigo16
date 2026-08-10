<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function regexpDql(): string
{
    return "SELECT t.id FROM " . TagEntity::class . " t WHERE REGEXP(t.name, 'foo') = TRUE";
}

test('compiles to a real, correctly-ordered RLIKE infix expression (column RLIKE pattern) on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), regexpDql()))
        ->toContain("t0_.name RLIKE 'foo'");
});

test('compiles to a real, correctly-ordered REGEXP infix expression (column REGEXP pattern) on SQLite', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new SQLitePlatform(), regexpDql()))
        ->toContain("t0_.name REGEXP 'foo'");
});

test('compiles to a real, correctly-ordered POSIX ~ infix expression (column ~ pattern) on PostgreSQL, not SIMILAR TO (a genuinely different pattern dialect)', function (): void {
    $sql = DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), regexpDql());

    expect($sql)->toContain("t0_.name ~ 'foo'")
        ->and($sql)->not->toContain('SIMILAR TO');
});
