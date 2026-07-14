<?php

declare(strict_types=1);

use Piwigo\Section\SectionPopulator;

/**
 * SectionPopulator::computeMetaRobots() -- the noindex/nofollow decision
 * tree extracted from include/section_init.inc.php during its P23 batch 4d
 * port. Pure function, no DB/globals needed.
 */
final class SectionPopulatorComputeMetaRobotsTest extends \PHPUnit\Framework\TestCase
{
    public function test_chronology_field_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'categories', 'chronology_field' => 'created'],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_flat_with_category_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'categories', 'flat' => true, 'category' => ['id' => 1]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_flat_without_category_does_not_force_it(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'categories', 'flat' => true],
            false
        );

        self::assertSame([], $result);
    }

    public function test_list_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'list'], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_recent_pics_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'recent_pics'], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_tags_section_with_a_single_tag_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'tags', 'tag_ids' => [5]],
            false
        );

        self::assertSame([], $result);
    }

    public function test_tags_section_with_multiple_tags_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'tags', 'tag_ids' => [5, 9]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_recent_cats_section_forces_noindex_only(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'recent_cats'], false);

        self::assertSame(['noindex' => 1], $result);
    }

    public function test_search_section_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'search'], false);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_categories_section_with_combined_categories_forces_noindex_and_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(
            ['section' => 'categories', 'combined_categories' => [['id' => 2]]],
            false
        );

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }

    public function test_plain_categories_section_is_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'categories'], false);

        self::assertSame([], $result);
    }

    public function test_enabled_filter_forces_noindex_even_when_otherwise_unrestricted(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'categories'], true);

        self::assertSame(['noindex' => 1], $result);
    }

    public function test_enabled_filter_does_not_clear_an_already_set_nofollow(): void
    {
        $result = SectionPopulator::computeMetaRobots(['section' => 'search'], true);

        self::assertSame(['noindex' => 1, 'nofollow' => 1], $result);
    }
}
