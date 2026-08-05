<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;

final class ImageIdTest extends TestCase
{
    public function testFromAcceptsPositiveInt(): void
    {
        $id = ImageId::from(42);
        self::assertSame(42, $id->value);
    }

    public function testFromAcceptsTheExactBoundaryValueOfOne(): void
    {
        // testFromAcceptsPositiveInt() above (42) can't tell `$value <= 0`
        // apart from a mutated `<= 1` -- both correctly accept 42.
        $id = ImageId::from(1);
        self::assertSame(1, $id->value);
    }

    public function testFromRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageId::from(0);
    }

    public function testFromRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageId::from(-1);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function tryFromCases(): iterable
    {
        yield 'positive int'    => [42, 42];
        yield 'numeric string'  => ['42', 42];
        yield 'zero int'        => [0, null];
        yield 'negative int'    => [-1, null];
        yield 'zero string'     => ['0', null];
        yield 'negative string' => ['-1', null];
        yield 'decimal string'  => ['1.5', null];
        yield 'scientific'      => ['1e5', null];
        yield 'hex string'      => ['0x1A', null];
        yield 'alpha'           => ['abc', null];
        yield 'empty string'    => ['', null];
        yield 'null'            => [null, null];
        yield 'array'           => [[], null];
        yield 'float'           => [1.5, null];
        yield 'bool true'       => [true, null];
    }

    #[DataProvider('tryFromCases')]
    public function testTryFrom(mixed $input, ?int $expected): void
    {
        $result = ImageId::tryFrom($input);
        if ($expected === null) {
            self::assertNull($result);
        } else {
            self::assertNotNull($result);
            self::assertSame($expected, $result->value);
        }
    }

    public function testTryFromAcceptsTheExactBoundaryValueOfOneAsIntAndString(): void
    {
        // Same boundary gap as testFromAcceptsTheExactBoundaryValueOfOne,
        // but for tryFrom()'s own separate int and numeric-string branches
        // (`$value > 0`/`$int > 0`), each its own mutable `0` literal --
        // 'positive int'/'numeric string' in tryFromCases() above (42)
        // can't tell them apart from a mutated `> 1`.
        self::assertSame(1, ImageId::tryFrom(1)?->value);
        self::assertSame(1, ImageId::tryFrom('1')?->value);
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        self::assertTrue(ImageId::from(7)->equals(ImageId::from(7)));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        self::assertFalse(ImageId::from(7)->equals(ImageId::from(8)));
    }

    public function testStringableProducesNumericForm(): void
    {
        self::assertSame('123', (string) ImageId::from(123));
    }

    public function testEqualsRejectsOtherNumericIdTypes(): void
    {
        // Same numeric value, different ID type — equals must return false.
        // Locks down the `$other instanceof self` guard that makes the typed
        // ID family meaningful at runtime as well as at the type-check layer.
        self::assertFalse(ImageId::from(5)->equals(UserId::from(5)));
    }
}
