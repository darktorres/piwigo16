<?php

declare(strict_types=1);

use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\NotificationConfig;

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

test('a bool accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfigTestFactory::get()->activateComments)->toBeTrue(); // property default

    CurrentConfigTestFactory::get()->activateComments = false;
    expect(CurrentConfigTestFactory::get()->activateComments)->toBe(false);
});

test('an int accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfigTestFactory::get()->webmasterId)->toBe(1);

    CurrentConfigTestFactory::get()->webmasterId = 42;
    expect(CurrentConfigTestFactory::get()->webmasterId)->toBe(42);
});

test('a string accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfigTestFactory::get()->galleryTitle)->toBe('Piwigo');

    CurrentConfigTestFactory::get()->galleryTitle = 'Custom';
    expect(CurrentConfigTestFactory::get()->galleryTitle)->toBe('Custom');
});

test('a nullable string accessor returns null when unset', function (): void {
    expect(CurrentConfigTestFactory::get()->galleryUrl)->toBeNull();

    CurrentConfigTestFactory::get()->galleryUrl = 'https://example.test';
    expect(CurrentConfigTestFactory::get()->galleryUrl)->toBe('https://example.test');
});

test('a custom array accessor coerces and falls back to its hardcoded default', function (): void {
    expect(CurrentConfigTestFactory::get()->pictureExtensions)->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);

    CurrentConfigTestFactory::get()->pictureExtensions = ['jpg', 'png'];
    expect(CurrentConfigTestFactory::get()->pictureExtensions)->toBe(['jpg', 'png']);
});

test('the recentPostDates custom accessor returns a NotificationConfig VO', function (): void {
    $config = CurrentConfigTestFactory::get()->recentPostDates;

    expect($config->rss->maxDates)->toBe(5)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('orderBy is a plain raw SQL-fragment string, not a structured {field,dir}[] shape', function (): void {
    expect(CurrentConfigTestFactory::get()->orderBy)->toBe('ORDER BY date_available DESC, file ASC, id ASC');

    CurrentConfigTestFactory::get()->orderBy = 'ORDER BY id ASC';
    expect(CurrentConfigTestFactory::get()->orderBy)->toBe('ORDER BY id ASC');
});

test('dumpForLog redacts sensitive properties', function (): void {
    CurrentConfigTestFactory::get()->smtpPassword = 'super-secret';
    CurrentConfigTestFactory::get()->galleryTitle = 'Visible';

    // Keyed by property name (smtpPassword/galleryTitle), not the DB param
    // name (smtp_password/gallery_title) -- CurrentConfig::all()'s own
    // docblock: reflection-based, replacing the former SCHEMA-driven all().
    $dump = CurrentConfigTestFactory::get()->dumpForLog();

    expect($dump['smtpPassword'])->toBe('********')
        ->and($dump['galleryTitle'])->toBe('Visible');
});

test('chmodValue defaults to 0777 in test mode regardless of SAPI, overridable via a plain nullable int', function (): void {
    // Real bug, found live: this test used to assert 0755 here ("non-Apache
    // SAPI in a CLI test run") -- true of the raw SAPI-only heuristic, but
    // wrong for this actual test environment, where CLI-run suites (Unit/
    // Integration, as torres) and real Apache-served suites (Contract/
    // Browser, as www-data) share the same _data/ tree within one
    // composer test:coverage:all run. Whichever side's mkgetdir() call
    // creates a shared directory first used to "win" its mode for the
    // rest of the run -- a CLI test creating a directory first left it
    // torres-only (0755), and the next Apache-served request 500'd the
    // moment it needed that same directory (took down an entire Contract
    // suite run this way). chmodValue() now special-cases test mode to
    // 0777 unconditionally, since Env::testModeIsActive() is true on both
    // sides here (CLI always; Apache via loopback + header).
    expect(CurrentConfigTestFactory::get()->chmodValue)->toBe(0777);

    CurrentConfigTestFactory::get()->chmodValue = 0700;
    expect(CurrentConfigTestFactory::get()->chmodValue)->toBe(0700);

    CurrentConfigTestFactory::get()->chmodValue = null;
    expect(CurrentConfigTestFactory::get()->chmodValue)->toBe(0777);
});

test('chmodValue falls back to the plain SAPI-dependent default outside test mode', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        // PHP_SAPI is 'cli' for this whole process (see tests/bootstrap.php's
        // own docblock) -- with the test-mode header cleared,
        // Env::testModeIsActive() is false, so this reaches the original
        // SAPI-only heuristic unchanged.
        expect(CurrentConfigTestFactory::get()->chmodValue)->toBe(0755);
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('orderByCustom/orderByInsideCategoryCustom are plain nullable raw-SQL-fragment strings', function (): void {
    expect(CurrentConfigTestFactory::get()->orderByCustom)->toBeNull()
        ->and(CurrentConfigTestFactory::get()->orderByInsideCategoryCustom)->toBeNull();

    CurrentConfigTestFactory::get()->orderByCustom = 'ORDER BY hit DESC';
    CurrentConfigTestFactory::get()->orderByInsideCategoryCustom = 'ORDER BY file ASC';

    expect(CurrentConfigTestFactory::get()->orderByCustom)->toBe('ORDER BY hit DESC')
        ->and(CurrentConfigTestFactory::get()->orderByInsideCategoryCustom)->toBe('ORDER BY file ASC');
});

test('setAvailablePermissionLevels falls back to the hardcoded default set when given an empty list', function (): void {
    CurrentConfigTestFactory::get()->availablePermissionLevels = [2, 4];
    expect(CurrentConfigTestFactory::get()->availablePermissionLevels)->toBe([2, 4]);

    CurrentConfigTestFactory::get()->availablePermissionLevels = [];
    expect(CurrentConfigTestFactory::get()->availablePermissionLevels)->toBe([0, 1, 2, 4, 8]);
});

test('setDefaultFiltersViews replaces only well-shaped entries, falling back per-key for anything else', function (): void {
    CurrentConfigTestFactory::get()->defaultFiltersViews = [
        'words' => ['access' => 'admin_only', 'default' => false],
        'tags' => 'not-an-array', // invalid shape -> falls back to the hardcoded default
    ];

    $result = CurrentConfigTestFactory::get()->defaultFiltersViews;

    expect($result['words'])->toBe(['access' => 'admin_only', 'default' => false])
        ->and($result['tags'])->toBe(['access' => 'everybody', 'default' => false]);

    CurrentConfigTestFactory::get()->defaultFiltersViews = null;
    expect(CurrentConfigTestFactory::get()->defaultFiltersViews['words'])->toBe(['access' => 'everybody', 'default' => true]);
});

test('setRandomIndexRedirect keeps only scalar values, stringified, keyed by stringified key', function (): void {
    CurrentConfigTestFactory::get()->randomIndexRedirect = [
        'random' => 'random.php',
        7 => 42,
        'bad' => ['not', 'scalar'],
    ];

    expect(CurrentConfigTestFactory::get()->randomIndexRedirect)->toBe([
        'random' => 'random.php',
        '7' => '42',
    ]);
});

test('setRecentPostDates coerces valid fields and falls back per-field and per-channel', function (): void {
    CurrentConfigTestFactory::get()->recentPostDates = NotificationConfig::fromArray([
        'RSS' => ['max_dates' => 10, 'max_elements' => 'not-an-int', 'max_cats' => 20],
        // NBM entirely omitted -> the whole channel falls back to its own hardcoded default
    ]);

    $config = CurrentConfigTestFactory::get()->recentPostDates;

    expect($config->rss->maxDates)->toBe(10)
        ->and($config->rss->maxElements)->toBe(6) // fallback: source value wasn't an int
        ->and($config->rss->maxCats)->toBe(20)
        ->and($config->nbm->maxDates)->toBe(7)
        ->and($config->nbm->maxElements)->toBe(3)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('setUpdateNotifyLastNotification keeps only the recognized keys and clears with null', function (): void {
    expect(CurrentConfigTestFactory::get()->updateNotifyLastNotification)->toBeNull();

    CurrentConfigTestFactory::get()->updateNotifyLastNotification = ['version' => '17.1.0', 'notified_on' => '2026-07-01', 'unexpected' => 'ignored'];
    expect(CurrentConfigTestFactory::get()->updateNotifyLastNotification)->toBe(['version' => '17.1.0', 'notified_on' => '2026-07-01']);

    CurrentConfigTestFactory::get()->updateNotifyLastNotification = null;
    expect(CurrentConfigTestFactory::get()->updateNotifyLastNotification)->toBeNull();
});
