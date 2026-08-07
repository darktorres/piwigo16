<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * CurrentConfig's ~280 simple typed property getters/setters had no
 * dedicated test file at all -- most of them get incidental coverage from
 * elsewhere (ConfigService/RequestBootstrap tests reading/writing various
 * properties), which is why a mutation-testing sweep only flagged the
 * "Custom-shaped properties (non-trivial coercion)" section (P17-23's own
 * heading in the source) as genuinely untested: every property there has
 * real branching/coercion logic in its setter (and, for a few, its
 * getter) that a plain round-trip assignment never exercises.
 */
beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

// ---- Pattern A: scalar-list -> string, null passthrough, non-scalar '' ----
// array_values(array_map(static fn (mixed $x): string => is_scalar($x) ||
// $x === null ? (string) $x : '', $value)) -- byte-identical across all 8.

dataset('scalarListToStringProperties', [
    'apiKeyDuration' => [CurrentConfigTestFactory::get()->setApiKeyDuration(...), CurrentConfigTestFactory::get()->apiKeyDuration(...)],
    'apiKeyForbiddenMethods' => [CurrentConfigTestFactory::get()->setApiKeyForbiddenMethods(...), CurrentConfigTestFactory::get()->apiKeyForbiddenMethods(...)],
    'fileExtensions' => [CurrentConfigTestFactory::get()->setFileExtensions(...), CurrentConfigTestFactory::get()->fileExtensions(...)],
    'formatExtensions' => [CurrentConfigTestFactory::get()->setFormatExtensions(...), CurrentConfigTestFactory::get()->formatExtensions(...)],
    'headerNotes' => [CurrentConfigTestFactory::get()->setHeaderNotes(...), CurrentConfigTestFactory::get()->headerNotes(...)],
    'pictureExtensions' => [CurrentConfigTestFactory::get()->setPictureExtensions(...), CurrentConfigTestFactory::get()->pictureExtensions(...)],
    'showExifFields' => [CurrentConfigTestFactory::get()->setShowExifFields(...), CurrentConfigTestFactory::get()->showExifFields(...)],
    'syncExcludeFolders' => [CurrentConfigTestFactory::get()->setSyncExcludeFolders(...), CurrentConfigTestFactory::get()->syncExcludeFolders(...)],
]);

test('scalar-list string properties cast scalars, pass null through as \'\', reject non-scalars, and reindex', function (Closure $setter, Closure $getter): void {
    // Non-sequential input keys (5/10/20/...) prove array_values() actually
    // reindexes, not just that the values happen to already be 0-based.
    $setter([
        5 => 123,
        10 => 45.6,
        20 => true,
        30 => null,
        40 => ['nested'],
        50 => 'already-a-string',
    ]);

    expect($getter())->toBe([
        '123', '45.6', '1', '', '', 'already-a-string',
    ]);
})->with('scalarListToStringProperties');

// ---- Pattern B: scalar-list -> int, non-scalar 0 -------------------------

dataset('scalarListToIntProperties', [
    'availablePermissionLevels' => [CurrentConfigTestFactory::get()->setAvailablePermissionLevels(...), CurrentConfigTestFactory::get()->availablePermissionLevels(...)],
    'rateItems' => [CurrentConfigTestFactory::get()->setRateItems(...), CurrentConfigTestFactory::get()->rateItems(...)],
]);

test('scalar-list int properties cast scalars (truncating floats), reject non-scalars to 0, and reindex', function (Closure $setter, Closure $getter): void {
    $setter([
        5 => '123',
        10 => 45.6,
        20 => true,
        30 => null,
        40 => ['nested'],
        50 => 100,
    ]);

    expect($getter())->toBe([123, 45, 1, 0, 0, 100]);
})->with('scalarListToIntProperties');

test('availablePermissionLevels falls back to its own factory default for an empty array', function (): void {
    CurrentConfigTestFactory::get()->setAvailablePermissionLevels([1, 2, 3]);
    expect(CurrentConfigTestFactory::get()->availablePermissionLevels())->toBe([1, 2, 3]);

    CurrentConfigTestFactory::get()->setAvailablePermissionLevels([]);

    expect(CurrentConfigTestFactory::get()->availablePermissionLevels())->toBe([0, 1, 2, 4, 8]);
});

test('availablePermissionLevels accepts the exact boundary of a single-item array', function (): void {
    // The empty/non-empty test above (0 vs 3 items) can't tell
    // `count($value) > 0` apart from a mutated `> 1` -- both correctly
    // take the same branch for 0 and 3. Only exactly 1 item does.
    CurrentConfigTestFactory::get()->setAvailablePermissionLevels([7]);

    expect(CurrentConfigTestFactory::get()->availablePermissionLevels())->toBe([7]);
});

// ---- Pattern C: key => string-value map, non-scalar value '' ------------
// $result[(string) $k] = is_scalar($val) ? (string) $val : ''; -- identical
// across all 3.
//
// The `(string) $k` cast itself (as opposed to `(string) $val` above it) is
// a confirmed-equivalent mutant, here and on setRandomIndexRedirect()'s own
// identical-shaped cast below: a foreach key is always already int|string
// (PHP array keys can never be anything else), and PHP itself re-normalizes
// any canonical-decimal-integer string key (e.g. '42') back to the int key
// on array insertion -- so casting an int key to string and letting PHP
// re-normalize it produces the exact same resulting array as never casting
// it at all, for every reachable key value.

dataset('keyToStringValueMapProperties', [
    'showIptcMapping' => [CurrentConfigTestFactory::get()->setShowIptcMapping(...), CurrentConfigTestFactory::get()->showIptcMapping(...)],
    'useExifMapping' => [CurrentConfigTestFactory::get()->setUseExifMapping(...), CurrentConfigTestFactory::get()->useExifMapping(...)],
    'useIptcMapping' => [CurrentConfigTestFactory::get()->setUseIptcMapping(...), CurrentConfigTestFactory::get()->useIptcMapping(...)],
]);

test('key-to-string-value map properties cast both key and scalar value to string, and \'\' for non-scalar values', function (Closure $setter, Closure $getter): void {
    $setter([
        42 => 123,
        'strkey' => true,
        'nonscalar' => ['nested'],
    ]);

    expect($getter())->toBe([
        '42' => '123',
        'strkey' => '1',
        'nonscalar' => '',
    ]);
})->with('keyToStringValueMapProperties');

// ---- Pattern D: empty-string-fallback scalar string ----------------------

dataset('emptyStringFallbackProperties', [
    'metadataKeywordSeparatorRegex' => [CurrentConfigTestFactory::get()->setMetadataKeywordSeparatorRegex(...), CurrentConfigTestFactory::get()->metadataKeywordSeparatorRegex(...), '/[.,;]/'],
    'syncCharsRegex' => [CurrentConfigTestFactory::get()->setSyncCharsRegex(...), CurrentConfigTestFactory::get()->syncCharsRegex(...), '/^[a-zA-Z0-9-_.]+$/'],
]);

test('empty-string-fallback properties keep a real value but fall back to their own default for an empty string', function (Closure $setter, Closure $getter, string $default): void {
    $setter('/custom/');
    expect($getter())->toBe('/custom/');

    $setter('');

    expect($getter())->toBe($default);
})->with('emptyStringFallbackProperties');

// ---- Pattern E: getters that must return the real stored value ----------

test('headerNotes getter returns the real stored value, not a hardcoded empty array', function (): void {
    CurrentConfigTestFactory::get()->setHeaderNotes(['a real note']);

    expect(CurrentConfigTestFactory::get()->headerNotes())->toBe(['a real note']);
});

test('links getter returns the real stored value, not a hardcoded empty array', function (): void {
    CurrentConfigTestFactory::get()->setLinks(['home' => 'https://example.test']);

    expect(CurrentConfigTestFactory::get()->links())->toBe(['home' => 'https://example.test']);
});

test('emptyLoungeRunning getter returns the real stored value, not a hardcoded null', function (): void {
    CurrentConfigTestFactory::get()->setEmptyLoungeRunning('12345-1700000000');

    expect(CurrentConfigTestFactory::get()->emptyLoungeRunning())->toBe('12345-1700000000');
});

test('pictureInformations getter returns the real stored value, not a hardcoded empty array', function (): void {
    CurrentConfigTestFactory::get()->setPictureInformations(['iso' => true]);

    expect(CurrentConfigTestFactory::get()->pictureInformations())->toBe(['iso' => true]);
});

test('countOrphans getter returns the real stored value, not a hardcoded null', function (): void {
    CurrentConfigTestFactory::get()->setCountOrphans(42);

    expect(CurrentConfigTestFactory::get()->countOrphans())->toBe(42);
});

// ---- Individual/unique-shape properties ----------------------------------

test('chmodValue falls back to 0777 when unset, under this suite\'s own always-active test mode', function (): void {
    // tests/bootstrap.php sets $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test' for
    // the whole suite, so Env::testModeIsActive() is always true here --
    // chmodValue()'s SAPI-dependent `substr_compare(PHP_SAPI, 'apa', 0, 3)
    // === 0 ? 0777 : 0755` branch is genuinely unreachable from any Unit
    // test in this suite (confirmed: this assertion fails against 0755,
    // the value that branch would produce, and passes against 0777, the
    // real early-return value). Its own several numeric-literal mutants
    // are correspondingly unobservable here -- not chased further.
    expect(CurrentConfigTestFactory::get()->chmodValue())->toBe(0777);
});

test('chmodValue returns the explicit override when set, regardless of the SAPI default', function (): void {
    CurrentConfigTestFactory::get()->setChmodValue(0700);

    expect(CurrentConfigTestFactory::get()->chmodValue())->toBe(0700);
});

test('setDefaultFiltersViews keeps a well-shaped override entry and falls back per-key otherwise', function (): void {
    CurrentConfigTestFactory::get()->setDefaultFiltersViews([
        // Well-shaped: both is_string(access) and is_bool(default) hold.
        'words' => ['access' => 'admins', 'default' => false],
        // Malformed access (not a string) -- falls back to the real default.
        'tags' => ['access' => 123, 'default' => true],
        // Malformed default (not a bool) -- falls back to the real default.
        'post_date' => ['access' => 'everybody', 'default' => 'yes'],
        // Missing entirely -- falls back to the real default.
    ]);

    $result = CurrentConfigTestFactory::get()->defaultFiltersViews();

    expect($result['words'])->toBe(['access' => 'admins', 'default' => false])
        ->and($result['tags'])->toBe(['access' => 'everybody', 'default' => false])
        ->and($result['post_date'])->toBe(['access' => 'everybody', 'default' => false])
        ->and($result['album'])->toBe(['access' => 'everybody', 'default' => true]);
});

test('setDefaultFiltersViews resets to the full factory default when given null', function (): void {
    CurrentConfigTestFactory::get()->setDefaultFiltersViews(['words' => ['access' => 'admins', 'default' => false]]);

    CurrentConfigTestFactory::get()->setDefaultFiltersViews(null);

    expect(CurrentConfigTestFactory::get()->defaultFiltersViews()['words'])->toBe(['access' => 'everybody', 'default' => true]);
});

test('setHistorySectionsCache keeps only string entries and reindexes', function (): void {
    CurrentConfigTestFactory::get()->setHistorySectionsCache([5 => 'add', 10 => 42, 20 => 'delete']);

    expect(CurrentConfigTestFactory::get()->historySectionsCache())->toBe(['add', 'delete']);
});

test('setHistorySectionsCache accepts a literal null', function (): void {
    CurrentConfigTestFactory::get()->setHistorySectionsCache(['add']);

    CurrentConfigTestFactory::get()->setHistorySectionsCache(null);

    expect(CurrentConfigTestFactory::get()->historySectionsCache())->toBeNull();
});

test('setPictureInformations keeps only string-keyed bool entries', function (): void {
    CurrentConfigTestFactory::get()->setPictureInformations([
        'iso' => true,
        'aperture' => false,
        42 => true, // non-string key -- excluded
        'shutter' => 'not-a-bool', // non-bool value -- excluded
    ]);

    expect(CurrentConfigTestFactory::get()->pictureInformations())->toBe(['iso' => true, 'aperture' => false]);
});

test('setRandomIndexRedirect casts both key and scalar value to string, excluding non-scalar values entirely', function (): void {
    CurrentConfigTestFactory::get()->setRandomIndexRedirect([
        5 => 'val1',
        'strkey' => 42,
        10 => ['nested'], // non-scalar value -- excluded, not defaulted
    ]);

    expect(CurrentConfigTestFactory::get()->randomIndexRedirect())->toBe(['5' => 'val1', 'strkey' => '42']);
});

test('setRecentPostDates keeps a well-shaped override entry and falls back per-field otherwise', function (): void {
    CurrentConfigTestFactory::get()->setRecentPostDates([
        'RSS' => ['max_dates' => 10, 'max_elements' => 11, 'max_cats' => 12],
        'NBM' => ['max_dates' => 'not-an-int', 'max_elements' => 20, 'max_cats' => 'also-not-an-int'],
    ]);

    $result = CurrentConfigTestFactory::get()->recentPostDates();

    expect($result->rss->maxDates)->toBe(10)
        ->and($result->rss->maxElements)->toBe(11)
        ->and($result->rss->maxCats)->toBe(12)
        // Malformed max_dates/max_cats fall back to NBM's own real default
        // (7 and 9), not RSS's or a zero value.
        ->and($result->nbm->maxDates)->toBe(7)
        ->and($result->nbm->maxElements)->toBe(20)
        ->and($result->nbm->maxCats)->toBe(9);
});

test('recentPostDates getter lazily builds its own real default when never explicitly set', function (): void {
    // Every other recentPostDates test below calls setRecentPostDates()
    // first, which unconditionally overwrites the stored property --
    // the getter's own `??= new NotificationConfig(...)` lazy-default
    // construction (with its own literal max_dates/max_elements/max_cats
    // values) never actually runs in any of them.
    $result = CurrentConfigTestFactory::get()->recentPostDates();

    expect($result->rss->maxDates)->toBe(5)
        ->and($result->rss->maxElements)->toBe(6)
        ->and($result->rss->maxCats)->toBe(6)
        ->and($result->nbm->maxDates)->toBe(7)
        ->and($result->nbm->maxElements)->toBe(3)
        ->and($result->nbm->maxCats)->toBe(9);
});

