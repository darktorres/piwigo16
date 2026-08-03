<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\NotificationConfig;

beforeEach(function (): void {
    CurrentConfig::current()->reset();
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
});

test('a bool accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::current()->activateComments())->toBeTrue(); // property default

    CurrentConfig::current()->setActivateComments(false);
    expect(CurrentConfig::current()->activateComments())->toBeFalse();
});

test('an int accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::current()->webmasterId())->toBe(1);

    CurrentConfig::current()->setWebmasterId(42);
    expect(CurrentConfig::current()->webmasterId())->toBe(42);
});

test('a string accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::current()->galleryTitle())->toBe('Piwigo');

    CurrentConfig::current()->setGalleryTitle('Custom');
    expect(CurrentConfig::current()->galleryTitle())->toBe('Custom');
});

test('a nullable string accessor returns null when unset', function (): void {
    expect(CurrentConfig::current()->galleryUrl())->toBeNull();

    CurrentConfig::current()->setGalleryUrl('https://example.test');
    expect(CurrentConfig::current()->galleryUrl())->toBe('https://example.test');
});

test('a custom array accessor coerces and falls back to its hardcoded default', function (): void {
    expect(CurrentConfig::current()->pictureExtensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);

    CurrentConfig::current()->setPictureExtensions(['jpg', 'png']);
    expect(CurrentConfig::current()->pictureExtensions())->toBe(['jpg', 'png']);
});

test('the recentPostDates custom accessor returns a NotificationConfig VO', function (): void {
    $config = CurrentConfig::current()->recentPostDates();

    expect($config)->toBeInstanceOf(NotificationConfig::class)
        ->and($config->rss->maxDates)->toBe(5)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('orderBy is a plain raw SQL-fragment string, not a structured {field,dir}[] shape', function (): void {
    expect(CurrentConfig::current()->orderBy())->toBe('ORDER BY date_available DESC, file ASC, id ASC');

    CurrentConfig::current()->setOrderBy('ORDER BY id ASC');
    expect(CurrentConfig::current()->orderBy())->toBe('ORDER BY id ASC');
});

test('dumpForLog redacts sensitive properties', function (): void {
    CurrentConfig::current()->setSmtpPassword('super-secret');
    CurrentConfig::current()->setGalleryTitle('Visible');

    // Keyed by property name (smtpPassword/galleryTitle), not the DB param
    // name (smtp_password/gallery_title) -- CurrentConfig::all()'s own
    // docblock: reflection-based, replacing the former SCHEMA-driven all().
    $dump = CurrentConfig::current()->dumpForLog();

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
    expect(CurrentConfig::current()->chmodValue())->toBe(0777);

    CurrentConfig::current()->setChmodValue(0700);
    expect(CurrentConfig::current()->chmodValue())->toBe(0700);

    CurrentConfig::current()->setChmodValue(null);
    expect(CurrentConfig::current()->chmodValue())->toBe(0777);
});

test('chmodValue falls back to the plain SAPI-dependent default outside test mode', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        // PHP_SAPI is 'cli' for this whole process (see tests/bootstrap.php's
        // own docblock) -- with the test-mode header cleared,
        // Env::testModeIsActive() is false, so this reaches the original
        // SAPI-only heuristic unchanged.
        expect(CurrentConfig::current()->chmodValue())->toBe(0755);
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('orderByCustom/orderByInsideCategoryCustom are plain nullable raw-SQL-fragment strings', function (): void {
    expect(CurrentConfig::current()->orderByCustom())->toBeNull()
        ->and(CurrentConfig::current()->orderByInsideCategoryCustom())->toBeNull();

    CurrentConfig::current()->setOrderByCustom('ORDER BY hit DESC');
    CurrentConfig::current()->setOrderByInsideCategoryCustom('ORDER BY file ASC');

    expect(CurrentConfig::current()->orderByCustom())->toBe('ORDER BY hit DESC')
        ->and(CurrentConfig::current()->orderByInsideCategoryCustom())->toBe('ORDER BY file ASC');
});

test('setAvailablePermissionLevels falls back to the hardcoded default set when given an empty list', function (): void {
    CurrentConfig::current()->setAvailablePermissionLevels([2, 4]);
    expect(CurrentConfig::current()->availablePermissionLevels())->toBe([2, 4]);

    CurrentConfig::current()->setAvailablePermissionLevels([]);
    expect(CurrentConfig::current()->availablePermissionLevels())->toBe([0, 1, 2, 4, 8]);
});

test('setDefaultFiltersViews replaces only well-shaped entries, falling back per-key for anything else', function (): void {
    CurrentConfig::current()->setDefaultFiltersViews([
        'words' => ['access' => 'admin_only', 'default' => false],
        'tags' => 'not-an-array', // invalid shape -> falls back to the hardcoded default
    ]);

    $result = CurrentConfig::current()->defaultFiltersViews();

    expect($result['words'])->toBe(['access' => 'admin_only', 'default' => false])
        ->and($result['tags'])->toBe(['access' => 'everybody', 'default' => false]);

    CurrentConfig::current()->setDefaultFiltersViews(null);
    expect(CurrentConfig::current()->defaultFiltersViews()['words'])->toBe(['access' => 'everybody', 'default' => true]);
});

test('setRandomIndexRedirect keeps only scalar values, stringified, keyed by stringified key', function (): void {
    CurrentConfig::current()->setRandomIndexRedirect([
        'random' => 'random.php',
        7 => 42,
        'bad' => ['not', 'scalar'],
    ]);

    expect(CurrentConfig::current()->randomIndexRedirect())->toBe([
        'random' => 'random.php',
        '7' => '42',
    ]);
});

test('setRecentPostDates coerces valid fields and falls back per-field and per-channel', function (): void {
    CurrentConfig::current()->setRecentPostDates([
        'RSS' => ['max_dates' => 10, 'max_elements' => 'not-an-int', 'max_cats' => 20],
        // NBM entirely omitted -> the whole channel falls back to its own hardcoded default
    ]);

    $config = CurrentConfig::current()->recentPostDates();

    expect($config->rss->maxDates)->toBe(10)
        ->and($config->rss->maxElements)->toBe(6) // fallback: source value wasn't an int
        ->and($config->rss->maxCats)->toBe(20)
        ->and($config->nbm->maxDates)->toBe(7)
        ->and($config->nbm->maxElements)->toBe(3)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('setUpdateNotifyLastNotification keeps only the recognized keys and clears with null', function (): void {
    expect(CurrentConfig::current()->updateNotifyLastNotification())->toBeNull();

    CurrentConfig::current()->setUpdateNotifyLastNotification(['version' => '17.1.0', 'notified_on' => '2026-07-01', 'unexpected' => 'ignored']);
    expect(CurrentConfig::current()->updateNotifyLastNotification())->toBe(['version' => '17.1.0', 'notified_on' => '2026-07-01']);

    CurrentConfig::current()->setUpdateNotifyLastNotification(null);
    expect(CurrentConfig::current()->updateNotifyLastNotification())->toBeNull();
});
