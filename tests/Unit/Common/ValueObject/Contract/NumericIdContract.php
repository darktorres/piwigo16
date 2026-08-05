<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject\Contract;

use InvalidArgumentException;
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

    /**
     * A different NumericId implementation than voClass() -- used only by
     * testEqualsRejectsADifferentNumericIdTypeWithTheSameValue() below to
     * prove equals()'s own `instanceof self` check is real, not just its
     * value comparison.
     *
     * @return class-string<NumericId>
     */
    abstract protected static function otherVoClass(): string;

    public function testFromAcceptsPositiveInt(): void
    {
        $class = static::voClass();
        $id    = $class::from(42);
        self::assertSame('42', (string) $id);
    }

    public function testFromAcceptsTheExactBoundaryValueOfOne(): void
    {
        // 42 above can't tell `$value <= 0` apart from a mutated `<= 1` --
        // both correctly accept 42. Only the exact boundary does.
        $class = static::voClass();
        self::assertSame('1', (string) $class::from(1));
    }

    public function testFromRejectsZero(): void
    {
        $class = static::voClass();
        $this->expectException(InvalidArgumentException::class);
        $class::from(0);
    }

    public function testFromRejectsNegative(): void
    {
        $class = static::voClass();
        $this->expectException(InvalidArgumentException::class);
        $class::from(-7);
    }

    public function testTryFromAcceptsTheExactBoundaryValueOfOneAsIntAndString(): void
    {
        // Same boundary gap as testFromAcceptsTheExactBoundaryValueOfOne,
        // but for tryFrom()'s own separate int and numeric-string branches
        // (`$value > 0`/`$int > 0`), each its own mutable `0` literal.
        $class = static::voClass();
        self::assertSame('1', (string) $class::tryFrom(1));
        self::assertSame('1', (string) $class::tryFrom('1'));
    }

    public function testEqualsRejectsADifferentNumericIdTypeWithTheSameValue(): void
    {
        // The equals-and-stringable test below only ever compares two
        // instances of the *same* voClass(), which can't tell a real
        // `instanceof self` apart from a mutated bare `true` -- an equal
        // *value* from an entirely different NumericId type must still
        // compare unequal.
        $class      = static::voClass();
        $otherClass = static::otherVoClass();
        self::assertFalse($class::from(5)->equals($otherClass::from(5)));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function tryFromCases(): iterable
    {
        yield 'positive int'    => [42, '42'];
        yield 'numeric string'  => ['42', '42'];
        yield 'zero int'        => [0, null];
        // ctype_digit('0') is true, so '0' reaches tryFrom()'s own
        // separate numeric-string `$int > 0` boundary check -- 'zero int'
        // above only exercises the sibling int branch's own `0`, not this
        // one's (each is its own mutable literal).
        yield 'zero string'     => ['0', null];
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
