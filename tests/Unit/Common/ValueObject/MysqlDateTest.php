<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Common\ValueObject\MysqlDate;

final class MysqlDateTest extends TestCase
{
    public function testFromAcceptsCanonicalForm(): void
    {
        $d = MysqlDate::from('2026-05-18');
        self::assertSame('2026-05-18', $d->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStrings(): iterable
    {
        yield 'with time'              => ['2026-05-18 12:34:56'];
        yield 'feb 30'                 => ['2026-02-30'];
        yield 'month 13'               => ['2026-13-01'];
        yield 'day 32'                 => ['2026-05-32'];
        yield 'wrong separator'        => ['2026/05/18'];
        yield 'two-digit year'         => ['26-05-18'];
        yield 'iso week'               => ['2026-W21-1'];
        yield 'trailing chars'         => ['2026-05-18 UTC'];
        yield 'empty'                  => [''];
        yield 'garbage'                => ['not-a-date'];
    }

    #[DataProvider('invalidStrings')]
    public function testFromRejects(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        MysqlDate::from($input);
    }

    public function testTryFromAcceptsCanonicalForm(): void
    {
        $d = MysqlDate::tryFrom('2026-05-18');
        self::assertNotNull($d);
        self::assertSame('2026-05-18', $d->value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function tryFromNullCases(): iterable
    {
        yield 'int'        => [123];
        yield 'null'       => [null];
        yield 'array'      => [[]];
        yield 'feb 30'     => ['2026-02-30'];
        yield 'garbage'    => ['garbage'];
    }

    #[DataProvider('tryFromNullCases')]
    public function testTryFromReturnsNull(mixed $input): void
    {
        self::assertNull(MysqlDate::tryFrom($input));
    }

    public function testFromDateTimeRoundTrips(): void
    {
        $dt  = new DateTimeImmutable('2026-05-18 12:34:56');
        $vo  = MysqlDate::fromDateTime($dt);
        self::assertSame('2026-05-18', $vo->value);
    }

    public function testToDateTimeImmutable(): void
    {
        $d = MysqlDate::from('2026-05-18');
        self::assertSame('2026-05-18 00:00:00', $d->toDateTimeImmutable()->format('Y-m-d H:i:s'));
    }

    public function testStringableProducesCanonicalString(): void
    {
        self::assertSame('2026-05-18', (string) MysqlDate::from('2026-05-18'));
    }

    public function testEqualsIsTrueForTheSameCalendarDate(): void
    {
        $a = MysqlDate::from('2026-05-18');
        $b = MysqlDate::from('2026-05-18');

        self::assertTrue($a->equals($b));
    }

    public function testEqualsIsFalseForADifferentCalendarDate(): void
    {
        $a = MysqlDate::from('2026-05-18');
        $b = MysqlDate::from('2026-05-19');

        self::assertFalse($a->equals($b));
    }
}
