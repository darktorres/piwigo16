<?php

declare(strict_types=1);

use Piwigo\Admin\AdminUiHelper;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * [Mutation] getAdminClientCacheKeys() is deliberately NOT covered here --
 * it needs a real DB connection (a live COUNT/MAX(lastmodified) per
 * table via DbInfo::getTableFingerprint()), so it's covered instead at
 * tests/Integration/AdminUiHelperCacheKeysTest.php (see that file's own
 * top docblock for the full split rationale). A scoped `pest --mutate`
 * run against the Unit suite alone will list its mutations as untested
 * -- expected, not a gap; the class's other, pure/file-based methods
 * (getExtents/pwgUrl/getActiveMenu/numberFormatHumanReadable) are what
 * this file covers.
 */
beforeEach(function (): void {
    // getExtents()'s no-args default is anchored to the live, container-bound Paths->
    // root (a real HTTP request's cwd is wherever Apache/PHP-FPM started
    // the process, not this project's root, so a `./`-relative default
    // silently resolved to nothing in production -- fixed at the source,
    // see AdminUiHelper's own docblock).
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getExtents finds every .latte file under the real template-extension directory, stripping the resolved root prefix', function (): void {
    // No args -> real default anchored to the live, container-bound Paths->root (the
    // repo root, same as every other test process in this suite) -- a
    // real, committed asset, not a throwaway fixture, so this asserts its
    // known real contents exactly.
    $result = AdminUiHelper::getExtents(Paths::fromRoot(dirname(__DIR__, 3)));

    sort($result);
    expect($result)
        ->toBe([
            'distributed/samples/my-picture.latte',
            'distributed/samples/my-thumbnails.latte',
            'distributed/samples/my-thumbnails2.latte',
            'distributed/samples/titling_categories.latte',
        ]);
});

test('getExtents returns an empty array when the directory does not exist', function (): void {
    // opendir() on a genuinely missing path raises a real PHP warning even
    // though getExtents() itself already handles the false return
    // gracefully -- a plain @ does NOT stop PHPUnit's ErrorHandler from
    // surfacing it regardless (@ only affects
    // error_reporting(), not whether the handler chain runs), so a real
    // no-op error handler for the duration of this one expected-to-warn
    // call is the only reliable way to swallow it, matching ImageGdTest's
    // own established pattern.
    set_error_handler(static fn (): bool => true);
    try {
        expect(AdminUiHelper::getExtents(Paths::fromRoot(dirname(__DIR__, 3)), '/definitely/does/not/exist-' . uniqid()))->toBe([]);
    } finally {
        restore_error_handler();
    }
});

test('getExtents skips symlinked .latte files and non-.latte files', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-admin-ui-helper-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);

    try {
        file_put_contents($dir . '/real.latte', 'real template');
        file_put_contents($dir . '/ignored.css', 'not a template');
        file_put_contents($dir . '/link-target.latte', 'linked template');
        symlink($dir . '/link-target.latte', $dir . '/symlinked.latte');

        // The prefix strip is relative to whatever $start was actually
        // passed (not a hardcoded length) -- real.latte and link-target.latte
        // (a genuine file, not itself a symlink) both survive as bare
        // filenames; only the symlink itself (symlinked.latte) and the
        // non-.latte file are excluded.
        $result = AdminUiHelper::getExtents(Paths::fromRoot(dirname(__DIR__, 3)), $dir);
        sort($result);

        expect($result)
            ->toBe(['link-target.latte', 'real.latte']);
    } finally {
        unlink($dir . '/symlinked.latte');
        unlink($dir . '/link-target.latte');
        unlink($dir . '/ignored.css');
        unlink($dir . '/real.latte');
        rmdir($dir);
    }
});

test('getExtents recurses into subdirectories', function (): void {
    $dir = sys_get_temp_dir() . '/piwigo-admin-ui-helper-test-' . bin2hex(random_bytes(8));
    mkdir($dir . '/nested/deeper', 0o777, true);

    try {
        file_put_contents($dir . '/top.latte', 'top');
        file_put_contents($dir . '/nested/mid.latte', 'mid');
        file_put_contents($dir . '/nested/deeper/bottom.latte', 'bottom');

        $result = AdminUiHelper::getExtents(Paths::fromRoot(dirname(__DIR__, 3)), $dir);

        expect($result)
            ->toHaveCount(3);
    } finally {
        unlink($dir . '/nested/deeper/bottom.latte');
        unlink($dir . '/nested/mid.latte');
        unlink($dir . '/top.latte');
        rmdir($dir . '/nested/deeper');
        rmdir($dir . '/nested');
        rmdir($dir);
    }
});

test('pwgUrl returns every known piwigo.org URL keyed by section', function (): void {
    expect(AdminUiHelper::pwgUrl())->toBe([
        'HOME' => AppInfo::URL,
        'WIKI' => AppInfo::URL . '/doc',
        'DEMO' => AppInfo::URL . '/demo',
        'FORUM' => AppInfo::URL . '/forum',
        'BUGS' => AppInfo::URL . '/bugs',
        'EXTENSIONS' => AppInfo::URL . '/ext',
    ]);
});

test('getNewsletterSubscribeBaseUrl and getOldNewslettersBaseUrl return their fixed piwigo.org URLs', function (): void {
    expect(AdminUiHelper::getNewsletterSubscribeBaseUrl())->toBe(AppInfo::URL . '/announcement/subscribe/');
    expect(AdminUiHelper::getOldNewslettersBaseUrl())->toBe(AppInfo::URL . '/newsletter');
});

test('getActiveMenu maps every known admin page to its menu section, and an unknown page to -1', function (): void {
    $expectations = [
        'photo' => 0,
        'photos_add' => 0,
        'rating' => 0,
        'tags' => 0,
        'batch_manager' => 0,
        'album' => 1,
        'cat_list' => 1,
        'albums' => 1,
        'cat_options' => 1,
        'cat_search' => 1,
        'permalinks' => 1,
        'user_list' => 2,
        'user_perm' => 2,
        'group_list' => 2,
        'group_perm' => 2,
        'notification_by_mail' => 2,
        'user_activity' => 2,
        'site_manager' => 3,
        'site_update' => 3,
        'stats' => 3,
        'history' => 3,
        'maintenance' => 3,
        'comments' => 3,
        'updates' => 3,
        'configuration' => 4,
        'derivatives' => 4,
        'extend_for_templates' => 4,
        'menubar' => 4,
        'themes' => 4,
        'theme' => 4,
        'languages' => 4,
        'not-a-real-page' => -1,
    ];

    foreach ($expectations as $page => $expectedMenu) {
        expect(AdminUiHelper::getActiveMenu($page))->toBe($expectedMenu);
    }
});

test('numberFormatHumanReadable formats small numbers with no suffix and no decimals', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(0))->toBe('0');
    expect(AdminUiHelper::numberFormatHumanReadable(0.0))->toBe('0');
    expect(AdminUiHelper::numberFormatHumanReadable(42))->toBe('42');
    expect(AdminUiHelper::numberFormatHumanReadable(999))->toBe('999');
});

test('numberFormatHumanReadable leaves a genuinely non-zero int/float value untouched by the zero-normalization ternary', function (): void {
    // Kills 4 separate mutations on the zero-normalization ternary
    // (`$numbers === 0 || $numbers === 0.0 ? 0 : $numbers`), each
    // changing ONE of its two literals to a neighboring value -- each
    // needs an input of the matching type (int vs float) and sign to
    // distinguish it, since strict === never matches across int/float:
    // - DecrementInteger (0 -> -1 on the int check): int -1 wrongly
    //   matches the mutant's first disjunct and gets zeroed out, real
    //   code leaves it as -1 (number_format(-1, 0) is '-1', not '0').
    // - IncrementInteger (0 -> 1 on the int check): int 1 wrongly
    //   matches and gets zeroed the same way.
    // - DecrementFloat (0.0 -> -1.0 on the float check): float -1.0
    //   wrongly matches the mutant's second disjunct.
    // - IncrementFloat (0.0 -> 1.0 on the float check): float 1.0
    //   wrongly matches the same way.
    // (The 5th mutation on this line, BooleanOrToBooleanAnd, is
    // confirmed-equivalent, NOT covered here: `$numbers === 0 &&
    // $numbers === 0.0` can never be true for any value -- a single
    // scalar can't strictly equal both an int and a float literal at
    // once -- so the mutant's ternary always keeps $numbers unchanged;
    // the only value where real code would have actually behaved
    // differently (float 0.0, coerced by real code to int 0) still
    // renders identically via number_format(), which coerces both to
    // the same internal float representation regardless.)
    expect(AdminUiHelper::numberFormatHumanReadable(-1))->toBe('-1');
    expect(AdminUiHelper::numberFormatHumanReadable(1))->toBe('1');
    expect(AdminUiHelper::numberFormatHumanReadable(-1.0))->toBe('-1');
    expect(AdminUiHelper::numberFormatHumanReadable(1.0))->toBe('1');
});

test('numberFormatHumanReadable formats thousands with a "k" suffix and 1 decimal', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(1000))->toBe('1.0k');
    expect(AdminUiHelper::numberFormatHumanReadable(1500))->toBe('1.5k');
    expect(AdminUiHelper::numberFormatHumanReadable(999999))->toBe('1,000.0k');
});

test('numberFormatHumanReadable formats millions with an "M" suffix and 1 decimal', function (): void {
    expect(AdminUiHelper::numberFormatHumanReadable(1000000))->toBe('1.0M');
    expect(AdminUiHelper::numberFormatHumanReadable(2500000))->toBe('2.5M');
});

test('numberFormatHumanReadable caps at "M" instead of overflowing past the known suffix list', function (): void {
    // $readable only has 3 entries (index 0..2 = '', 'k', 'M') -- a value
    // large enough to need a 4th tier (billions) crosses the 1000
    // threshold a 3rd time (index reaches 3), hits the `$index >
    // count($readable) - 1` guard, and steps back to index 2 ('M') --
    // note this means the already-divided value (1.0, not 1000.0) is
    // what gets displayed, a real, observed lossy-display quirk for
    // billion-scale input, not a hypothetical.
    expect(AdminUiHelper::numberFormatHumanReadable(1_000_000_000))->toBe('1.0M');
});

test('numberFormatHumanReadable stops re-dividing once it has already stepped back to the capped tier', function (): void {
    // A value large enough that even the post-cap value is still >= 1000
    // (1 trillion: crosses the 1000 threshold a 4th time before the guard
    // fires) distinguishes a real `break` from a `continue` here -- both
    // land on index 2 ('M') after the guard's $index--, but `continue`
    // would re-enter the loop (numbers is still >= 1000 at that point) and
    // divide a 4th time, an extra, wrong step down to '1.0M' instead of
    // stopping with the already-divided '1,000.0M'.
    expect(AdminUiHelper::numberFormatHumanReadable(1_000_000_000_000))->toBe('1,000.0M');
});
