<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Tag\TagEntity;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

function anyValueDql(): string
{
    return 'SELECT ANY_VALUE(t.name) FROM ' . TagEntity::class . ' t GROUP BY t.id';
}

function anyValueNestedIdentityDql(): string
{
    return 'SELECT ANY_VALUE(IDENTITY(ic.category)) FROM ' . ImageCategoryEntity::class . ' ic GROUP BY ic.image';
}

test('compiles to a real ANY_VALUE() call on MySQL/MariaDB', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), anyValueDql()))
        ->toContain('ANY_VALUE(t0_.name)');
});

test('compiles to a real ANY_VALUE() call on PostgreSQL', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new PostgreSQLPlatform(), anyValueDql()))
        ->toContain('ANY_VALUE(t0_.name)');
});

test('composes with a nested IDENTITY() call to extract a bare FK column', function (): void {
    expect(DqlPlatformQueryTestFactory::generatedSql(new MySQLPlatform(), anyValueNestedIdentityDql()))
        ->toContain('ANY_VALUE(i0_.category_id)');
});
