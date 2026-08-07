<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\SqlDateTime;

final class SqlDateTimeTest extends TestCase
{
    public function testFromAcceptsCanonicalForm(): void
    {
        $dt = SqlDateTime::from('2026-05-18 12:34:56');
        self::assertSame('2026-05-18 12:34:56', $dt->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStrings(): iterable
    {
        yield 'date only'              => ['2026-05-18'];
        yield 'time only'              => ['12:34:56'];
        yield 'feb 30'                 => ['2026-02-30 00:00:00'];
        yield 'month 13'               => ['2026-13-01 00:00:00'];
        yield 'hour 25'                => ['2026-05-18 25:00:00'];
        yield 'minute 60'              => ['2026-05-18 12:60:00'];
        yield 'wrong separator (T)'    => ['2026-05-18T12:34:56'];
        yield 'iso8601 with timezone'  => ['2026-05-18 12:34:56+00:00'];
        yield 'trailing chars'         => ['2026-05-18 12:34:56 UTC'];
        yield 'empty'                  => [''];
        yield 'garbage'                => ['not-a-date'];
    }

    #[DataProvider('invalidStrings')]
    public function testFromRejects(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqlDateTime::from($input);
    }

    public function testTryFromAcceptsCanonicalForm(): void
    {
        $dt = SqlDateTime::tryFrom('2026-05-18 12:34:56');
        self::assertNotNull($dt);
        self::assertSame('2026-05-18 12:34:56', $dt->value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function tryFromNullCases(): iterable
    {
        yield 'int'        => [123];
        yield 'null'       => [null];
        yield 'array'      => [[]];
        yield 'feb 30'     => ['2026-02-30 00:00:00'];
        yield 'bad format' => ['garbage'];
    }

    #[DataProvider('tryFromNullCases')]
    public function testTryFromReturnsNull(mixed $input): void
    {
        self::assertNull(SqlDateTime::tryFrom($input));
    }

    public function testFromDateTimeRoundTrips(): void
    {
        $dt  = new DateTimeImmutable('2026-05-18 12:34:56');
        $vo  = SqlDateTime::fromDateTime($dt);
        $out = $vo->toDateTimeImmutable();
        self::assertSame($dt->format('Y-m-d H:i:s'), $out->format('Y-m-d H:i:s'));
    }

    public function testNowProducesValidValue(): void
    {
        // Round-trip the result through from() -- if it weren't canonical, this would throw.
        $now = SqlDateTime::now();
        self::assertSame($now->value, SqlDateTime::from($now->value)->value);
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        self::assertTrue(
            SqlDateTime::from('2026-05-18 00:00:00')
                ->equals(SqlDateTime::from('2026-05-18 00:00:00')),
        );
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        self::assertFalse(
            SqlDateTime::from('2026-05-18 00:00:00')
                ->equals(SqlDateTime::from('2026-05-18 00:00:01')),
        );
    }

    public function testStringableProducesCanonicalString(): void
    {
        self::assertSame('2026-05-18 12:34:56', (string) SqlDateTime::from('2026-05-18 12:34:56'));
    }
}
