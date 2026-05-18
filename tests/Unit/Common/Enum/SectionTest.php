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
        self::assertSame('categories', Section::Categories->value);
        self::assertSame('tags', Section::Tags->value);
        self::assertSame('search', Section::Search->value);
        self::assertSame('favorites', Section::Favorites->value);
        self::assertSame('recent_pics', Section::RecentPics->value);
        self::assertSame('recent_cats', Section::RecentCats->value);
        self::assertSame('most_visited', Section::MostVisited->value);
        self::assertSame('best_rated', Section::BestRated->value);
        self::assertSame('list', Section::ListView->value);
    }

    public function testFromRoundTrips(): void
    {
        self::assertSame(Section::Categories, Section::from('categories'));
        self::assertSame(Section::ListView, Section::from('list'));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        self::assertNull(Section::tryFrom('not_a_section_' . uniqid()));
    }

    public function testNineCases(): void
    {
        self::assertCount(9, Section::cases());
    }
}
