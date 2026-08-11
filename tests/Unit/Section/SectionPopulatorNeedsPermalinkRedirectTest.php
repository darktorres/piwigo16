<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Section;

use PHPUnit\Framework\TestCase;
use Piwigo\Section\SectionPopulator;

/**
 * SectionPopulator::needsPermalinkRedirect() -- the permalink-mismatch
 * redirect decision. Pure function, no DB/globals needed (it does not
 * itself redirect -- see the class docblock). $expectedCatUrlName is
 * str2url($category['name']) as computed by the real caller
 * (SectionPopulator::populate()) -- passed in directly here rather than
 * recomputed, since str2url() isn't available to the Unit suite.
 */
final class SectionPopulatorNeedsPermalinkRedirectTest extends TestCase
{
    public function testNoPermalinkIdNameStyleMatchingUrlNameDoesNotRedirect(): void
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

    public function testNoPermalinkIdNameStyleMismatchedUrlNameRedirects(): void
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

    public function testNoPermalinkNonIdNameStyleNeverRedirects(): void
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

    public function testFalsyStringPermalinkIsTreatedAsNoPermalink(): void
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

    public function testMatchingPermalinkDoesNotRedirect(): void
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

    public function testMismatchedPermalinkRedirects(): void
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

    public function testPermalinkSetButHitByNoneRedirects(): void
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
