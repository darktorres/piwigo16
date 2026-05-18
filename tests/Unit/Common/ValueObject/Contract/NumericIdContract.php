<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\NumericId;

/**
 * Shared contract for positive-int row-identifier value objects.
 *
 * Each concrete row-ID test subclasses this and supplies the VO's
 * class-string. The contract is exercised once per concrete subclass so a
 * copy-paste regression in any single VO is caught at the same coverage as
 * the canonical ImageIdTest.
 *
 * Assertions go through the Stringable surface only (`(string) $id`) so the
 * contract stays usable against any future NumericId implementation. The
 * standalone ImageIdTest documents the full boundary surface (decimal,
 * scientific, hex, …) — this contract trusts those reject cases and focuses
 * on the canonical positive shape that every NumericId must share.
 *
 * @template T of NumericId
 */
abstract class NumericIdContract extends TestCase
{
    /** @return class-string<T> */
    abstract protected static function voClass(): string;

    public function testFromAcceptsPositiveInt(): void
    {
        $class = static::voClass();
        $id    = $class::from(42);
        self::assertSame('42', (string) $id);
    }

    public function testFromRejectsZero(): void
    {
        $class = static::voClass();
        $this->expectException(\InvalidArgumentException::class);
        $class::from(0);
    }

    public function testFromRejectsNegative(): void
    {
        $class = static::voClass();
        $this->expectException(\InvalidArgumentException::class);
        $class::from(-7);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function tryFromCases(): iterable
    {
        yield 'positive int'    => [42, '42'];
        yield 'numeric string'  => ['42', '42'];
        yield 'zero int'        => [0, null];
        yield 'negative int'    => [-1, null];
        yield 'decimal string'  => ['1.5', null];
        yield 'alpha'           => ['abc', null];
        yield 'null'            => [null, null];
        yield 'array'           => [[], null];
        yield 'float'           => [1.5, null];
    }

    #[DataProvider('tryFromCases')]
    public function testTryFrom(mixed $input, ?string $expected): void
    {
        $class  = static::voClass();
        $result = $class::tryFrom($input);
        if ($expected === null) {
            self::assertNull($result);
        } else {
            self::assertNotNull($result);
            self::assertSame($expected, (string) $result);
        }
    }

    public function testEqualsAndStringable(): void
    {
        $class = static::voClass();
        $a     = $class::from(11);
        $b     = $class::from(11);
        $c     = $class::from(12);
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
        self::assertSame('11', (string) $a);
    }
}
