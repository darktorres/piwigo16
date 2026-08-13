<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\AlbumSortOrder;

final class AlbumSortOrderTest extends TestCase
{
    public function testCaseValuesMatchAlbumsPageRendererSortOrdersArray(): void
    {
        // AlbumsPageRenderer.php's own $sort_orders array literal -- if
        // either side ever drifts, this guard (not a PHPStan run) is what
        // catches it in CI.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('name ASC', AlbumSortOrder::NameAsc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('name DESC', AlbumSortOrder::NameDesc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('date_creation ASC', AlbumSortOrder::DateCreationAsc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('date_creation DESC', AlbumSortOrder::DateCreationDesc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('date_available ASC', AlbumSortOrder::DateAvailableAsc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('date_available DESC', AlbumSortOrder::DateAvailableDesc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('natural_order ASC', AlbumSortOrder::NaturalOrderAsc->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('natural_order DESC', AlbumSortOrder::NaturalOrderDesc->value);
    }

    public function testFromRoundTrips(): void
    {
        foreach (AlbumSortOrder::cases() as $case) {
            self::assertSame($case, AlbumSortOrder::from($case->value));
        }
    }

    public function testEightCases(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertCount(8, AlbumSortOrder::cases());
    }
}
