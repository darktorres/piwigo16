<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\SortOrder;

final class SortOrderTest extends TestCase
{
    public function testCaseValuesAreUppercaseSqlForm(): void
    {
        // Uppercase to drop straight into `ORDER BY … ASC | DESC`. PHPStan
        // proves these from the enum's own current definition, hence
        // "redundant" -- but that's exactly the point: if the SQL-critical
        // casing/spelling ever drifted, this guard (not a PHPStan run) is
        // what catches it in CI.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('ASC', SortOrder::Asc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('DESC', SortOrder::Desc->value);
    }

    public function testFromRoundTrips(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(SortOrder::Asc, SortOrder::from('ASC'));
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(SortOrder::Desc, SortOrder::from('DESC'));
    }

    public function testTwoCases(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertCount(2, SortOrder::cases());
    }
}
