<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject\Contract;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\StringVo;

/**
 * Shared contract for shape-validated string value objects.
 *
 * Concrete tests supply the VO class and a (valid, invalid) sample pair —
 * the contract exercises `from`/`tryFrom`/`equals`/`Stringable` against
 * both. Per-shape boundary coverage (e.g. the exhaustive Email rejection
 * surface) belongs in the concrete VO's standalone test.
 *
 * @template T of StringVo
 */
abstract class StringVoContract extends TestCase
{
    /** @return class-string<T> */
    abstract protected static function voClass(): string;

    /** A canonical value that must be accepted by `from()` and `tryFrom()`. */
    abstract protected static function validSample(): string;

    /** @return iterable<string, array{string}> Sample inputs the VO must reject. */
    abstract public static function invalidSamples(): iterable;

    /**
     * A different StringVo implementation whose own from() accepts this
     * VO's validSample() unchanged -- used only by
     * testEqualsReturnsFalseForADifferentStringVoTypeWithTheSameWrappedValue()
     * below, to prove equals()'s own `instanceof self` check is real, not
     * just its value comparison.
     *
     * @return class-string<StringVo>
     */
    abstract protected static function otherVoClass(): string;

    public function testFromAcceptsValidSample(): void
    {
        $class = static::voClass();
        $vo    = $class::from(static::validSample());
        self::assertSame(static::validSample(), (string) $vo);
    }

    #[DataProvider('invalidSamples')]
    public function testFromRejects(string $invalid): void
    {
        $class = static::voClass();
        $this->expectException(InvalidArgumentException::class);
        $class::from($invalid);
    }

    public function testTryFromAcceptsValidSample(): void
    {
        $class = static::voClass();
        $vo    = $class::tryFrom(static::validSample());
        self::assertNotNull($vo);
        self::assertSame(static::validSample(), (string) $vo);
    }

    #[DataProvider('invalidSamples')]
    public function testTryFromReturnsNullForInvalid(string $invalid): void
    {
        $class = static::voClass();
        self::assertNull($class::tryFrom($invalid));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringInputs(): iterable
    {
        yield 'int'   => [42];
        yield 'null'  => [null];
        yield 'array' => [[]];
        yield 'float' => [1.5];
        yield 'bool'  => [true];
    }

    #[DataProvider('nonStringInputs')]
    public function testTryFromReturnsNullForNonString(mixed $input): void
    {
        $class = static::voClass();
        self::assertNull($class::tryFrom($input));
    }

    public function testEqualsAndStringable(): void
    {
        $class = static::voClass();
        $a     = $class::from(static::validSample());
        $b     = $class::from(static::validSample());
        self::assertTrue($a->equals($b));
        self::assertSame(static::validSample(), (string) $a);
    }

    public function testEqualsReturnsFalseForADifferentStringVoTypeWithTheSameWrappedValue(): void
    {
        // testEqualsAndStringable() above only ever compares two instances
        // of the *same* voClass(), which can't tell a real `instanceof
        // self` apart from a mutated bare `true` -- an equal-looking
        // wrapped string from an entirely different VO must still compare
        // unequal.
        $class      = static::voClass();
        $otherClass = static::otherVoClass();
        $a          = $class::from(static::validSample());
        $other      = $otherClass::from(static::validSample());
        self::assertFalse($a->equals($other));
    }
}
