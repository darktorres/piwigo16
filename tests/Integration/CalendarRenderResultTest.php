<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Calendar\CalendarRenderResult;

/**
 * CalendarRenderResult is a plain constructor-promoted value object (see
 * its own docblock) -- no methods beyond the constructor, so there is
 * nothing DB-backed to exercise here. Kept under tests/Integration/
 * (rather than tests/Unit/) to match every other Calendar class's test
 * location, per this batch's own convention.
 */
final class CalendarRenderResultTest extends IntegrationTestCase
{
    /**
     * Every constructor argument uses a distinct, recognisable value (not
     * the same placeholder repeated) so a transposition bug -- e.g.
     * $comment and $chronologyStyle swapped, or $items and
     * $chronologyDate swapped (both are list<int|string>, the easiest
     * pair to accidentally transpose) -- would fail this assertion.
     */
    public function test_constructor_maps_each_argument_to_its_own_property_without_transposition(): void
    {
        $result = new CalendarRenderResult(
            items: [42, 17, 99],
            comment: 'from search results',
            chronologyDate: [2026, 7, 25],
            chronologyStyle: 'weekly',
            chronologyView: 'calendar',
        );

        self::assertSame([42, 17, 99], $result->items);
        self::assertSame('from search results', $result->comment);
        self::assertSame([2026, 7, 25], $result->chronologyDate);
        self::assertSame('weekly', $result->chronologyStyle);
        self::assertSame('calendar', $result->chronologyView);
    }

    /**
     * chronologyStyle/chronologyView are ?string (CalendarRenderer's own
     * "nothing to do" early return passes the caller's raw, possibly-null
     * style/view straight through -- see that class's own docblock) --
     * both real call sites of the constructor pass empty items/chronologyDate too.
     */
    public function test_nullable_style_and_view_parameters_accept_null_alongside_empty_lists(): void
    {
        $result = new CalendarRenderResult(
            items: [],
            comment: '',
            chronologyDate: [],
            chronologyStyle: null,
            chronologyView: null,
        );

        self::assertSame([], $result->items);
        self::assertSame('', $result->comment);
        self::assertSame([], $result->chronologyDate);
        self::assertNull($result->chronologyStyle);
        self::assertNull($result->chronologyView);
    }

    /**
     * chronologyDate is documented as list<int|string> -- a real
     * chronology_date array mixes int year/month/day components with the
     * literal string 'any' (CalendarRenderer's own sanitization only
     * casts non-'any', non-empty tokens to int).
     */
    public function test_chronology_date_accepts_a_mix_of_int_and_any_string_tokens(): void
    {
        $result = new CalendarRenderResult(
            items: [1],
            comment: '',
            chronologyDate: [2026, 'any'],
            chronologyStyle: 'monthly',
            chronologyView: 'list',
        );

        self::assertSame([2026, 'any'], $result->chronologyDate);
    }
}
