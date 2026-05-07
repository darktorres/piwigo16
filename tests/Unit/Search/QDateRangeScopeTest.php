<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\QDateRangeScope;
use Piwigo\Search\QSingleToken;

final class QDateRangeScopeTest extends TestCase
{
    private QDateRangeScope $scope;

    protected function setUp(): void
    {
        $this->scope = new QDateRangeScope('date', []);
    }

    private function makeToken(string $term, int $modifier = 0): QSingleToken
    {
        return new QSingleToken($term, $modifier, null);
    }

    public function testExactDate(): void
    {
        $token = $this->makeToken('2023-01-15');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('date_field', $token);
        self::assertStringContainsString('2023-01-15', $sql);
    }

    public function testYearOnly(): void
    {
        $token = $this->makeToken('2023');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('date_field', $token);
        self::assertStringContainsString('2023', $sql);
        self::assertStringContainsString('>=', $sql);
        self::assertStringContainsString('<=', $sql);
    }

    public function testYearMonthRange(): void
    {
        $token = $this->makeToken('2023-06');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('date_field', $token);
        self::assertStringContainsString('2023-06', $sql);
    }

    public function testGreaterThanDate(): void
    {
        $token = $this->makeToken('>2023-06');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('date_field', $token);
        self::assertStringContainsString('>=', $sql);
        self::assertStringContainsString('2023-06', $sql);
    }

    public function testYearRange(): void
    {
        $token = $this->makeToken('2022..2023');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('date_field', $token);
        self::assertStringContainsString('2022', $sql);
        self::assertStringContainsString('2023', $sql);
        self::assertStringContainsString('AND', $sql);
    }

    public function testInvalidDateReturnsFalse(): void
    {
        $token = $this->makeToken('not-a-date');
        self::assertFalse($this->scope->parse($token));
    }

    public function testEmptyNullableReturnsTrue(): void
    {
        $nullableScope = new QDateRangeScope('date', [], true);
        $token = $this->makeToken('');
        self::assertTrue($nullableScope->parse($token));
    }

    public function testEmptyNotNullableReturnsFalse(): void
    {
        $token = $this->makeToken('');
        self::assertFalse($this->scope->parse($token));
    }
}
