<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\Enum;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\Section;

final class SectionTest extends TestCase
{
    public function testCaseValuesMatchSectionInitializerDispatch(): void
    {
        // Backing values exactly match the strings SectionInitializer
        // dispatches on; renaming a case must NOT change the wire format.
        // PHPStan proves each of these from the enum's own current
        // definition, so it reports them as redundant -- but that's
        // exactly the point: if the definition itself ever drifts, this
        // guard (not a PHPStan run) is what catches it in CI.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('categories', Section::Categories->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('tags', Section::Tags->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('search', Section::Search->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('favorites', Section::Favorites->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('recent_pics', Section::RecentPics->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('recent_cats', Section::RecentCats->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('most_visited', Section::MostVisited->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('best_rated', Section::BestRated->value);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('list', Section::ListView->value);
    }

    public function testFromRoundTrips(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(Section::Categories, Section::from('categories'));
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame(Section::ListView, Section::from('list'));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        self::assertNull(Section::tryFrom('not_a_section_' . uniqid()));
    }

    public function testNineCases(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertCount(9, Section::cases());
    }
}
