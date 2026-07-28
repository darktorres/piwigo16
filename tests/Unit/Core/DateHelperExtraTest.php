<?php

declare(strict_types=1);

use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;

/**
 * Piwigo\Core\DateHelper::formatDateLegacy()/formatFromto()/transformDate()
 * -- had zero dedicated coverage (see /home/torres/.claude/plans/piped-
 * enchanting-spark.md, Wave 1) despite the class being used constantly
 * throughout the codebase; formatDate()/timeSince()/str2DateTime() are
 * already well covered indirectly (real page renders), these 3 were not.
 *
 * formatFromto()/formatDate() go through IntlDateFormatter (php-intl is
 * loaded in this environment, confirmed via class_exists()), using
 * Lang::currentUserLanguage() ?? AppInfo::DEFAULT_LANGUAGE as the ICU
 * locale -- not this file's own Lang::loadArray() month/day injection,
 * which only formatDateLegacy() (called directly, bypassing formatDate()'s
 * Intl branch) actually reads. Every value below was independently
 * confirmed by invoking the real class before writing the assertion.
 */
beforeEach(function (): void {
    Lang::loadArray([
        'month' => [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
            7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'],
        'day' => [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'],
    ]);
});

afterEach(function (): void {
    Lang::reset();
});

test('formatDateLegacy renders only the requested components, in day/month/year order', function (): void {
    expect(DateHelper::formatDateLegacy('2024-06-15', ['day', 'month', 'year']))->toBe('15 June 2024');
});

test('formatDateLegacy includes the day name and a non-midnight time when requested', function (): void {
    expect(DateHelper::formatDateLegacy('2024-06-15', ['day_name', 'day', 'month', 'year']))
        ->toBe('Saturday 15 June 2024');
    expect(DateHelper::formatDateLegacy('2024-06-15 14:30:00', ['day', 'month', 'year', 'time']))
        ->toBe('15 June 2024 14:30');
});

test('formatDateLegacy omits a midnight (00:00) time even when time is requested', function (): void {
    expect(DateHelper::formatDateLegacy('2024-06-15 00:00:00', ['day', 'month', 'year', 'time']))
        ->toBe('15 June 2024');
});

test('formatDateLegacy defaults to day_name/day/month/year when show is null', function (): void {
    expect(DateHelper::formatDateLegacy('2024-06-15'))->toBe('Saturday 15 June 2024');
});

test('formatDateLegacy returns the untranslated "N/A" key for an unparseable date', function (): void {
    expect(DateHelper::formatDateLegacy(false))->toBe('N/A');
});

test('formatFromto returns a single formatted date when both dates fall on the same day', function (): void {
    expect(DateHelper::formatFromto('2024-06-15', '2024-06-15'))->toBe('Saturday, June 15, 2024');
});

test('formatFromto shows only day/day_name for a same-month range', function (): void {
    expect(DateHelper::formatFromto('2024-06-15', '2024-06-20'))
        ->toBe('from Saturday 15 to Thursday, June 20, 2024');
});

test('formatFromto shows day_name/day/month for a same-year, different-month range', function (): void {
    expect(DateHelper::formatFromto('2024-06-15', '2024-07-20'))
        ->toBe('from Saturday 15 June to Saturday, July 20, 2024');
});

test('formatFromto shows the full date on both ends for a cross-year range', function (): void {
    expect(DateHelper::formatFromto('2024-06-15', '2025-01-20'))
        ->toBe('from Saturday, June 15, 2024 to Monday, January 20, 2025');
});

test('formatFromto forces the full date on both ends when $full is true, even within the same month', function (): void {
    expect(DateHelper::formatFromto('2024-06-15', '2024-06-20', true))
        ->toBe('from Saturday, June 15, 2024 to Thursday, June 20, 2024');
});

test('transformDate converts between two date() formats', function (): void {
    expect(DateHelper::transformDate('2024-06-15 10:20:30', 'Y-m-d H:i:s', 'd/m/Y'))->toBe('15/06/2024');
});

test('transformDate returns the default for an empty or "0" original', function (): void {
    expect(DateHelper::transformDate('', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
    expect(DateHelper::transformDate('0', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
});

test('transformDate returns the default (null unless given) when the input format does not match', function (): void {
    expect(DateHelper::transformDate('not-in-the-expected-format', 'Y-m-d H:i:s', 'd/m/Y'))->toBeNull();
    expect(DateHelper::transformDate('not-in-the-expected-format', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
});
