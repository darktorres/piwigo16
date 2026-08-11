<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\SqlTime;

final class SqlTimeTest extends TestCase
{
    public function testFromAcceptsCanonicalForm(): void
    {
        $t = SqlTime::from('12:34:56');
        self::assertSame('12:34:56', $t->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'with date' => ['2026-05-18 12:34:56'];
        yield 'hour 25' => ['25:00:00'];
        yield 'minute 60' => ['12:60:00'];
        yield 'second 60' => ['12:00:60'];
        yield 'wrong separator' => ['12.34.56'];
        yield 'no seconds' => ['12:34'];
        yield 'trailing chars' => ['12:34:56 UTC'];
        yield 'empty' => [''];
        yield 'garbage' => ['not-a-time'];
    }

    #[DataProvider('invalidStrings')]
    public function testFromRejects(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqlTime::from($input);
    }

    public function testTryFromAcceptsCanonicalForm(): void
    {
        $t = SqlTime::tryFrom('12:34:56');
        self::assertNotNull($t);
        self::assertSame('12:34:56', $t->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tryFromNullCases(): iterable
    {
        yield 'int' => [123];
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'hour 25' => ['25:00:00'];
        yield 'garbage' => ['garbage'];
    }

    #[DataProvider('tryFromNullCases')]
    public function testTryFromReturnsNull(mixed $input): void
    {
        self::assertNull(SqlTime::tryFrom($input));
    }

    public function testFromDateTimeRoundTrips(): void
    {
        $dt = new DateTimeImmutable('2026-05-18 12:34:56');
        $vo = SqlTime::fromDateTime($dt);
        self::assertSame('12:34:56', $vo->value);
    }

    public function testToDateTimeImmutable(): void
    {
        $t = SqlTime::from('12:34:56');
        self::assertSame('1970-01-01 12:34:56', $t->toDateTimeImmutable()->format('Y-m-d H:i:s'));
    }

    public function testStringableProducesCanonicalString(): void
    {
        self::assertSame('12:34:56', (string) SqlTime::from('12:34:56'));
    }

    public function testEqualsIsTrueForTheSameTime(): void
    {
        $a = SqlTime::from('12:34:56');
        $b = SqlTime::from('12:34:56');

        self::assertTrue($a->equals($b));
    }

    public function testEqualsIsFalseForADifferentTime(): void
    {
        $a = SqlTime::from('12:34:56');
        $b = SqlTime::from('12:34:57');

        self::assertFalse($a->equals($b));
    }
}
