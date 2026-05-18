<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\SortOrder;

final class SortOrderTest extends TestCase
{
    public function testCaseValuesAreUppercaseSqlForm(): void
    {
        // Uppercase to drop straight into `ORDER BY … ASC | DESC`.
        self::assertSame('ASC', SortOrder::Asc->value);
        self::assertSame('DESC', SortOrder::Desc->value);
    }

    public function testFromRoundTrips(): void
    {
        self::assertSame(SortOrder::Asc, SortOrder::from('ASC'));
        self::assertSame(SortOrder::Desc, SortOrder::from('DESC'));
    }

    public function testTwoCases(): void
    {
        self::assertCount(2, SortOrder::cases());
    }
}
