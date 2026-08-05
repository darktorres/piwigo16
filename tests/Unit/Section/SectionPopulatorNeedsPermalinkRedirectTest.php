<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Section;

use PHPUnit\Framework\TestCase;
use Piwigo\Section\SectionPopulator;

/**
 * SectionPopulator::needsPermalinkRedirect() -- the permalink-mismatch
 * redirect decision extracted from include/section_init.inc.php during its
 * P23 batch 4d port. Pure function, no DB/globals needed (it does not
 * itself redirect -- see the class docblock). $expectedCatUrlName is
 * str2url($category['name']) as computed by the real caller
 * (SectionPopulator::populate()) -- passed in directly here rather than
 * recomputed, since str2url() isn't available to the Unit suite.
 */
final class SectionPopulatorNeedsPermalinkRedirectTest extends TestCase
{
    public function test_no_permalink_id_name_style_matching_url_name_does_not_redirect(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            null,
            'id-name',
            'my_category',
            null,
            'my_category'
        );

        self::assertFalse($result);
    }

    public function test_no_permalink_id_name_style_mismatched_url_name_redirects(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            null,
            'id-name',
            'some-other-slug',
            null,
            'my_category'
        );

        self::assertTrue($result);
    }

    public function test_no_permalink_non_id_name_style_never_redirects(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            null,
            'id',
            'whatever-mismatched-slug',
            null,
            'my_category'
        );

        self::assertFalse($result);
    }

    public function test_falsy_string_permalink_is_treated_as_no_permalink(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            '0',
            'id-name',
            'my_category',
            null,
            'my_category'
        );

        self::assertFalse($result);
    }

    public function test_matching_permalink_does_not_redirect(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            'my-permalink',
            'id-name',
            null,
            'my-permalink',
            'my_category'
        );

        self::assertFalse($result);
    }

    public function test_mismatched_permalink_redirects(): void
    {
        $result = SectionPopulator::needsPermalinkRedirect(
            'my-permalink',
            'id-name',
            null,
            'a-different-permalink',
            'my_category'
        );

        self::assertTrue($result);
    }

    public function test_permalink_set_but_hit_by_none_redirects(): void
    {
        // e.g. the category was hit by its numeric id/name URL even though
        // it now has a real permalink -- must redirect to the canonical
        // permalink URL.
        $result = SectionPopulator::needsPermalinkRedirect(
            'my-permalink',
            'id-name',
            'my_category',
            null,
            'my_category'
        );

        self::assertTrue($result);
    }
}
