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
    public function test_missing_section_key_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots([], false);

        self::assertSame([], $result);
    }

    public function test_non_string_section_value_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 42], false);

        self::assertSame([], $result);
    }

    public function test_chronology_field_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Categories, 'chronology_field' => 'created'],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_flat_with_category_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Categories, 'flat' => true, 'category' => ['id' => 1]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_flat_without_category_does_not_force_it(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Categories, 'flat' => true],
            false
        );

        self::assertSame([], $result);
    }

    public function test_list_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::ListView], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_recent_pics_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::RecentPics], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_tags_section_with_a_single_tag_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Tags, 'tag_ids' => [5]],
            false
        );

        self::assertSame([], $result);
    }

    public function test_tags_section_with_multiple_tags_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Tags, 'tag_ids' => [5, 9]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_recent_cats_section_forces_noindex_only(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::RecentCats], false);

        self::assertSame(['noindex' => 1], $result);
    }

    public function test_search_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::Search], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_categories_section_with_combined_categories_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => Section::Categories, 'combined_categories' => [['id' => 2]]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_plain_categories_section_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::Categories], false);

        self::assertSame([], $result);
    }

    public function test_enabled_filter_forces_noindex_even_when_otherwise_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::Categories], true);

        self::assertSame(['noindex' => 1], $result);
    }

    public function test_enabled_filter_does_not_clear_an_already_set_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => Section::Search], true);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }
}
