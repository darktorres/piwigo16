<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;

final class QSearchScopeTest extends TestCase
{
    public function testConstructorStoresProperties(): void
    {
        $scope = new QSearchScope('date', ['created', 'taken'], true, false);
        self::assertSame('date', $scope->id);
        self::assertSame(['created', 'taken'], $scope->aliases);
        self::assertTrue($scope->nullable);
        self::assertFalse($scope->is_text);
    }

    public function testParseReturnsTrueForNonEmptyTerm(): void
    {
        $scope = new QSearchScope('tag', []);
        $token = new QSingleToken('nature', 0, $scope);
        self::assertTrue($scope->parse($token));
    }

    public function testParseReturnsFalseForEmptyTermWhenNotNullable(): void
    {
        $scope = new QSearchScope('tag', [], false);
        $token = new QSingleToken('', 0, $scope);
        self::assertFalse($scope->parse($token));
    }

    public function testParseReturnsTrueForEmptyTermWhenNullable(): void
    {
        $scope = new QSearchScope('tag', [], true);
        $token = new QSingleToken('', 0, $scope);
        self::assertTrue($scope->parse($token));
    }

    public function testProcessCharReturnsFalse(): void
    {
        $scope = new QSearchScope('tag', []);
        $ch = 'x';
        $crt = '';
        self::assertFalse($scope->processChar($ch, $crt));
    }

    public function testGetSqlReturnsEmptyString(): void
    {
        $scope = new QSearchScope('tag', []);
        $token = new QSingleToken('nature', 0, $scope);
        self::assertSame('', $scope->getSql('field', $token));
    }
}
