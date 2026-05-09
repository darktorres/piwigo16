<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\QNumericRangeScope;
use Piwigo\Search\QSingleToken;

final class QNumericRangeScopeTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private QNumericRangeScope $scope;

    #[\Override]
    protected function setUp(): void
    {
        $this->scope = new QNumericRangeScope('size', []);
    }

    private function makeToken(string $term, int $modifier = 0): QSingleToken
    {
        return new QSingleToken($term, $modifier, null);
    }

    public function testGreaterThan(): void
    {
        // '>' prefix means strict greater-than (exclusive lower bound).
        $token = $this->makeToken('>100');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('size >100', $sql);
        self::assertStringNotContainsString('>=', $sql);
    }

    public function testLessThan(): void
    {
        // '<' prefix means strict less-than (exclusive upper bound).
        $token = $this->makeToken('<500');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('size <500', $sql);
        self::assertStringNotContainsString('<=', $sql);
    }

    public function testRange(): void
    {
        $token = $this->makeToken('100..500');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('size >=100', $sql);
        self::assertStringContainsString('size <=500', $sql);
        self::assertStringContainsString('AND', $sql);
    }

    public function testExactValue(): void
    {
        $token = $this->makeToken('250');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('250', $sql);
    }

    public function testKiloMultiplier(): void
    {
        $token = $this->makeToken('>1K');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('1000', $sql);
    }

    public function testMegaMultiplier(): void
    {
        $token = $this->makeToken('<2M');
        self::assertTrue($this->scope->parse($token));
        $sql = $this->scope->getSql('size', $token);
        self::assertStringContainsString('2000000', $sql);
    }

    public function testInvalidValueReturnsFalse(): void
    {
        $token = $this->makeToken('abc');
        self::assertFalse($this->scope->parse($token));
    }

    public function testEmptyNullableReturnsTrue(): void
    {
        $nullableScope = new QNumericRangeScope('size', [], true);
        $token = $this->makeToken('');
        self::assertTrue($nullableScope->parse($token));
    }

    public function testEmptyNotNullableReturnsFalse(): void
    {
        $token = $this->makeToken('');
        self::assertFalse($this->scope->parse($token));
    }
}
