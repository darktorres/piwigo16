<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Section;

use PHPUnit\Framework\TestCase;
use Piwigo\Common\Enum\Section;
use Piwigo\Section\SectionPopulator;

/**
 * SectionPopulator::computeMetaRobots() -- the noindex/nofollow decision
 * tree. Pure function, no DB/globals needed.
 *
 * `$page['section']` is a real Section enum case, not a raw string --
 * computeMetaRobots() narrows via `$page['section'] instanceof Section ?
 * ... : null`, so a missing or non-Section 'section' value is equivalent
 * to no section at all.
 */
final class SectionPopulatorComputeMetaRobotsTest extends TestCase
{
    public function testMissingSectionKeyIsUnrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots([], false);

        self::assertSame([], $result);
    }

    public function testNonStringSectionValueIsUnrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => 42,
        ], false);

        self::assertSame([], $result);
    }

    public function testChronologyFieldForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Categories,
                'chronology_field' => 'created',
            ],
            false
        );

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testFlatWithCategoryForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Categories,
                'flat' => true,
                'category' => [
                    'id' => 1,
                ],
            ],
            false
        );

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testFlatWithoutCategoryDoesNotForceIt(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Categories,
                'flat' => true,
            ],
            false
        );

        self::assertSame([], $result);
    }

    public function testListSectionForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::ListView,
        ], false);

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testRecentPicsSectionForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::RecentPics,
        ], false);

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testTagsSectionWithASingleTagIsUnrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Tags,
                'tag_ids' => [5],
            ],
            false
        );

        self::assertSame([], $result);
    }

    public function testTagsSectionWithMultipleTagsForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Tags,
                'tag_ids' => [5, 9],
            ],
            false
        );

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testRecentCatsSectionForcesNoindexOnly(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::RecentCats,
        ], false);

        self::assertSame([
            'noindex' => 1,
        ], $result);
    }

    public function testSearchSectionForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::Search,
        ], false);

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testCategoriesSectionWithCombinedCategoriesForcesNoindexAndNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            [
                'section' => Section::Categories,
                'combined_categories' => [[
                    'id' => 2,
                ]],
            ],
            false
        );

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }

    public function testPlainCategoriesSectionIsUnrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::Categories,
        ], false);

        self::assertSame([], $result);
    }

    public function testEnabledFilterForcesNoindexEvenWhenOtherwiseUnrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::Categories,
        ], true);

        self::assertSame([
            'noindex' => 1,
        ], $result);
    }

    public function testEnabledFilterDoesNotClearAnAlreadySetNofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots([
            'section' => Section::Search,
        ], true);

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], $result);
    }
}
