<?php

declare(strict_types=1);

use Piwigo\Core\DateHelper;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;

/**
 * formatFromto()/formatDate() go through IntlDateFormatter, using
 * Lang::currentUserLanguage() ?? AppInfo::DEFAULT_LANGUAGE as the ICU
 * locale -- not this file's own Lang::loadArray() month/day injection,
 * which only formatDateLegacy() (called directly, bypassing formatDate()'s
 * Intl branch) reads.
 *
 * DateHelper's own private lang() resolver's t()/day()/month()/
 * currentUserLanguage() calls are a live container resolve with no
 * pre-boot fallback -- see Lang's own docblock -- so this file boots/
 * resets a real Kernel around each test. A real Paths must be supplied to
 * boot() too -- Lang's own constructor needs one, and PHP-DI can't
 * autowire Paths on its own (every property is a required string with no
 * default). No filesystem access is ever exercised through it here, so a
 * bare sys_get_temp_dir() root, never actually written to, is enough.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    LangTestFactory::get()->loadArray([
        'month' => [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ],
        'day' => [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ],
    ]);
});

afterEach(function (): void {
    LangTestFactory::get()->reset();
    Kernel::reset();
});

test('formatDateLegacy renders only the requested components, in day/month/year order', function (): void {
    expect(DateHelper::formatDateLegacy('2024-06-15', ['day', 'month', 'year']))->toBe('15 June 2024');
});

/**
 * Also confirmed-equivalent (both verified live via temporary
 * sed-applied mutations, full suite unaffected): a ConcatEqualToEqual on
 * the day_name append (`$print .= ... ` -> `$print = ...`) -- day_name
 * is unconditionally the FIRST of the 5 possible $show contributors in
 * this method's own fixed check order, so $print is always genuinely ''
 * at that exact line regardless of which components were requested,
 * making `.=` and `=` identical there; and a ConcatRemoveRight on the
 * 'time' append's own trailing `. ' '` -- 'time' is unconditionally the
 * LAST possible contributor, and the method's own final `trim($print)`
 * strips any trailing space either way.
 */
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

/**
 * A mutation-testing sweep found 5 confirmed-equivalent RemoveBooleanCast
 * mutants across this class (formatDateLegacy/formatDate/timeSince's own
 * `!(bool) $date` and isValidMysqlDatetime's own two `(bool) $date and
 * ...` checks): $date's own static type at each of these sites is always
 * `\DateTime|false` -- a real DateTime instance is never falsy (PHP
 * objects are always truthy regardless of content) and `false` is always
 * falsy, so an explicit `(bool)` cast before a boolean context (`!`/
 * `and`) changes nothing a plain, uncast value wouldn't already do.
 * Verified live via a temporary sed-applied mutation on the first of the
 * 5 (formatDateLegacy's own check) -- the full existing suite still
 * passed unchanged, and the same reasoning applies identically to the
 * other 4 (same type, same boolean-context shape).
 */
test('formatDateLegacy returns the untranslated "N/A" key for an unparseable date', function (): void {
    expect(DateHelper::formatDateLegacy(false))->toBe('N/A');
});

test('lang() throws when the container returns an unexpected type for Lang', function (): void {
    // Real gap: kills the private lang() resolver's own InstanceOfToTrue
    // guard. formatDateLegacy() calls self::lang()->t('N/A') on the very
    // first line of its own unparseable-date branch, so a bare `false`
    // original is enough to reach it without needing a real Lang call
    // anywhere else in the method.
    //
    // KernelContainerOverride's own cleanup resets Kernel entirely, but
    // this file's afterEach() calls LangTestFactory::get()->reset(),
    // which needs Kernel still booted -- re-boot with a plain, real
    // container in this test's own finally so afterEach() doesn't itself
    // fail on a now-unbooted Kernel.
    try {
        KernelContainerOverride::withWrongTypeFor(
            Lang::class,
            static fn (): string => DateHelper::formatDateLegacy(false),
        );
    } finally {
        Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    }
})->throws(LogicException::class, 'Container returned an unexpected type for ' . Lang::class);

test('translator() throws when the container returns an unexpected type for Translator', function (): void {
    // Real gap: kills the private translator() resolver's own
    // InstanceOfToTrue guard. timeSince() only reaches self::translator()
    // for a real, non-zero chunk -- a 90-second-old clock guarantees the
    // 'second' chunk is non-zero without touching any coarser unit.
    // Same afterEach()-needs-a-booted-Kernel reasoning as the lang() test
    // above.
    try {
        KernelContainerOverride::withWrongTypeFor(
            Translator::class,
            static fn (): string => DateHelper::timeSince(new DateTime()->modify('-90 seconds')->getTimestamp()),
        );
    } finally {
        Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    }
})->throws(LogicException::class, 'Container returned an unexpected type for ' . Translator::class);

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

/**
 * A mutation-testing sweep found this method's own early-exit guard
 * (`$original === '' || $original === '0'`, and its own `return
 * $default;`) is entirely confirmed-equivalent: verified live (temporary
 * sed-applied mutations to the condition's own operator, one operand,
 * and the return statement itself, one at a time) that str2DateTime()'s
 * OWN identical `$original === '' ... === '0'` guard (DateHelper.php
 * line 33) independently catches both sentinel values and returns
 * false, which this method's own `if ($date === false) { return
 * $default; }` a few lines below already converts back to the exact
 * same $default -- this guard is a pure (harmless) optimization that
 * skips a redundant str2DateTime() call, not a behavioral difference.
 */
test('transformDate returns the default for an empty or "0" original', function (): void {
    expect(DateHelper::transformDate('', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
    expect(DateHelper::transformDate('0', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
});

test('transformDate returns the default (null unless given) when the input format does not match', function (): void {
    expect(DateHelper::transformDate('not-in-the-expected-format', 'Y-m-d H:i:s', 'd/m/Y'))->toBeNull();
    expect(DateHelper::transformDate('not-in-the-expected-format', 'Y-m-d H:i:s', 'd/m/Y', 'fallback'))->toBe('fallback');
});

test('str2DateTime returns false for an unformatted string that tokenizes to fewer than 3 date parts', function (): void {
    // No format given, and "notadate" has no digits to trim and no '- :/'
    // delimiters to split on -- strtok() yields exactly one token, well
    // short of the 3 (year/month/day) the "unknown format" branch needs.
    expect(DateHelper::str2DateTime('notadate'))->toBeFalse();
});

test('str2DateTime returns false for the exact false/empty-string/int-0/string-"0" sentinels', function (): void {
    expect(DateHelper::str2DateTime(0))->toBeFalse()
        ->and(DateHelper::str2DateTime(''))->toBeFalse()
        ->and(DateHelper::str2DateTime('0'))->toBeFalse();
});

test('str2DateTime treats an explicitly-empty format the same as no format at all', function (): void {
    // Kills line 41's EmptyStringToNotEmpty: an empty (but non-null)
    // $format must still fall through to the "unknown date format"
    // branch below, not be treated as a real format string -- '!' alone
    // (the mutated createFromFormat() call's own format) can't match
    // '2024-06-15' and would return false instead of a real DateTime.
    $date = DateHelper::str2DateTime('2024-06-15', '');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('Y-m-d'))
        ->toBe('2024-06-15');
});

test('str2DateTime string-casts a numeric original before formatting with an explicit format', function (): void {
    // Kills line 42's RemoveStringCast: passing a raw int where
    // createFromFormat() declares a native `string $datetime` parameter
    // would TypeError under this file's own strict_types=1.
    $date = DateHelper::str2DateTime(20240615, '!Ymd');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('Y-m-d'))
        ->toBe('2024-06-15');
});

test('str2DateTime resets unspecified time fields to midnight via the "!" format-reset prefix', function (): void {
    // Kills line 42's ConcatRemoveLeft: without the '!' prefix,
    // createFromFormat() would merge unspecified (time) fields from the
    // *current* moment instead of resetting them to epoch/midnight --
    // never exactly '00:00:00' in practice.
    $date = DateHelper::str2DateTime('2024-06-15', 'Y-m-d');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('H:i:s'))
        ->toBe('00:00:00');
});

test('str2DateTime string-casts before tokenizing an original that reaches the unknown-format branch', function (): void {
    // Kills line 44's RemoveStringCast: a raw int where trim()/strtok()
    // declare a native `string` param would TypeError under this file's
    // own strict_types=1. A negative int is the only way an int
    // $original reaches this branch at all -- its string form has a
    // real '- :/' delimiter (a positive int's string form is pure
    // digits, always caught by the timestamp branch instead, see the
    // dedicated test below).
    expect(DateHelper::str2DateTime(-123))->toBeFalse();
});

test('str2DateTime treats a pure-digit int original with no format as a Unix timestamp', function (): void {
    // Kills line 44's UnwrapTrim and line 45's EmptyStringToNotEmpty:
    // $t is ONLY ever consulted for its emptiness (strtok() below
    // always re-reads the RAW (string) $original, not $t) -- for any
    // input where the real trim() already leaves a non-empty result
    // (like the negative-int test above), skipping trim() entirely or
    // comparing against a placeholder instead of '' changes nothing,
    // since both real and mutant take the same branch either way. A
    // pure-digit (positive) int is the one shape where trim() genuinely
    // reduces to '' -- skip or mis-compare that and the timestamp
    // branch below is wrongly bypassed for the "unknown format" one
    // instead, where strtok() (no '- :/' delimiters in a pure-digit
    // string) yields a single token, short of the 3 needed, and returns
    // false.
    $date = DateHelper::str2DateTime(1718409600);

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('Y-m-d H:i:s'))
        ->toBe('2024-06-15 00:00:00');
});

test('str2DateTime returns false for exactly 2 unknown-format tokens, one short of a full date', function (): void {
    // Kills line 55's DecrementInteger (count < 3 -> < 2).
    expect(DateHelper::str2DateTime('2024-06'))->toBeFalse();
});

test('str2DateTime defaults hour/minute/second to exactly 0 when only year/month/day tokens are present', function (): void {
    // Kills line 59's IncrementInteger (the assigned default 0 -> 1) and
    // (via the leading-space-then-trim() shape elsewhere) line 264's
    // UnwrapTrim once combined with timeSince()'s own tests -- this one
    // on its own is enough for line 59.
    $date = DateHelper::str2DateTime('2024-06-15');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('H:i:s'))
        ->toBe('00:00:00');
});

test('str2DateTime keeps a real hour token, not overwriting it with the default 0', function (): void {
    // Kills line 58's IncrementInteger (the isset($ymdhms[3]) check
    // shifted to check index 4 instead) and line 62's IncrementInteger
    // (minute's own default 0 -> 1, proven by the trailing :00 here).
    $date = DateHelper::str2DateTime('2024-06-15 14');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('H:i:s'))
        ->toBe('14:00:00');
});

test('str2DateTime keeps a real minute token, not overwriting it with the default 0', function (): void {
    // Kills line 61's IncrementInteger (isset($ymdhms[4]) shifted to
    // check index 5) and line 65's IncrementInteger (second's own
    // default 0 -> 1, proven by the trailing :00 here).
    $date = DateHelper::str2DateTime('2024-06-15 14:30');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('H:i:s'))
        ->toBe('14:30:00');
});

test('str2DateTime keeps a real, non-zero second token, not overwriting it or reusing the minute value', function (): void {
    // Kills line 64's IncrementInteger (isset($ymdhms[5]) shifted to
    // check index 6) and line 70's DecrementInteger (setTime()'s own
    // third argument reads index 4 -- the minute -- instead of index 5):
    // a non-zero, minute-distinct second value is required to tell
    // "real second" apart from "minute value reused as the second".
    $date = DateHelper::str2DateTime('2024-06-15 14:30:45');

    expect($date)
        ->not->toBeFalse();
    assert($date instanceof DateTime);
    expect($date->format('H:i:s'))
        ->toBe('14:30:45');
});

test('formatFromto returns the untranslated "N/A" key when either date is unparseable', function (): void {
    // "notadate" has no digits to trim and no '- :/' delimiter to tokenize
    // on, so str2DateTime() returns false outright (see the direct
    // str2DateTime test above) -- unlike a hyphenated non-date string,
    // which tokenizes into 3+ parts and is accepted as a (nonsensical but
    // non-false) DateTime instead.
    expect(DateHelper::formatFromto('notadate', '2024-06-15'))->toBe('N/A');
    expect(DateHelper::formatFromto('2024-06-15', 'notadate'))->toBe('N/A');
});

/**
 * Also confirmed-equivalent (both verified live via temporary
 * sed-applied mutations, full suite unaffected, in this php-intl-loaded
 * environment): a RemoveArrayItem dropping 'day' from formatDate()'s own
 * default $show list -- the IntlDateFormatter branch below only ever
 * consults 'year'/'month'/'day_name'/'time' from $show (never 'day'
 * itself; the FULL/LONG IntlDateFormatter date type already always
 * includes the day-of-month), so 'day' only matters for the
 * formatDateLegacy() fallback, unreachable from a $show=null call in an
 * environment where IntlDateFormatter succeeds; and a FalseToTrue on
 * `$formatted !== false` -> `!== true` -- $formatted is always either a
 * real formatted string (never identical to either bool) or a genuine
 * `false` on a real Intl formatting failure (not forceable without
 * mocking a final class), so both variants agree on every reachable
 * value.
 */
test('formatDate uses the current user\'s own language as the ICU locale, not always the app default', function (): void {
    // Kills line 161's CoalesceRemoveLeft: forcing the locale to always
    // be AppInfo::DEFAULT_LANGUAGE ('en_UK') would keep producing
    // English month/day names even with a real, present current-user
    // language.
    LangTestFactory::get()->setDefaultLanguageProvider(new class() implements DefaultLanguageProviderInterface {
        public function getDefaultLanguage(): string
        {
            return 'en_UK';
        }

        public function getCurrentLanguage(): string
        {
            return 'fr_FR';
        }
    });

    expect(DateHelper::formatDate('2024-06-15'))->toBe('samedi 15 juin 2024');
});

/**
 * Kills line 44's IfNegated and line 50's RemoveEarlyReturn: while
 * Kernel is booted (this file's own beforeEach), translator() must
 * resolve the real container-shared Translator -- the exact instance
 * TranslatorTestFactory::get() also returns, per its own docblock --
 * not DateHelper's private $translatorFallback (a separate, never-
 * loaded, memoized `new Translator(new CurrentConfig())`). Both
 * mutations independently reroute the booted-Kernel case onto that
 * untouched fallback instead: IfNegated by taking the else branch
 * outright, RemoveEarlyReturn by discarding `return $translator;` (so
 * the fallthrough `return self::$translatorFallback ??= ...;` still
 * fires even after resolving the real one). Loading a distinctive
 * plural translation onto the container-shared instance and expecting
 * it in timeSince()'s output only passes when the real, resolved
 * instance is actually the one consulted.
 */
test('translator() resolves the real container-shared Translator, not its own private pre-boot fallback, once Kernel is booted', function (): void {
    $poFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';
    file_put_contents($poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d hour"
        msgid_plural "%d hours"
        msgstr[0] "%d HOUR-CUSTOM"
        msgstr[1] "%d HOURS-CUSTOM"
        PO);

    TranslatorTestFactory::get()->load('en', $poFile);

    expect(DateHelper::timeSince('2026-07-31 23:00:00', stop: 'hour'))->toBe('1 HOUR-CUSTOM ago');

    unlink($poFile);
});

test('timeSince with only_last_unit stops at the first non-zero chunk once it reaches the requested $stop unit', function (): void {
    // tests/.env.test freezes Env::now() at 2026-08-01 00:00:00 -- against
    // that clock, 2023-01-15 10:00:00 is exactly a 3-year-and-change diff,
    // so $diff->y (the very first chunk checked) is already non-zero.
    // stop: 'year' makes $j point at that same first chunk (index 0), so
    // the break fires on the very first iteration instead of the outer
    // while condition alone ending the loop.
    expect(DateHelper::timeSince('2023-01-15 10:00:00', stop: 'year', only_last_unit: true))
        ->toBe('3 years ago');
});

test('timeSince with only_last_unit advances past leading zero chunks to a real, later one', function (): void {
    // Kills line 251's SmallerToSmallerOrEqual/BooleanAndToBooleanOr and
    // line 254's DecrementInteger/IncrementInteger: the existing
    // only_last_unit test above finds its non-zero value on the very
    // FIRST iteration (year), which can't tell `$i < count(...)` apart
    // from `<=` (never reached) or `$value !== 0` apart from `!== -1`/
    // `!== 1` (0 is the only value ever compared there). This one's
    // diff is 0 in year/month/week, only becoming non-zero at 'day'
    // (the 4th chunk), forcing the while loop to actually iterate.
    expect(DateHelper::timeSince('2026-07-29 00:00:00', only_last_unit: true))
        ->toBe('3 days ago');
});

/**
 * Also confirmed-equivalent (verified live via a temporary
 * sed-applied mutation, checked against the multi-segment "2 months 2
 * weeks 2 days 13 hours ago" scenario below): a ConcatSwitchSides on
 * line 292's `' ' . self::translator()->plural(...)` -> `plural(...) .
 * ' '`, moving the join separator from before each segment to after it.
 * Since every segment in the default (!only_last_unit) loop gets the
 * identical treatment, consecutive segments still end up separated by
 * exactly one space either way -- the only difference is a leading vs.
 * trailing space on the WHOLE joined string, which this method's own
 * final `trim($print)` removes regardless.
 */
test('timeSince names and counts each unit correctly when it is the only non-zero chunk', function (): void {
    // tests/.env.test freezes Env::now() at 2026-08-01T00:00:00. Each
    // $original below differs from "now" by exactly one chunk, proving
    // every entry in $chunks (kills the 5 RemoveArrayItem mutants on
    // that array literal), the week/day split arithmetic (kills the
    // floor()/division/multiplication/+-/int-cast mutants around it),
    // and the leading-space-then-trim() shape (kills UnwrapTrim -- an
    // un-trimmed result would have a leading space, failing this exact
    // toBe() match).
    expect(DateHelper::timeSince('2025-08-01 00:00:00', stop: 'year'))->toBe('1 year ago');
    expect(DateHelper::timeSince('2026-06-01 00:00:00', stop: 'month'))->toBe('2 months ago');
    expect(DateHelper::timeSince('2026-07-18 00:00:00', stop: 'week'))->toBe('2 weeks ago');
    expect(DateHelper::timeSince('2026-07-29 00:00:00', stop: 'day'))->toBe('3 days ago');
    expect(DateHelper::timeSince('2026-07-31 23:00:00', stop: 'hour'))->toBe('1 hour ago');
    expect(DateHelper::timeSince('2026-07-31 23:59:00', stop: 'minute'))->toBe('1 minute ago');
    expect(DateHelper::timeSince('2026-07-31 23:59:59', stop: 'second'))->toBe('1 second ago');
});

test('timeSince skips a zero-valued $stop chunk, continuing to the first real non-zero one after it', function (): void {
    // Kills line 244's EmptyStringToNotEmpty (the default, !only_last_unit
    // foreach loop's own break condition): $stop points at 'month', but
    // the actual diff has month === 0 (nothing appended yet, $print still
    // '') right as $i reaches that position -- the real `$print !== ''`
    // check correctly keeps the loop going past it to 'day' (the next
    // real non-zero chunk). A mutated `$print !== 'PEST Mutator was
    // here!'` is true even while $print is still genuinely empty,
    // wrongly breaking the loop right there with nothing ever appended.
    expect(DateHelper::timeSince('2026-07-29 00:00:00', stop: 'month'))->toBe('3 days ago');
});

test('timeSince stops at exactly the requested $stop unit among several non-zero chunks, not earlier or later', function (): void {
    // Exercises line 244's own boundary shape (same check as line 257's
    // below, but in the default !only_last_unit foreach loop, which had
    // no untested mutants left -- kept
    // as a real regression test, not credited with killing anything
    // new): with month/week/day/hour/minute/second ALL non-zero, only
    // the real `$print !== '' && $i >= $j` shape stops the accumulation
    // at exactly 'hour' (index 4).
    expect(DateHelper::timeSince('2026-05-15 10:15:20', stop: 'hour'))
        ->toBe('2 months 2 weeks 2 days 13 hours ago');
});

/**
 * A mutation-testing sweep found part of the `if ($print !== '' && $i
 * >= $j) { break; }` check inside the only_last_unit branch (lines
 * 297-298 -- textually identical to, but a different copy of, line
 * 244's own check in the default branch above) confirmed-equivalent,
 * verified live via a temporary sed-applied `if (false) { break; }`
 * (BreakToContinue has the same disabling effect: `continue` skips the
 * loop body's trailing `$i++` and jumps straight back to the enclosing
 * `while ($print === '' && ...)`'s own condition, which already exits
 * on the very next check once $print is non-empty -- nothing after the
 * loop reads $i's own final value): the enclosing while loop's own
 * condition already terminates the search as soon as $print becomes
 * non-empty, so disabling the inner break outright, or only shifting
 * *when* (not *whether*) it fires while $print is ALREADY non-empty
 * (GreaterOrEqualToGreater/GreaterOrEqualToSmaller on `$i >= $j`),
 * changes nothing regardless of $stop -- confirmed against 4 different
 * scenarios (single-unit, mid-loop-advance, multi-non-zero-chunk, and
 * a zero-valued $stop position).
 *
 * That reasoning does NOT extend to a mutation that makes the break
 * fire EARLY, while $print is still genuinely '' (this loop's own
 * search for the first non-zero chunk not yet done) -- see the
 * dedicated test below for the 3 mutations (NotIdenticalToIdentical,
 * BooleanAndToBooleanOr, EmptyStringToNotEmpty on the `$print !== ''`
 * half of the check) that do exactly that.
 */
test('timeSince(only_last_unit) does not stop early on a zero-valued chunk sitting at exactly the $stop position', function (): void {
    // $stop: 'day' points $j at index 3; the diff here is a clean 0
    // days / 5 hours (day is zero, hour is the first real non-zero
    // chunk, one position later) -- the one shape that tells the real
    // `$print !== '' && $i >= $j` apart from 3 mutations that drop or
    // invert the `$print !== ''` half (NotIdenticalToIdentical =>
    // `$print === ''`; BooleanAndToBooleanOr => `|| $i >= $j`;
    // EmptyStringToNotEmpty => comparing against a placeholder instead
    // of ''): all 3 make the check true purely from `$i >= $j` at i=3
    // (day, still genuinely '') and break the loop right there with
    // $print never set, producing ' ago' -- the unmutated check
    // correctly requires $print to be non-empty too, so the search
    // continues one more chunk to the real first non-zero value (hour).
    expect(DateHelper::timeSince('2026-07-31 19:00:00', stop: 'day', only_last_unit: true))
        ->toBe('5 hours ago');
});

test('timeSince(only_last_unit) picks the correct singular/plural unit name, not a stray mutated format string', function (): void {
    // Kills line 255's ConcatRemoveLeft/ConcatRemoveRight/ConcatSwitchSides
    // on the singular '%d ' . $name format string -- only reachable with
    // a value of exactly 1 (the plural branch's own '%d ' . $name . 's'
    // is separately covered by the existing "3 years ago" test above).
    expect(DateHelper::timeSince('2026-07-31 23:00:00', stop: 'hour', only_last_unit: true))
        ->toBe('1 hour ago');
});

test('timeSince(only_last_unit) does not overrun $chunks when every single unit is exactly 0', function (): void {
    // Kills line 251's SmallerToSmallerOrEqual and BooleanAndToBooleanOr:
    // an exact clock tie (every chunk 0) is the only scenario where the
    // while loop's own $i reaches the true boundary (count($chunks),
    // i.e. 7) without ever finding a non-zero value to stop it early --
    // `$i < count(...)` correctly exits there; `<=` or `||` both let it
    // run one iteration past the end, reading a non-existent array
    // index and crashing with a real TypeError a few lines below.
    expect(DateHelper::timeSince('2026-08-01 00:00:00', only_last_unit: true))->toBe(' ago');
});

test('timeSince reports "ago" for an exact clock tie, not "in the future"', function (): void {
    // Kills line 275's GreaterOrEqualToGreater: at the exact boundary
    // ($now === $date, every chunk 0), only `>=` (not the mutated `>`)
    // still classifies it as "ago" -- matching this method's own
    // documented invert-semantics rationale a few lines above.
    expect(DateHelper::timeSince('2026-08-01 00:00:00'))->toBe(' ago');
});

test('timeSince reports "in the future" for a real future date', function (): void {
    expect(DateHelper::timeSince('2026-08-02 00:00:00', stop: 'day'))->toBe('1 day in the future');
});

test('timeSince keeps week at exactly 0, not a stray non-zero literal, when with_week is false', function (): void {
    // Kills line 221's DecrementInteger/IncrementInteger on the 'week'
    // => 0 array literal: with with_week explicitly false, the
    // week/day-split block below never overwrites it, so this literal's
    // own value is what actually reaches the "is this chunk non-zero"
    // check -- a mutated -1/1 would wrongly make 'week' itself a
    // non-zero chunk, appended (and, since $stop points right at it,
    // immediately breaking on) instead of the real 13-day value.
    expect(DateHelper::timeSince('2026-07-19 00:00:00', stop: 'week', with_week: false))
        ->toBe('13 days ago');
});

test('timeSince rounds the week count down (floor), and divides by 7, not some other value', function (): void {
    // Kills line 230's FloorToRound (floor(13/7)=1 vs round(13/7)=2)
    // and DecrementInteger (floor(13/7)=1 vs floor(13/6)=2) -- 13 days
    // is the smallest value where both floor()-vs-round() and /7-vs-/6
    // diverge from the real floor(13/7)=1 at once. 'week' comes before
    // 'day' in $chunks, so $stop: 'week' breaks right after it without
    // ever looking at the remaining 6 days.
    expect(DateHelper::timeSince('2026-07-19 00:00:00', stop: 'week'))->toBe('1 week ago');
});

test('isValidMysqlDatetime returns true for a full "Y-m-d H:i:s" match', function (): void {
    expect(DateHelper::isValidMysqlDatetime('2024-06-15 10:20:30'))->toBeTrue();
});

test('isValidMysqlDatetime returns true for a date-only "Y-m-d" match, not just the full format', function (): void {
    // Kills line 321's IdenticalToNotIdentical: a bare date (no time
    // component) fails the first (full datetime) format check, only
    // succeeding via this second, date-only one.
    expect(DateHelper::isValidMysqlDatetime('2024-06-15'))->toBeTrue();
});

test('isValidMysqlDatetime returns false for a string matching neither MySQL datetime format', function (): void {
    expect(DateHelper::isValidMysqlDatetime('not-a-date'))->toBeFalse();
});

/**
 * [Mutation] A scoped `pest --mutate` rerun leaves 22 mutations
 * "untested" beyond the 2 real gaps this file's own container-guard
 * tests above close. Every one was individually hand-mutation-verified
 * against the real source (temporary sed edit + a full rerun of this
 * file, reverted after), not assumed from the pattern alone:
 *
 * 1. The `$ymdhms[3]`/`[4]`/`[5]` isset()-then-default-fill trio in
 *    str2DateTime() (Lines 99-106, all DecrementInteger/IncrementInteger
 *    on the literal index) is masked by count($ymdhms) < 3's own earlier
 *    guard: shifting an isset() check to an index that's ALWAYS already
 *    set (or always unset) just moves WHEN the index gets defaulted, not
 *    WHETHER it ends up 0 -- an undefined array offset still coerces to
 *    `(int) null === 0` at the final setTime() call, identical to the
 *    intended default, just with an extra (suppressible, non-failing)
 *    PHP warning along the way.
 * 2. Every `! (bool) $date`/`(bool) $date and ...` RemoveBooleanCast
 *    (Lines 130/181/251/352/359) is the same universal redundant-cast-
 *    in-boolean-context pattern confirmed throughout this whole
 *    campaign.
 * 3. Line 140's ConcatEqualToEqual (`.=` -> `=`) is inert because it's
 *    the very FIRST possible write to $print (which starts as ''), so
 *    concat-assign and plain-assign produce the same result; Line 158's
 *    ConcatRemoveRight (dropping a trailing space) is inert because
 *    'time' is the LAST possible component appended before
 *    `trim($print)` strips it either way; Line 282's ConcatSwitchSides
 *    (moving the separator space from before to after each translated
 *    chunk) is mathematically inert once every chunk is joined and the
 *    whole result trimmed -- the same single space ends up between each
 *    pair of chunks regardless of which side it started on.
 * 4. Lines 186/203 (formatDate()'s own default $show missing 'day', and
 *    the `$formatted !== false` guard around IntlDateFormatter::format())
 *    are linked and NOT chased further: 'day' is never checked anywhere
 *    in formatDate()'s own Intl branch (only 'day_name'/'month'/'year'/
 *    'time' are), so it's provably irrelevant whenever Intl succeeds --
 *    and forcing IntlDateFormatter::format() to genuinely return false
 *    turned out impractical (probed both an extreme out-of-range
 *    DateTime and an invalid locale string live: the former formats
 *    successfully regardless, the latter throws a ValueError at
 *    __construct() instead of reaching format() at all).
 * 5. Lines 297/298 (the only_last_unit while-loop's own `$i >= $j` break
 *    check, all 3 comparison-operator directions plus BreakToContinue)
 *    are inert because the loop's own OUTER condition
 *    (`$print === '' && ...`) already exits on the very next check once
 *    $print is set -- the inner break changes only how many extra times
 *    the never-read-afterward $i counter increments, never $print's own
 *    final value.
 * 6. Line 332's transformDate() guard (`$original === '' || $original
 *    === '0'`, both BooleanOrToBooleanAnd and EmptyStringToNotEmpty) and
 *    Line 333's RemoveEarlyReturn are all inert for the same structural
 *    reason: str2DateTime() has its OWN identical `''`/`'0'` guard
 *    internally, so bypassing transformDate()'s own outer guard just
 *    defers to str2DateTime()'s inner one, which still returns false and
 *    still produces the same $default fallback either way.
 */
